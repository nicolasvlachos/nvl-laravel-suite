<?php

declare(strict_types=1);

namespace Nvl\Forms\Enums;

/**
 * Enum for CORS policy configurations.
 */
enum CorsPolicy: string
{
    case Strict = 'strict';
    case Moderate = 'moderate';
    case Permissive = 'permissive';
    case Custom = 'custom';

    /**
     * Get human readable label for the policy.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::Strict => (string) trans('forms::forms/forms.options.cors_policy.strict'),
            self::Moderate => (string) trans('forms::forms/forms.options.cors_policy.moderate'),
            self::Permissive => (string) trans('forms::forms/forms.options.cors_policy.permissive'),
            self::Custom => (string) trans('forms::forms/forms.options.cors_policy.custom'),
        };
    }

    /**
     * Get description for the policy.
     */
    public function description(): string
    {
        return match ($this) {
            self::Strict => (string) trans('forms::forms/forms.descriptions.cors_policy.strict'),
            self::Moderate => (string) trans('forms::forms/forms.descriptions.cors_policy.moderate'),
            self::Permissive => (string) trans('forms::forms/forms.descriptions.cors_policy.permissive'),
            self::Custom => (string) trans('forms::forms/forms.descriptions.cors_policy.custom'),
        };
    }

    /**
     * Get default CORS settings for the policy.
     *
     * @return array{
     *     allowCredentials:bool,
     *     allowWildcards:bool,
     *     maxAge:int,
     *     allowedMethods:list<string>,
     *     allowedHeaders:list<string>
     * }
     */
    public function getDefaultSettings(): array
    {
        return match ($this) {
            self::Strict => [
                'allowCredentials' => true,
                'allowWildcards' => false,
                'maxAge' => 300,
                'allowedMethods' => ['GET', 'POST', 'OPTIONS'],
                'allowedHeaders' => ['Content-Type', 'X-CSRF-TOKEN', 'X-XSRF-TOKEN', 'X-Forms-Public-Token', 'Idempotency-Key'],
            ],
            self::Moderate => [
                'allowCredentials' => true,
                'allowWildcards' => false,
                'maxAge' => 600,
                'allowedMethods' => ['GET', 'POST', 'OPTIONS'],
                'allowedHeaders' => ['Content-Type', 'X-CSRF-TOKEN', 'X-XSRF-TOKEN', 'X-Form-Origin', 'X-Forms-Public-Token', 'Idempotency-Key'],
            ],
            self::Permissive => [
                'allowCredentials' => false,
                'allowWildcards' => true,
                'maxAge' => 3600,
                'allowedMethods' => ['GET', 'POST', 'OPTIONS'],
                'allowedHeaders' => ['*'],
            ],
            self::Custom => [
                'allowCredentials' => true,
                'allowWildcards' => false,
                'maxAge' => 300,
                'allowedMethods' => ['GET', 'POST', 'OPTIONS'],
                'allowedHeaders' => ['Content-Type', 'X-Forms-Public-Token'],
            ],
        };
    }

    /**
     * Check if this policy supports wildcards.
     */
    public function supportsWildcards(): bool
    {
        return match ($this) {
            self::Permissive => true,
            self::Strict, self::Moderate, self::Custom => false,
        };
    }

    /**
     * Return enum options for select inputs.
     *
     * @return list<array{value:string,label:string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $policy): array => ['value' => $policy->value, 'label' => $policy->getLabel()],
            self::cases(),
        );
    }
}
