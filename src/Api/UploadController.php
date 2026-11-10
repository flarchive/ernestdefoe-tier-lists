<?php

namespace ErnestDefoe\TierLists\Api;

use ErnestDefoe\TierLists\Access;
use ErnestDefoe\TierLists\Images;
use ErnestDefoe\TierLists\ImageValidator;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/tier-lists/images  (multipart, field `image`)  →  {url}
 *
 * The editor's own uploader, used when FoF Upload isn't enabled. The list that
 * shows the picture doesn't exist yet; an upload no list claims within a day
 * is deleted.
 */
class UploadController implements RequestHandlerInterface
{
    public function __construct(
        private readonly Images $images,
        private readonly ImageValidator $validator,
        private readonly RateLimit $limit,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        if (! Access::create($actor)) {
            return Errors::make(403, 'permission_denied');
        }

        // Each upload is a decode and an encode; a big list is 120 pictures.
        if ($this->limit->exceeded($actor, 'upload-m', 60, 60) || $this->limit->exceeded($actor, 'upload-d', 400, 86400)) {
            return Errors::make(429, 'tier_lists_slow_down');
        }

        $file = Arr::get($request->getUploadedFiles(), 'image');
        if (! $file instanceof UploadedFileInterface) {
            throw new ValidationException(['image' => $this->translator->trans('validation.required', ['attribute' => 'image'])]);
        }

        $this->validator->assertImageValid('image', $file);

        $url = $this->images->store((string) $file->getStream()->getMetadata('uri'), (int) $actor->id);

        // Tidy up after this member: forums without a scheduler still lose abandoned uploads.
        $this->images->pruneAbandoned((int) $actor->id, 20);

        return new JsonResponse(['url' => $url], 201);
    }
}
