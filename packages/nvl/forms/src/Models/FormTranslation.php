<?php

declare(strict_types=1);

namespace Nvl\Forms\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nvl\Forms\Definitions\Tables\FormsTables;

/**
 * Stores locale-specific public copy and arbitrary form content.
 *
 * @property string $id
 * @property string $form_id
 * @property string $locale
 * @property string|null $name
 * @property string|null $description
 * @property string|null $submit_button_label
 * @property string|null $success_title
 * @property string|null $success_message
 * @property array<string, mixed>|null $content
 */
final class FormTranslation extends Model
{
    use HasUuids;

    public const string TABLE = FormsTables::FORM_I18N;

    protected $table = self::TABLE;

    /** @var list<string> */
    protected $fillable = [
        'form_id',
        'locale',
        'name',
        'description',
        'submit_button_label',
        'success_title',
        'success_message',
        'content',
    ];

    /**
     * Return translation attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'content' => 'array',
        ];
    }

    /**
     * Return the owning form.
     *
     * @return BelongsTo<Form, $this>
     */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }
}
