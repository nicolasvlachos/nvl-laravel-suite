<?php

declare(strict_types=1);

namespace Nvl\Forms\Services;

use Nvl\Forms\Data\FormCorsSettings;
use Nvl\Forms\Enums\CorsPolicy;
use Nvl\Forms\Models\Form;

/**
 * Resolves form-level CORS policy with an optional matching-origin override.
 */
final class FormCorsPolicyResolver
{
    public function __construct(
        private readonly FormOriginAccessService $originAccess,
    ) {}

    public function resolve(Form $form, ?string $originHost): FormCorsSettings
    {
        $formSettings = $this->normalize($form->cors_settings);
        $policyValue = $formSettings['policy'] ?? null;
        $policy = CorsPolicy::tryFrom(is_string($policyValue) ? $policyValue : '')
            ?? CorsPolicy::Moderate;
        $settings = array_replace(
            ['policy' => $policy->value],
            $policy->getDefaultSettings(),
            $formSettings,
        );

        if (is_string($originHost) && $originHost !== '') {
            $matchedOrigin = $this->originAccess->resolveMatchingOrigin($form, $originHost);
            if ($matchedOrigin !== null) {
                $settings = array_replace(
                    $settings,
                    $this->normalize($matchedOrigin->cors_settings),
                );
            }
        }

        $resolvedPolicyValue = $settings['policy'] ?? null;
        $resolvedPolicy = CorsPolicy::tryFrom(
            is_string($resolvedPolicyValue) ? $resolvedPolicyValue : '',
        )
            ?? $policy;

        return new FormCorsSettings(
            policy: $resolvedPolicy,
            allowCredentials: (bool) ($settings['allowCredentials'] ?? true),
            allowWildcards: (bool) ($settings['allowWildcards'] ?? false),
            maxAge: max(0, min(86400, $this->integer($settings['maxAge'] ?? null, 600))),
            allowedMethods: $this->methods($settings['allowedMethods'] ?? []),
            allowedHeaders: $this->headers($settings['allowedHeaders'] ?? []),
        );
    }

    /**
     * Normalize supported camelCase and legacy snake_case JSON keys.
     *
     * @return array<string, mixed>
     */
    private function normalize(mixed $settings): array
    {
        if ($settings instanceof FormCorsSettings) {
            $settings = $settings->toArray();
        }

        if (! is_array($settings)) {
            return [];
        }

        $normalized = [];
        foreach ([
            'policy' => 'policy',
            'allowCredentials' => 'allowCredentials',
            'allow_credentials' => 'allowCredentials',
            'allowWildcards' => 'allowWildcards',
            'allow_wildcards' => 'allowWildcards',
            'maxAge' => 'maxAge',
            'max_age' => 'maxAge',
            'allowedMethods' => 'allowedMethods',
            'allowed_methods' => 'allowedMethods',
            'allowedHeaders' => 'allowedHeaders',
            'allowed_headers' => 'allowedHeaders',
        ] as $input => $output) {
            if (array_key_exists($input, $settings)) {
                $normalized[$output] = $settings[$input];
            }
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function methods(mixed $methods): array
    {
        if (! is_array($methods)) {
            return ['GET', 'POST', 'OPTIONS'];
        }

        $normalized = [];
        foreach ($methods as $method) {
            if (! is_string($method)) {
                continue;
            }

            $method = strtoupper(trim($method));
            if (in_array($method, ['GET', 'POST', 'OPTIONS'], true)) {
                $normalized[] = $method;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return list<string>
     */
    private function headers(mixed $headers): array
    {
        if (! is_array($headers)) {
            return [];
        }

        $normalized = [];
        foreach ($headers as $header) {
            if (is_string($header) && preg_match('/^(?:\*|[A-Za-z0-9!#$%&\'*+.^_`|~-]+)$/', $header) === 1) {
                $normalized[] = $header;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Resolve a bounded integer from persisted configuration.
     */
    private function integer(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return intval($value);
        }

        return $default;
    }
}
