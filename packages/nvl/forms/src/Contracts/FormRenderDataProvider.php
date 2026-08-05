<?php

declare(strict_types=1);

namespace Nvl\Forms\Contracts;

use Illuminate\Http\Request;
use Nvl\Forms\Models\Form;

/**
 * Contract for providing additional render-time data for specific form handles.
 *
 * Modules can implement this interface to inject custom data into form pages
 * without requiring Forms to know about external application packages.
 */
interface FormRenderDataProvider
{
    /**
     * Get additional data to include in the form render payload.
     *
     * @param  Form  $form  The form being rendered
     * @param  Request  $request  Current HTTP request
     * @return array<string, mixed> Additional data to merge into page props
     */
    public function getData(Form $form, Request $request): array;

    /**
     * Get additional translations to include for the form.
     *
     * @param  Form  $form  The form being rendered
     * @return array<string, mixed> Translation data to merge into page translations
     */
    public function getTranslations(Form $form): array;
}
