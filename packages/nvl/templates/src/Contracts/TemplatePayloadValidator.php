<?php

declare(strict_types=1);

namespace Nvl\Templates\Contracts;

/**
 * Validates source-controlled payload schemas and concrete render payloads.
 */
interface TemplatePayloadValidator
{
    /**
     * @param  array<string, mixed>  $schema
     */
    public function validateSchema(array $schema): void;

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $payload
     */
    public function validate(array $schema, array $payload): void;
}
