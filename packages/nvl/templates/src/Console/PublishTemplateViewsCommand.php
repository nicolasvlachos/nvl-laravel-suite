<?php

declare(strict_types=1);

namespace Nvl\Templates\Console;

use FilesystemIterator;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use Nvl\Templates\Services\SafeFilesystemPathResolver;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Publishes bundled Blade starting points to a guarded consumer directory.
 */
final class PublishTemplateViewsCommand extends Command
{
    /** @var string */
    protected $signature = 'nvl:templates:views:publish
        {--path= : Absolute or application-relative destination}
        {--force : Replace existing files}';

    /** @var string */
    protected $description = 'Publish NVL Templates Blade starting views to an allowed path';

    /**
     * Publish package views without traversing links or escaping allowed roots.
     */
    public function handle(
        Filesystem $files,
        SafeFilesystemPathResolver $paths,
    ): int {
        $path = $this->option('path');

        if ($path === null || $path === '') {
            $path = config(
                'templates.views.publish_path',
                resource_path('views/vendor/nvl-templates'),
            );
        }

        if (! is_string($path) || trim($path) === '') {
            throw new InvalidArgumentException(
                'The template view destination cannot be empty.',
            );
        }

        $destination = str_starts_with($path, DIRECTORY_SEPARATOR)
            ? $path
            : base_path($path);
        $roots = config(
            'templates.views.allowed_publish_roots',
            [resource_path('views')],
        );

        if (! is_array($roots) || $roots === []) {
            throw new InvalidArgumentException(
                'templates.views.allowed_publish_roots must contain paths.',
            );
        }

        $destination = $paths->directory(
            $destination,
            array_values($roots),
            create: true,
            writable: true,
            description: 'Template view destination',
        );
        $source = realpath(__DIR__.'/../../resources/views');

        if ($source === false) {
            throw new InvalidArgumentException('The package view source is unavailable.');
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        );
        $copied = 0;

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->isLink()) {
                continue;
            }

            $relative = ltrim(
                substr($file->getPathname(), strlen($source)),
                DIRECTORY_SEPARATOR,
            );
            $target = $destination.DIRECTORY_SEPARATOR.$relative;
            $target = $paths->file(
                $target,
                [$destination],
                createParent: true,
                description: 'Template view target',
            );

            if ($files->exists($target) && ! $this->option('force')) {
                $this->warn("Skipped existing [{$target}].");

                continue;
            }
            $files->copy($file->getPathname(), $target);
            $copied++;
        }

        $this->info("Published {$copied} Templates view files to [{$destination}].");

        return self::SUCCESS;
    }
}
