<?php

declare(strict_types=1);

namespace Nvl\Templates\Definitions\Tables;

/**
 * Defines the configurable persistence table keys owned by Templates.
 */
final class TemplatesTables
{
    public const string Templates = 'templates';

    public const string I18n = 'templates_i18n';

    public const string Versions = 'template_versions';

    public const string Assignments = 'template_assignments';

    public const string Renders = 'template_renders';

    public const string TEMPLATES = self::Templates;

    public const string TEMPLATES_I18N = self::I18n;

    public const string TEMPLATE_VERSIONS = self::Versions;

    public const string TEMPLATE_ASSIGNMENTS = self::Assignments;

    public const string TEMPLATE_RENDERS = self::Renders;

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
