<?php

declare(strict_types=1);

namespace Nvl\Forms\Services;

use Closure;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Nvl\Forms\Contracts\CustomFormHandler;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Support\FormHandlerRegistry;

final readonly class CustomFormRegistry
{
    /**
     * @param  Container  $container  IoC container instance
     * @param  FormHandlerRegistry  $registry  Handler registry instance
     */
    public function __construct(
        private Container $container,
        private FormHandlerRegistry $registry,
    ) {}

    /**
     * @throws BindingResolutionException
     */
    public function resolve(Form $form): ?CustomFormHandler
    {
        $entry = $this->registry->get($form->handle);
        if ($entry === null) {
            return null;
        }

        // Allow class-string
        if (is_string($entry)) {
            $obj = $this->container->make($entry);
            if (! is_object($obj)) {
                return null;
            }

            if ($obj instanceof CustomFormHandler) {
                return $obj;
            }
            if (is_callable([$obj, 'execute'])) {
                return new ActionBackedHandler(
                    $obj,
                    'execute',
                );
            }
            if (is_callable([$obj, '__invoke'])) {
                return new ActionBackedHandler(
                    $obj,
                    '__invoke',
                );
            }

            return null;
        }

        // Allow dev callbacks (not suitable for config:cache)
        if (is_callable($entry)) {
            $callback = Closure::fromCallable($entry);

            return new CallbackFormHandler(
                /**
                 * @param  array<string, mixed>  $data
                 * @return array<string, mixed>
                 */
                static function (Form $form, array $data, Request $request) use ($callback): array {
                    $result = $callback($form, $data, $request);
                    if (! is_array($result)) {
                        return [];
                    }

                    $normalized = [];
                    foreach ($result as $key => $value) {
                        if (is_string($key)) {
                            $normalized[$key] = $value;
                        }
                    }

                    return $normalized;
                }
            );
        }

        return null;
    }
}
