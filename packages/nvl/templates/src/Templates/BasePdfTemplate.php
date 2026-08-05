<?php

declare(strict_types=1);

namespace Nvl\Templates\Templates;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nvl\Templates\Contracts\TemplateAssetResolver;
use Nvl\Templates\Pdf\Contracts\GeneratedPdfInterface;
use Nvl\Templates\Pdf\Contracts\PdfServiceInterface;
use Nvl\Templates\Services\TemplateAssetGuard;
use Nvl\Templates\Services\TemplateContentGuard;
use Nvl\Templates\Support\PdfConfig\Data\HeaderFooterData;
use Nvl\Templates\Support\PdfConfig\Data\MarginsData;
use Nvl\Templates\Support\PdfConfig\Data\MetadataData;
use Nvl\Templates\Support\PdfConfig\Data\PageNumberingData;
use Nvl\Templates\Support\PdfConfig\Data\ProtectionData;
use Nvl\Templates\Support\PdfConfig\Data\WatermarkData;
use Nvl\Templates\Support\PdfConfig\Enums\PageOrientation;
use Nvl\Templates\Support\PdfConfig\Enums\PaperSize;
use Spatie\LaravelData\Data;
use Throwable;

/**
 * Fluent PDF template API implemented over the verified NVL renderer.
 */
abstract class BasePdfTemplate extends BaseTemplate
{
    protected ?string $fallbackLanguage = null;

    protected ?string $variant = null;

    protected bool $multivariate = true;

    protected ?string $selectedFrameKey = null;

    /** @var list<array{src: string, x_mm?: float, y_mm?: float, w_mm?: float, h_mm?: float, rotate?: float}> */
    protected array $stickers = [];

    protected bool $stickersEnabled = false;

    /** @var class-string|null */
    private ?string $dataClass = null;

    public function __construct(
        Factory $views,
        TemplateContentGuard $contentGuard,
        TemplateAssetGuard $assetGuard,
        protected readonly PdfServiceInterface $pdfService,
        protected readonly TemplateAssetResolver $assetResolver,
    ) {
        parent::__construct($views, $contentGuard, $assetGuard);
        $fallback = config('app.fallback_locale', 'en');
        $this->fallbackLanguage = is_string($fallback) ? $fallback : 'en';
        $schema = $this->defaultDataSchema() ?? $this->dataClassFqcn();

        if ($schema !== null) {
            $this->withDataSchema($schema);
        }

        $variants = $this->defaultSupportsVariants() ?? $this->supportsVariants();

        if ($variants !== null) {
            $this->multivariate($variants);
        }
    }

    public function multivariate(bool $enabled = true): static
    {
        $this->multivariate = $enabled;

        return $this;
    }

    public function isMultivariate(): bool
    {
        return $this->multivariate;
    }

    public function setData(mixed $data): static
    {
        if ($data instanceof Arrayable) {
            $data = $data->toArray();
        } elseif (is_object($data) && method_exists($data, 'toArray')) {
            $data = $data->toArray();
        }

        $data = $this->normalizeTemplateData($data);

        $normalized = $this->dataClass !== null
            ? $this->coerceToSchema($data, $this->dataClass)
            : $data;

        return parent::setData($this->keysToSnakeCase($normalized));
    }

    public function setDataObject(object $dto): static
    {
        return $this->setData($dto);
    }

    public function setOptions(array $options): static
    {
        return parent::setOptions($options);
    }

    public function setOption(string $key, mixed $value): static
    {
        return $this->setOptions([$key => $value]);
    }

    /**
     * @param  class-string  $fqcn
     */
    public function dataClass(string $fqcn): static
    {
        return $this->withDataSchema($fqcn);
    }

    /**
     * @param  class-string  $fqcn
     */
    public function withDataSchema(string $fqcn): static
    {
        if (! class_exists($fqcn)) {
            throw new InvalidArgumentException("Template data class [{$fqcn}] does not exist.");
        }

        $this->dataClass = $fqcn;

        return $this;
    }

    public function addAsset(string $key, string $path): static
    {
        return $this->registerAsset($key, $path);
    }

    public function addInlineAsset(string $key, string $dataUri): static
    {
        return $this->registerAsset($key, $dataUri, true);
    }

    public function addUrlAsset(string $key, string $url): static
    {
        return $this->registerUrlAsset($key, $url);
    }

    public function withFallbackLanguage(?string $language): static
    {
        $this->fallbackLanguage = $language === null
            ? null
            : $this->normalizeLanguage($language);

        return $this;
    }

