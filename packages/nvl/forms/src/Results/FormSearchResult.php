<?php

declare(strict_types=1);

namespace Nvl\Forms\Results;

use Illuminate\Support\Collection;
use Nvl\Forms\Models\Form;

/**
 * Value object bundling paginated form search data with total matches.
 *
 * @property-read Collection<int, Form> $forms
 */
final readonly class FormSearchResult
{
    /**
     * Create the form search result value object.
     *
     * @param  Collection<int, Form>  $forms
     * @param  int  $total  Total matching forms
     */
    public function __construct(
        public Collection $forms,
        public int $total,
    ) {}
}
