<?php

declare(strict_types=1);

namespace Nvl\Csv\Tests;

use Nvl\Csv\Providers\CsvServiceProvider;
use Nvl\Data\Providers\DataServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots CSV in an isolated Laravel application.
 */
abstract class TestCase extends Orchestra
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            DataServiceProvider::class,
            CsvServiceProvider::class,
        ];
    }

    protected function temporaryCsv(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'nvl_csv_');
        if ($path === false) {
            self::fail('Unable to create a temporary CSV fixture.');
        }

        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }
}
