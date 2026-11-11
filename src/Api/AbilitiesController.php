<?php

namespace ErnestDefoe\TierLists\Api;

use ErnestDefoe\TierLists\Access;
use ErnestDefoe\TierLists\Discussions;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/tier-lists/abilities: what the editor may offer, asked when it
 * opens. Not in the forum payload: "may start a discussion" can cost a tag
 * query, and that payload is built on every page.
 */
class AbilitiesController implements RequestHandlerInterface
{
    public function __construct(private readonly Discussions $discussions)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $create = Access::create($actor);

        return new JsonResponse(['create' => $create, 'discuss' => $create && $this->discussions->canStart($actor)]);
    }
}
