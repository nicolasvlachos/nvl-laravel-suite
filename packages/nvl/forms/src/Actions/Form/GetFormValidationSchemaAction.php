<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\Form;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Nvl\Forms\Data\Display\PublicFormSchemaPayload;
use Nvl\Forms\Data\Display\PublicSubmissionFieldPayload;
use Nvl\Forms\Data\Mutations\SubmitFormPayload;
use Nvl\Forms\Models\Form;
use Stringable;

/**
 * Builds JSON-safe client validation metadata for the submission envelope.
 */
final class GetFormValidationSchemaAction
{
    /**
     * Execute the validation schema generation.
     *
     * @param  string  $formIdentifier  Form ID or handle
     * @return PublicFormSchemaPayload Public validation schema
     */
    public function execute(string $formIdentifier): PublicFormSchemaPayload
    {
        $query = Form::query()->where('handle', $formIdentifier);

        if (Str::isUuid($formIdentifier)) {
            $query->orWhere('id', $formIdentifier);
        }

        $form = $query->firstOrFail();

        $rules = SubmitFormPayload::rules();

        $fields = [];
        $normalizedRules = [];

        foreach ($rules as $field => $fieldRules) {
            if (is_string($fieldRules)) {
                $fieldRules = explode('|', $fieldRules);
            }

            $normalizedRules[$field] = array_values(
                array_map($this->normalizeRule(...), $fieldRules),
            );

            if (! str_contains($field, '.')) {
                $fields[] = new PublicSubmissionFieldPayload(
                    key: $field,
                    required: in_array('required', $fieldRules, true),
                    rules: $normalizedRules[$field],
                );
            }
        }

        return new PublicFormSchemaPayload(
            formId: $form->id,
            name: $form->displayName(),
            fields: $fields,
            validationRules: $normalizedRules,
            messages: SubmitFormPayload::messages(),
            attributes: SubmitFormPayload::attributes(),
        );
    }

    /**
     * Convert one Laravel validation rule to a stable client representation.
     */
    private function normalizeRule(mixed $rule): string
    {
        if (is_string($rule)) {
            return $rule;
        }

        if ($rule instanceof Stringable) {
            return (string) $rule;
        }

        if ($rule instanceof ValidationRule || is_object($rule)) {
            return 'custom:'.class_basename($rule);
        }

        return 'unsupported:'.get_debug_type($rule);
    }
}
