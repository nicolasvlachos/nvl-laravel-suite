<?php

declare(strict_types=1);

namespace Nvl\Forms\Definitions\Tables;

/**
 * Table name constants for the Forms module.
 */
class FormsTables
{
    public const string Forms = 'forms';

    public const string I18n = 'forms_i18n';

    public const string Entries = 'form_entries';

    public const string SubmissionReceipts = 'form_submission_receipts';

    public const string AllowedOrigins = 'form_allowed_origins';

    public const string Analytics = 'form_analytics';

    public const string RateLimits = 'form_rate_limits';

    public const string FORMS = self::Forms;

    public const string FORM_I18N = self::I18n;

    public const string FORM_ENTRIES = self::Entries;

    public const string FORM_SUBMISSION_RECEIPTS = self::SubmissionReceipts;

    public const string ALLOWED_ORIGINS = self::AllowedOrigins;

    public const string FORM_ANALYTICS = self::Analytics;

    public const string FORM_RATE_LIMITS = self::RateLimits;
}
