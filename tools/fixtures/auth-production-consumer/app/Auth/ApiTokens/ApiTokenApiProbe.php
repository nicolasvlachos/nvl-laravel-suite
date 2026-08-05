<?php

declare(strict_types=1);

namespace App\Auth\ApiTokens;

use App\Auth\Flows\AuthenticationApiProbeResult;
use App\Auth\Http\SyntheticHttpProbe;
use App\Models\User;
use JsonException;
use Nvl\Auth\Contracts\AuthenticatedPrincipalResolver;
use Nvl\Auth\Enums\SessionStatus;
use Nvl\Auth\Models\AuthSession;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exercises managed Sanctum issuance and host ability authorization end to end.
 */
final readonly class ApiTokenApiProbe
{
    /**
     * Create the managed personal-token integration probe.
     */
    public function __construct(
        private SyntheticHttpProbe $http,
        private AuthenticatedPrincipalResolver $principals,
    ) {}

    /**
     * Prove exact-session CRUD, rotation, revocation, lineage, and abilities.
     *
     * @throws JsonException
     */
    public function probe(
        User $member,
        AuthenticationApiProbeResult $authentication,
    ): ApiTokenApiProbeResult {
        $this->http->useBrowser('member');
        $principal = $this->principals->resolve($member);
        $session = AuthSession::query()
            ->whereKey($authentication->sessionId)
            ->where('principal_id', $principal->id)
            ->where('status', SessionStatus::Active)
            ->first();

        if (! $session instanceof AuthSession) {
            throw new RuntimeException('The managed API-token probe requires the exact active package session.');
        }

        $csrfToken = $this->http->csrfToken();
        $headers = [
            'X-Correlation-ID' => 'auth-consumer-api-token',
            'X-NVL-Auth-Binding' => str_repeat('b', 64),
            'X-NVL-Auth-Client-Key' => 'auth-consumer-web',
        ];
        $statuses = [];
        $abilities = $this->http->dispatch(
            'GET',
            '/api/v1/auth/api-tokens/abilities',
            [],
            $csrfToken,
            $headers,
        );
        $this->assertStatus($abilities, Response::HTTP_OK, 'api_tokens.abilities');
        $statuses['api_tokens.abilities'] = $abilities->getStatusCode();
        $original = $this->issue(
            'Read profile probe',
            ['profile:read'],
            $csrfToken,
            $headers,
        );
        $statuses['api_tokens.store.read'] = $original['status'];

        $index = $this->http->dispatch(
            'GET',
            '/api/v1/auth/api-tokens',
            [],
            $csrfToken,
            $headers,
        );
        $this->assertStatus($index, Response::HTTP_OK, 'api_tokens.index');
        $statuses['api_tokens.index'] = $index->getStatusCode();

        $shown = $this->http->dispatch(
            'GET',
            '/api/v1/auth/api-tokens/'.$original['id'],
            [],
            $csrfToken,
            $headers,
        );
        $this->assertStatus($shown, Response::HTTP_OK, 'api_tokens.show');
        $statuses['api_tokens.show'] = $shown->getStatusCode();

        $updated = $this->http->dispatch(
            'PUT',
            '/api/v1/auth/api-tokens/'.$original['id'],
            [
                'name' => 'Read profile probe updated',
                'abilities' => ['profile:read'],
                'expiresAt' => $original['expiresAt'],
            ],
            $csrfToken,
            $headers,
        );
        $this->assertStatus($updated, Response::HTTP_OK, 'api_tokens.update');
        $statuses['api_tokens.update'] = $updated->getStatusCode();

        $rotatedResponse = $this->http->dispatch(
            'POST',
            '/api/v1/auth/api-tokens/'.$original['id'].'/rotate',
            [
                'name' => 'Read profile probe rotated',
                'abilities' => ['profile:read'],
                'expiresAt' => $original['expiresAt'],
            ],
            $csrfToken,
            $headers,
        );
        $this->assertStatus($rotatedResponse, Response::HTTP_CREATED, 'api_tokens.rotate');
        $statuses['api_tokens.rotate'] = $rotatedResponse->getStatusCode();
        $rotated = [
            'id' => $this->stringValue(
                $rotatedResponse,
                'data.apiToken.id',
                'rotated API token identifier',
            ),
            'credential' => $this->stringValue(
                $rotatedResponse,
                'data.credential',
                'rotated API token credential',
            ),
            'noStore' => $this->noStore($rotatedResponse),
        ];
        $wrongAbilityToken = $this->issue(
            'Update profile probe',
            ['profile:update'],
            $csrfToken,
            $headers,
        );
        $statuses['api_tokens.store.wrong_ability'] = $wrongAbilityToken['status'];

        $managed = $this->bearer($rotated['credential']);
        $statuses['profile.managed'] = $managed->getStatusCode();
        $wrongAbility = $this->bearer($wrongAbilityToken['credential']);
        $statuses['profile.wrong_ability'] = $wrongAbility->getStatusCode();
        $legacy = $member->createToken('Unmanaged legacy probe', ['profile:read']);
        $unmanaged = $this->bearer($legacy->plainTextToken);
        $statuses['profile.unmanaged'] = $unmanaged->getStatusCode();
        $legacy->accessToken->delete();
        $rotatedCredential = $this->bearer($original['credential']);
        $statuses['profile.rotated_credential'] = $rotatedCredential->getStatusCode();

        $destroyed = $this->http->dispatch(
            'DELETE',
            '/api/v1/auth/api-tokens/'.$wrongAbilityToken['id'],
            ['reason' => 'consumer_probe'],
            $csrfToken,
            $headers,
        );
        $this->assertStatus($destroyed, Response::HTTP_OK, 'api_tokens.destroy');
        $statuses['api_tokens.destroy'] = $destroyed->getStatusCode();
        $revokedSingle = $this->bearer($wrongAbilityToken['credential']);
        $statuses['profile.revoked_single'] = $revokedSingle->getStatusCode();

        $cleanupToken = $this->issue(
            'Bulk cleanup probe',
            ['profile:read'],
            $csrfToken,
            $headers,
        );
        $statuses['api_tokens.store.cleanup'] = $cleanupToken['status'];
        $revokedAll = $this->http->dispatch(
            'DELETE',
            '/api/v1/auth/api-tokens',
            ['reason' => 'consumer_probe_cleanup'],
            $csrfToken,
            $headers,
        );
        $this->assertStatus($revokedAll, Response::HTTP_OK, 'api_tokens.destroy_all');
        $statuses['api_tokens.destroy_all'] = $revokedAll->getStatusCode();
        $revokedBearer = $this->bearer($rotated['credential']);
        $statuses['profile.revoked_all'] = $revokedBearer->getStatusCode();

        return new ApiTokenApiProbeResult(
            tokenId: $rotated['id'],
            sessionId: $session->id,
            oneTimeMaterialProtected: $original['noStore']
                && $rotated['noStore']
                && $wrongAbilityToken['noStore']
                && $cleanupToken['noStore'],
            managedBearerAccepted: $managed->getStatusCode() === Response::HTTP_OK,
            wrongAbilityDenied: $wrongAbility->getStatusCode() === Response::HTTP_FORBIDDEN,
            unmanagedBearerRejected: $unmanaged->getStatusCode() === Response::HTTP_UNAUTHORIZED,
            rotatedCredentialRejected: $rotatedCredential->getStatusCode()
                === Response::HTTP_UNAUTHORIZED,
            singlyRevokedBearerRejected: $revokedSingle->getStatusCode()
                === Response::HTTP_UNAUTHORIZED,
            revokedBearerRejected: $revokedBearer->getStatusCode()
                === Response::HTTP_UNAUTHORIZED,
            bulkRevokedCount: $this->integerValue(
                $revokedAll,
                'data.revokedCount',
                'bulk-revoked token count',
            ),
            statuses: $statuses,
        );
    }

    /**
     * Issue one package-managed bearer through the account API.
     *
     * @param  list<string>  $abilities
     * @param  array<string, string>  $headers
     * @return array{id: string, credential: string, expiresAt: string, noStore: bool, status: int}
     *
     * @throws JsonException
     */
    private function issue(
        string $name,
        array $abilities,
        string $csrfToken,
        array $headers,
    ): array {
        $response = $this->http->dispatch(
            'POST',
            '/api/v1/auth/api-tokens',
            [
                'name' => $name,
                'abilities' => $abilities,
            ],
            $csrfToken,
            $headers,
        );
        $this->assertStatus($response, Response::HTTP_CREATED, 'api_tokens.store');

        return [
            'id' => $this->stringValue($response, 'data.apiToken.id', 'API token identifier'),
            'credential' => $this->stringValue($response, 'data.credential', 'API token credential'),
            'expiresAt' => $this->stringValue(
                $response,
                'data.apiToken.expiresAt',
                'API token expiration',
            ),
            'noStore' => $this->noStore($response),
            'status' => $response->getStatusCode(),
        ];
    }

    /**
     * Dispatch one cookie-free bearer to the managed host endpoint.
     */
    private function bearer(string $credential): Response
    {
        return $this->http->dispatchStateless(
            'GET',
            '/api/v1/auth-consumer/profile',
            headers: ['Authorization' => 'Bearer '.$credential],
        );
    }

    /**
     * Read one required string from a response envelope.
     *
     * @throws JsonException
     */
    private function stringValue(
        Response $response,
        string $path,
        string $label,
    ): string {
        $payload = json_decode(
            (string) $response->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $value = is_array($payload) ? data_get($payload, $path) : null;

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("The package did not return the {$label}.");
        }

        return $value;
    }

    /**
     * Read one required integer from a response envelope.
     *
     * @throws JsonException
     */
    private function integerValue(
        Response $response,
        string $path,
        string $label,
    ): int {
        $payload = json_decode(
            (string) $response->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $value = is_array($payload) ? data_get($payload, $path) : null;

        if (! is_int($value)) {
            throw new RuntimeException("The package did not return the {$label}.");
        }

        return $value;
    }

    /**
     * Determine whether one-time response material is non-cacheable.
     */
    private function noStore(Response $response): bool
    {
        $cacheControl = $response->headers->get('Cache-Control');

        return is_string($cacheControl) && str_contains($cacheControl, 'no-store');
    }

    /**
     * Fail with the response body when an API operation violates its contract.
     */
    private function assertStatus(
        Response $response,
        int $expected,
        string $operation,
    ): void {
        if ($response->getStatusCode() === $expected) {
            return;
        }

        throw new RuntimeException(sprintf(
            'API-token operation [%s] returned HTTP %d instead of %d: %s',
            $operation,
            $response->getStatusCode(),
            $expected,
            (string) $response->getContent(),
        ));
    }
}
