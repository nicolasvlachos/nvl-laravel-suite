<?php

declare(strict_types=1);

namespace Nvl\Templates\Templates;

use Illuminate\Contracts\View\Factory;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nvl\Content\Data\RenderedContentCompositionData;
use Nvl\Templates\Data\TemplateMetadataData;
use Nvl\Templates\Services\TemplateAssetGuard;
use Nvl\Templates\Services\TemplateContentGuard;
use Nvl\Templates\Support\PdfConfig\EngineConfig;
use Nvl\Templates\Support\View\AssetAccessor;
use Nvl\Templates\Support\View\ContentAccessor;
use Nvl\Templates\Templates\Contracts\TemplateInterface;

/**
 * Class-based Blade template adapter backed by bounded data, Content, and assets.
 */
abstract class BaseTemplate implements TemplateInterface
{
    protected EngineConfig $config;

    /** @var array<string, mixed> */
    protected array $options = [];

    /** @var array<string, mixed> */
    protected array $rawData = [];

    /** @var array<string, string> */
    protected array $assets = [];

    /** @var array<string, mixed> */
    protected array $contentValues = [];

    protected string $language;

    protected ?RenderedContentCompositionData $composition = null;

    public function __construct(
        private readonly Factory $views,
        private readonly TemplateContentGuard $contentGuard,
        private readonly TemplateAssetGuard $assetGuard,
    ) {
        $locale = config('app.locale', 'en');
        $this->language = is_string($locale) ? $locale : 'en';
        $this->config = new EngineConfig;
        $this->configure();
        $this->loadRequirements();
    }

    public static function metadata(): TemplateMetadataData
    {
        return new TemplateMetadataData(
            title: Str::headline(class_basename(static::class)),
        );
    }

    abstract protected function configure(): void;

    abstract protected function getViewPath(): string;

    abstract public function getName(): string;

    abstract public function getModule(): string;

    protected function getCssPath(): string
    {
        return '';
    }

    protected function getAssetPath(): string
    {
        return '';
    }

    protected function getContentPath(): string
    {
        return '';
    }

    public function getConfig(): EngineConfig
    {
        return $this->config;
    }

    public function setLanguage(string $language): static
    {
        $this->language = $this->normalizeLanguage($language);
        $this->assertCompositionLocale();

        return $this;
    }

    public function setData(mixed $data): static
    {
        $data = $this->normalizeTemplateData($data);
        $this->contentGuard->data($data);
        $this->rawData = [...$this->rawData, ...$data];

        return $this;
    }

