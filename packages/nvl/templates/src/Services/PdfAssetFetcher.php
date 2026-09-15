<?php

declare(strict_types=1);

namespace Nvl\Templates\Services;

use Illuminate\Http\Client\Factory;
use InvalidArgumentException;
use Mpdf\AssetFetcher;
use Nvl\Templates\Exceptions\TemplateResolutionException;
use Nvl\Templates\Support\TemplatesConfiguration;

/**
 * Enforces asset policy at every mPDF read, including nested SVG and CSS resources.
 *
 * @internal
 */
final class PdfAssetFetcher extends AssetFetcher
{
    /**
     * Create the renderer's guarded asset reader and bounded HTTP transport.
     */
    public function __construct(
        private readonly TemplateAssetGuard $assets,
        private readonly Factory $http,
    ) {}

    /**
     * Fetch bounded bytes from one canonical allowed local file or remote URL.
     *
     * @param  string  $path
     * @param  string|false|null  $originalSrc
     */
    public function fetchDataFromPath(mixed $path, mixed $originalSrc = null): string
    {
        $source = trim(is_string($originalSrc) && $originalSrc !== '' ? $originalSrc : $path);

        if ($source === '' || str_contains($source, "\0") || str_contains($source, '\\')) {
            throw new InvalidArgumentException('PDF asset source is invalid.');
        }

        if (str_starts_with($source, '//')) {
            $source = 'https:'.$source;
        }

        $maximum = TemplatesConfiguration::positiveInteger(
            'templates.compatibility.assets.maximum_bytes',
            5_242_880,
        );

        if ($maximum === PHP_INT_MAX) {
            throw new InvalidArgumentException('PDF asset byte limit cannot be represented safely.');
        }

        $scheme = parse_url($source, PHP_URL_SCHEME);

        if ($scheme !== null) {
            $this->assets->remote($source);

            return $this->remote($source, $maximum);
        }

        $localPath = $this->assets->localPath(rawurldecode($source));
        $readLimit = $maximum + 1;

        if ($readLimit < 1) {
            throw new InvalidArgumentException('PDF asset byte limit cannot be represented safely.');
        }

        $bytes = file_get_contents($localPath, false, null, 0, $readLimit);

        if (! is_string($bytes) || strlen($bytes) > $maximum) {
            throw new TemplateResolutionException('PDF local asset could not be read within its byte limit.');
        }

        return $bytes;
    }

    /**
     * Read one remote response without redirects or an unbounded response body.
     */
    private function remote(string $source, int $maximum): string
    {
        $deadline = hrtime(true) + 5_000_000_000;
        $response = $this->http->withOptions([
            'allow_redirects' => false,
            'stream' => true,
            'read_timeout' => 5,
        ])->connectTimeout(5)->timeout(5)->get($source);
        $stream = $response->toPsrResponse()->getBody();

        try {
            if (! $response->successful()) {
                throw new TemplateResolutionException('PDF remote asset returned an unsuccessful response.');
            }

            $bytes = '';

            while (strlen($bytes) <= $maximum) {
                if (hrtime(true) >= $deadline) {
                    throw new TemplateResolutionException('PDF remote asset exceeded its read timeout.');
                }

                $chunk = $stream->read(min(8192, $maximum + 1 - strlen($bytes)));

                $bytes .= $chunk;

                if ($stream->eof()) {
                    break;
                }

                if ($chunk === '') {
                    throw new TemplateResolutionException('PDF remote asset could not be read completely.');
                }
            }

            if (strlen($bytes) > $maximum) {
                throw new TemplateResolutionException('PDF remote asset exceeds the configured byte limit.');
            }

            return $bytes;
        } finally {
            $stream->close();
        }
    }
}
