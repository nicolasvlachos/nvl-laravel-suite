<?php

declare(strict_types=1);

use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Enums\ContentVisibility;
use Nvl\Content\Schema\ContentFieldDefinition;
use Nvl\Content\Services\ContentFieldPresetRegistry;
use Nvl\Content\Services\ContentJsonSchemaBuilder;
use Nvl\Content\Services\ContentSchemaCompiler;
use Nvl\Content\Validation\ContentValidationContext;
use Nvl\Content\Validation\ContentValueValidator;
use Opis\JsonSchema\Validator;

it('keeps generated schemas aligned with runtime content constraints', function (): void {
    config()->set('content.validation.maximum_items', 2);
    config()->set('content.rich_text.maximum_input_length', 5);

    $schema = app(ContentSchemaCompiler::class)->compile([
        'fields' => [
            [
                'key' => 'items',
                'type' => 'list',
                'label' => 'Items',
                'item' => ['type' => 'text'],
            ],
            [
                'key' => 'body',
                'type' => 'rich_text',
                'label' => 'Body',
            ],
            [
                'key' => 'payload',
                'type' => 'json',
                'label' => 'Payload',
                'settings' => [
                    'schema' => [
                        '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                        'type' => 'object',
                        'properties' => [
                            'enabled' => ['type' => 'boolean'],
                        ],
                        'required' => ['enabled'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'key' => 'image',
                'preset' => 'image',
                'label' => 'Image',
            ],
        ],
    ]);
    $generated = app(ContentJsonSchemaBuilder::class)->definition(
        'schema-parity',
        1,
        $schema,
    );
    $jsonValidator = app(Validator::class);

    /** @param array<string, mixed> $value */
    $isValidGeneratedValue = static function (array $value) use (
        $generated,
        $jsonValidator,
    ): bool {
        $data = json_decode(
            json_encode($value, JSON_THROW_ON_ERROR),
            false,
            flags: JSON_THROW_ON_ERROR,
        );
        $schemaObject = json_decode(
            json_encode($generated, JSON_THROW_ON_ERROR),
            false,
            flags: JSON_THROW_ON_ERROR,
        );

        return $jsonValidator->validate($data, $schemaObject)->isValid();
    };

    expect($generated['properties']['items']['maxItems'])->toBe(2)
        ->and($generated['properties']['body']['maxLength'])->toBe(5)
        ->and($isValidGeneratedValue(['items' => ['one', 'two']]))->toBeTrue()
        ->and($isValidGeneratedValue(['items' => ['one', 'two', 'three']]))->toBeFalse()
        ->and($isValidGeneratedValue(['body' => '12345']))->toBeTrue()
        ->and($isValidGeneratedValue(['body' => '123456']))->toBeFalse()
        ->and($isValidGeneratedValue(['payload' => null]))->toBeTrue()
        ->and($isValidGeneratedValue(['payload' => ['enabled' => true]]))->toBeTrue()
        ->and($isValidGeneratedValue([
            'image' => [
                'media' => '2ff49e0a-c3ae-4d26-a81b-722a422241ca',
                'alt' => 'An accessible image',
            ],
        ]))->toBeTrue()
        ->and($isValidGeneratedValue([
            'image' => [
                'media' => '2ff49e0a-c3ae-4d26-a81b-722a422241ca',
                'alt' => " \t ",
            ],
        ]))->toBeFalse();

    $runtimeValidator = app(ContentValueValidator::class);

    /** @param array<string, mixed> $values */
    $runtimeValues = static fn (array $values) => $runtimeValidator->validate(
        schema: $schema,
        values: $values,
        translations: [],
        actor: ContentActorData::system(),
        visibility: ContentVisibility::Private,
    );

    expect($runtimeValues(['items' => ['one', 'two']])->values['items'])
        ->toBe(['one', 'two'])
        ->and(fn () => $runtimeValues(['items' => ['one', 'two', 'three']]))
        ->toThrow(InvalidArgumentException::class)
        ->and($runtimeValues(['body' => '12345'])->values['body'])
        ->toBe('12345')
        ->and(fn () => $runtimeValues(['body' => '123456']))
        ->toThrow(InvalidArgumentException::class)
        ->and($runtimeValues(['payload' => null])->values['payload'])
        ->toBeNull()
        ->and($runtimeValues(['payload' => ['enabled' => true]])->values['payload'])
        ->toBe(['enabled' => true]);

    $image = $schema->get('image');

    expect($image)->toBeInstanceOf(ContentFieldDefinition::class);

    if (! $image instanceof ContentFieldDefinition) {
        return;
    }

    $imagePreset = app(ContentFieldPresetRegistry::class)->get('image');
    $publishingContext = new ContentValidationContext(
        actor: ContentActorData::system(),
        locale: 'en',
        path: 'image',
        visibility: ContentVisibility::Public,
        publishing: true,
    );

    $imagePreset->validate(
        [
            'media' => '2ff49e0a-c3ae-4d26-a81b-722a422241ca',
            'alt' => 'An accessible image',
        ],
        $image,
        $publishingContext,
    );

    expect(fn () => $imagePreset->validate(
        [
            'media' => '2ff49e0a-c3ae-4d26-a81b-722a422241ca',
            'alt' => " \t ",
        ],
        $image,
        $publishingContext,
    ))->toThrow(InvalidArgumentException::class);
});
