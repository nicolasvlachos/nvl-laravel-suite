<?php

declare(strict_types=1);

namespace Nvl\Forms\Services;

use Illuminate\Http\Request;
use LogicException;
use Nvl\Forms\Contracts\CustomFormHandler;
use Nvl\Forms\Models\Form;

/**
 * Action-backed custom handler adapter for form submissions.
 */
final class ActionBackedHandler implements CustomFormHandler
{
    /**
     * Create the handler adapter.
     *
     * @param  object  $action  Action instance to call
     * @param  string  $method  Method name to invoke on the action
     */
    public function __construct(
        private readonly object $action,
        private readonly string $method,
    ) {}

    /**
     * Invoke the action handler for a submission.
     *
     * @param  Form  $form  Form model instance
     * @param  array<string, mixed>  $data  Submission payload
     * @param  Request  $request  HTTP request instance
     * @return array<string, mixed> Handler response payload
     */
    public function handle(Form $form, array $data, Request $request): array
    {
        if (! is_callable([$this->action, $this->method])) {
            throw new LogicException(
                sprintf('Configured form action [%s::%s] is not callable.', $this->action::class, $this->method),
            );
        }

        $result = $this->action->{$this->method}($form, $data, $request);

        if (! is_array($result)) {
            throw new LogicException(
                sprintf('Configured form action [%s::%s] must return an array.', $this->action::class, $this->method),
            );
        }

        /** @var array<string, mixed> $result */
        return $result;
    }
}
