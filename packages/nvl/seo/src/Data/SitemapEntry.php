<?php

declare(strict_types=1);

namespace Nvl\Seo\Data;

use DateTimeInterface;
use InvalidArgumentException;
use Nvl\Seo\Enums\SitemapChangeFrequency;
use Nvl\Seo\Support\HttpUrl;

/**
 * One canonical sitemap URL and its optional localized alternates.
 */
final readonly class SitemapEntry
{
    /**
     * @param  array<string, string>  $alternates
     */
    public function __construct(
        public string $url,
        public ?DateTimeInterface $lastModified = null,
        public ?SitemapChangeFrequency $changeFrequency = null,
        public ?string $priority = null,
        public array $alternates = [],
    ) {
        if (! HttpUrl::isCanonical($this->url)) {
            throw new InvalidArgumentException(
                'A sitemap entry requires an absolute fragment-free HTTP or HTTPS URL.',
            );
        }

        if (
            $this->priority !== null
            && preg_match('/^(?:0(?:\.[0-9]+)?|1(?:\.0+)?)$/', $this->priority) !== 1
        ) {
            throw new InvalidArgumentException(
                'A sitemap priority must use decimal notation between 0 and 1.',
            );
        }

        foreach ($this->alternates as $locale => $url) {
            if (($locale !== 'x-default'
                    && preg_match('/^[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*$/', $locale) !== 1)
                || ! HttpUrl::isCanonical($url)) {
                throw new InvalidArgumentException(
                    'Every sitemap alternate requires a valid locale and an absolute fragment-free HTTP or HTTPS URL.',
                );
            }
        }
    }
}
