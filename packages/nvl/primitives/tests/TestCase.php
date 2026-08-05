<?php

declare(strict_types=1);

namespace Nvl\Primitives\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Primitives\Providers\PrimitivesServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots the primitives package in an isolated Laravel application.
 */
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            DataServiceProvider::class,
            PrimitivesServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('primitive_test_models', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->json('money')->nullable();
            $table->string('money_minor')->nullable();
            $table->decimal('money_decimal', 12, 2)->nullable();
            $table->json('coordinates')->nullable();
            $table->json('postal_address')->nullable();
            $table->string('date_time')->nullable();
            $table->string('timezone')->nullable();
            $table->string('external_id')->nullable();
            $table->json('length')->nullable();
            $table->timestamps();
        });
    }
}
