<?php

declare(strict_types=1);

namespace App\Content\Media;

use Illuminate\Http\UploadedFile;
use Nvl\Media\Contracts\MediaContentScanner;
use Nvl\Media\Exceptions\MediaUploadException;

/** Demonstrates a consumer-owned fail-closed upload scanner boundary. */
final class ContentConsumerMediaScanner implements MediaContentScanner
{
    public function scan(UploadedFile $file): void
    {
        $path = $file->getRealPath();

        if (! is_string($path) || ! is_file($path) || $file->getSize() < 1) {
            throw new MediaUploadException('The consumer scanner rejected an unreadable upload.');
        }
    }
}
