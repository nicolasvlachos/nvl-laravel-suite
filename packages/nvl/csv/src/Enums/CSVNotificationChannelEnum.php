<?php

declare(strict_types=1);

namespace Nvl\Csv\Enums;

/**
 * Notification channel types for CSV operation alerts and updates.
 *
 * Defines available communication channels for sending notifications about
 * CSV import/export operations, including progress updates, error alerts,
 * and completion notifications.
 */
enum CSVNotificationChannelEnum: string
{
    // Communication channels
    case EMAIL = 'email';               // Email notifications
    case SLACK = 'slack';               // Slack messages
    case WEBHOOK = 'webhook';           // HTTP webhooks
    case DATABASE = 'database';         // Database notifications
    case SMS = 'sms';                   // SMS text messages
    case PUSHER = 'pusher';            // Real-time push notifications
    case TEAMS = 'teams';              // Microsoft Teams
    case DISCORD = 'discord';          // Discord messages
    case LOG = 'log';                  // Log file only

    /**
     * Check if channel requires external configuration.
     *
     * Some channels need API keys, webhooks, or other settings.
     *
     * @return bool True if configuration is required
     */
    public function requiresConfiguration(): bool
    {
        return match ($this) {
            self::DATABASE, self::LOG => false,  // Internal channels
            default => true,                      // External channels need config
        };
    }

    /**
     * Check if channel supports rich content.
     *
     * Determines if channel can handle formatted messages,
     * tables, attachments, or other rich content.
     *
     * @return bool True if rich content is supported
     */
    public function supportsRichContent(): bool
    {
        return match ($this) {
            self::EMAIL, self::SLACK, self::TEAMS, self::DISCORD, self::WEBHOOK => true,
            self::SMS, self::DATABASE, self::LOG, self::PUSHER => false,
        };
    }

    /**
     * Check if channel supports attachments.
     *
     * Determines if CSV files can be attached to notifications.
     *
     * @return bool True if attachments are supported
     */
    public function supportsAttachments(): bool
    {
        return match ($this) {
            self::EMAIL, self::SLACK, self::TEAMS, self::DISCORD => true,
            default => false,
        };
    }

    /**
     * Check if channel provides real-time updates.
     *
     * Real-time channels deliver notifications instantly.
     *
     * @return bool True if real-time delivery
     */
    public function isRealTime(): bool
    {
        return match ($this) {
            self::PUSHER, self::WEBHOOK, self::SLACK, self::DISCORD => true,
            self::EMAIL, self::SMS => false,  // May have delays
            default => true,
        };
    }

    /**
     * Get notification priority for this channel.
     *
     * Higher priority channels are processed first.
     *
     * @return int Priority level (1-10)
     */
    public function getPriority(): int
    {
        return match ($this) {
            self::SMS => 10,        // Highest - immediate attention
            self::PUSHER => 9,      // Real-time push
            self::SLACK => 8,       // Team communication
            self::TEAMS => 8,       // Team communication
            self::EMAIL => 7,       // Standard notification
            self::DISCORD => 6,     // Gaming/community
            self::WEBHOOK => 5,     // System integration
            self::DATABASE => 3,    // Internal storage
            self::LOG => 1,         // Lowest - just logging
        };
    }

    /**
     * Get delivery reliability level.
     *
     * Indicates how reliable the delivery mechanism is.
     *
     * @return string Reliability level
     */
    public function getReliability(): string
    {
        return match ($this) {
            self::DATABASE, self::LOG => 'guaranteed',     // Local storage
            self::EMAIL => 'high',                         // Retry mechanisms
            self::WEBHOOK => 'high',                       // HTTP with retries
            self::SLACK, self::TEAMS, self::DISCORD => 'medium',  // API dependent
            self::SMS => 'medium',                         // Carrier dependent
            self::PUSHER => 'low',                        // Connection dependent
        };
    }

    /**
     * Get typical delivery speed.
     *
     * Expected time for notification delivery.
     *
     * @return string Delivery speed description
     */
    public function getDeliverySpeed(): string
    {
        return match ($this) {
            self::PUSHER, self::DATABASE, self::LOG => 'instant',
            self::SLACK, self::DISCORD, self::WEBHOOK => 'seconds',
            self::TEAMS => 'seconds',
            self::SMS => 'seconds to minutes',
            self::EMAIL => 'minutes',
        };
    }

    /**
     * Get cost tier for this channel.
     *
     * Relative cost of using this notification channel.
     *
     * @return string Cost tier
     */
    public function getCostTier(): string
    {
        return match ($this) {
            self::DATABASE, self::LOG => 'free',           // No external cost
            self::EMAIL => 'low',                          // Minimal cost
            self::SLACK, self::TEAMS, self::DISCORD => 'low',  // API limits
            self::WEBHOOK => 'low',                        // Bandwidth only
            self::PUSHER => 'medium',                      // Subscription based
            self::SMS => 'high',                           // Per message cost
        };
    }

