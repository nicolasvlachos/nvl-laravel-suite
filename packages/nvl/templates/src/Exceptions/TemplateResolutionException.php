<?php

declare(strict_types=1);

namespace Nvl\Templates\Exceptions;

/**
 * Raised when no safe published template version can satisfy a render.
 */
final class TemplateResolutionException extends TemplatesException {}
