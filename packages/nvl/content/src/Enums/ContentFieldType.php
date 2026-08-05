<?php

declare(strict_types=1);

namespace Nvl\Content\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Built-in aliases. Registries may add custom field types without changing this enum.
 */
#[TypeScript]
enum ContentFieldType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case RichText = 'rich_text';
    case Boolean = 'boolean';
    case Integer = 'integer';
    case Number = 'number';
    case Date = 'date';
    case DateTime = 'date_time';
    case Url = 'url';
    case Uri = 'uri';
    case Email = 'email';
    case Color = 'color';
    case Select = 'select';
    case MultiSelect = 'multi_select';
    case Json = 'json';
    case Object = 'object';
    case List = 'list';
    case Repeater = 'repeater';
    case Table = 'table';
    case Media = 'media';
    case MediaCollection = 'media_collection';
    case Reference = 'reference';
    case ReferenceList = 'reference_list';
}
