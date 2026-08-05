<?php

declare(strict_types=1);

namespace App\Comments\Probe;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use JsonException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dispatches isolated synthetic HTTP requests through the real Laravel kernel.
 */
final readonly class SyntheticCommentsHttpClient
{
    /**
     * Create a synthetic client around the consumer's real HTTP kernel.
     */
    public function __construct(
        private HttpKernel $kernel,
    ) {}

    /**
     * Dispatch one JSON API request and decode its object response.
     *
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $headers
     * @return array{
     *     status: int,
     *     payload: array<string, mixed>|null,
     *     contentType: string|null,
     *     cacheControl: string|null
     * }
     *
     * @throws JsonException
     */
    public function json(
        string $method,
        string $uri,
        ?string $actorEmail = null,
        ?array $body = null,
        array $headers = [],
        bool $acceptJson = true,
    ): array {
        $response = $this->dispatch(
            $method,
            $uri,
            $actorEmail,
            $body,
            $headers,
            $acceptJson,
        );
        $content = (string) $response->getContent();
        $payload = $content === '' ? null : json_decode(
            $content,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        if ($payload !== null && ! is_array($payload)) {
            throw new RuntimeException(
                "Comments endpoint [{$method} {$uri}] returned a non-object JSON payload.",
            );
        }

        $objectPayload = null;

        if (is_array($payload)) {
            $objectPayload = [];

            foreach ($payload as $key => $value) {
                if (! is_string($key)) {
                    throw new RuntimeException(
                        "Comments endpoint [{$method} {$uri}] returned a non-object JSON payload.",
                    );
                }

                $objectPayload[$key] = $value;
            }
        }

        return [
            'status' => $response->getStatusCode(),
            'payload' => $objectPayload,
            'contentType' => $response->headers->get('Content-Type'),
            'cacheControl' => $response->headers->get('Cache-Control'),
        ];
    }

    /**
     * Dispatch one signed asset request and capture its binary body and safety headers.
     *
     * @param  array<string, string>  $headers
     * @return array{
     *     status: int,
     *     content: string,
     *     contentType: string|null,
     *     cacheControl: string|null,
     *     contentTypeOptions: string|null,
     *     etag: string|null
     * }
     */
    public function asset(string $url, array $headers = []): array
    {
        $response = $this->dispatch('GET', $url, headers: $headers);
        $content = $this->responseContent($response);

        return [
            'status' => $response->getStatusCode(),
            'content' => $content,
            'contentType' => $response->headers->get('Content-Type'),
            'cacheControl' => $response->headers->get('Cache-Control'),
            'contentTypeOptions' => $response->headers->get('X-Content-Type-Options'),
            'etag' => $response->headers->get('ETag'),
        ];
    }

    /**
     * Send one request through route middleware, exception rendering, and termination.
     *
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $headers
     */
    private function dispatch(
        string $method,
        string $uri,
        ?string $actorEmail = null,
        ?array $body = null,
        array $headers = [],
        bool $acceptJson = true,
    ): Response {
        $server = [
            'HTTPS' => 'on',
            'HTTP_HOST' => 'comments-consumer.test',
            'REMOTE_ADDR' => '127.0.0.1',
            'SERVER_PORT' => '443',
        ];

        if ($acceptJson) {
            $server['HTTP_ACCEPT'] = 'application/json';
        }

        if ($actorEmail !== null) {
            $server['HTTP_X_COMMENTS_CONSUMER_USER'] = $actorEmail;
        }

        foreach ($headers as $name => $value) {
            $normalized = strtoupper(str_replace('-', '_', $name));
            $server[$normalized === 'CONTENT_TYPE' ? $normalized : 'HTTP_'.$normalized] = $value;
        }

        $content = null;

        if ($body !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
            $content = json_encode($body, JSON_THROW_ON_ERROR);
        }

        Auth::forgetGuards();
        $request = Request::create($uri, $method, [], [], [], $server, $content);
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);
        Auth::forgetGuards();

        return $response;
    }

    /**
     * Materialize a local binary or streamed response without sending headers to the console.
     */
    private function responseContent(Response $response): string
    {
        if ($response instanceof BinaryFileResponse) {
            $content = file_get_contents($response->getFile()->getPathname());

            return is_string($content) ? $content : '';
        }

        if ($response instanceof StreamedResponse) {
            ob_start();
            $response->sendContent();
            $content = ob_get_clean();

            return is_string($content) ? $content : '';
        }

        return (string) $response->getContent();
    }
}
