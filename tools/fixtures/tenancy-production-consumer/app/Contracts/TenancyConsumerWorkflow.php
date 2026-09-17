<?php

declare(strict_types=1);

namespace App\Contracts;

/** Runs one profile of the sealed tenancy production-consumer proof. */
interface TenancyConsumerWorkflow
{
    /**
     * Execute one bounded seed or verification phase.
     *
     * @return array{passed: bool, checks: array<string, bool>, profile: string}
     */
    public function execute(string $phase): array;
}
