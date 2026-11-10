<?php

namespace ErnestDefoe\TierLists\Api;

use Carbon\Carbon;
use ErnestDefoe\TierLists\Access;
use ErnestDefoe\TierLists\Notification\LikedBlueprint;
use ErnestDefoe\TierLists\TierList;
use Flarum\Http\RequestUtil;
use Flarum\Notification\NotificationSyncer;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** POST likes, DELETE unlikes: /api/tier-lists/{id}/like → {likes, liked}. */
class LikeController implements RequestHandlerInterface
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly NotificationSyncer $notifications,
        private readonly RateLimit $limit,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        // Liking is part of "make, remix and like tier lists".
        if (! Access::view($actor) || ! Access::create($actor)) {
            return Errors::make(403, 'permission_denied');
        }

        $list = TierList::query()->with('user')->find((int) Arr::get($request->getQueryParams(), 'id'));
        if (! $list) {
            return Errors::make(404, 'not_found');
        }
        if ($this->limit->exceeded($actor, 'like', 60, 60)) {
            return Errors::make(429, 'tier_lists_slow_down');
        }

        $likes = $this->db->table('tier_list_likes');
        $like = $request->getMethod() === 'POST';

        if ($like) {
            try {
                $changed = $likes->insertOrIgnore(['tier_list_id' => $list->id, 'user_id' => $actor->id, 'created_at' => Carbon::now()]) > 0;
            } catch (UniqueConstraintViolationException) {
                $changed = false;
            }
        } else {
            $changed = $likes->where('tier_list_id', $list->id)->where('user_id', $actor->id)->delete() > 0;
        }

        if ($changed) {
            $count = $this->db->table('tier_list_likes')->where('tier_list_id', $list->id)->count();
            TierList::query()->where('id', $list->id)->update(['likes_count' => $count]);
            $list->likes_count = $count;

            if ($list->user && $list->user->id !== $actor->id) {
                // sync() with nobody takes the alert back on an unlike.
                $this->notifications->sync(new LikedBlueprint($list, $actor), $like ? [$list->user] : []);
            }
        }

        return new JsonResponse(['likes' => $list->likes_count, 'liked' => $like]);
    }
}