    public function setVariable(string $key, mixed $value): static
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/D', $key) !== 1) {
            throw new InvalidArgumentException("Template variable [{$key}] is invalid.");
        }

        $data = [...$this->rawData, $key => $value];
        $this->contentGuard->data($data);
        $this->rawData = $data;

        return $this;
    }

    public function setOptions(array $options): static
    {
        $merged = [...$this->options, ...$options];
        $this->contentGuard->rendererOptions($merged);
        $this->options = $merged;

        return $this;
    }

    public function setAssets(array $assets): static
    {
        foreach ($assets as $key => $value) {
            $this->registerAsset($key, $value);
        }

        return $this;
    }

    public function registerAsset(
        string $key,
        string $pathOrData,
        bool $isInline = false,
    ): static {
        $this->assetGuard->key($key);

        if ($isInline && ! str_starts_with($pathOrData, 'data:')) {
            throw new InvalidArgumentException(
                'Inline template assets require a complete image data URI.',
            );
        }

        $this->assetGuard->value($pathOrData);
        $this->assets[$key] = $pathOrData;

        return $this;
    }

    public function registerUrlAsset(string $key, string $url): static
    {
        $this->assetGuard->key($key);
        $this->assetGuard->remote($url);
        $this->assets[$key] = $url;

        return $this;
    }

    public function withComposition(
        ?RenderedContentCompositionData $composition,
    ): static {
        $this->composition = $composition;
        $this->assertCompositionLocale();

        return $this;
    }

    /**
     * Transitional in-memory copy; persisted localized copy belongs to Content.
     *
     * @param  array<string, mixed>  $content
     */
    public function withContent(array $content): static
    {
        $this->contentGuard->data($content);
        $this->contentValues = $content;

        return $this;
    }

    public function requires(): array
    {
        return ['content' => [], 'assets' => []];
    }

    /**
     * @return array{html: string, css: string}
     */
    public function render(): array
    {
        $this->validate();
        $view = $this->getViewPath();

        if (! $this->views->exists($view)) {
            throw new InvalidArgumentException("Template view [{$view}] does not exist.");
        }

        $html = $this->views->make($view, [
            'content' => new ContentAccessor($this),
            'assets' => new AssetAccessor($this),
            'options' => $this->options,
            'data' => $this->rawData,
            'config' => $this->config,
            'language' => $this->language,
            'composition' => $this->composition,
        ])->render();
        $configuredMaximum = config('templates.pdf.maximum_html_bytes', 1_048_576);
        $maximum = is_int($configuredMaximum) ? $configuredMaximum : 1_048_576;

        if ($maximum < 1 || strlen($html) > $maximum) {
            throw new InvalidArgumentException(
                'Rendered template HTML exceeds its configured byte limit.',
            );
        }

        return ['html' => $html, 'css' => $this->loadCss()];
    }

    public function validate(): void
    {
        $this->assertCompositionLocale();
        $missing = [];

        foreach ($this->getRequiredContent() as $key) {
            if (! $this->hasContent($key)) {
                $missing[] = "content [{$key}]";
            }
        }

        foreach ($this->getRequiredData() as $key) {
            if (! array_key_exists($key, $this->rawData)) {
                $missing[] = "data [{$key}]";
            }
        }

        foreach ($this->getRequiredAssets() as $key) {
            if (! $this->hasAsset($key)) {
                $missing[] = "asset [{$key}]";
            }
        }

        foreach ($this->getRequiredOptions() as $key) {
            if (! array_key_exists($key, $this->options)) {
                $missing[] = "option [{$key}]";
            }
        }

        if ($missing !== []) {
            throw new InvalidArgumentException(
                'Template validation failed: missing '.implode(', ', $missing).'.',
            );
        }
    }

    public function getStorageDisk(): ?string
    {
        $disk = $this->options['storage_disk']
            ?? config('templates.rendering.output.disk');

        return is_string($disk) && $disk !== '' ? $disk : null;
    }

    public function getStoragePath(): ?string
    {
        $path = $this->options['storage_path'] ?? null;

        if ($path === null) {
            return null;
        }

        if (! is_string($path)
            || trim($path, '/') === ''
            || str_contains($path, '..')
            || str_starts_with($path, '/')) {
            throw new InvalidArgumentException('Template storage path is invalid.');
        }

        return trim($path, '/');
    }

    public function getDefaultFilename(array $data = []): ?string
    {
        $filename = $this->options['filename'] ?? null;

        if ($filename !== null && ! is_string($filename)) {
            throw new InvalidArgumentException('Template filename option must be a string.');
        }

        $filename ??= Str::slug($this->getName()).'.pdf';
        $filename = basename($filename);
        $filename = str_ends_with(strtolower($filename), '.pdf')
            ? $filename
            : $filename.'.pdf';

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,190}$/D', $filename) !== 1
            || str_contains($filename, '..')) {
            throw new InvalidArgumentException('Template filename is invalid.');
        }

        return $filename;
    }

    public function hasAsset(string $key): bool
    {
        return isset($this->assets[$key]);
    }

    public function getAsset(string $key): string
    {
        return $this->assets[$key]
            ?? throw new InvalidArgumentException("Template asset [{$key}] is not registered.");
    }

    public function getAssetFileUrl(string $key): string
    {
        return $this->getAsset($key);
    }

    public function getAssetDataUri(string $key): string
    {
        $asset = $this->getAsset($key);

        if (str_starts_with($asset, 'data:')) {
            return $asset;
        }

        $this->assetGuard->local($asset);
        $bytes = file_get_contents($asset);
        $mime = mime_content_type($asset);

        if (! is_string($bytes) || ! is_string($mime)) {
            throw new InvalidArgumentException(
                "Template asset [{$key}] cannot be represented as an image data URI.",
            );
        }

        $this->assetGuard->imageMimeType($mime);

        return "data:{$mime};base64,".base64_encode($bytes);
    }

    public function hasContent(string $path): bool
    {
        return $this->getContent($path) !== null;
    }

    public function getContent(string $path, mixed $default = null): mixed
    {
        $value = $this->composition?->value($path)
            ?? data_get($this->contentValues, $path);

        return $value ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAllContent(): array
    {
        return $this->contentValues;
    }

    public function getContentFromNamespace(
        string $namespace,
        string $path,
        mixed $default = null,
    ): mixed {
        return $this->getContent($namespace.'.'.$path, $default);
    }

    /**
     * @return list<string>
     */
    protected function getRequiredContent(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    protected function getRequiredData(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    protected function getRequiredAssets(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    protected function getRequiredOptions(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    protected function getCssPaths(): array
    {
        $path = $this->getCssPath();

        return $path !== '' ? [$path] : [];
    }

    private function loadRequirements(): void
    {
        $this->loadRequirementsFrom($this->requires());
    }

    /**
     * @param  array<array-key, mixed>  $requirements
     */
    private function loadRequirementsFrom(array $requirements): void
    {
        $unknown = array_diff(array_keys($requirements), ['content', 'assets']);
        $requiredContent = $requirements['content'] ?? null;
        $requiredAssets = $requirements['assets'] ?? null;

        if ($unknown !== []
            || ! is_array($requiredContent)
            || ! is_array($requiredAssets)) {
            throw new InvalidArgumentException(
                'Template requirements must contain only content and assets arrays.',
            );
        }

        $content = [];

        foreach ($requiredContent as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidArgumentException(
                    'Template required content must be a string-keyed object.',
                );
            }

            $content[$key] = $value;
        }

        if ($content !== []) {
            $this->withContent($content);
        }

        foreach ($requiredAssets as $key => $asset) {
            if (! is_string($key) || ! is_string($asset)) {
                throw new InvalidArgumentException(
                    'Template required assets must map string keys to string values.',
                );
            }

            if ($asset !== '') {
                $this->registerAsset($key, $asset, str_starts_with($asset, 'data:'));
            }
        }
    }

    protected function normalizeLanguage(string $language): string
    {
        if (preg_match('/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})*$/D', $language) !== 1) {
            throw new InvalidArgumentException("Template locale [{$language}] is invalid.");
        }

        return mb_strtolower(str_replace('_', '-', $language));
    }

    private function assertCompositionLocale(): void
    {
        if ($this->composition !== null
            && $this->normalizeLanguage($this->composition->locale) !== $this->language) {
            throw new InvalidArgumentException(
                'The Content composition locale must match the class-template language.',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeTemplateData(mixed $data): array
    {
        if (! is_array($data)) {
            throw new InvalidArgumentException('Template data must be an array.');
        }

        $normalized = [];

        foreach ($data as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidArgumentException(
                    'Template data root keys must be strings.',
                );
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    private function loadCss(): string
    {
        $css = [];

        foreach ($this->getCssPaths() as $path) {
            $this->assetGuard->local($path);
            $contents = file_get_contents($path);

            if (! is_string($contents)) {
                throw new InvalidArgumentException(
                    "Template CSS [{$path}] could not be read.",
                );
            }

            $css[] = $contents;
        }

        return trim(implode("\n\n", $css));
    }
}
