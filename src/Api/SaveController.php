<?php

namespace ErnestDefoe\TierLists\Api;

use Carbon\Carbon;
use ErnestDefoe\TierLists\Access;
use ErnestDefoe\TierLists\Discussions;
use ErnestDefoe\TierLists\Images;
use ErnestDefoe\TierLists\Item;
use ErnestDefoe\TierLists\Notification\RemixedBlueprint;
use ErnestDefoe\TierLists\Settings;
use ErnestDefoe\TierLists\TierList;
use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Flarum\Notification\NotificationSyncer;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST  /api/tier-lists        a new list, or a remix ({parentId})
 * PATCH /api/tier-lists/{id}   the owner (or a moderator) edits it
 *
 * Body: {title, description, tiers: [{label, color}], items: [{key, label, image, tier}],
 *        isTemplate, discussion}. Items arrive in the member's order; `tier` null = the pool.
 *
 * 🚨 Item keys are the server's. A key is kept only when it already belongs to
 * this list or the list it remixes; anything else gets a new one, so nobody
 * can slip items into another family's crowd average.
 *
 * 🚨 A remix of a TEMPLATE takes its items (labels, pictures, keys) from the
 * template, never from the browser: the member only places them.
 */
class SaveController implements RequestHandlerInterface
{
    public function __construct(
        private readonly Images $images,
        private readonly Discussions $discussions,
        private readonly ShowController $show,
        private readonly RateLimit $limit,
        private readonly NotificationSyncer $notifications,
        private readonly ConnectionInterface $db,
        private readonly SettingsRepositoryInterface $settings,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $creating = $request->getMethod() === 'POST';
        $body = (array) $request->getParsedBody();

        if ($creating) {
            if (! Access::create($actor)) {
                return Errors::make(403, 'permission_denied');
            }
            if ($this->limit->exceeded($actor, 'save', 20, 3600)) {
                return Errors::make(429, 'tier_lists_slow_down');
            }
            $list = new TierList();
            $list->user_id = $actor->id;
            $parent = isset($body['parentId']) ? TierList::query()->with('items')->find((int) $body['parentId']) : null;
            if (isset($body['parentId']) && ! $parent) {
                return Errors::make(404, 'not_found');
            }
        } else {
            $list = TierList::query()->with('items')->find((int) Arr::get($request->getQueryParams(), 'id'));
            if (! $list) {
                return Errors::make(404, 'not_found');
            }
            if (! Access::edit($actor, $list)) {
                return Errors::make(403, 'permission_denied');
            }
            if ($this->limit->exceeded($actor, 'edit', 60, 3600)) {
                return Errors::make(429, 'tier_lists_slow_down');
            }
            $parent = $list->parent_id ? TierList::query()->with('items')->find($list->parent_id) : null;
        }

        $rootId = $creating ? $parent?->familyId() : $list->root_id;
        $root = $rootId ? ($parent && $parent->id === $rootId ? $parent : TierList::query()->with('items')->find($rootId)) : null;

        $title = is_string($body['title'] ?? null) ? trim(preg_replace('/\s+/u', ' ', $body['title'])) : '';
        $description = is_string($body['description'] ?? null) ? trim($body['description']) : '';
        if ($title === '' || mb_strlen($title) > 100) {
            return Errors::make(422, 'tier_lists_bad_title');
        }
        if (mb_strlen($description) > 1000) {
            return Errors::make(422, 'tier_lists_bad_description');
        }

        $tiers = $this->tiers($body['tiers'] ?? null);
        if ($tiers === null) {
            return Errors::make(422, 'tier_lists_bad_tiers');
        }

        // Pictures and keys this list may already use: its own, and its parent's.
        $known = [];
        foreach ([$list->exists ? $list->items : [], $parent ? $parent->items : []] as $source) {
            foreach ($source as $item) {
                $known[$item->key] ??= $item;
            }
        }

        $template = $root && $root->is_template && $root->id !== $list->id ? $root : null;
        $items = $template ? $this->placeTemplate($template, $body['items'] ?? null, count($tiers)) : $this->items($body['items'] ?? null, $known, count($tiers));
        if (is_string($items)) {
            return Errors::make(422, $items);
        }

        $before = $list->exists ? $list->items->pluck('image')->filter()->all() : [];
        $wasNew = ! $list->exists;

        $this->db->transaction(function () use ($list, $title, $description, $tiers, $items, $body, $parent, $rootId, $creating) {
            $list->title = $title;
            $list->description = $description === '' ? null : $description;
            $list->tiers = json_encode($tiers, JSON_UNESCAPED_UNICODE);
            $list->tier_count = count($tiers);
            $list->items_count = count($items);
            if ($creating) {
                $list->parent_id = $parent?->id;
                $list->root_id = $rootId;
            }
            // Only an original can be a template.
            $list->is_template = ! $list->root_id && ! empty($body['isTemplate']);
            $list->save();

            Item::query()->where('tier_list_id', $list->id)->delete();
            $rows = [];
            foreach ($items as $position => $item) {
                $rows[] = ['tier_list_id' => $list->id, 'position' => $position] + $item;
            }
            foreach (array_chunk($rows, 200) as $chunk) {
                Item::query()->insert($chunk);
            }
        });

        $after = array_values(array_filter(array_column($items, 'image')));
        $this->images->claim($after);
        if ($gone = array_values(array_diff($before, $after))) {
            $this->images->release($gone);
        }

        if ($wasNew && $root) {
            $this->recountRemixes($root->id);
            $this->notify($parent, $list, $actor);
        }

        $discussionError = null;
        if (! empty($body['discussion'])) {
            $discussionError = $this->discuss($request, $actor, $list, $root, $wasNew);
        }

        $list->unsetRelation('items');
        $list->load(['user', 'items']);

        return new JsonResponse(['data' => $this->show->full($list, $actor), 'discussionError' => $discussionError], $wasNew ? 201 : 200);
    }

