<?php

declare(strict_types=1);

namespace Nvl\Forms\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Nvl\Forms\Services\PublicFormTokenService;
use Nvl\Forms\Services\RequestOriginResolver;

/**
 * FormSubmissionContext carries request-derived submission metadata for action calls.
 */
final readonly class FormSubmissionContext
{
    /**
     * Create a submission context instance.
     *
     * @param  string|null  $ipAddress  Trusted client IP address
     * @param  string|null  $userAgent  Trusted request user agent
     * @param  string|null  $sessionId  Active session identifier
     * @param  string|null  $sessionToken  Active session CSRF token
     * @param  string|null  $csrfToken  Submitted CSRF token
     * @param  string|null  $publicToken  Submitted signed public token
     * @param  string|null  $idempotencyKey  Bounded retry key
     * @param  string|null  $originHost  Normalized request origin host
     * @param  string|null  $originHeader  Raw request Origin header
     * @param  string|null  $requestHost  Host serving the request
     * @param  Authenticatable|null  $actor  Authenticated request actor
     * @param  Request|null  $request  Optional HTTP request for handler adapters
     */
    public function __construct(
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
        public ?string $sessionId = null,
        public ?string $sessionToken = null,
        public ?string $csrfToken = null,
        public ?string $publicToken = null,
        public ?string $idempotencyKey = null,
        public ?string $originHost = null,
        public ?string $originHeader = null,
        public ?string $requestHost = null,
        public ?Authenticatable $actor = null,
        public ?Request $request = null,
    ) {}

    /**
     * Build submission context from an HTTP request.
     */
    public static function fromRequest(Request $request, RequestOriginResolver $originResolver): self
    {
        $sessionId = null;
        $sessionToken = null;

        if ($request->hasSession()) {
            $session = $request->session();
            $sessionId = $session->getId();
            $token = $session->token();
            $sessionToken = $token !== '' ? $token : null;
        }

        $csrfToken = self::resolveCsrfToken($request);
        $publicToken = self::resolvePublicToken($request);
        $actor = $request->user();

        return new self(
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            sessionId: $sessionId,
            sessionToken: $sessionToken,
            csrfToken: $csrfToken,
            publicToken: $publicToken,
            idempotencyKey: self::resolveIdempotencyKey($request),
            originHost: $originResolver->originHost($request),
            originHeader: $originResolver->originHeader($request),
            requestHost: $request->getHost(),
            actor: $actor instanceof Authenticatable ? $actor : null,
            request: $request,
        );
    }

    /**
     * Resolve a usable IP address string for downstream actions.
     */
    public function resolvedIpAddress(): string
    {
        return is_string($this->ipAddress) && $this->ipAddress !== ''
            ? $this->ipAddress
            : '0.0.0.0';
    }

    /**
     * Resolve the HTTP request used by handler and origin adapters.
     */
    public function httpRequest(): Request
    {
        return $this->request ?? Request::create('/', 'POST');
    }

    /**
     * Resolve candidate CSRF token from headers or input.
     */
    private static function resolveCsrfToken(Request $request): ?string
    {
        $csrfHeader = $request->header('X-CSRF-TOKEN');
        if (is_string($csrfHeader) && trim($csrfHeader) !== '') {
            return trim($csrfHeader);
        }

        $xsrfHeader = $request->header('X-XSRF-TOKEN');
        if (is_string($xsrfHeader) && trim($xsrfHeader) !== '') {
            return urldecode(trim($xsrfHeader));
        }

        $inputToken = $request->input('_token', $request->input('csrf_token'));
        if (is_string($inputToken) && trim($inputToken) !== '') {
            return trim($inputToken);
        }

        return null;
    }

    /**
     * Resolve candidate signed public token from headers or input.
     */
    private static function resolvePublicToken(Request $request): ?string
    {
        $headerToken = $request->header(PublicFormTokenService::HEADER);
        if (is_string($headerToken) && trim($headerToken) !== '') {
            return trim($headerToken);
        }

        $inputToken = $request->input('publicToken', $request->input('public_token'));
        if (is_string($inputToken) && trim($inputToken) !== '') {
            return trim($inputToken);
        }

        return null;
    }

    /**
     * Resolve a bounded client-provided idempotency key.
     */
    private static function resolveIdempotencyKey(Request $request): ?string
    {
        $value = $request->header('Idempotency-Key');

        if (! is_string($value)) {
            $value = $request->input('idempotencyKey');
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' && strlen($value) <= 128 ? $value : null;
    }
}
