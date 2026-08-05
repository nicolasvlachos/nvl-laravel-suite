<?php

declare(strict_types=1);

namespace Nvl\Templates\Templates\Contracts;

use Nvl\Templates\Data\TemplateMetadataData;
use Nvl\Templates\Support\PdfConfig\EngineConfig;

/**
 * Reusable class-template contract retained for application template migration.
 */
interface TemplateInterface
{
    public static function metadata(): TemplateMetadataData;

    public function setData(mixed $data): self;

    public function setVariable(string $key, mixed $value): self;

    /**
     * @param  array<string, string>  $assets
     */
    public function setAssets(array $assets): self;

    public function registerAsset(
        string $key,
        string $pathOrData,
        bool $isInline = false,
    ): self;

    public function registerUrlAsset(string $key, string $url): self;

    public function setLanguage(string $language): self;

    /**
     * @param  array<string, mixed>  $options
     */
    public function setOptions(array $options): self;

    public function getConfig(): EngineConfig;

    /**
     * @return array{html: string, css: string}
     */
    public function render(): array;

    public function getName(): string;

    public function getModule(): string;

    public function validate(): void;

    public function getStorageDisk(): ?string;

    public function getStoragePath(): ?string;

    /**
     * @param  array<string, mixed>  $data
     */
    public function getDefaultFilename(array $data = []): ?string;

    /**
     * @return array{content: array<array-key, mixed>, assets: array<string, string>}
     */
    public function requires(): array;
}
