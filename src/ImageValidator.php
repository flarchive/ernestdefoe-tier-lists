<?php

namespace ErnestDefoe\TierLists;

use Flarum\Foundation\AbstractImageValidator;
use Psr\Http\Message\UploadedFileInterface;

/**
 * An item picture: JPEG, PNG or WebP, at most 10 MB.
 *
 * 🚨 Judged by CONTENT. Core's check reads the type the browser claimed, which
 * the uploader controls; the bytes' own signature is checked here first, so a
 * GIF, an SVG or a script renamed `.jpg` and sent as `image/jpeg` is refused
 * before anything decodes it. Core's checks then run as well: the byte size
 * before reading, the pixel count before decoding (a decompression bomb), and
 * a full decode.
 */
class ImageValidator extends AbstractImageValidator
{
    private const TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    public function getMaxSize(): int
    {
        return 10240;
    }

    /** @return list<string> */
    protected function getAllowedTypes(): array
    {
        return ['jpeg', 'jpg', 'png', 'webp'];
    }

    protected function assertFileMimes(UploadedFileInterface $file): void
    {
        $path = $file->getStream()->getMetadata('uri');
        $info = is_string($path) && is_file($path) ? @getimagesize($path) : false;

        if ($info === false || ! in_array($info[2], self::TYPES, true)) {
            $this->raise('mimes', [':values' => implode(', ', $this->getAllowedTypes())]);
        }

        parent::assertFileMimes($file);
    }
}
