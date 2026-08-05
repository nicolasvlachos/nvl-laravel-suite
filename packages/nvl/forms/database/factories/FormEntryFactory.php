<?php

declare(strict_types=1);

namespace Nvl\Forms\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormEntry;

/**
 * @extends Factory<FormEntry>
 */
final class FormEntryFactory extends Factory
{
    protected $model = FormEntry::class;

    /**
     * Define the model's default state.
     *
     * @return array<model-property<FormEntry>, mixed>
     */
    public function definition(): array
    {
        return [
            'form_id' => Form::factory(),
            'subject' => $this->faker->optional()->sentence(),
            'email' => $this->faker->optional()->safeEmail(),
            'first_name' => $this->faker->optional()->firstName(),
            'last_name' => $this->faker->optional()->lastName(),
            'phone' => $this->faker->optional()->phoneNumber(),
            'address' => $this->faker->optional()->address(),
            'body' => $this->faker->optional()->paragraph(),
            'submission_data' => ['message' => $this->faker->sentence()],
            'submitted_from' => $this->faker->domainName(),
            'ip_address' => $this->faker->ipv4(),
            'user_agent' => $this->faker->userAgent(),
            'session_id' => $this->faker->uuid(),
            'is_spam' => false,
            'spam_score' => null,
            'security_flags' => null,
        ];
    }
}
