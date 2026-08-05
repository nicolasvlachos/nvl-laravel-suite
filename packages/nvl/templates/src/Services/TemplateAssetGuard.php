<?php

declare(strict_types=1);

namespace Nvl\Templates\Services;

use finfo;
use InvalidArgumentException;
use Nvl\Templates\Support\TemplatesConfiguration;

/**
 * Validates local, inline, and remote class-template assets before Blade sees them.
 */
final class TemplateAssetGuard
{
    /** @var list<string> */
    private const array INLINE_MIME_TYPES = [
        'image/gif',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public function key(string $key): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,190}$/D', $key) !== 1) {
            throw new InvalidArgumentException("Template asset key [{$key}] is invalid.");
        }
    }

    public function value(string $value): void
    {
        if (str_starts_with($value, 'data:')) {
            $this->inline($value);

            return;
        }

        if (filter_var($value, FILTER_VALIDATE_URL) !== false) {
            $this->remote($value);

            return;
        }

        $this->local($value);
    }

    public function local(string $path): void
    {
        if (! str_starts_with($path, DIRECTORY_SEPARATOR)
            || is_link($path)
            || ! is_file($path)) {
            throw new InvalidArgumentException(
                'Template local assets must be existing absolute regular files.',
            );
        }

        $resolved = realpath($path);

        if ($resolved === false || ! $this->withinAllowedRoot($resolved)) {
            throw new InvalidArgumentException(
                'Template local asset is outside the configured allowed roots.',
            );
        }

        $size = filesize($resolved);
        $maximum = TemplatesConfiguration::positiveInteger(
            'templates.compatibility.assets.maximum_bytes',
            5_242_880,
        );

        if (! is_int($size) || $size > $maximum) {
            throw new InvalidArgumentException(
                "Template local asset exceeds the configured {$maximum} byte limit.",
            );
        }
    }

    public function inline(string $value): void
    {
        if (preg_match(
            '#^data:(image/[a-z0-9.+-]+);base64,([A-Za-z0-9+/=\\r\\n]+)$#Di',
            $value,
            $matches,
        ) !== 1) {
            throw new InvalidArgumentException(
                'Inline template assets must be base64 image data URIs.',
            );
        }

        $declaredMimeType = mb_strtolower($matches[1]);
        $this->imageMimeType($declaredMimeType);
        $bytes = base64_decode(str_replace(["\r", "\n"], '', $matches[2]), true);
        $maximum = TemplatesConfiguration::positiveInteger(
            'templates.compatibility.assets.maximum_inline_bytes',
            2_097_152,
        );

        if (! is_string($bytes) || strlen($bytes) > $maximum) {
            throw new InvalidArgumentException(
                "Inline template asset exceeds the configured {$maximum} byte limit.",
            );
        }

        $detectedMimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        if (! is_string($detectedMimeType)
            || mb_strtolower($detectedMimeType) !== $declaredMimeType) {
            throw new InvalidArgumentException(
                'Inline template asset bytes do not match the declared image MIME type.',
            );
        }
    }

    /**
     * Assert that an image MIME type is safe for direct PDF embedding.
     */
    public function imageMimeType(string $mimeType): void
    {
        if (! in_array(mb_strtolower($mimeType), self::INLINE_MIME_TYPES, true)) {
            throw new InvalidArgumentException(
                "Template image MIME type [{$mimeType}] is not allowed.",
            );
        }
    }

    public function remote(string $url): void
    {
        $enabled = (bool) config('templates.pdf.remote_assets.enabled', false);
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        $allowedHosts = config('templates.pdf.remote_assets.allowed_hosts', []);
        $allowHttp = (bool) config('templates.pdf.remote_assets.allow_http', false);

        if (! $enabled
            || ! is_string($scheme)
            || ! is_string($host)
            || (! $allowHttp && strtolower($scheme) !== 'https')
            || ($allowHttp && ! in_array(strtolower($scheme), ['http', 'https'], true))
            || ! is_array($allowedHosts)
            || ! in_array(strtolower($host), array_map(
                static fn (mixed $item): string => is_string($item)
                    ? strtolower($item)
                    : '',
                $allowedHosts,
            ), true)
            || parse_url($url, PHP_URL_USER) !== null
            || parse_url($url, PHP_URL_PASS) !== null) {
            throw new InvalidArgumentException(
                'Remote template asset is not allowed by the exact host policy.',
            );
        }
    }

    private function withinAllowedRoot(string $path): bool
    {
        $roots = config(
            'templates.compatibility.assets.allowed_local_roots',
            [resource_path(), storage_path('app')],
        );

        if (! is_array($roots)) {
            return false;
        }

        foreach ($roots as $root) {
            if (! is_string($root) || is_link($root)) {
                continue;
            }

            $resolvedRoot = realpath($root);

            if ($resolvedRoot !== false
                && ($path === $resolvedRoot
                    || str_starts_with(
                        $path,
                        rtrim($resolvedRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR,
                    ))) {
                return true;
            }
        }

        return false;
    }
}
