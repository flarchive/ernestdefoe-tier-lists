<?php

namespace ErnestDefoe\TierLists\Tests\integration\api;

use ErnestDefoe\TierLists\TierList;
use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class TierListTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private const TIERS = [['label' => 'S', 'color' => '#ff7f7f'], ['label' => 'A', 'color' => '#ffbf7f'], ['label' => 'B', 'color' => '#ffdf7f']];

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-tier-lists');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'carol', 'email' => 'carol@machine.local', 'is_email_confirmed' => 1, 'password' => 'x'],
            ],
            'group_user' => [['user_id' => 3, 'group_id' => Group::MEMBER_ID]],
        ]);
    }

    /** @return array{int, array<string, mixed>|null} */
    private function call(string $method, string $path, ?array $body = null, ?int $as = 2): array
    {
        $options = array_filter(['authenticatedAs' => $as, 'json' => $body], fn ($v) => $v !== null);
        $response = $this->send($this->request($method, $path, $options));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    /** @return array<string, mixed> */
    private function list(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Best pizza',
            'tiers' => self::TIERS,
            'items' => [
                ['label' => 'Pepperoni', 'tier' => 0],
                ['label' => 'Mushroom', 'tier' => 1],
                ['label' => 'Pineapple', 'tier' => 2],
                ['label' => 'Anchovies', 'tier' => null],
            ],
        ];
    }

    #[Test]
    public function a_member_makes_a_list_and_guests_can_read_it()
    {
        [$status, $body] = $this->call('POST', '/api/tier-lists', $this->list());
        $this->assertSame(201, $status);
        $this->assertSame('Best pizza', $body['data']['title']);
        $this->assertCount(4, $body['data']['allItems']);

        [$status, $body] = $this->call('GET', '/api/tier-lists/'.$body['data']['id'], null, null);
        $this->assertSame(200, $status);
        $this->assertSame('Pepperoni', $body['data']['allItems'][0]['label']);
        $this->assertNull($body['data']['allItems'][3]['tier']);
    }

    #[Test]
    public function bad_input_is_refused()
    {
        $this->assertSame(422, $this->call('POST', '/api/tier-lists', $this->list(['title' => ' ']))[0]);
        $this->assertSame(422, $this->call('POST', '/api/tier-lists', $this->list(['tiers' => [self::TIERS[0]]]))[0]);
        $this->assertSame(422, $this->call('POST', '/api/tier-lists', $this->list(['items' => [['label' => 'x', 'tier' => 7]]]))[0]);
        $this->assertSame(422, $this->call('POST', '/api/tier-lists', $this->list(['tiers' => [['label' => 'S', 'color' => 'red'], self::TIERS[1]]]))[0]);
        // A picture from another site would leak every reader's address to it.
        [$status, $body] = $this->call('POST', '/api/tier-lists', $this->list(['items' => [['label' => 'x', 'image' => 'https://evil.example/a.png', 'tier' => 0]]]));
        $this->assertSame(422, $status);
        $this->assertSame('tier_lists_bad_image', $body['errors'][0]['code']);
    }

    #[Test]
    public function guests_cannot_make_lists_and_members_cannot_edit_others()
    {
        $this->assertContains($this->call('POST', '/api/tier-lists', $this->list(), null)[0], [400, 401, 403]);

        $id = $this->call('POST', '/api/tier-lists', $this->list())[1]['data']['id'];
        $this->assertSame(403, $this->call('PATCH', "/api/tier-lists/$id", $this->list(['title' => 'Mine now']), 3)[0]);
        $this->assertSame(403, $this->call('DELETE', "/api/tier-lists/$id", null, 3)[0]);
        $this->assertSame(200, $this->call('PATCH', "/api/tier-lists/$id", $this->list(['title' => 'Renamed']), 1)[0]);
        $this->assertSame(204, $this->call('DELETE', "/api/tier-lists/$id", null, 2)[0]);
    }

    #[Test]
    public function a_remix_keeps_item_keys_and_the_original_shows_the_crowd()
    {
        $original = $this->call('POST', '/api/tier-lists', $this->list())[1]['data'];
        $keys = array_column($original['allItems'], 'key', 'label');

        // Carol flips Pepperoni and Pineapple, and tries to borrow a key twice and invent one.
        [$status, $remix] = $this->call('POST', '/api/tier-lists', [
            'parentId' => $original['id'],
            'title' => 'My pizza',
            'tiers' => self::TIERS,
            'items' => [
                ['key' => $keys['Pineapple'], 'label' => 'Pineapple', 'tier' => 0],
                ['key' => $keys['Mushroom'], 'label' => 'Mushroom', 'tier' => 1],
                ['key' => $keys['Pepperoni'], 'label' => 'Pepperoni', 'tier' => 2],
                ['key' => $keys['Pepperoni'], 'label' => 'Ham', 'tier' => 0],
                ['key' => 'made-up', 'label' => 'Olives', 'tier' => 1],
            ],
        ], 3);
        $this->assertSame(201, $status);
        $remixKeys = array_column($remix['data']['allItems'], 'key', 'label');
        $this->assertSame($keys['Pineapple'], $remixKeys['Pineapple']);
        $this->assertNotSame($keys['Pepperoni'], $remixKeys['Ham']);
        $this->assertNotSame('made-up', $remixKeys['Olives']);

        [, $show] = $this->call('GET', '/api/tier-lists/'.$original['id']);
        $crowd = $show['data']['crowd'];
        $this->assertSame(2, $show['data']['familySize']);
        $this->assertSame([0.5, 2], $crowd[$keys['Pepperoni']]);
        $this->assertSame([0.5, 2], $crowd[$keys['Pineapple']]);
        $this->assertSame([0.5, 2], $crowd[$keys['Mushroom']]);
        $this->assertSame(1, TierList::find($original['id'])->remixes_count);

        // Carol's own ranking comes along for "you vs the crowd".
        [, $mine] = $this->call('GET', '/api/tier-lists/'.$original['id'], null, 3);
        $this->assertSame(0, $mine['data']['mine']['items'][$keys['Pineapple']]);

        // The owner hears about it.
        $this->assertSame(1, $this->database()->table('notifications')->where('user_id', 2)->where('type', 'tierListRemixed')->count());
    }

    #[Test]
    public function ranking_a_template_takes_its_items_from_the_template()
    {
        $template = $this->call('POST', '/api/tier-lists', $this->list(['isTemplate' => true]))[1]['data'];
        $keys = array_column($template['allItems'], 'key', 'label');

        [$status, $ranking] = $this->call('POST', '/api/tier-lists', [
            'parentId' => $template['id'],
            'title' => 'Ranked',
            'tiers' => self::TIERS,
            'items' => [
                ['key' => $keys['Anchovies'], 'label' => 'Something else', 'image' => 'https://evil.example/x.png', 'tier' => 0],
                ['key' => 'new', 'label' => 'Sneaky extra', 'tier' => 0],
            ],
        ], 3);

        $this->assertSame(201, $status);
        $items = $ranking['data']['allItems'];
        $this->assertCount(4, $items);
        $this->assertSame(['Anchovies', 0, null], [$items[0]['label'], $items[0]['tier'], $items[0]['image']]);
        $this->assertNull(array_values(array_filter($items, fn ($item) => $item['label'] === 'Pepperoni'))[0]['tier']);
        $this->assertFalse($ranking['data']['isTemplate']);
    }

    #[Test]
    public function likes_count_once_and_can_be_taken_back()
    {
        $id = $this->call('POST', '/api/tier-lists', $this->list())[1]['data']['id'];

        $this->call('POST', "/api/tier-lists/$id/like", null, 3);
        [, $body] = $this->call('POST', "/api/tier-lists/$id/like", null, 3);
        $this->assertSame(1, $body['likes']);
        [, $body] = $this->call('DELETE', "/api/tier-lists/$id/like", null, 3);
        $this->assertSame(0, $body['likes']);
    }

    #[Test]
    public function the_list_page_is_a_constant_number_of_queries()
    {
        $count = function (int $lists): int {
            for ($i = 0; $i < $lists; $i++) {
                $this->call('POST', '/api/tier-lists', $this->list(['title' => "List $i"]));
            }
            $db = $this->database();
            $db->flushQueryLog();
            $db->enableQueryLog();
            $this->call('GET', '/api/tier-lists', null, 3);
            $queries = count($db->getQueryLog());
            $db->disableQueryLog();

            return $queries;
        };

        $one = $count(1);
        $many = $count(19);
        // The first request also warms caches: 20 lists may cost fewer queries than 1, never more.
        $this->assertLessThanOrEqual($one, $many, "1 list: $one queries; 20 lists: $many");
    }

    #[Test]
    public function liking_needs_the_make_remix_and_like_permission()
    {
        // Permissions are read once per process: take it away before the first request.
        $this->database()->table('group_permission')->where('permission', 'ernestdefoe-tier-lists.create')->delete();
        $id = $this->call('POST', '/api/tier-lists', $this->list(), 1)[1]['data']['id'];

        $this->assertSame(403, $this->call('POST', "/api/tier-lists/$id/like", null, 3)[0]);
        $this->assertSame(403, $this->call('POST', "/api/tier-lists/$id/discussion", null, 3)[0]);
        $this->assertFalse($this->call('GET', "/api/tier-lists/$id", null, 3)[1]['data']['can']['like']);
    }

    #[Test]
    public function editing_a_template_ranking_keeps_the_template_items()
    {
        $template = $this->call('POST', '/api/tier-lists', $this->list(['isTemplate' => true]))[1]['data'];
        $keys = array_column($template['allItems'], 'key', 'label');
        $ranking = $this->call('POST', '/api/tier-lists', ['parentId' => $template['id'], 'title' => 'Mine', 'tiers' => self::TIERS, 'items' => []], 3)[1]['data'];

        [$status, $edited] = $this->call('PATCH', '/api/tier-lists/'.$ranking['id'], [
            'title' => 'Mine',
            'tiers' => self::TIERS,
            'items' => [['key' => $keys['Pepperoni'], 'label' => 'Renamed', 'tier' => 2], ['label' => 'Extra', 'tier' => 0]],
        ], 3);

        $this->assertSame(200, $status);
        $this->assertCount(4, $edited['data']['allItems']);
        $this->assertSame(['Pepperoni', 2], [$edited['data']['allItems'][0]['label'], $edited['data']['allItems'][0]['tier']]);
    }

    #[Test]
    public function deleting_a_member_releases_their_pictures_and_recounts_others()
    {
        $original = $this->call('POST', '/api/tier-lists', $this->list())[1]['data'];
        $keys = array_column($original['allItems'], 'key');

        // Carol remixes it with a picture of her own, and likes it.
        $images = $this->app()->getContainer()->make(\ErnestDefoe\TierLists\Images::class);
        $path = tempnam(sys_get_temp_dir(), 'tl').'.png';
        $im = imagecreatetruecolor(40, 40);
        imagepng($im, $path);
        $url = $images->store($path, 3);
        @unlink($path);

        $items = array_map(fn ($key) => ['key' => $key, 'label' => 'x', 'tier' => 0], $keys);
        $items[] = ['label' => 'Mine', 'image' => $url, 'tier' => 1];
        $this->assertSame(201, $this->call('POST', '/api/tier-lists', ['parentId' => $original['id'], 'title' => 'Remix', 'tiers' => self::TIERS, 'items' => $items], 3)[0]);
        $this->call('POST', '/api/tier-lists/'.$original['id'].'/like', null, 3);

        $row = TierList::find($original['id']);
        $this->assertSame([1, 1], [$row->likes_count, $row->remixes_count]);
        $this->assertSame(1, $this->database()->table('tier_list_images')->where('claimed', true)->count());

        User::find(3)->delete();

        $row = TierList::find($original['id']);
        $this->assertSame([0, 0], [$row->likes_count, $row->remixes_count]);
        $this->assertSame(0, $this->database()->table('tier_list_images')->count());
    }
}
