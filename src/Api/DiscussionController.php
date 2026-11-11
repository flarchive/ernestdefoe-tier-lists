<?php

namespace ErnestDefoe\TierLists\Api;

use ErnestDefoe\TierLists\Access;
use ErnestDefoe\TierLists\Discussions;
use ErnestDefoe\TierLists\TierList;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** POST /api/tier-lists/{id}/discussion: the owner of an original starts its discussion later. */
class DiscussionController implements RequestHandlerInterface
{
    public function __construct(
        private readonly Discussions $discussions,
        private readonly ShowController $show,
        private readonly RateLimit $limit,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        if (! Access::create($actor)) {
            return Errors::make(403, 'permission_denied');
        }

        $list = TierList::query()->with(['user', 'items'])->find((int) Arr::get($request->getQueryParams(), 'id'));
        if (! $list) {
            return Errors::make(404, 'not_found');
        }
        if ($list->user_id !== $actor->id || $list->root_id) {
            return Errors::make(403, 'permission_denied');
        }
        if ($list->discussion_id) {
            return Errors::make(409, 'tier_lists_has_discussion');
        }
        if ($this->limit->exceeded($actor, 'discuss', 10, 3600)) {
            return Errors::make(429, 'tier_lists_slow_down');
        }

        if ($error = $this->discussions->start($request, $actor, $list)) {
            return Errors::make(422, $error);
        }

        return new JsonResponse(['data' => $this->show->full($list, $actor)]);
    }
}
