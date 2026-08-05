<?php

declare(strict_types=1);

namespace Nvl\Forms\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\Forms\Models\AllowedOrigin;
use Nvl\Forms\Models\Form;

/**
 * @extends Factory<AllowedOrigin>
 */
final class AllowedOriginFactory extends Factory
{
    protected $model = AllowedOrigin::class;

    /**
     * Define the model's default state.
     *
     * @return array<model-property<AllowedOrigin>, mixed>
     */
    public function definition(): array
    {
        return [
            'form_id' => Form::factory(),
            'origin' => $this->faker->unique()->domainName(),
            'is_active' => true,
            'description' => $this->faker->optional()->sentence(),
            'cors_settings' => null,
            'usage_count' => 0,
            'last_used_at' => null,
        ];
    }
}
