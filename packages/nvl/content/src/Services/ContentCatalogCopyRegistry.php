<?php
declare(strict_types=1);
namespace Nvl\Content\Services;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Nvl\Content\Contracts\ContentCatalogCopyAccess;
final class ContentCatalogCopyRegistry
{
    /** @var array<string,class-string<ContentCatalogCopyAccess>> */
    private array $access = [];
    public function __construct(private readonly Container $container) {}
    public function register(string $ownerAlias, string $accessClass): void
    {
        if ($ownerAlias === '' || ! is_a($accessClass, ContentCatalogCopyAccess::class, true) || (isset($this->access[$ownerAlias]) && $this->access[$ownerAlias] !== $accessClass)) {
            throw new InvalidArgumentException('Content catalog copy registration is invalid.');
        }
        $this->access[$ownerAlias] = $accessClass;
    }
    public function resolve(string $ownerAlias): ContentCatalogCopyAccess
    {
        $class = $this->access[$ownerAlias] ?? throw new InvalidArgumentException("Content owner [{$ownerAlias}] has no catalog copy authorizer.");
        $access = $this->container->make($class);
        if (! $access instanceof ContentCatalogCopyAccess) {
            throw new InvalidArgumentException('Content catalog copy authorizer resolved incorrectly.');
        }
        return $access;
    }
}
