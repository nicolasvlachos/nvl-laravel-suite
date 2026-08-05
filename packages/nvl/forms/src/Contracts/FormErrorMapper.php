<?php

declare(strict_types=1);

namespace Nvl\Forms\Contracts;

use Nvl\Forms\Models\Form;
use Nvl\Support\Exceptions\BusinessException;

/**
 * Contract for mapping business exceptions to form field errors.
 *
 * Modules can implement this interface to provide custom error mapping
 * for specific form handles without requiring the Forms module to know
 * about external module exceptions.
 */
interface FormErrorMapper
{
    /**
     * Map a business exception to form field errors.
     *
     * @param  Form  $form  The form that received the submission
     * @param  BusinessException  $exception  The business exception to map
     * @return array<string, mixed>|null Mapped errors or null if this mapper doesn't handle the exception
     */
    public function map(Form $form, BusinessException $exception): ?array;
}