    /** @return list<array{label: string, color: string}>|null */
    private function tiers(mixed $raw): ?array
    {
        if (! is_array($raw) || count($raw) < Settings::MIN_TIERS || count($raw) > Settings::MAX_TIERS) {
            return null;
        }

        $tiers = [];
        foreach (array_values($raw) as $tier) {
            $label = is_array($tier) && is_string($tier['label'] ?? null) ? trim($tier['label']) : '';
            $color = is_array($tier) && is_string($tier['color'] ?? null) ? strtolower($tier['color']) : '';
            if ($label === '' || mb_strlen($label) > 24 || ! preg_match('/^#[0-9a-f]{6}$/D', $color)) {
                return null;
            }
            $tiers[] = ['label' => $label, 'color' => $color];
        }

        return $tiers;
    }

    /**
     * @param array<string, Item> $known
     * @return list<array{key: string, label: string, image: ?string, tier: ?int}>|string
     */
    private function items(mixed $raw, array $known, int $tierCount): array|string
    {
        if (! is_array($raw) || count($raw) < 1) {
            return 'tier_lists_no_items';
        }
        if (count($raw) > Settings::maxItems($this->settings)) {
            return 'tier_lists_too_many_items';
        }

        $items = [];
        $seen = [];
        $check = [];
        $knownImages = [];
        foreach ($known as $item) {
            if ($item->image) {
                $knownImages[$item->image] = true;
            }
        }

        foreach (array_values($raw) as $item) {
            if (! is_array($item)) {
                return 'tier_lists_bad_items';
            }
            $label = is_string($item['label'] ?? null) ? trim(preg_replace('/\s+/u', ' ', $item['label'])) : '';
            $image = is_string($item['image'] ?? null) && $item['image'] !== '' ? $item['image'] : null;
            $tier = $this->tier($item['tier'] ?? null, $tierCount);

            if (mb_strlen($label) > 80 || ($label === '' && ! $image) || $tier === false || ($image !== null && strlen($image) > 255)) {
                return 'tier_lists_bad_items';
            }

            $key = is_string($item['key'] ?? null) && isset($known[$item['key']]) && ! isset($seen[$item['key']]) ? $item['key'] : $this->newKey();
            $seen[$key] = true;

            if ($image !== null && ! isset($knownImages[$image])) {
                $check[] = $image;
            }

            $items[] = ['key' => $key, 'label' => $label, 'image' => $image, 'tier' => $tier];
        }

        if ($check) {
            $allowed = $this->images->allowed($check);
            foreach ($check as $url) {
                if (! isset($allowed[$url])) {
                    return 'tier_lists_bad_image';
                }
            }
        }

        return $items;
    }

    /**
     * The template's items, placed where the member put them (by key, in their order).
     *
     * @return list<array{key: string, label: string, image: ?string, tier: ?int}>|string
     */
    private function placeTemplate(TierList $template, mixed $raw, int $tierCount): array|string
    {
        $placed = [];
        foreach (is_array($raw) ? array_values($raw) : [] as $order => $item) {
            if (is_array($item) && is_string($item['key'] ?? null)) {
                $tier = $this->tier($item['tier'] ?? null, $tierCount);
                if ($tier === false) {
                    return 'tier_lists_bad_items';
                }
                $placed[$item['key']] ??= [$tier, $order];
            }
        }

        $items = [];
        foreach ($template->items as $index => $item) {
            [$tier, $order] = $placed[$item->key] ?? [null, PHP_INT_MAX - 1000 + $index];
            $items[] = ['order' => $order, 'key' => $item->key, 'label' => $item->label, 'image' => $item->image, 'tier' => $tier];
        }
        usort($items, fn ($a, $b) => $a['order'] <=> $b['order']);

        return array_map(fn ($item) => Arr::except($item, 'order'), $items);
    }

    /** @return int|null|false null for the pool, false when out of range */
    private function tier(mixed $tier, int $tierCount): int|null|false
    {
        if ($tier === null) {
            return null;
        }

        return is_int($tier) && $tier >= 0 && $tier < $tierCount ? $tier : false;
    }

    private function newKey(): string
    {
        return Str::lower(Str::random(12));
    }

    private function recountRemixes(int $rootId): void
    {
        $count = TierList::query()->where('root_id', $rootId)->count();
        TierList::query()->where('id', $rootId)->update(['remixes_count' => $count]);
    }

    private function notify(?TierList $parent, TierList $remix, User $actor): void
    {
        $owner = $parent?->user;
        if ($owner && $owner->id !== $actor->id) {
            $this->notifications->sync(new RemixedBlueprint($remix, $actor), [$owner]);
        }
    }

    private function discuss(ServerRequestInterface $request, User $actor, TierList $list, ?TierList $root, bool $wasNew): ?string
    {
        if (! $list->root_id) {
            return $list->discussion_id ? null : $this->discussions->start($request, $actor, $list);
        }

        // A remix is shared once, when it is made, in the original's discussion.
        if (! $wasNew) {
            return null;
        }
        $discussion = $root?->discussion_id ? Discussion::whereVisibleTo($actor)->find($root->discussion_id) : null;

        return $discussion ? $this->discussions->reply($request, $actor, $list, $discussion) : 'tier_lists_no_discussion';
    }
}
