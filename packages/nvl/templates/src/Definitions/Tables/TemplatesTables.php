<?php

declare(strict_types=1);

namespace Nvl\Templates\Definitions\Tables;

/**
 * Defines the configurable persistence table keys owned by Templates.
 */
final class TemplatesTables
{
    public const string TEMPLATES = 'templates';

    public const string TEMPLATES_I18N = 'templates_i18n';

    public const string TEMPLATE_VERSIONS = 'template_versions';

    public const string TEMPLATE_ASSIGNMENTS = 'template_assignments';

    public const string TEMPLATE_RENDERS = 'template_renders';

    /**
     * Return a configured package table name.
     */
    public static function get(string $key): string
    {
        $value = config("templates.tables.{$key}", $key);

        return is_string($value) && $value !== '' ? $value : $key;
    }

    private function __construct() {}
}
