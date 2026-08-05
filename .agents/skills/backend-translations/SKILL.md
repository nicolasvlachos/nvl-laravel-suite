---
name: backend-translations
description: "Use this skill for handling database and file-based string translations using the nvl/translations package, including scanning, exporting, and importing translations."
---

# Translations Package Guidelines

The `nvl/translations` package provides tools to scan, export, and import translations within a Laravel application. 

## Core Concepts

- **File-based & Database synchronization:** Handles syncing localized string keys across JSON files, module-specific `lang/` directories, and the database.
- **Console Utilities:** The main interface to this package is via Artisan commands for scanning the codebase for translation keys, exporting them, and importing them back.

## Commands

- `php artisan translations:scan`: Scans the application for `__('some.string')` and `@lang('some.string')` calls to discover missing translation keys.
- `php artisan translations:import`: Imports translations from language files into the database.
- `php artisan translations:export`: Exports translations from the database into language files.
- `php artisan translations:unused`: Scans for translations that exist in language files but are no longer used.

## Usage

When building features, always use Laravel's native translation helpers:

```php
// PHP
__('messages.welcome');
trans('messages.welcome');

// Blade
@lang('messages.welcome')
{{ __('messages.welcome') }}
```

After adding new translation keys to your code, run `php artisan translations:scan` to generate the missing keys in your language files.
