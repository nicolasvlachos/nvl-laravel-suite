<?php

declare(strict_types=1);

namespace App\Auth\Flows;

use App\Auth\Clients\AuthClientApiProbeResult;
use App\Auth\Http\SyntheticHttpProbe;
use App\Models\User;
use JsonException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drives the canonical login-to-session pipeline through package HTTP routes.
 */
final readonly class AuthenticationApiProbe
{
    /**
     * Create the canonical authentication HTTP probe.
     */
    public function __construct(private SyntheticHttpProbe $http) {}

    /**
     * Exercise host identity/password adapters and first-party session establishment.
     *
     * @throws JsonException
     */
    public function probe(
        User $member,
        string $password,
        AuthClientApiProbeResult $client,
    ): AuthenticationApiProbeResult {
        $this->http->useBrowser('member');
        $csrfToken = $this->http->csrfToken();
        $headers = [
            'X-Correlation-ID' => 'auth-consumer-canonical-flow',
            'X-NVL-Auth-Binding' => $client->binding->reveal(),
            'X-NVL-Auth-Client-Key' => $client->clientId,
            'Origin' => $client->origin,
        ];
        $statuses = [];
        $started = $this->http->dispatch(
            'POST',
            '/api/v1/auth/flows/login',
            [
                'identifierType' => 'email',
                'identifier' => $member->email,
                'intent' => 'consumer-smoke',
                'clientGrant' => $client->clientGrant->reveal(),
            ],
            $csrfToken,
            $headers,
        );
        $this->assertStatus($started, Response::HTTP_CREATED, 'flows.login');
        $statuses['flows.login'] = $started->getStatusCode();
        $flowId = $this->stringValue($started, 'data.flowId', 'flow identifier');

        $passwordProof = $this->http->dispatch(
            'POST',
            "/api/v1/auth/flows/{$flowId}/password",
            ['password' => $password],
            $csrfToken,
            $headers,
        );
        $this->assertStatus($passwordProof, Response::HTTP_OK, 'flows.password');
        $statuses['flows.password'] = $passwordProof->getStatusCode();

        $completed = $this->http->dispatch(
            'POST',
            "/api/v1/auth/flows/{$flowId}/complete",
            [],
            $csrfToken,
            $headers,
        );
        $this->assertStatus($completed, Response::HTTP_OK, 'flows.complete');
        $statuses['flows.complete'] = $completed->getStatusCode();
        $grant = $this->stringValue(
            $completed,
            'data.sessionGrant',
            'session grant',
        );

        $exchanged = $this->http->dispatch(
            'POST',
            '/api/v1/auth/session-grants/exchange',
            ['grant' => $grant],
            $csrfToken,
            $headers,
        );
        $this->assertStatus(
            $exchanged,
            Response::HTTP_OK,
            'session_grants.exchange',
        );
        $statuses['session_grants.exchange'] = $exchanged->getStatusCode();

        return new AuthenticationApiProbeResult(
            flowId: $flowId,
            sessionId: $this->stringValue(
                $exchanged,
                'data.sessionId',
                'session identifier',
            ),
            sessionDriver: $this->stringValue(
                $exchanged,
                'data.driver',
                'session driver',
            ),
            oneTimeSecretsProtected: $this->noStore($started)
                && $this->noStore($completed)
                && $this->noStore($exchanged),
            statuses: $statuses,
        );
    }

    /**
     * Read one required string from a package response envelope.
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
            throw new RuntimeException("The package did not return a {$label}.");
        }

        return $value;
    }

    /**
     * Require the package's no-store contract for one-time material.
     */
    private function noStore(Response $response): bool
    {
        $cacheControl = $response->headers->get('Cache-Control');

        return is_string($cacheControl)
            && str_contains($cacheControl, 'no-store');
    }

    /**
     * Fail with the safe response envelope when a route violates its contract.
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
            'Authentication API operation [%s] returned HTTP %d instead of %d: %s',
            $operation,
            $response->getStatusCode(),
            $expected,
            (string) $response->getContent(),
        ));
    }
}
