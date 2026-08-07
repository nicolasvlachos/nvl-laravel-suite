<?php

declare(strict_types=1);

namespace Nvl\Workbench\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Nvl\Activity\Traits\HasModelActivity;
use Nvl\Comments\Contracts\HasComments;
use Nvl\Comments\Traits\InteractsWithComments;
use Nvl\Content\Contracts\ContentOwner;
use Nvl\Content\Traits\HasContent;
use Nvl\Media\Contracts\HasMedia;
use Nvl\Media\Traits\InteractsWithMedia;
use Nvl\Metafields\Traits\HasMetafields;
use Nvl\Seo\Traits\HasSeo;
use Nvl\Taxonomy\Concerns\HasTaxonomies;

/**
 * Represents a real consumer-owned model used to verify cross-package integration.
 *
 * @property int $id Persisted model identifier
 * @property string $name Display name
 * @property string|null $category_id Optional legacy category reference
 * @property Carbon|null $created_at Creation timestamp
 * @property Carbon|null $updated_at Last update timestamp
 */
final class IntegrationTestModel extends Model implements ContentOwner, HasComments, HasMedia
{
    use HasContent;
    use HasMetafields;
    use HasModelActivity;
    use HasSeo;
    use HasTaxonomies;
    use InteractsWithComments;
    use InteractsWithMedia;

    public const CONTENT_GROUP = 'main';

    /** @var list<string> */
    protected array $taxonomies = ['category', 'tag'];

    /** @var list<string> */
    protected $fillable = ['name', 'category_id'];
}
