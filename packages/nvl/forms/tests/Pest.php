<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Nvl\Forms\Services\RequestOriginResolver;
use Nvl\Forms\Support\FormSubmissionContext;
use Nvl\Forms\Tests\FormsTestCase;

uses(FormsTestCase::class)->in('Feature');
uses(\Nvl\Forms\Tests\TenancyTestCase::class)->in('Tenancy');

function formSubmissionContext(Request $request): FormSubmissionContext
{
    return FormSubmissionContext::fromRequest(
        $request,
        app(RequestOriginResolver::class),
    );
}
