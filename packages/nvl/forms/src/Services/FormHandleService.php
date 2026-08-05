<?php

declare(strict_types=1);

namespace Nvl\Forms\Services;

use Exception;
use Illuminate\Support\Str;
use Nvl\Forms\Models\Form;

/**
 * Manages form handle generation and uniqueness validation.
 *
 * Provides slug-based handle generation from form names with automatic
 * counter-suffixed deduplication for uniqueness.
 */
final class FormHandleService
{
    /**
     * Generate a handle from the given name, falling back to 'form' for empty input.
     *
     * Does not check uniqueness — use generateUniqueHandle() for that.
     *
     * @param  string|null  $name  Source name to slugify
     * @return string Generated handle slug
     */
    public function generateHandle(?string $name): string
    {
        return Str::slug(is_string($name) && $name !== '' ? $name : 'form');
    }

    /**
     * Generate a unique handle by appending a numeric suffix when collisions occur.
     *
     * Starts with the base slug and increments a counter suffix (-1, -2, etc.)
     * until no existing form uses the handle.
     *
     * @param  string  $name  Source name to slugify
     * @param  string|null  $excludeFormId  Form ID to exclude from uniqueness check (for updates)
     * @return string Unique handle slug
     */
    public function generateUniqueHandle(string $name, ?string $excludeFormId = null): string
    {
        $baseHandle = Str::slug($name);
        $handle = $baseHandle;
        $counter = 1;

        while ($this->handleExists($handle, $excludeFormId)) {
            $handle = $baseHandle.'-'.$counter;
            $counter++;
        }

        return $handle;
    }

    /**
     * Validate that a handle is unique, optionally excluding a specific form.
     *
     * @param  string  $handle  Handle to validate
     * @param  string|null  $excludeFormId  Form ID to exclude from the check
     *
     * @throws Exception When the handle is already in use by another form
     */
    public function validateUniqueness(string $handle, ?string $excludeFormId = null): void
    {
        if ($this->handleExists($handle, $excludeFormId)) {
            throw new Exception(
                (string) trans('forms::forms/messages.error.handle_exists', ['handle' => $handle])
            );
        }
    }

    /**
     * Check if a handle already exists in the database.
     *
     * @param  string  $handle  Handle to check
     * @param  string|null  $excludeFormId  Form ID to exclude from the check
     * @return bool True when the handle is already taken
     */
    private function handleExists(string $handle, ?string $excludeFormId = null): bool
    {
        $query = Form::where('handle', $handle);

        if ($excludeFormId !== null) {
            $query->where('id', '!=', $excludeFormId);
        }

        return $query->exists();
    }
}
