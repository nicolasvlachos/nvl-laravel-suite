<?php

declare(strict_types=1);

namespace Nvl\Forms\Services;

use Illuminate\Http\Request;

/**
 * Resolves and normalizes the request origin (host) and raw Origin header for CORS.
 */
final class RequestOriginResolver
{
    /**
     * Resolve the raw Origin header value (scheme + host + optional port).
     *
     * @param  Request  $request  Incoming request
     * @return string|null Raw Origin header or null when absent
     */
    public function originHeader(Request $request): ?string
    {
        $origin = $request->header('Origin');

        return is_string($origin) && $origin !== '' ? $origin : null;
    }

    /**
     * Resolve the origin host (and optional port) from common request headers.
     *
     * @param  Request  $request  Incoming request
     * @return string|null Origin host or null when absent
     */
    public function originHost(Request $request): ?string
    {
        $origin = $request->header('Origin');
        if (is_string($origin) && $origin !== '') {
            return $this->extractHost($origin);
        }

        $referer = $request->header('Referer');
        if (is_string($referer) && $referer !== '') {
            return $this->extractHost($referer);
        }

        $custom = $request->header('X-Form-Origin');
        if (is_string($custom) && $custom !== '') {
            return $this->extractHost($custom);
        }

        return null;
    }

    /**
     * Extract host (and port) from a URL-like value or return the raw value.
     *
     * @param  string  $value  Raw origin/referer/custom value
     * @return string Host representation
     */
    public function extractHost(string $value): string
    {
        $parsed = parse_url($value);

        if ($parsed === false || ! isset($parsed['host'])) {
            return $value;
        }

        $host = (string) $parsed['host'];

        if (isset($parsed['port'])) {
            $host .= ':'.(string) $parsed['port'];
        }

        return $host;
    }
}
