<?php

declare(strict_types=1);

namespace Nvl\Content\Data\Queries;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class ContentLocaleQueryData extends Data
{
    use DataTransform;

    public function __construct(
        public readonly ?string $locale = null,

    ) {}

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'locale' => ['nullable', 'string', 'max:35'],

        ];
    }
}
