<?php

declare(strict_types=1);

namespace Nvl\Seo\Services;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Seo\Contracts\StructuredDataProvider;
use Nvl\Seo\Data\StructuredDataContextData;
use Nvl\Seo\Data\StructuredDataNodeData;

/**
 * Registers deterministic resource-to-JSON-LD provider extensions.
 */
final class StructuredDataRegistry
{
    /**
     * @var array<string, array{
     *     resource: class-string<Model>,
     *     provider: StructuredDataProvider,
     *     priority: int
     * }>
     */
    private array $providers = [];

    /**
     * Register one uniquely keyed resource provider.
     */
    public function register(
        string $key,
        string $resourceClass,
        StructuredDataProvider $provider,
        int $priority = 0,
    ): void {
        $key = trim($key);

        if ($key === '' || isset($this->providers[$key])) {
            throw new InvalidArgumentException(
                "Structured-data provider key [{$key}] is empty or duplicated.",
            );
        }

        if (! is_a($resourceClass, Model::class, true)) {
            throw new InvalidArgumentException(
                "Structured-data resource [{$resourceClass}] must be an Eloquent model.",
            );
        }

        $this->providers[$key] = [
            'resource' => $resourceClass,
            'provider' => $provider,
            'priority' => $priority,
        ];
    }

    /**
     * Resolve every applicable provider node in deterministic order.
     *
     * @return list<StructuredDataNodeData>
     */
    public function resolve(
        Model $resource,
        StructuredDataContextData $context,
    ): array {
        $providers = array_filter(
            $this->providers,
            static fn (array $entry): bool => $resource instanceof $entry['resource'],
        );

        uksort($providers, function (string $left, string $right) use ($providers): int {
            $priority = $providers[$left]['priority'] <=> $providers[$right]['priority'];

            return $priority !== 0 ? $priority : $left <=> $right;
        });

        $nodes = [];

        foreach ($providers as $key => $entry) {
            foreach ($this->validatedNodes(
                $entry['provider']->provide($resource, $context),
                $key,
            ) as $node) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * Return registered provider keys for diagnostics.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = array_keys($this->providers);
        sort($keys);

        return $keys;
    }

    /**
     * Enforce provider output types at the runtime extension boundary.
     *
     * @param  iterable<mixed>  $nodes
     * @return iterable<StructuredDataNodeData>
     */
    private function validatedNodes(iterable $nodes, string $key): iterable
    {
        foreach ($nodes as $node) {
            if (! $node instanceof StructuredDataNodeData) {
                throw new InvalidArgumentException(
                    "Structured-data provider [{$key}] returned an invalid node.",
                );
            }

            yield $node;
        }
    }
}
