<?php

namespace ErnestDefoe\TierLists\Api;

use ErnestDefoe\TierLists\Access;
use ErnestDefoe\TierLists\Item;
use ErnestDefoe\TierLists\Present;
use ErnestDefoe\TierLists\TierList;
use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Flarum\Http\SlugManager;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/tier-lists?sort=new|popular|remixed&filter=all|templates|remixes|mine&author=<slug>&family=<id>&q=&offset=
 * GET /api/tier-lists?ids=1,2,3     (lists linked in posts: every item, one request per page)
 *
 * A constant number of queries whatever the page size: the lists with their
 * authors, then ONE query each for the items, the viewer's likes, the
 * discussions (visible to the viewer only) and the lists remixed.
 */
class ListController implements RequestHandlerInterface
{
    private const PER_PAGE = 24;

    public function __construct(
        private readonly Present $present,
        private readonly SlugManager $slugs,
        private readonly ConnectionInterface $db,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        if (! Access::view($actor)) {
            return Errors::make(403, 'permission_denied');
        }

        $params = $request->getQueryParams();
        $ids = $this->ids($params['ids'] ?? null);
        $offset = max(0, (int) ($params['offset'] ?? 0));

        $query = TierList::query()->with('user');

        if ($ids !== null) {
            $query->whereIn('id', $ids);
        } else {
            if (! $this->filter($query, $params, $actor)) {
                return new JsonResponse(['data' => [], 'hasMore' => false]);
            }
            $this->search($query, $params['q'] ?? null);
            $this->sort($query, (string) ($params['sort'] ?? 'new'));
            $query->offset($offset)->limit(self::PER_PAGE + 1);
        }

        $lists = $query->get();
        $hasMore = $ids === null && $lists->count() > self::PER_PAGE;
        $lists = $lists->take($ids === null ? self::PER_PAGE : 20);
        $listIds = $lists->pluck('id')->all();

        $items = $listIds
            ? Item::query()->whereIn('tier_list_id', $listIds)
                ->when($ids === null, fn ($q) => $q->whereNotNull('tier')->where('tier', '<', 3))
                ->orderBy('position')->get()->groupBy('tier_list_id')
            : collect();

        $liked = $listIds && ! $actor->isGuest()
            ? array_flip($this->db->table('tier_list_likes')->where('user_id', $actor->id)->whereIn('tier_list_id', $listIds)->pluck('tier_list_id')->all())
            : [];

        $discussionIds = $lists->pluck('discussion_id')->filter()->unique()->values()->all();
        $discussions = $discussionIds
            ? Discussion::whereVisibleTo($actor)->whereIn('discussions.id', $discussionIds)->get()->keyBy('id')
            : collect();

        $parentIds = $lists->pluck('parent_id')->filter()->unique()->values()->all();
        $parents = $parentIds ? TierList::query()->with('user')->whereIn('id', $parentIds)->get()->keyBy('id') : collect();

        $data = [];
        foreach ($lists as $list) {
            $listItems = $items->get($list->id, collect());
            $card = $this->present->card(
                $list,
                $listItems,
                isset($liked[$list->id]),
                $list->discussion_id ? $discussions->get($list->discussion_id) : null,
                $list->parent_id ? $parents->get($list->parent_id) : null,
            );
            if ($ids !== null) {
                $card['allItems'] = $this->present->items($listItems);
            }
            $data[] = $card;
        }

        return new JsonResponse(['data' => $data, 'hasMore' => $hasMore]);
    }

    /** @return list<int>|null */
    private function ids(mixed $raw): ?array
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', $raw))))), 0, 20);
    }

    /**
     * @param Builder<TierList> $query
     * @param array<string, mixed> $params
     * @return bool false when the filter can match nothing (an unknown author, "mine" for a guest)
     */
    private function filter(Builder $query, array $params, User $actor): bool
    {
        $author = $params['author'] ?? null;
        if (is_string($author) && $author !== '') {
            $user = $this->author($author, $actor);
            if (! $user) {
                return false;
            }
            $query->where('user_id', $user->id);

            return true;
        }

        $family = (int) ($params['family'] ?? 0);
        if ($family > 0) {
            $query->where('root_id', $family);

            return true;
        }

        switch ($params['filter'] ?? 'all') {
            case 'templates':
                $query->whereNull('root_id')->where('is_template', true);
                break;
            case 'remixes':
                $query->whereNotNull('root_id');
                break;
            case 'mine':
                if ($actor->isGuest()) {
                    return false;
                }
                $query->where('user_id', $actor->id);
                break;
            default:
                $query->whereNull('root_id');
        }

        return true;
    }

    private function author(string $slug, User $actor): ?User
    {
        try {
            return $this->slugs->forResource(User::class)->fromSlug($slug, $actor);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param Builder<TierList> $query */
    private function search(Builder $query, mixed $q): void
    {
        if (! is_string($q) || ($q = trim(mb_substr($q, 0, 60))) === '') {
            return;
        }

        // 🚨 LIKE is case-sensitive on PostgreSQL, and SQLite has no default escape.
        $column = $query->getQuery()->getGrammar()->wrap('tier_lists.title');
        $needle = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($q)).'%';
        $query->whereRaw("LOWER($column) LIKE ? ESCAPE '!'", [$needle]);
    }

    /** @param Builder<TierList> $query */
    private function sort(Builder $query, string $sort): void
    {
        match ($sort) {
            'popular' => $query->orderByDesc('likes_count'),
            'remixed' => $query->orderByDesc('remixes_count'),
            default => null,
        };

        $query->orderByDesc('id');
    }
}
