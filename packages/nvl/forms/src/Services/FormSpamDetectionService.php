<?php

declare(strict_types=1);

namespace Nvl\Forms\Services;

use Nvl\Forms\Contracts\FormSpamDetector;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormRateLimit;

/**
 * Stateless service for evaluating spam signals in form submissions.
 *
 * Combines config-driven heuristics (honeypot, link density, punctuation,
 * spam phrases) with content analysis (text patterns, email domain reputation,
 * user agent inspection) and IP reputation from rate-limit history. Timing is
 * only scored when a trusted server-issued load timestamp is provided.
 *
 * All scoring weights and thresholds are driven by the `forms.security.spam_protection` config.
 */
final class FormSpamDetectionService implements FormSpamDetector
{
    /** @var array<int, string> Spam keywords to detect in text fields */
    private const SPAM_KEYWORDS = [
        'viagra', 'casino', 'loan', 'mortgage', 'bitcoin',
        'crypto', 'investment', 'lottery',
    ];

    /** @var array<int, string> Disposable email domain fragments */
    private const SUSPICIOUS_EMAIL_DOMAINS = [
        'tempmail', '10minutemail', 'guerrillamail', 'mailinator',
    ];

    /** @var array<int, string> Bot-like user agent patterns */
    private const SUSPICIOUS_UA_PATTERNS = [
        'bot', 'crawler', 'spider', 'scraper', 'python', 'curl',
    ];

    /**
     * Check if honeypot was triggered in submission data.
     *
     * Honeypot fields are hidden from human users but filled by automated bots.
     * Returns false immediately when honeypot protection is disabled on the form.
     *
     * @param  Form  $form  The form to check
     * @param  array<string, mixed>  $data  Submission data containing field values
     * @return bool True when a honeypot field contains a non-empty value
     */
    public function checkHoneypot(Form $form, array $data): bool
    {
        if (! $form->enable_honeypot) {
            return false;
        }

        /** @var array<int, string> $honeypotFields */
        $honeypotFields = config('forms.security.spam_protection.honeypot.field_names', []);

        return array_any($honeypotFields, fn ($field) => isset($data[$field]) && $data[$field] !== '');
    }

    /**
     * Calculate a composite spam score using all available heuristic factors.
     *
     * Evaluates in order: honeypot, optional trusted submission timing, text
     * patterns (links, punctuation, spam phrases, keywords, caps ratio), email
     * domain reputation, user agent bot detection, rapid IP submissions, and IP
     * violation history. Final score is capped at 100.
     *
     * @param  Form  $form  The form being submitted
     * @param  array<string, mixed>  $data  Submission data to evaluate
     * @param  string  $ipAddress  Submitter IP address for reputation lookup
     * @param  string|null  $userAgent  Request user agent for bot detection
     * @param  FormRateLimit|null  $rateLimit  Pre-loaded rate limit record to avoid redundant queries
     * @param  float|null  $formLoadTime  Timestamp when the form was loaded (microtime)
     * @return float Spam score in the range 0-100
     */
    public function calculateSpamScore(
        Form $form,
        array $data,
        string $ipAddress,
        ?string $userAgent = null,
        ?FormRateLimit $rateLimit = null,
        ?float $formLoadTime = null,
    ): float {
        /** @var array<string, int> $weights */
        $weights = config('forms.security.spam_protection.score_weights', []);
        /** @var int $minSubmissionTime */
        $minSubmissionTime = config('forms.security.spam_protection.min_submission_time', 3);
        /** @var array<int, string> $spamPhrases */
        $spamPhrases = config('forms.security.spam_protection.spam_phrases', []);

        $score = 0;

        // Honeypot check (+100 by default)
        if ($this->checkHoneypot($form, $data)) {
            $score += $weights['honeypot'] ?? 100;
        }

        // Submission speed check — too fast indicates automation
        if ($formLoadTime !== null) {
            $timeSpent = microtime(true) - $formLoadTime;
            if ($timeSpent < $minSubmissionTime) {
                $score += $weights['fast_submission'] ?? 50;
            }
        }

        // Content-based analysis of all string fields
        foreach ($data as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            if ($key === 'email') {
                $score += $this->analyzeEmailForSpam($value);

                continue;
            }

            $score += $this->scoreTextContent($value, $weights, $spamPhrases);
        }

        // User agent bot detection
        if ($this->isSuspiciousUserAgent($userAgent)) {
            $score += $weights['suspicious_user_agent'] ?? 25;
        }

        // IP reputation from rate-limit history
        $score += $this->scoreIpReputation($form, $ipAddress, $rateLimit, $weights);

        return min((float) $score, 100.0);
    }

