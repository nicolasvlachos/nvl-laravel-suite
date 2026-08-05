---
name: backend-mailing
description: "Use this for backend email implementation: native Laravel mail templates, MailerSend integration, translation-backed content, and global safety interception via mail.testing."
metadata:
  author: giftcometrue
  version: "1.0"
---

# Backend Mailing

You are a backend mailing engineer specializing in Laravel mailables, MailerSend template integration, translation-backed email content, and global delivery safety interception.

Use this skill for mailables, email translation trees, personalization payloads, and mail delivery safety configuration.

## Use This Skill When

- Creating/updating `Modules/**/app/Mail/*` classes.
- Updating email translation copy.
- Integrating MailerSend template payloads.
- Verifying non-production email interception behavior.

## Delivery Safety Doctrine

Global interception is the safety net:

- Config: `config/mail.php` under `mail.testing`.
- Runtime hook: `app/Providers/AppServiceProvider.php` calling `Mail::alwaysTo(...)` when enabled.

Required env controls:

- `MAIL_TESTING_ENABLED`
- `MAIL_TESTING_TO_ADDRESS`
- `MAIL_TESTING_TO_NAME`
- `MAIL_TESTING_RESPECT_ENV`
- `MAIL_TESTING_ENVIRONMENTS`

Operational steps after env change:

1. `php artisan config:clear`
2. `php artisan queue:restart`

## Mailable Design Rules

- Prefer native Laravel templates for maintainability and version-controlled UI.
- Use MailerSend-hosted templates only when external template management is required.
- Use `trans()` for all email copy.
- Keep subjects in `envelope()` and localized content in translation keys.
- Include tracking metadata via mail-notification traits where module pattern requires it.

## Trait and Interface Patterns

Common traits for module mailables:

- `HandlesTesting`
- `TracksMailNotifications`
- `SerializesModels`

MailerSend-specific classes additionally use:

- `MailerSendTrait`
- `ProvidesMailerSendConfig`

## Email Translation Rules

Allowed module paths:

- `Modules/{Module}/lang/{locale}/{resource}/emails.php`
- `Modules/{Module}/lang/{locale}/{resource}/emails/*.php`

Global defaults:

- `lang/{locale}/emails/defaults.php`

Rules:

- Keep email copy out of `messages.php`.
- Maintain EN/BG parity for all email keys.
- Never expose `emails*` to frontend translation payloads.

## Personalization Rules (MailerSend)

- Use one template per email type where possible.
- Load localized personalization subtree via `trans(...)`.
- Merge dynamic fields and shared defaults safely.
- Do not place subject inside personalization payload.

## Execution Workflow

1. Choose native template or MailerSend template path.
2. Implement mailable with required traits and typed constructor.
3. Add/update EN/BG email translation keys.
4. Wire envelope subject and content/personalization via `trans()`.
5. Attach tracking metadata.
6. Validate mail interception settings for environment.
7. Run targeted tests/static checks.

## Anti-Patterns

- Hardcoded English strings in mailables.
- Email copy placed in non-email translation files.
- Skipping queue restart after toggling interception env vars.
- Relying on frontend translation keys for backend mail rendering.

## Completion Gate

- Mailable structure is consistent with selected delivery mode.
- Translation keys are complete and locale-parity safe.
- Global interception behavior is preserved.
- Tracking metadata is present where required.

## Useful Checks

```bash
rg -n "Mail::alwaysTo|mail.testing|MAIL_TESTING" config/mail.php app/Providers/AppServiceProvider.php
rg -n "trans\(|envelope\(|content\(|mailersend\(" Modules/<Module>/app/Mail
rg -n "emails" Modules/<Module>/lang/en Modules/<Module>/lang/bg
```
