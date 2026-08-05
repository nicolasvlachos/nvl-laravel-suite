<?php

declare(strict_types=1);

namespace App\Auth\Management;

use App\Auth\Http\SyntheticHttpProbe;
use App\Models\User;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drives every host management endpoint through the real Laravel HTTP stack.
 */
final readonly class ManagementApiProbe
{
    /**
     * Create the host management HTTP probe.
     */
    public function __construct(private SyntheticHttpProbe $http) {}

    /**
     * Exercise authentication, authorization, validation, routing, resources, and persistence.
     *
     * @throws JsonException
     */
    public function probe(User $administrator): ManagementApiProbeResult
    {
        $this->http->useBrowser('administrator');
        $unauthenticated = $this->http->dispatch(
            'GET',
            '/api/v1/auth/management/users',
        );
        $this->assertStatus($unauthenticated, Response::HTTP_UNAUTHORIZED, 'unauthenticated');

        $bootstrapToken = $this->http->csrfToken();
        $login = $this->http->dispatch(
            'POST',
            '/auth-consumer/session',
            [
                'email' => $administrator->email,
                'password' => 'AdministratorPassphrase!123',
            ],
            $bootstrapToken,
        );
        $this->assertStatus($login, Response::HTTP_OK, 'session.store');
        $csrfToken = $this->http->csrfToken();
        $email = 'api-probe+'.Str::lower((string) Str::uuid()).'@auth-consumer.test';
        $statuses = [];

        $statuses['roles.index'] = $this->successfulStatus(
            'GET',
            '/api/v1/auth/management/roles',
            [],
            $csrfToken,
        );
        $statuses['permissions.index'] = $this->successfulStatus(
            'GET',
            '/api/v1/auth/management/permissions',
            [],
            $csrfToken,
        );
        $statuses['access_catalog.synchronize'] = $this->successfulStatus(
            'POST',
            '/api/v1/auth/management/access-catalog/synchronize',
            [],
            $csrfToken,
        );
        $created = $this->http->dispatch(
            'POST',
            '/api/v1/auth/management/users',
            [
                'name' => 'Management API Probe',
                'email' => $email,
                'password' => 'ManagementProbePassphrase!123',
                'roles' => ['member'],
                'permissions' => [],
            ],
            $csrfToken,
        );
        $this->assertStatus($created, Response::HTTP_CREATED, 'users.store');
        $statuses['users.store'] = $created->getStatusCode();
        $userId = $this->createdUserId($created);
        $createdSecurityVersion = $this->integerValue(
            $created,
            'data.principal.securityVersion',
            'created principal security version',
        );

        $statuses['users.index'] = $this->successfulStatus(
            'GET',
            '/api/v1/auth/management/users',
            [],
            $csrfToken,
        );
        $statuses['users.show'] = $this->successfulStatus(
            'GET',
            "/api/v1/auth/management/users/{$userId}",
            [],
            $csrfToken,
        );
        $updated = $this->http->dispatch(
            'PATCH',
            "/api/v1/auth/management/users/{$userId}",
            [
                'name' => 'Updated Management API Probe',
                'password' => 'ManagementProbeUpdatedPassphrase!123',
            ],
            $csrfToken,
        );
        $this->assertStatus($updated, Response::HTTP_OK, 'users.update');
        $statuses['users.update'] = $updated->getStatusCode();
        $updatedSecurityVersion = $this->integerValue(
            $updated,
            'data.principal.securityVersion',
            'updated principal security version',
        );
        $statuses['users.access'] = $this->successfulStatus(
            'PUT',
            "/api/v1/auth/management/users/{$userId}/access",
            ['roles' => ['member'], 'permissions' => []],
            $csrfToken,
        );
        $deleted = $this->http->dispatch(
            'DELETE',
            "/api/v1/auth/management/users/{$userId}",
            [],
            $csrfToken,
        );
        $this->assertStatus($deleted, Response::HTTP_NO_CONTENT, 'users.destroy');
        $statuses['users.destroy'] = $deleted->getStatusCode();

        return new ManagementApiProbeResult(
            authorizationProtected: true,
            passwordChangeInvalidatedTrust: $updatedSecurityVersion
                === $createdSecurityVersion + 1,
            statuses: $statuses,
        );
    }

    /**
     * Dispatch one request and require a successful JSON response.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function successfulStatus(
        string $method,
        string $uri,
        array $parameters,
        string $csrfToken,
    ): int {
        $response = $this->http->dispatch(
            $method,
            $uri,
            $parameters,
            $csrfToken,
        );
        $this->assertStatus($response, Response::HTTP_OK, $uri);

        return $response->getStatusCode();
    }

    /**
     * Read the created host user identifier from its resource response.
     *
     * @throws JsonException
     */
    private function createdUserId(Response $response): string
    {
        $payload = json_decode(
            (string) $response->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $identifier = is_array($payload)
            ? data_get($payload, 'data.id')
            : null;

        if (! is_int($identifier) && ! is_string($identifier)) {
            throw new RuntimeException('The management API did not return a user identifier.');
        }

        return (string) $identifier;
    }

    /**
     * Read one required integer from a management resource response.
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
            throw new RuntimeException("The management API did not return the {$label}.");
        }

        return $value;
    }

    /**
     * Fail with the response body when a route violates its contract.
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
            'Management API operation [%s] returned HTTP %d instead of %d: %s',
            $operation,
            $response->getStatusCode(),
            $expected,
            (string) $response->getContent(),
        ));
    }
}