    /**
     * Analyze a normalized submission payload and return score plus detected flags.
     *
     * This is the shared scoring entry point for entry-based and custom form guards.
     * Fast-submission timing is intentionally excluded until a trusted server-issued
     * form-load timestamp exists.
     *
     * @param  Form  $form  The form being submitted
     * @param  array<string, mixed>  $data  Normalized submission data to evaluate
     * @param  string  $ipAddress  Submitter IP address
     * @param  string|null  $userAgent  Request user agent
     * @param  FormRateLimit|null  $rateLimit  Pre-loaded rate limit record to avoid redundant queries
     * @return array{score: int, flags: array<string, mixed>}
     */
    public function analyzeSubmission(
        Form $form,
        array $data,
        string $ipAddress,
        ?string $userAgent,
        ?FormRateLimit $rateLimit = null,
        ?float $trustedFormLoadTime = null,
    ): array {
        $score = (int) round($this->calculateSpamScore(
            $form,
            $data,
            $ipAddress,
            $userAgent,
            $rateLimit,
            $trustedFormLoadTime,
        ));
        $flags = [];

        if ($this->checkHoneypot($form, $data)) {
            $flags['honeypot'] = true;
        }

        foreach ($data as $field => $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $textScore = $this->analyzeTextForSpam($value);
            if ($textScore > 30 && ! isset($flags['suspicious_text'])) {
                $flags['suspicious_text'] = $field;
            }
        }

        $email = $data['email'] ?? null;
        if (is_string($email) && $email !== '' && $this->analyzeEmailForSpam($email) > 20) {
            $flags['suspicious_email'] = true;
        }

        if ($this->isSuspiciousUserAgent($userAgent)) {
            $flags['suspicious_user_agent'] = $userAgent ?? 'empty';
        }

        $record = $rateLimit ?? FormRateLimit::where('form_id', $form->id)
            ->where('ip_address', $ipAddress)
            ->first();

        if ($record !== null && $record->violation_count > 0) {
            $flags['ip_reputation'] = (int) $record->violation_count;
        }

        $recentSubmissions = (int) FormRateLimit::where('ip_address', $ipAddress)
            ->where('window_start', '>=', now()->subHour())
            ->sum('submission_count');

        if ($recentSubmissions >= 5) {
            $flags['rapid_submissions'] = $recentSubmissions;
        }

        return [
            'score' => min($score, 100),
            'flags' => $flags,
        ];
    }

    /**
     * Determine if a submission should be blocked based on its spam score.
     *
     * @param  float  $spamScore  Calculated spam score
     * @return bool True when spam score meets or exceeds the configured block threshold
     */
    public function shouldBlockSubmission(float $spamScore): bool
    {
        /** @var int $threshold */
        $threshold = config('forms.security.spam_protection.score_thresholds.block', 70);

        return $spamScore >= $threshold;
    }

    /**
     * Determine if a submission should be flagged for review without blocking.
     *
     * A submission is flagged when its score meets the flag threshold but stays
     * below the block threshold.
     *
     * @param  float  $spamScore  Calculated spam score
     * @return bool True when score falls in the flag-but-not-block range
     */
    public function shouldFlagSubmission(float $spamScore): bool
    {
        /** @var int $flagThreshold */
        $flagThreshold = config('forms.security.spam_protection.score_thresholds.flag', 40);
        /** @var int $blockThreshold */
        $blockThreshold = config('forms.security.spam_protection.score_thresholds.block', 70);

        return $spamScore >= $flagThreshold && $spamScore < $blockThreshold;
    }

