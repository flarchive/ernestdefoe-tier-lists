<?php

namespace ErnestDefoe\TierLists\Api;

use ErnestDefoe\TierLists\Access;
use ErnestDefoe\TierLists\Images;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST   /api/tier-lists/images/track  {url}  a picture the editor just uploaded to FoF Upload
 * DELETE /api/tier-lists/images        {url}  a picture removed in the editor before saving
 *
 * Both only touch the member's own uploads; deleting also needs the picture to
 * be unclaimed (no saved list has shown it) and unused.
 */
class ImageController implements RequestHandlerInterface
{
    public function __construct(private readonly Images $images, private readonly RateLimit $limit)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        if (! Access::create($actor)) {
            return Errors::make(403, 'permission_denied');
        }
        if ($this->limit->exceeded($actor, 'image-ops', 120, 60)) {
            return Errors::make(429, 'tier_lists_slow_down');
        }

        $body = (array) $request->getParsedBody();
        $url = is_string($body['url'] ?? null) ? $body['url'] : '';
        if ($url === '' || strlen($url) > 255) {
            return Errors::make(422, 'tier_lists_bad_image');
        }

        if ($request->getMethod() === 'DELETE') {
            $this->images->forget($url, (int) $actor->id);

            return new EmptyResponse(204);
        }

        return new JsonResponse(['tracked' => $this->images->track($url, (int) $actor->id)]);
    }
}
