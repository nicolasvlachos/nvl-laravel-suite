<?php

declare(strict_types=1);

namespace App\Auth\Clients;

use App\Auth\Http\SyntheticHttpProbe;
use Illuminate\Support\Str;
use JsonException;
use Nvl\Auth\ValueObjects\SecretValue;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drives registered-client management and hosted-flow start through HTTP.
 */
final readonly class AuthClientApiProbe
{
    /**
     * Create the registered-client integration probe.
     */
    public function __construct(private SyntheticHttpProbe $http) {}

    /**
     * Exercise client CRUD, admission status, and hosted start preparation.
     *
     * @throws JsonException
     */
    public function probe(): AuthClientApiProbeResult
    {
        $this->http->useBrowser('administrator');
        $csrfToken = $this->http->csrfToken();
        $surfaceKey = 'consumer-'.Str::lower(Str::random(12));
        $origin = 'https://app.auth-consumer.test';
        $configuration = [
            'name' => 'Auth Consumer Hosted Web',
            'surfaceKey' => $surfaceKey,
            'baseUrl' => $origin.'/auth',
            'defaultReturnPath' => '/dashboard',
            'allowedOrigins' => [$origin],
            'allowedReturnPaths' => ['/dashboard', '/settings'],
        ];
        $statuses = [];
        $created = $this->http->dispatch(
            'POST',
            '/api/v1/auth/management/clients',
            $configuration,
            $csrfToken,
        );
        $this->assertStatus($created, Response::HTTP_CREATED, 'clients.store');
        $statuses['clients.store'] = $created->getStatusCode();
        $projectionId = $this->stringValue(
            $created,
            'data.client.id',
            'registered client projection identifier',
        );
        $clientId = $this->stringValue(
            $created,
            'data.client.clientId',
            'registered client identifier',
        );

        $indexed = $this->http->dispatch(
            'GET',
            '/api/v1/auth/management/clients?surfaceKey='.$surfaceKey,
            [],
            $csrfToken,
        );
        $this->assertStatus($indexed, Response::HTTP_OK, 'clients.index');
        $statuses['clients.index'] = $indexed->getStatusCode();

        $shown = $this->http->dispatch(
            'GET',
            '/api/v1/auth/management/clients/'.$projectionId,
            [],
            $csrfToken,
        );
        $this->assertStatus($shown, Response::HTTP_OK, 'clients.show');
        $statuses['clients.show'] = $shown->getStatusCode();

        $updated = $this->http->dispatch(
            'PUT',
            '/api/v1/auth/management/clients/'.$projectionId,
            [
                ...$configuration,
                'name' => 'Auth Consumer Hosted Web Updated',
                'baseUrl' => $origin.'/authentication',
            ],
            $csrfToken,
        );
        $this->assertStatus($updated, Response::HTTP_OK, 'clients.update');
        $statuses['clients.update'] = $updated->getStatusCode();

        $deactivated = $this->http->dispatch(
            'PATCH',
            '/api/v1/auth/management/clients/'.$projectionId.'/status',
            ['isActive' => false],
            $csrfToken,
        );
        $this->assertStatus($deactivated, Response::HTTP_OK, 'clients.status.deactivate');
        $statuses['clients.status.deactivate'] = $deactivated->getStatusCode();

        $binding = new SecretValue(str_repeat('b', 64));
        $inactiveStart = $this->start(
            $clientId,
            $origin,
            $binding,
            $csrfToken,
        );
        $this->assertStatus($inactiveStart, Response::HTTP_NOT_FOUND, 'clients.start.inactive');
        $statuses['clients.start.inactive'] = $inactiveStart->getStatusCode();

        $activated = $this->http->dispatch(
            'PATCH',
            '/api/v1/auth/management/clients/'.$projectionId.'/status',
            ['isActive' => true],
            $csrfToken,
        );
        $this->assertStatus($activated, Response::HTTP_OK, 'clients.status.activate');
        $statuses['clients.status.activate'] = $activated->getStatusCode();

        $started = $this->start(
            $clientId,
            $origin,
            $binding,
            $csrfToken,
        );
        $this->assertStatus($started, Response::HTTP_CREATED, 'clients.start');
        $statuses['clients.start'] = $started->getStatusCode();

        return new AuthClientApiProbeResult(
            projectionId: $projectionId,
            clientId: $clientId,
            origin: $origin,
            binding: $binding,
            clientGrant: new SecretValue($this->stringValue(
                $started,
                'data.clientGrant',
                'hosted client grant',
            )),
            oneTimeMaterialProtected: $this->noStore($started),
            statuses: $statuses,
        );
    }

    /**
     * Retire the client after its hosted-flow grant has been consumed.
     *
     * @throws JsonException
     */
    public function cleanup(
        AuthClientApiProbeResult $result,
    ): AuthClientApiProbeResult {
        $this->http->useBrowser('administrator');
        $csrfToken = $this->http->csrfToken();
        $destroyed = $this->http->dispatch(
            'DELETE',
            '/api/v1/auth/management/clients/'.$result->projectionId,
            [],
            $csrfToken,
        );
        $this->assertStatus($destroyed, Response::HTTP_OK, 'clients.destroy');
        $missing = $this->http->dispatch(
            'GET',
            '/api/v1/auth/management/clients/'.$result->projectionId,
            [],
            $csrfToken,
        );
        $this->assertStatus($missing, Response::HTTP_NOT_FOUND, 'clients.show.deleted');

        return $result->withCleanupEvidence(
            destroyStatus: $destroyed->getStatusCode(),
            deletedShowStatus: $missing->getStatusCode(),
        );
    }

    /**
     * Request one browser-bound hosted-client continuation.
     */
    private function start(
        string $clientId,
        string $origin,
        SecretValue $binding,
        string $csrfToken,
    ): Response {
        return $this->http->dispatch(
            'POST',
            '/api/v1/auth/clients/start',
            [
                'clientId' => $clientId,
                'returnPath' => '/dashboard',
            ],
            $csrfToken,
            [
                'Origin' => $origin,
                'X-NVL-Auth-Binding' => $binding->reveal(),
            ],
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
            'Client API operation [%s] returned HTTP %d instead of %d: %s',
            $operation,
            $response->getStatusCode(),
            $expected,
            (string) $response->getContent(),
        ));
    }
}