    /**
     * Analyze a text value for spam keywords, links, caps ratio, and excessive punctuation.
     *
     * @param  string  $text  Text content to analyze
     * @param  array<string, int>  $weights  Config-driven scoring weights
     * @param  array<int, string>  $spamPhrases  Configured spam phrase list
     * @return int Total spam score contribution from this text
     */
    public function analyzeTextForSpam(string $text, array $weights = [], array $spamPhrases = []): int
    {
        $score = 0;
        $lowerText = strtolower($text);

        // Spam keywords
        foreach (self::SPAM_KEYWORDS as $keyword) {
            if (str_contains($lowerText, $keyword)) {
                $score += 20;
            }
        }

        // Link density
        $linkCount = substr_count($lowerText, 'http');
        if ($linkCount > 2) {
            $score += $weights['multiple_links'] ?? 30;
        }

        // Excessive punctuation
        if (preg_match_all('/[!@#$%^&*]/', $text) > strlen($text) / 4) {
            $score += $weights['excessive_punctuation'] ?? 20;
        }

        // Spam phrases
        foreach ($spamPhrases as $phrase) {
            if (str_contains($lowerText, $phrase)) {
                $score += $weights['spam_phrases'] ?? 25;
            }
        }

        // Caps ratio — over 50% uppercase is suspicious
        $onlyCaps = preg_replace('/[^A-Z]/', '', $text) ?? '';
        $capsRatio = strlen($onlyCaps) / max(strlen($text), 1);
        if ($capsRatio > 0.5) {
            $score += 15;
        }

        return $score;
    }

    /**
     * Analyze an email address for disposable domain patterns and number-heavy usernames.
     *
     * @param  string  $email  Email address to analyze
     * @return int Spam score contribution from email analysis
     */
    public function analyzeEmailForSpam(string $email): int
    {
        $score = 0;
        $lowerEmail = strtolower($email);

        foreach (self::SUSPICIOUS_EMAIL_DOMAINS as $domain) {
            if (str_contains($lowerEmail, $domain)) {
                $score += 30;
            }
        }

        $digitsOnly = preg_replace('/[^0-9]/', '', $email) ?? '';
        if (strlen($digitsOnly) > 10) {
            $score += 10;
        }

        return $score;
    }

    /**
     * Determine whether a user agent string looks automated or absent.
     *
     * @param  string|null  $userAgent  User agent value
     * @return bool True when the agent is empty or matches a known bot pattern
     */
    public function isSuspiciousUserAgent(?string $userAgent): bool
    {
        if ($userAgent === null || $userAgent === '') {
            return true;
        }

        $lowerAgent = strtolower($userAgent);

        foreach (self::SUSPICIOUS_UA_PATTERNS as $pattern) {
            if (str_contains($lowerAgent, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Score text content for links, punctuation, spam phrases, and keywords.
     *
     * @param  string  $value  Text field value to analyze
     * @param  array<string, int>  $weights  Config-driven scoring weights
     * @param  array<int, string>  $spamPhrases  Configured spam phrase list
     * @return int Score contribution from this field
     */
    private function scoreTextContent(string $value, array $weights, array $spamPhrases): int
    {
        return $this->analyzeTextForSpam($value, $weights, $spamPhrases);
    }

    /**
     * Score IP reputation based on rate-limit violation history and recent submission volume.
     *
     * Uses a pre-loaded rate limit record when available; otherwise queries the database.
     *
     * @param  Form  $form  The form being submitted
     * @param  string  $ipAddress  Submitter IP address
     * @param  FormRateLimit|null  $rateLimit  Pre-loaded rate limit record
     * @param  array<string, int>  $weights  Config-driven scoring weights
     * @return int Score contribution from IP reputation
     */
    private function scoreIpReputation(
        Form $form,
        string $ipAddress,
        ?FormRateLimit $rateLimit,
        array $weights,
    ): int {
        $score = 0;

        $record = $rateLimit ?? FormRateLimit::where('form_id', $form->id)
            ->where('ip_address', $ipAddress)
            ->first();

        $ipWeight = $weights['ip_reputation'] ?? 10;

        if ($record !== null && $record->violation_count > 0) {
            $score += min($record->violation_count * $ipWeight, 50);
        }

        // Rapid submissions across all forms from this IP
        $recentSubmissions = (int) FormRateLimit::where('ip_address', $ipAddress)
            ->where('window_start', '>=', now()->subHour())
            ->sum('submission_count');

        if ($recentSubmissions >= 5) {
            $score += 40;
        }

        return $score;
    }
}