    public function variant(?string $variant): static
    {
        if ($variant !== null
            && preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $variant) !== 1) {
            throw new InvalidArgumentException("Template variant [{$variant}] is invalid.");
        }

        if (! $this->multivariate && $variant !== null) {
            throw new InvalidArgumentException('This template does not accept variants.');
        }

        $this->variant = $variant;

        return $this;
    }

    public function useFrame(string|int $handle): static
    {
        $key = (string) $handle;
        $asset = $this->assetResolver->resolve($key);

        if ($asset === null) {
            throw new InvalidArgumentException("Template frame [{$key}] was not resolved.");
        }

        $this->selectedFrameKey = $key;

        return $this->addAsset($this->frameAssetKey(), $asset);
    }

    public function defaultFrame(?string $key): static
    {
        if ($key !== null && $this->assetResolver->resolve($key) !== null) {
            $this->useFrame($key);
        }

        return $this;
    }

    public function useStickers(bool $enabled = true): static
    {
        $this->stickersEnabled = $enabled;

        return $this;
    }

    /**
     * @param  array<string, float>  $millimetres
     */
    public function addStickerSrc(string $source, array $millimetres = []): static
    {
        if (! $this->stickersEnabled) {
            throw new InvalidArgumentException('Enable template stickers before adding one.');
        }

        foreach ($millimetres as $key => $value) {
            if (! in_array($key, ['x_mm', 'y_mm', 'w_mm', 'h_mm', 'rotate'], true)
                || ! is_finite($value)
                || abs($value) > 10_000) {
                throw new InvalidArgumentException('Template sticker geometry is invalid.');
            }
        }

        /** @var array{src: string, x_mm?: float, y_mm?: float, w_mm?: float, h_mm?: float, rotate?: float} $sticker */
        $sticker = ['src' => $source];

        foreach (['x_mm', 'y_mm', 'w_mm', 'h_mm', 'rotate'] as $key) {
            if (array_key_exists($key, $millimetres)) {
                $sticker[$key] = $millimetres[$key];
            }
        }

        $assetKey = 'sticker.'.count($this->stickers);
        $this->registerAsset($assetKey, $source, str_starts_with($source, 'data:'));
        $this->stickers[] = $sticker;

        return $this;
    }

    /**
     * @param  array<string, float>  $millimetres
     */
    public function addStickerByKey(string $key, array $millimetres = []): static
    {
        $asset = $this->assetResolver->resolve($key);

        if ($asset === null) {
            throw new InvalidArgumentException("Template sticker [{$key}] was not resolved.");
        }

        return $this->addStickerSrc($asset, $millimetres);
    }

    public function addAssetByKey(string $handle, ?string $asKey = null): static
    {
        $asset = $this->assetResolver->resolve($handle);

        if ($asset === null) {
            throw new InvalidArgumentException("Template asset [{$handle}] was not resolved.");
        }

        return $this->addAsset($asKey ?? $handle, $asset);
    }

    public function addAssetsByScope(string $scope, ?string $assetType = null): static
    {
        foreach ($this->assetResolver->scope($scope, $assetType) as $key => $asset) {
            $this->addAsset($key, $asset);
        }

        return $this;
    }

    public function addStickersByScope(string $scope): static
    {
        $this->useStickers();

        foreach ($this->assetResolver->scope($scope, 'sticker') as $asset) {
            $this->addStickerSrc($asset);
        }

        return $this;
    }

    public function addFramesByScope(string $scope): static
    {
        return $this->addAssetsByScope($scope, 'frame');
    }

    public function setPageSize(PaperSize $size): static
    {
        $this->getConfig()->setPageSize($size);

        return $this;
    }

    public function setOrientation(PageOrientation $orientation): static
    {
        $this->getConfig()->setOrientation($orientation);

        return $this;
    }

    public function setMargins(MarginsData $margins): static
    {
        $this->getConfig()->setMargins($margins);

        return $this;
    }

    public function setMetadata(MetadataData $metadata): static
    {
        $this->getConfig()->setMetadata($metadata);

        return $this;
    }

    public function setPageNumbering(PageNumberingData $numbering): static
    {
        $this->getConfig()->setPageNumbering($numbering);

        return $this;
    }

    public function setProtection(ProtectionData $protection): static
    {
        $this->getConfig()->setProtection($protection);

        return $this;
    }

    public function setWatermark(WatermarkData $watermark): static
    {
        $this->getConfig()->setWatermark($watermark);

        return $this;
    }

    public function setHeaderHtml(?string $html): static
    {
        $current = $this->getConfig()->headerFooter ?? new HeaderFooterData;
        $this->getConfig()->setHeaderFooter(new HeaderFooterData(
            headerHtml: $html,
            footerHtml: $current->footerHtml,
            showOnFirstPage: $current->showOnFirstPage,
            showOnOtherPages: $current->showOnOtherPages,
        ));

        return $this;
    }

    public function setFooterHtml(?string $html): static
    {
        $current = $this->getConfig()->headerFooter ?? new HeaderFooterData;
        $this->getConfig()->setHeaderFooter(new HeaderFooterData(
            headerHtml: $current->headerHtml,
            footerHtml: $html,
            showOnFirstPage: $current->showOnFirstPage,
            showOnOtherPages: $current->showOnOtherPages,
        ));

        return $this;
    }

    /**
     * @return array{html: string, css: string}
     */
    public function render(): array
    {
        $this->setVariable('variant', $this->variant);
        $this->setVariable('fallback_language', $this->fallbackLanguage);
        $this->setVariable('frame_key', $this->selectedFrameKey);
        $this->setVariable('stickers', $this->stickers);

        return parent::render();
    }

    /**
     * @param  array<string, mixed>  $engineOptions
     */
    public function generate(array $engineOptions = []): GeneratedPdfInterface
    {
        return $this->pdfService->renderTemplate(
            $this,
            $this->language,
            options: $engineOptions,
        );
    }

    public function preview(): Response
    {
        return $this->generate()->display();
    }

    public function download(string $filename = 'document.pdf'): Response
    {
        return $this->generate()->download($filename);
    }

    public function save(?string $filename = null): string
    {
        return $this->pdfService->saveWithTemplate(
            $this,
            $this->language,
            filename: $filename,
        );
    }

    public function saveToStorage(?string $filename = null): string
    {
        return $this->pdfService->saveToStorage(
            $this,
            $this->language,
            filename: $filename,
        );
    }

    public function supportsQrCode(): bool
    {
        return false;
    }

    public function generateQrCode(): static
    {
        throw new InvalidArgumentException(
            'QR generation requires an application-provided asset and renderer integration.',
        );
    }

    /**
     * @return list<string>
     */
    protected function contentScopes(): array
    {
        return ['shared'];
    }

    /**
     * @return list<string>
     */
    protected function defaultContentScopes(): array
    {
        return $this->contentScopes();
    }

    /**
     * @return class-string|null
     */
    protected function dataClassFqcn(): ?string
    {
        return null;
    }

    /**
     * @return class-string|null
     */
    protected function defaultDataSchema(): ?string
    {
        return null;
    }

    protected function defaultSupportsVariants(): ?bool
    {
        return null;
    }

    protected function supportsVariants(): ?bool
    {
        return null;
    }

    protected function frameAssetKey(): string
    {
        return 'frame';
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  class-string  $class
     * @return array<string, mixed>
     */
    private function coerceToSchema(array $data, string $class): array
    {
        try {
            if (is_subclass_of($class, Data::class)) {
                return $this->stringKeyedArray($class::from($data)->toArray(), $class);
            }

            $from = [$class, 'from'];

            if (is_callable($from)) {
                $value = $from($data);

                if (is_object($value) && method_exists($value, 'toArray')) {
                    return $this->stringKeyedArray($value->toArray(), $class);
                }
            }

            $fromArray = [$class, 'fromArray'];

            if (is_callable($fromArray)) {
                $value = $fromArray($data);

                if (is_object($value) && method_exists($value, 'toArray')) {
                    return $this->stringKeyedArray($value->toArray(), $class);
                }
            }
        } catch (Throwable $exception) {
            throw new InvalidArgumentException(
                "Template data could not be converted to [{$class}].",
                0,
                $exception,
            );
        }

        throw new InvalidArgumentException(
            "Template data class [{$class}] must support a typed array factory.",
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function keysToSnakeCase(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $result[Str::snake($key)] = $this->snakeCaseValue($value);
        }

        return $result;
    }

    private function snakeCaseValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $result = [];

        foreach ($value as $key => $nestedValue) {
            $normalizedKey = is_string($key)
                ? Str::snake($key)
                : $key;
            $result[$normalizedKey] = $this->snakeCaseValue($nestedValue);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function stringKeyedArray(mixed $value, string $class): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException(
                "Template data class [{$class}] did not return an array.",
            );
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new InvalidArgumentException(
                    "Template data class [{$class}] returned a non-string root key.",
                );
            }

            $result[$key] = $item;
        }

        return $result;
    }
}
