<?php

namespace ErnestDefoe\TierLists\Api;

use ErnestDefoe\TierLists\Access;
use ErnestDefoe\TierLists\Images;
use ErnestDefoe\TierLists\TierList;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * DELETE /api/tier-lists/{id}: the owner or a moderator. Its discussion stays
 * (it's the forum's now); its remixes stay and keep their own family.
 */
class DeleteController implements RequestHandlerInterface
{
    public function __construct(private readonly Images $images)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $list = TierList::query()->with('items')->find((int) Arr::get($request->getQueryParams(), 'id'));
        if (! $list) {
            return Errors::make(404, 'not_found');
        }
        if (! Access::edit($actor, $list)) {
            return Errors::make(403, 'permission_denied');
        }

        $pictures = $list->items->pluck('image')->filter()->values()->all();
        $root = $list->root_id;
        // Items first: SQLite may not enforce the cascade, and the pictures are released by what's left.
        $list->items()->delete();
        $list->delete();

        $this->images->release($pictures);
        if ($root) {
            TierList::query()->where('id', $root)->update(['remixes_count' => TierList::query()->where('root_id', $root)->count()]);
        }

        return new EmptyResponse(204);
    }
}
