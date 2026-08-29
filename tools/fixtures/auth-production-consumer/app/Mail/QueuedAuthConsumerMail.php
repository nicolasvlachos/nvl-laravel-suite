<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Nvl\MailNotifications\Contracts\TrackableMessage;
use Nvl\MailNotifications\Laravel\Concerns\TracksMailDelivery;
use Nvl\MailNotifications\ValueObjects\TrackingContext;

/** A serializable opt-in Mailable that proves the database queue boundary. */
final class QueuedAuthConsumerMail extends Mailable implements ShouldQueue, TrackableMessage
{
    use Queueable;
    use SerializesModels;
    use TracksMailDelivery;

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Queued Auth consumer proof');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.auth-consumer');
    }

    public function trackingContext(): TrackingContext
    {
        return TrackingContext::forCategory('auth.consumer.queued');
    }
}
