<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use InvalidArgumentException;
use Nvl\MailNotifications\Contracts\ScheduledMessageFactory;
use Nvl\MailNotifications\ValueObjects\ScheduledMessageData;

/** Rebuilds the consumer publication message from its stable payload. */
final class ConsumerScheduledMessageFactory implements ScheduledMessageFactory
{
    public function alias(): string
    {
        return 'consumer.publication';
    }

    public function supportsVersion(int $version): bool
    {
        return $version === 1;
    }

    /** @param array<string, mixed> $payload */
    public function validate(int $version, array $payload): void
    {
        if (! $this->supportsVersion($version)
            || ! is_string($payload['tenant_label'] ?? null)
            || trim($payload['tenant_label']) === '') {
            throw new InvalidArgumentException('The publication mail payload is invalid.');
        }
    }

    public function make(ScheduledMessageData $message): Mailable
    {
        $this->validate($message->payloadVersion, $message->payload);
        $label = $message->payload['tenant_label'] ?? null;
        if (! is_string($label)) {
            throw new InvalidArgumentException('The publication mail payload is invalid.');
        }

        return new ConsumerPublicationMail($label);
    }
}
