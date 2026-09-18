<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Nvl\Pages\Tests\TenancyTestCase as PagesTenancyTestCase;
use Tests\Fixtures\TenantResourceCompositionTestCase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->in(
        __DIR__.'/Feature/ExampleTest.php',
        __DIR__.'/Feature/PackagePublishingContractTest.php',
        __DIR__.'/Feature/SuiteConfigurationWriterTest.php',
        __DIR__.'/Feature/SuiteConsumerAuditTest.php',
        __DIR__.'/Feature/SuiteDiagnosticsTest.php',
        __DIR__.'/Feature/SuiteSkillsTest.php',
        __DIR__.'/Feature/TenancyCompositionTest.php',
        __DIR__.'/Feature/Integration/CrossPackageIntegrationTest.php',
        __DIR__.'/Feature/Integration/TenantWorkflowJourneyTest.php',
    );

pest()->extend(TenantResourceCompositionTestCase::class)->in(
    __DIR__.'/Feature/Integration/TenantResourceCompositionTest.php',
    __DIR__.'/Feature/Integration/TenantResourceAdoptionTest.php',
);

pest()->extend(PagesTenancyTestCase::class)->in(
    __DIR__.'/Feature/Integration/TenantPagePublicationTest.php',
);

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});