    /**
     * Get icon for visual representation.
     *
     * Returns emoji or icon for channel visualization.
     *
     * @return string Unicode emoji
     */
    public function getIcon(): string
    {
        return match ($this) {
            self::EMAIL => '📧',      // Envelope
            self::SLACK => '💬',      // Speech bubble
            self::WEBHOOK => '🔗',    // Link
            self::DATABASE => '💾',   // Floppy disk
            self::SMS => '📱',        // Mobile phone
            self::PUSHER => '🔔',     // Bell
            self::TEAMS => '👥',      // People
            self::DISCORD => '🎮',    // Game controller
            self::LOG => '📝',        // Memo
        };
    }

    /**
     * Get user-friendly label for this channel.
     *
     * Provides human-readable channel names for UI display.
     *
     * @return string Display label
     */
    public function label(): string
    {
        return match ($this) {
            self::EMAIL => 'Email',
            self::SLACK => 'Slack',
            self::WEBHOOK => 'Webhook',
            self::DATABASE => 'Database',
            self::SMS => 'SMS',
            self::PUSHER => 'Push Notification',
            self::TEAMS => 'Microsoft Teams',
            self::DISCORD => 'Discord',
            self::LOG => 'Log File',
        };
    }

    /**
     * Get detailed description of this channel.
     *
     * Explains how notifications are delivered through this channel.
     *
     * @return string Channel description
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::EMAIL => 'Send notifications via email with attachments support',
            self::SLACK => 'Post messages to Slack channels or direct messages',
            self::WEBHOOK => 'Send HTTP POST requests to configured endpoints',
            self::DATABASE => 'Store notifications in database for in-app display',
            self::SMS => 'Send text messages to mobile phones',
            self::PUSHER => 'Real-time push notifications to connected clients',
            self::TEAMS => 'Post messages to Microsoft Teams channels',
            self::DISCORD => 'Send messages to Discord servers',
            self::LOG => 'Write notifications to application log files',
        };
    }

    /**
     * Get required configuration keys for this channel.
     *
     * Lists the configuration parameters needed to use this channel.
     *
     * @return array<string> Required configuration keys
     */
    public function getRequiredConfig(): array
    {
        return match ($this) {
            self::EMAIL => ['to', 'subject'],
            self::SLACK => ['webhook_url', 'channel'],
            self::WEBHOOK => ['url', 'method', 'headers'],
            self::DATABASE => [],
            self::SMS => ['to', 'provider', 'api_key'],
            self::PUSHER => ['channel', 'event'],
            self::TEAMS => ['webhook_url'],
            self::DISCORD => ['webhook_url'],
            self::LOG => ['level', 'channel'],
        };
    }

    /**
     * Get notification types suitable for this channel.
     *
     * Returns the types of notifications appropriate for this channel.
     *
     * @return array<string> Suitable notification types
     */
    public function getSuitableNotificationTypes(): array
    {
        return match ($this) {
            self::EMAIL => ['completed', 'failed', 'report', 'summary'],
            self::SLACK, self::TEAMS => ['started', 'completed', 'failed', 'progress'],
            self::WEBHOOK => ['all'],  // Flexible for any type
            self::DATABASE => ['all'],  // Store everything
            self::SMS => ['failed', 'critical'],  // Only important
            self::PUSHER => ['progress', 'completed', 'failed'],
            self::DISCORD => ['completed', 'failed', 'summary'],
            self::LOG => ['all'],  // Log everything
        };
    }

    /**
     * Get channels suitable for error notifications.
     *
     * Returns channels appropriate for error alerts.
     *
     * @return array<self> Error notification channels
     */
    public static function errorChannels(): array
    {
        return [
            self::EMAIL,
            self::SLACK,
            self::SMS,
            self::TEAMS,
            self::PUSHER,
        ];
    }

    /**
     * Get channels suitable for progress updates.
     *
     * Returns channels appropriate for real-time progress.
     *
     * @return array<self> Progress update channels
     */
    public static function progressChannels(): array
    {
        return [
            self::PUSHER,
            self::WEBHOOK,
            self::SLACK,
            self::DISCORD,
        ];
    }

    /**
     * Get channels suitable for completion reports.
     *
     * Returns channels appropriate for final reports.
     *
     * @return array<self> Report channels
     */
    public static function reportChannels(): array
    {
        return [
            self::EMAIL,
            self::SLACK,
            self::TEAMS,
            self::DATABASE,
        ];
    }
}
