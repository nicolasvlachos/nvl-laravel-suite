<?php

declare(strict_types=1);

namespace Nvl\Pages\Tests;

use Nvl\Pages\Contracts\PageAuthorization;
use Nvl\Pages\Tests\Fixtures\RecordingPageAuthorization;

/**
 * Boots the opt-in public and management HTTP adapters for route-contract tests.
 */
abstract class HttpTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set([
            'pages.authorization.class' => RecordingPageAuthorization::class,
            'pages.routes.public.enabled' => true,
            'pages.routes.public.middleware' => ['api'],
            'pages.routes.management.enabled' => true,
            'pages.routes.management.middleware' => ['api'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(PageAuthorization::class, new RecordingPageAuthorization);
    }
}
