<?php

declare(strict_types=1);

namespace Nvl\Templates\Services;

use InvalidArgumentException;
use Nvl\Templates\Support\TemplatesConfiguration;

/**
 * Rejects dangerous or unbounded HTML resource references before mPDF parses.
 */
final class PdfHtmlGuard
{
    /**
     * Create the PDF HTML guard with binary asset validation.
     */
    public function __construct(private readonly TemplateAssetGuard $assets) {}

    /**
     * Validate bounded PDF HTML and every resource reference it contains.
     */
    public function validate(string $html): void
    {
        $maximum = TemplatesConfiguration::positiveInteger(
            'templates.pdf.maximum_html_bytes',
            1_048_576,
        );

        if (strlen($html) > $maximum) {
            throw new InvalidArgumentException('PDF HTML exceeds the configured byte limit.');
        }

        if (! mb_check_encoding($html, 'UTF-8')) {
            throw new InvalidArgumentException('PDF HTML must be valid UTF-8.');
        }

        if (preg_match(
            '/<(?:script|iframe|object|embed|annotation|form|input|textarea|button|link)\\b/i',
            $html,
        ) === 1
            || preg_match(
                '/<!\\s*ENTITY\\b|<!\\s*DOCTYPE\\b[^>]*(?:SYSTEM|PUBLIC)\\b/i',
                $html,
            ) === 1
            || preg_match('/@import\\b/i', $html) === 1) {
            throw new InvalidArgumentException('PDF HTML contains a forbidden element.');
        }

        foreach ($this->resources($html) as $url) {
            $this->validateResource(html_entity_decode($url, ENT_QUOTES | ENT_HTML5));
        }
    }

    private function validateResource(string $resource): void
    {
        $resource = trim($resource);

        if ($resource === '' || $resource[0] === '#') {
            return;
        }

        if (str_contains($resource, '\\')) {
            throw new InvalidArgumentException(
                'PDF HTML resource URLs cannot contain escaped path characters.',
            );
        }

        if (str_starts_with(strtolower($resource), 'data:')) {
            $this->validateDataUri($resource);

            return;
        }

        if (str_starts_with($resource, '//')) {
            $resource = 'https:'.$resource;
        }

        $parts = parse_url($resource);

        if ($parts === false) {
            throw new InvalidArgumentException('PDF HTML contains an invalid resource URL.');
        }

        $scheme = strtolower(is_string($parts['scheme'] ?? null) ? $parts['scheme'] : '');

        if ($scheme !== '') {
            if (! in_array($scheme, ['http', 'https'], true)) {
                throw new InvalidArgumentException(
                    'PDF HTML contains a forbidden resource scheme.',
                );
            }

            $this->validateRemoteResource($parts, $scheme);

            return;
        }

        $path = rawurldecode(is_string($parts['path'] ?? null) ? $parts['path'] : '');

        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $this->assets->local($path);

            return;
        }

        if ($path === ''
            || preg_match('~(^|[/\\\\])\\.\\.([/\\\\]|$)~', $path) === 1
            || str_contains($path, "\0")) {
            throw new InvalidArgumentException(
                'PDF HTML local resources must use safe relative paths.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private function validateRemoteResource(array $parts, string $scheme): void
    {
        if (! (bool) config('templates.pdf.remote_assets.enabled', false)) {
            throw new InvalidArgumentException('Remote PDF assets are disabled.');
        }

        if ($scheme !== 'https'
            && ! (bool) config('templates.pdf.remote_assets.allow_http', false)) {
            throw new InvalidArgumentException('Remote PDF assets require HTTPS.');
        }

        $host = strtolower(is_string($parts['host'] ?? null) ? $parts['host'] : '');
        $allowed = config('templates.pdf.remote_assets.allowed_hosts', []);

        if ($host === ''
            || ! is_array($allowed)
            || ! in_array($host, array_map('strtolower', array_filter($allowed, 'is_string')), true)) {
            throw new InvalidArgumentException('The remote PDF asset host is not allowlisted.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Remote PDF asset URLs cannot contain credentials.');
        }

        $port = $parts['port'] ?? null;
        $standardPort = $scheme === 'https' ? 443 : 80;

        if ($port !== null && $port !== $standardPort) {
            throw new InvalidArgumentException(
                'Remote PDF asset URLs must use the standard port for their scheme.',
            );
        }
    }

    private function validateDataUri(string $resource): void
    {
        if (! (bool) config('templates.pdf.data_images.enabled', true)) {
            throw new InvalidArgumentException('PDF data images are disabled.');
        }

        if (preg_match(
            '~^data:image/(?:png|jpeg|gif|webp);base64,(?<data>[A-Za-z0-9+/=]+)$~i',
            $resource,
            $matches,
        ) !== 1) {
            throw new InvalidArgumentException('PDF data URIs must be base64 image data.');
        }

        $encoded = $matches['data'];
        $maximum = TemplatesConfiguration::positiveInteger(
            'templates.pdf.data_images.maximum_bytes',
            2_097_152,
        );

        if ((int) floor(strlen($encoded) * 3 / 4) > $maximum) {
            throw new InvalidArgumentException('PDF data image exceeds the configured byte limit.');
        }

        $this->assets->inline($resource);
    }

    /**
     * Extract HTML attributes and CSS url() values without skipping quoted whitespace.
     *
     * @return list<string>
     */
    private function resources(string $html): array
    {
        $resources = [];
        preg_match_all(
            '~\\b(?:src|href|background|poster)\\s*=\\s*(?:"(?<double>[^"]*)"|\'(?<single>[^\']*)\'|(?<bare>[^\\s>]+))~i',
            $html,
            $attributeMatches,
            PREG_SET_ORDER,
        );
        preg_match_all(
            '~url\\(\\s*(?:"(?<double>[^"]*)"|\'(?<single>[^\']*)\'|(?<bare>[^)\\s]+))\\s*\\)~i',
            $html,
            $cssMatches,
            PREG_SET_ORDER,
        );

        foreach ([...$attributeMatches, ...$cssMatches] as $match) {
            foreach (['double', 'single', 'bare'] as $key) {
                if (isset($match[$key]) && $match[$key] !== '') {
                    $resources[] = $match[$key];

                    break;
                }
            }
        }

        return $resources;
    }
}
