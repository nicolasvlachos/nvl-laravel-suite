<?php

declare(strict_types=1);

namespace App\Activity;

use App\Models\ActivityArticle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Nvl\Activity\Contracts\ActivityMapping;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Defines automatic capture and display semantics for consumer-owned articles.
 */
final class ArticleActivityMapping implements ActivityMapping
{
    /**
     * Resolve the mapped consumer model class.
     */
    public function modelClass(): string
    {
        return ActivityArticle::class;
    }

    /**
     * Resolve the human-readable entity label.
     */
    public function entityLabel(): string
    {
        return 'Article';
    }

    /**
     * Resolve the label for one concrete article.
     */
    public function subjectLabel(Model $subject): string
    {
        $title = $subject->getAttribute('title');

        return is_string($title) && trim($title) !== ''
            ? $title
            : 'Article';
    }

    /**
     * Resolve the stable Activity log channel.
     */
    public function logName(): string
    {
        return 'activity-consumer-articles';
    }

    /**
     * Configure narrow automatic capture for consumer articles.
     */
    public function logOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'status'])
            ->logOnlyDirty();
    }

    /**
     * Resolve one stored field key to display copy.
     */
    public function fieldLabel(string $key): string
    {
        return match ($key) {
            'title' => 'Title',
            'status' => 'Status',
            default => Str::headline($key),
        };
    }

    /**
     * Resolve one stored value to display copy.
     */
    public function fieldValue(string $key, mixed $value): string
    {
        if ($key === 'status' && is_string($value)) {
            return match ($value) {
                'draft' => 'Draft',
                'published' => 'Published',
                default => Str::headline($value),
            };
        }

        return is_scalar($value) || $value === null
            ? (string) $value
            : '[structured value]';
    }

    /**
     * Resolve consumer event context used by headline templates.
     *
     * @param  array<string, mixed>  $properties
     */
    public function eventDisplayValue(string $event, array $properties): ?string
    {
        if ($event !== 'article.published') {
            return null;
        }

        $context = $properties['context'] ?? null;
        $channel = is_array($context) ? ($context['channel'] ?? null) : null;

        return is_string($channel) && trim($channel) !== ''
            ? Str::headline($channel)
            : null;
    }

    /**
     * Return consumer-owned semantic event templates.
     *
     * @return array<string, string>
     */
    public function eventTemplates(): array
    {
        return [
            'article.published' => ':actor published this :subject on :value.',
        ];
    }
}
