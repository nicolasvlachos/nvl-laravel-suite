<?php

declare(strict_types=1);

namespace Nvl\Forms\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\Forms\Enums\FormStatus;
use Nvl\Forms\Enums\FormType;
use Nvl\Forms\Enums\Resolvement;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormTranslation;

/**
 * @extends Factory<Form>
 */
final class FormFactory extends Factory
{
    protected $model = Form::class;

    /**
     * Keep localized test copy out of the structural form row.
     */
    public function configure(): static
    {
        return $this
            ->afterMaking(function (Form $form): void {
                $name = $form->getAttribute('name');
                $description = $form->getAttribute('description');
                $form->setRelation('__factory_localized_copy', [
                    'name' => is_string($name) && $name !== ''
                        ? $name
                        : fake()->sentence(3),
                    'description' => is_string($description)
                        ? $description
                        : fake()->optional()->sentence(),
                    'translations' => $form->getAttribute('translations'),
                ]);
                $form->offsetUnset('name');
                $form->offsetUnset('description');
                $form->offsetUnset('translations');
            })
            ->afterCreating(function (Form $form): void {
                $copy = $form->getRelation('__factory_localized_copy');
                $form->unsetRelation('__factory_localized_copy');

                if (! is_array($copy)) {
                    return;
                }

                $translations = is_array($copy['translations'] ?? null)
                    ? $copy['translations']
                    : [];
                $configuredLocale = config('app.locale', 'en');
                $locale = is_string($configuredLocale) ? $configuredLocale : 'en';

                if (! isset($translations[$locale])) {
                    $translations[$locale] = [
                        'name' => $copy['name'] ?? null,
                        'description' => $copy['description'] ?? null,
                    ];
                }

                foreach ($translations as $translationLocale => $payload) {
                    if (! is_string($translationLocale) || ! is_array($payload)) {
                        continue;
                    }

                    FormTranslation::query()->create([
                        'form_id' => $form->getKey(),
                        'locale' => $translationLocale,
                        'name' => is_string($payload['name'] ?? null)
                            ? $payload['name']
                            : (is_string($payload['title'] ?? null) ? $payload['title'] : null),
                        'description' => is_string($payload['description'] ?? null)
                            ? $payload['description']
                            : null,
                        'content' => $payload,
                    ]);
                }

                $form->load('translations');
            });
    }

    /**
     * Define the model's default state.
     *
     * @return array<model-property<Form>, mixed>
     */
    public function definition(): array
    {
        return [
            'handle' => $this->faker->unique()->slug(3),
            'status' => FormStatus::ACTIVE,
            'resolvement' => Resolvement::ENTRIES,
            'type' => FormType::LANDING_PAGE,
            'restrict_public_access' => $this->faker->boolean(),
            'allow_multiple_registrations' => true,
            'date_restricted' => false,
            'available_from' => null,
            'available_until' => null,
            'submissions_count' => 0,
            'views_count' => 0,
            'spam_count' => 0,
            'last_used_at' => null,
            'first_used_at' => null,
            'enable_honeypot' => true,
            'enable_rate_limiting' => true,
            'rate_limit_per_hour' => 10,
            'require_csrf' => true,
            'cors_settings' => null,
        ];
    }
}
