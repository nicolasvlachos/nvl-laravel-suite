<?php

declare(strict_types=1);

namespace Nvl\Seo\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Nvl\Seo\Enums\SitemapChangeFrequency;
use Nvl\Seo\Models\SeoProfile;

/**
 * Generates structurally valid SEO profiles for consumer tests.
 *
 * @extends Factory<SeoProfile>
 */
final class SeoProfileFactory extends Factory
{
    protected $model = SeoProfile::class;

    /**
     * @return array<model-property<SeoProfile>, mixed>
     */
    public function definition(): array
    {
        return [
            'scope' => 'default',
            'seoable_type' => Model::class,
            'seoable_id' => (string) fake()->uuid(),
            'is_indexable' => true,
            'is_followable' => true,
            'max_image_preview' => 'large',
            'sitemap_included' => true,
            'sitemap_priority' => '0.5',
            'sitemap_change_frequency' => SitemapChangeFrequency::Weekly,
            'metadata' => null,
        ];
    }
}
