<?php

declare(strict_types=1);

namespace App\Auth;

/** Executes the active sealed Auth consumer profile. */
interface AuthConsumerSmoke
{
    /**
     * Execute the profile and return a transport-safe summary.
     *
     * @return array<string, int|string|bool>
     */
    public function execute(bool $verifyQueuedMail): array;
}
