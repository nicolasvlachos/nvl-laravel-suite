<?php

declare(strict_types=1);

namespace Nvl\Templates\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nvl\Templates\Support\TemplatesConfiguration;

/**
 * Localized template label and management description.
 *
 * @property string $id
 * @property string $template_id
 * @property string $locale
 * @property string $title
 * @property string|null $description
 * @property-read Template $template
 */
final class TemplateTranslation extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['template_id', 'locale', 'title', 'description'];

    public function getTable(): string
    {
        return TemplatesConfiguration::table('templates_i18n');
    }

    public function getConnectionName(): ?string
    {
        return TemplatesConfiguration::connection() ?? parent::getConnectionName();
    }

    /**
     * Return the canonical template for this locale row.
     *
     * @return BelongsTo<Template, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class, 'template_id');
    }
}
