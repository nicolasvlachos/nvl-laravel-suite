<?php

declare(strict_types=1);

namespace Nvl\Forms\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\Forms\Enums\FormSubmissionReceiptState;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormSubmissionReceipt;

/**
 * @extends Factory<FormSubmissionReceipt>
 */
final class FormSubmissionReceiptFactory extends Factory
{
    protected $model = FormSubmissionReceipt::class;

    /**
     * @return array<model-property<FormSubmissionReceipt>, mixed>
     */
    public function definition(): array
    {
        return [
            'form_id' => Form::factory(),
            'idempotency_key' => null,
            'payload_digest' => hash('sha256', $this->faker->uuid()),
            'registration_fingerprint' => null,
            'state' => FormSubmissionReceiptState::Completed,
            'result_id' => $this->faker->uuid(),
        ];
    }
}
