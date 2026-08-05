<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\Form;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Forms\Data\FormPayload;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Services\FormShowStateResolver;

/**
 * Orchestrates canonical form retrieval with show-state projection.
 *
 * The action composition preserves one display-loading and optional
 * view-recording path before transport-safe state is derived.
 */
final class BuildFormShowPayloadAction
{
    /**
     * @param  ShowFormAction  $show  Form show/read action
     * @param  FormShowStateResolver  $stateResolver  Derived state resolver
     */
    public function __construct(
        private readonly ShowFormAction $show,
        private readonly FormShowStateResolver $stateResolver,
    ) {}

    /**
     * Build show-page payload with DTO form data and derived display states.
     *
     * @return array{
     *     form: FormPayload,
     *     states: array<string, mixed>
     * }
     */
    public function execute(
        Form|string $form,
        bool $recordView = false,
        ?string $origin = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $sessionId = null,
        ?Authenticatable $actor = null,
    ): array {
        $formModel = $this->show->execute(
            $form,
            $recordView,
            $origin,
            $ipAddress,
            $userAgent,
            $sessionId,
            $actor,
        );

        $states = $this->stateResolver->resolve($formModel);

        return [
            'form' => FormPayload::fromModel($formModel),
            'states' => [
                'status' => $states->status->toArray(),
                'security' => $states->security->toArray(),
                'links' => $states->links->toArray(),
                'stats' => $states->stats->toArray(),
            ],
        ];
    }
}
