<?php

declare(strict_types=1);

namespace App\Auth\Invitations;

use App\Models\User;
use Illuminate\Support\Str;
use Nvl\Auth\Actions\Invitations\AcceptInvitationAction;
use Nvl\Auth\Actions\Invitations\CreateInvitationAction;
use Nvl\Auth\Data\Invitations\AcceptInvitationData;
use Nvl\Auth\Data\Invitations\CreateInvitationData;
use Nvl\Auth\Enums\ContactType;
use RuntimeException;

/**
 * Exercises the package reservation and consumer-owned invitation effect.
 */
final readonly class InvitationWorkflowProbe
{
    public function __construct(
        private CreateInvitationAction $createInvitation,
        private AcceptInvitationAction $acceptInvitation,
    ) {}

    /**
     * Create, deliver, provision, apply, and idempotently retry one invitation.
     */
    public function probe(User $administrator): InvitationWorkflowProbeResult
    {
        $operation = Str::lower((string) Str::uuid());
        $clientKey = 'auth-consumer-web';
        $created = $this->createInvitation->execute(
            actor: $administrator,
            data: new CreateInvitationData(
                contactType: ContactType::Email,
                destination: 'invited@auth-consumer.test',
                clientKey: $clientKey,
                correlationId: "invitation-{$operation}",
                idempotencyKey: "invitation-create-{$operation}",
                purpose: 'member',
                roles: ['member'],
                permissions: [],
                locale: 'en',
                recipientName: 'Invited Consumer Member',
                portable: true,
            ),
        );

        if ($created->validator === null) {
            throw new RuntimeException('A new invitation did not expose its one-time validator.');
        }

        $acceptanceData = new AcceptInvitationData(
            idempotencyKey: "invitation-accept-{$operation}",
            clientKey: $clientKey,
            correlationId: "invitation-acceptance-{$operation}",
            ipAddress: '192.0.2.80',
            userAgent: 'NVL Auth clean consumer probe',
        );
        $accepted = $this->acceptInvitation->execute(
            $created->validator,
            $acceptanceData,
        );
        $retried = $this->acceptInvitation->execute(
            $created->validator,
            $acceptanceData,
        );
        $user = User::query()->find($accepted->principal->subjectId);

        return new InvitationWorkflowProbeResult(
            invitationId: $accepted->invitation->invitationId,
            acceptanceId: $accepted->acceptanceId,
            principalId: $accepted->principal->id,
            deliveryScheduled: $created->message->deliveryIds !== [],
            principalProvisioned: $user instanceof User
                && $user->authPrincipal?->id === $accepted->principal->id,
            purposeApplied: $accepted->purposeResult->accepted
                && $user instanceof User
                && $user->hasRole('member'),
            retryIdempotent: $retried->alreadyAccepted
                && $retried->acceptanceId === $accepted->acceptanceId
                && $retried->principal->id === $accepted->principal->id,
        );
    }
}
