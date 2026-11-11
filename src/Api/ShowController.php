<?php

namespace ErnestDefoe\TierLists\Api;

use ErnestDefoe\TierLists\Access;
use ErnestDefoe\TierLists\Crowd;
use ErnestDefoe\TierLists\Discussions;
use ErnestDefoe\TierLists\Present;
use ErnestDefoe\TierLists\TierList;
use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/tier-lists/{id}
 *
 * The whole list, the crowd's average for each of its items (over the remix
 * family), the viewer's own ranking in the family (for "how you differ"),
 * where it came from and the newest remixes.
 */
class ShowController implements RequestHandlerInterface
{
    public function __construct(
        private readonly Present $present,
        private readonly Crowd $crowd,
        private readonly Discussions $discussions,
        private readonly ConnectionInterface $db,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        if (! Access::view($actor)) {
            return Errors::make(403, 'permission_denied');
        }

        /** @var TierList|null $list */
        $list = TierList::query()->with(['user', 'items'])->find((int) Arr::get($request->getQueryParams(), 'id'));
        if (! $list) {
            return Errors::make(404, 'not_found');
        }

        return new JsonResponse(['data' => $this->full($list, $actor)]);
    }

    /** @return array<string, mixed> */
    public function full(TierList $list, User $actor): array
    {
        $family = $list->familyId();

        $related = TierList::query()->with('user')->whereIn('id', array_unique(array_filter([$list->parent_id, $list->root_id])))->get()->keyBy('id');
        $root = $list->root_id ? $related->get($list->root_id) : $list;

        // The conversation lives on the list's own discussion, or the original's.
        $discussionId = $list->discussion_id ?: $root?->discussion_id;
        $discussion = $discussionId ? Discussion::whereVisibleTo($actor)->find($discussionId) : null;

        $liked = ! $actor->isGuest() && $this->db->table('tier_list_likes')->where('tier_list_id', $list->id)->where('user_id', $actor->id)->exists();

        $card = $this->present->card($list, [], $liked, $discussion, $list->parent_id ? $related->get($list->parent_id) : null);

        $remixes = TierList::query()->with('user')
            ->where('root_id', $family)->where('id', '!=', $list->id)
            ->orderByDesc('id')->limit(12)->get();

        $mine = null;
        if (! $actor->isGuest() && $list->user_id !== $actor->id) {
            $own = TierList::query()->with('items')->where('user_id', $actor->id)
                ->where(fn ($q) => $q->where('id', $family)->orWhere('root_id', $family))
                ->orderByDesc('id')->first();
            if ($own) {
                $mine = [
                    'id' => $own->id,
                    'tiers' => $own->tier_count,
                    'items' => $own->items->filter(fn ($item) => $item->tier !== null)->mapWithKeys(fn ($item) => [$item->key => $item->tier])->all(),
                ];
            }
        }

        return $card + [
            'description' => $list->description,
            'allItems' => $this->present->items($list->items),
            'crowd' => (object) $this->crowd->averages($family),
            'familySize' => $this->db->table('tier_lists')->where(fn ($q) => $q->where('id', $family)->orWhere('root_id', $family))->count(),
            'root' => $list->root_id ? ($root ? ['id' => $root->id, 'title' => $root->title, 'user' => $this->present->user($root->user), 'isTemplate' => $root->is_template] : ['id' => null]) : null,
            'remixList' => $remixes->map(fn (TierList $remix) => ['id' => $remix->id, 'user' => $this->present->user($remix->user), 'createdAt' => $remix->created_at?->toIso8601String()])->all(),
            'mine' => $mine,
            'updatedAt' => $list->updated_at?->toIso8601String(),
            'can' => [
                'edit' => Access::edit($actor, $list),
                'remix' => Access::create($actor),
                'like' => Access::create($actor),
                'discuss' => ! $list->discussion_id && ! $list->root_id && $list->user_id === $actor->id && $this->discussions->canStart($actor),
            ],
        ];
    }
}
