<?php

declare(strict_types=1);

namespace App\Auth\Http;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dispatches fixture probes through the complete Laravel HTTP middleware stack.
 */
final class SyntheticHttpProbe
{
    /**
     * Browser cookie values retained independently for each synthetic actor.
     *
     * @var array<string, array<string, string>>
     */
    private array $browserCookies = [];

    private string $browser = 'default';

    /**
     * Create the multi-browser synthetic HTTP dispatcher.
     */
    public function __construct(
        private readonly Kernel $kernel,
        private readonly AuthManager $auth,
        private readonly Repository $config,
    ) {}

    /**
     * Select one isolated browser cookie jar for subsequent stateful requests.
     */
    public function useBrowser(string $browser): void
    {
        if (preg_match('/\A[a-z][a-z0-9_-]{0,31}\z/', $browser) !== 1) {
            throw new InvalidArgumentException('The synthetic browser name is invalid.');
        }

        if ($this->browser === $browser) {
            return;
        }

        $this->browser = $browser;
        $this->auth->forgetGuards();
    }

    /**
     * Start the shared request session and return its CSRF token.
     *
     * @throws JsonException
     */
    public function csrfToken(): string
    {
        $response = $this->dispatch('GET', '/auth-consumer/csrf-token');
        $payload = json_decode(
            (string) $response->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $token = is_array($payload) ? data_get($payload, 'data.token') : null;

        if (! is_string($token) || trim($token) === '') {
            throw new RuntimeException('The CSRF bootstrap route did not return a token.');
        }

        return $token;
    }

    /**
     * Dispatch one synthetic HTTPS request through the application kernel.
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, string>  $headers
     */
    public function dispatch(
        string $method,
        string $uri,
        array $parameters = [],
        ?string $csrfToken = null,
        array $headers = [],
    ): Response {
        return $this->send(
            method: $method,
            uri: $uri,
            parameters: $parameters,
            csrfToken: $csrfToken,
            headers: $headers,
            cookies: $this->browserCookies[$this->browser] ?? [],
            retainResponseCookies: true,
        );
    }

    /**
     * Dispatch one bearer request without ambient browser cookies.
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, string>  $headers
     */
    public function dispatchStateless(
        string $method,
        string $uri,
        array $parameters = [],
        array $headers = [],
    ): Response {
        return $this->send(
            method: $method,
            uri: $uri,
            parameters: $parameters,
            csrfToken: null,
            headers: $headers,
            cookies: [],
            retainResponseCookies: false,
        );
    }

    /**
     * Dispatch one synthetic request with an explicit cookie policy.
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, string>  $headers
     * @param  array<string, string>  $cookies
     */
    private function send(
        string $method,
        string $uri,
        array $parameters,
        ?string $csrfToken,
        array $headers,
        array $cookies,
        bool $retainResponseCookies,
    ): Response {
        $server = [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_HOST' => 'auth-consumer.test',
            'HTTP_USER_AGENT' => 'NVL Auth clean consumer probe',
            'HTTPS' => 'on',
        ];

        if ($csrfToken !== null) {
            $server['HTTP_X_CSRF_TOKEN'] = $csrfToken;
        }

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $request = Request::create(
            uri: $uri,
            method: $method,
            parameters: $parameters,
            cookies: $cookies,
            server: $server,
        );
        $sanctumGuards = null;

        if (! $retainResponseCookies) {
            $sanctumGuards = $this->config->get('sanctum.guard');
            $this->config->set('sanctum.guard', []);
            $this->auth->forgetGuards();
        }

        try {
            $response = $this->kernel->handle($request);

            if ($retainResponseCookies) {
                $this->captureCookies($response);
            }

            $this->kernel->terminate($request, $response);

            return $response;
        } finally {
            if (! $retainResponseCookies) {
                $this->config->set('sanctum.guard', $sanctumGuards);
                $this->auth->forgetGuards();
            }
        }
    }

    /**
     * Retain cookie creation, rotation, and deletion exactly like one browser.
     */
    private function captureCookies(Response $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if (! $cookie instanceof Cookie) {
                continue;
            }

            if ($cookie->getExpiresTime() !== 0
                && $cookie->getExpiresTime() <= time()) {
                unset($this->browserCookies[$this->browser][$cookie->getName()]);

                continue;
            }

            $this->browserCookies[$this->browser][$cookie->getName()] = $cookie->getValue();
        }
    }
}
