<?php

declare(strict_types=1);

namespace Nvl\Forms\Definitions\Tables;

/**
 * Table name constants for the Forms module.
 */
class FormsTables
{
    public const string FORMS = 'forms';

    public const string FORM_I18N = 'forms_i18n';

    public const string FORM_ENTRIES = 'form_entries';

    public const string FORM_SUBMISSION_RECEIPTS = 'form_submission_receipts';

    public const string ALLOWED_ORIGINS = 'form_allowed_origins';

    public const string FORM_ANALYTICS = 'form_analytics';

    public const string FORM_RATE_LIMITS = 'form_rate_limits';
}
