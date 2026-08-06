<?php

declare(strict_types=1);

use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Enums\ContentLinkRelationship;
use Nvl\Content\Enums\ContentLinkTarget;
use Nvl\Content\Enums\ContentVisibility;
use Nvl\Content\Exceptions\ContentDefinitionMigrationException;
use Nvl\Content\FieldPresets\LinkContentFieldPreset;
use Nvl\Content\Schema\ContentFieldDefinition;
use Nvl\Content\Tests\Fixtures\HeroV1ToV2ContentMigration;
use Nvl\Content\Validation\ContentValidationContext;

it('describes and securely renders the reusable link preset', function (): void {
    $preset = new LinkContentFieldPreset;
    $field = new ContentFieldDefinition('navigation', 'object', 'Navigation');
    $context = new ContentValidationContext(
        actor: ContentActorData::system(),
        locale: 'en',
        path: 'navigation',
        visibility: ContentVisibility::Public,
    );

    $rendered = $preset->render([
        'label' => 'Documentation',
        'href' => 'https://example.test/docs',
        'title' => 'Read the documentation',
        'target' => '_blank',
        'rel' => ['nofollow', 'noopener', 'invalid', 42],
    ], $field, $context);

    expect($preset->alias())->toBe('link')
        ->and($preset->name())->toBe('Link')
        ->and($preset->description())->toContain('Accessible')
        ->and($preset->definition())->toMatchArray(['type' => 'object'])
        ->and($preset->definition()['fields'])->toHaveCount(5)
        ->and($rendered?->label)->toBe('Documentation')
        ->and($rendered?->href)->toBe('https://example.test/docs')
        ->and($rendered?->title)->toBe('Read the documentation')
        ->and($rendered?->target)->toBe(ContentLinkTarget::NewContext)
        ->and($rendered?->rel)->toBe([
            ContentLinkRelationship::NoFollow,
            ContentLinkRelationship::NoOpener,
            ContentLinkRelationship::NoReferrer,
        ]);
});

it('uses safe defaults and rejects malformed rendered link values', function (): void {
    $preset = new LinkContentFieldPreset;
    $field = new ContentFieldDefinition('navigation', 'object', 'Navigation');
    $context = new ContentValidationContext(
        actor: ContentActorData::system(),
        locale: 'en',
        path: '',
        visibility: ContentVisibility::Public,
    );

    $rendered = $preset->render([
        'label' => 'Home',
        'href' => '/',
        'title' => false,
        'target' => 'unsupported',
        'rel' => 'nofollow',
    ], $field, $context);

    expect($rendered?->title)->toBeNull()
        ->and($rendered?->target)->toBe(ContentLinkTarget::SameContext)
        ->and($rendered?->rel)->toBe([])
        ->and($preset->render(null, $field, $context))->toBeNull()
        ->and($preset->render(['label' => 'Missing destination'], $field, $context))->toBeNull();
});

it('wraps definition migration failures with stable safe context', function (): void {
    $previous = new RuntimeException('Internal migration failure');
    $migration = new HeroV1ToV2ContentMigration;

    $exception = ContentDefinitionMigrationException::forStep(
        'block-123',
        'hero',
        $migration,
        $previous,
    );

    expect($exception->getMessage())
        ->toBe('Content block [block-123] failed migration [hero] 1->2.')
        ->and($exception->responseCode())->toBe('definition_migration_failed')
        ->and($exception->suggestedStatus())->toBe(422)
        ->and($exception->publicContext())->toBe([
            'block_id' => 'block-123',
            'definition' => 'hero',
            'from_version' => 1,
            'to_version' => 2,
        ])
        ->and($exception->getPrevious())->toBe($previous);
});
