<?php

declare(strict_types=1);

namespace Nvl\Primitives\Data;

use Nvl\Data\Traits\DataTransform;
use Nvl\Primitives\ValueObjects\Money;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Stable API and TypeScript representation of exact money.
 */
#[TypeScript]
final class MoneyData extends Data
{
    use DataTransform;

    /**
     * Create an API money payload with both display and storage amounts.
     */
    public function __construct(
        public readonly string $amount,
        public readonly string $minor,
        public readonly string $currency,
    ) {}

    /**
     * Create an API payload from exact money.
     */
    public static function fromMoney(Money $money): self
    {
        return new self(
            amount: $money->amount(),
            minor: $money->minorAmount(),
            currency: (string) $money->currency(),
        );
    }
}
