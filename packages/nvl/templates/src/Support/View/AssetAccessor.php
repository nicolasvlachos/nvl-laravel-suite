<?php

declare(strict_types=1);

namespace Nvl\Templates\Support\View;

use Nvl\Templates\Templates\BaseTemplate;

/**
 * Read-only view adapter for validated template assets.
 */
final readonly class AssetAccessor
{
    public function __construct(private BaseTemplate $template) {}

    public function get(string $key): string
    {
        return $this->template->getAsset($key);
    }

    public function getFile(string $key): string
    {
        return $this->template->getAssetFileUrl($key);
    }

    public function fileUrl(string $key): string
    {
        return $this->template->getAssetFileUrl($key);
    }

    public function has(string $key): bool
    {
        return $this->template->hasAsset($key);
    }
}
