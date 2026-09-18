<?php

declare(strict_types=1);

namespace Nvl\Forms\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Nvl\Forms\Exceptions\FormException;
use Nvl\Forms\Models\Form;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Enums\TenantContextMode;
use Nvl\Tenancy\Services\TenantBoundary;
use Throwable;

/**
 * Issues and validates short-lived tokens for public embed endpoints (e.g. suggestions).
 *
 * Token format: base64url(json_payload) . '.' . base64url(hmac_sha256(payload, app_key))
 */
final class PublicFormTokenService
{
    public const string HEADER = 'X-Forms-Public-Token';

    /** Create the versioned tenant-bound public token service. */
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantBoundary $boundary,
    ) {}

    /**
     * Issue a signed token for a specific form.
     *
     * @param  Form  $form  Target form
     * @param  CarbonInterface  $expiresAt  Expiration timestamp
     * @return string Signed token
     */
    public function issue(Form $form, CarbonInterface $expiresAt, string $site = 'default'): string
    {
        $key = $this->keyBytes();
        if ($key === null) {
            throw new FormException('A valid APP_KEY is required to sign public form tokens.');
        }

        $this->boundary->assertRecord($form, 'forms.forms');
        $snapshot = $this->context->snapshot();
        $payload = [
            'v' => 2,
            'tenant_id' => $snapshot->tenantId?->value,
            'site' => $site,
            'form_id' => (string) $form->id,
            'iat' => CarbonImmutable::now()->getTimestamp(),
            'exp' => $expiresAt->getTimestamp(),
            'nonce' => Str::random(16),
        ];

        $encodedPayload = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $encodedPayload, $key, true));

        return $encodedPayload.'.'.$signature;
    }

    /**
     * Validate a token for a specific form.
     *
     * @param  string|null  $token  Token from header
     * @param  Form  $form  Target form
     * @return bool Whether token is valid and not expired
     */
    public function validate(?string $token, Form $form, string $site = 'default'): bool
    {
        $payload = $this->validatedPayload($token);
        if ($payload === null) {
            return false;
        }

        $formId = $payload['form_id'] ?? '';

        return $formId !== ''
            && $formId === $form->id
            && $this->matchesOwnership($payload, $site);
    }

    /**
     * Return the trusted server-issued load timestamp for spam timing.
     */
    public function issuedAt(?string $token, Form $form, string $site = 'default'): ?float
    {
        $payload = $this->validatedPayload($token);
        if ($payload === null || ($payload['form_id'] ?? '') !== $form->id || ! $this->matchesOwnership($payload, $site)) {
            return null;
        }

        $issuedAt = $payload['iat'] ?? 0;

        return $issuedAt > 0 ? (float) $issuedAt : null;
    }

    /**
     * Validate a token for a specific public form handle.
     *
     * @param  string|null  $token  Token from header
     * @param  string  $handle  Required form handle
     * @return bool Whether token is valid, not expired, and belongs to the expected handle
     */
    public function validateForHandle(?string $token, string $handle): bool
    {
        $payload = $this->validatedPayload($token);
        if ($payload === null) {
            return false;
        }

        $formId = $payload['form_id'] ?? '';
        if ($formId === '') {
            return false;
        }

        return $this->boundary->query(Form::query(), 'forms.forms')
            ->whereKey($formId)
            ->where('handle', $handle)
            ->exists();
    }

    /**
     * Determine whether public tokens have a usable application signing key.
     */
    public function hasSigningKey(): bool
    {
        return $this->keyBytes() !== null;
    }

    /**
     * Resolve the application key bytes for HMAC.
     */
    private function keyBytes(): ?string
    {
        $configuredKey = config('app.key', '');
        if (! is_string($configuredKey) || trim($configuredKey) === '') {
            return null;
        }

        $key = $configuredKey;
        if (Str::startsWith($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            return is_string($decoded) && $decoded !== '' ? $decoded : null;
        }

        return $key;
    }

    /**
     * @return array{v?:int,tenant_id?:string|null,site?:string,form_id?:string,iat?:int,exp?:int,nonce?:string}|null
     */
    private function validatedPayload(?string $token): ?array
    {
        $key = $this->keyBytes();
        if ($key === null) {
            return null;
        }

        if (! is_string($token) || trim($token) === '') {
            return null;
        }

        $token = trim($token);
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$encodedPayload, $encodedSignature] = $parts;
        if ($encodedPayload === '' || $encodedSignature === '') {
            return null;
        }

        $expected = $this->base64UrlEncode(hash_hmac('sha256', $encodedPayload, $key, true));
        if (! hash_equals($expected, $encodedSignature)) {
            return null;
        }

        $raw = $this->base64UrlDecode($encodedPayload);
        if ($raw === null) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        $formId = $decoded['form_id'] ?? null;
        $issuedAt = $decoded['iat'] ?? null;
        $expiresAt = $decoded['exp'] ?? null;
        $nonce = $decoded['nonce'] ?? null;
        $version = $decoded['v'] ?? null;
        $tenantId = $decoded['tenant_id'] ?? null;
        $site = $decoded['site'] ?? null;

        if (! is_string($formId)
            || ! is_int($issuedAt)
            || ! is_int($expiresAt)
            || ! is_string($nonce)) {
            return null;
        }

        $mode = $this->context->snapshot()->mode;
        if ($version !== 2) {
            if ($expiresAt <= 0 || CarbonImmutable::now()->getTimestamp() > $expiresAt) {
                return null;
            }

            return $mode === TenantContextMode::Disabled ? [
                'form_id' => $formId,
                'iat' => $issuedAt,
                'exp' => $expiresAt,
                'nonce' => $nonce,
            ] : null;
        }
        if (($tenantId !== null && ! is_string($tenantId)) || ! is_string($site) || $site === '') {
            return null;
        }

        $payload = [
            'v' => 2,
            'tenant_id' => $tenantId,
            'site' => $site,
            'form_id' => $formId,
            'iat' => $issuedAt,
            'exp' => $expiresAt,
            'nonce' => $nonce,
        ];
        $exp = $payload['exp'];
        if ($exp <= 0 || CarbonImmutable::now()->getTimestamp() > $exp) {
            return null;
        }

        return $payload;
    }

    /**
     * Confirm token tenancy and site against the currently admitted request.
     *
     * @param  array<string, mixed>  $payload
     */
    private function matchesOwnership(array $payload, string $site): bool
    {
        $snapshot = $this->context->snapshot();
        if ($snapshot->mode === TenantContextMode::Disabled) {
            return ! isset($payload['v']) || $payload['v'] === 2;
        }

        return $snapshot->mode === TenantContextMode::Tenant
            && ($payload['tenant_id'] ?? null) === $snapshot->tenantId?->value
            && ($payload['site'] ?? null) === $site;
    }

    private function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $encoded): ?string
    {
        $padded = strtr($encoded, '-_', '+/');
        $padLen = 4 - (strlen($padded) % 4);
        if ($padLen !== 4) {
            $padded .= str_repeat('=', $padLen);
        }

        $decoded = base64_decode($padded, true);

        return is_string($decoded) ? $decoded : null;
    }
}
