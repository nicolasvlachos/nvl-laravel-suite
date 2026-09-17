<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** Host-owned mail rebuilt from the durable scheduled-mail payload. */
final class ConsumerPublicationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $tenantLabel) {}

    /** Build a deliberately plain message so the fixture owns no package view. */
    public function build(): self
    {
        return $this->subject("Publication ready for tenant {$this->tenantLabel}")
            ->html("<p>Publication ready for tenant {$this->tenantLabel}</p>");
    }
}
