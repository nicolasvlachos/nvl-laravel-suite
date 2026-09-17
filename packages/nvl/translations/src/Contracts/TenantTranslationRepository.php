<?php

declare(strict_types=1);

namespace Nvl\Translations\Contracts;

interface TenantTranslationRepository
{
    public function get(string $key, string $locale): ?string;

    public function set(string $key, string $locale, string $value, int $expectedRevision): void;

    public function forget(string $key, string $locale, int $expectedRevision): void;
}
