<?php

declare(strict_types=1);

namespace Nvl\Seo\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * Base package exception for safe SEO domain failures.
 */
abstract class SeoException extends RuntimeException implements ShouldntReport
{
    /**
     * Create a domain failure while preserving its original cause.
     */
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    /**
     * Render API requests through the stable SEO error envelope.
     */
    public function render(Request $request): JsonResponse|bool
    {
        if (! $request->expectsJson()) {
            return false;
        }

        $error = [
            'code' => $this->responseCode(),
            'message' => $this->getMessage(),
        ];
        $context = $this->publicContext();

        if ($context !== []) {
            $error['context'] = $context;
        }

        return response()->json(['error' => $error], $this->status());
    }

    /**
     * Return the stable machine-readable error code.
     */
    abstract protected function responseCode(): string;

    /**
     * Return the suggested HTTP response status.
     */
    abstract protected function status(): int;

    /**
     * Return safe structured context for API consumers.
     *
     * @return array<string, mixed>
     */
    protected function publicContext(): array
    {
        return [];
    }
}
