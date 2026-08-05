<?php

declare(strict_types=1);

namespace Nvl\Seo\Exceptions;

use Throwable;

/**
 * Identifies the profile that already owns a route.
 */
final class SeoPathConflictException extends SeoException
{
    private ?string $scope = null;

    private ?string $locale = null;

    private ?string $path = null;

    private ?string $profileId = null;

    /**
     * Create a route ownership conflict with its existing profile.
     */
    public static function forRoute(
        string $scope,
        string $locale,
        string $path,
        string $profileId,
    ): self {
        $exception = new self(
            "SEO route [{$scope}:{$locale}:{$path}] is already owned by profile [{$profileId}].",
        );
        $exception->scope = $scope;
        $exception->locale = $locale;
        $exception->path = $path;
        $exception->profileId = $profileId;

        return $exception;
    }

    /**
     * Create a route conflict detected by a database uniqueness constraint.
     */
    public static function concurrent(string $scope, ?Throwable $previous = null): self
    {
        $exception = new self(
            "An SEO route in scope [{$scope}] was claimed by another write; reload before saving.",
            $previous,
        );
        $exception->scope = $scope;

        return $exception;
    }

    /**
     * Return the stable machine-readable error code.
     */
    protected function responseCode(): string
    {
        return 'seo_path_conflict';
    }

    /**
     * Return the conflict HTTP status.
     */
    protected function status(): int
    {
        return 409;
    }

    /**
     * Return safe conflict context for API consumers.
     *
     * @return array<string, mixed>
     */
    protected function publicContext(): array
    {
        return array_filter([
            'scope' => $this->scope,
            'locale' => $this->locale,
            'path' => $this->path,
            'profileId' => $this->profileId,
        ], static fn (?string $value): bool => $value !== null);
    }
}
