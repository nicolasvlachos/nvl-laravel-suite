<?php

declare(strict_types=1);

namespace Nvl\Forms\Services;

use Closure;
use Illuminate\Http\Request;
use Nvl\Forms\Contracts\CustomFormHandler;
use Nvl\Forms\Models\Form;

final class CallbackFormHandler implements CustomFormHandler
{
    /** @var Closure(Form,array<string,mixed>,Request):array<string,mixed> */
    private Closure $callback;

    /**
     * @param  Closure(Form,array<string,mixed>,Request):array<string,mixed>  $callback
     */
    public function __construct(Closure $callback)
    {
        $this->callback = $callback;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function handle(Form $form, array $data, Request $request): array
    {
        $cb = $this->callback;

        /** @var array<string,mixed> $resp */
        $resp = $cb($form, $data, $request);

        return $resp;
    }
}
