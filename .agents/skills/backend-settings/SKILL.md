---
name: backend-settings
description: Provides guidelines and architecture for using the nvl/settings package in Laravel. Use this skill when managing global configuration, user settings, or database-backed dynamic properties.
---

# Settings Package

The `nvl/settings` package is a standalone modular package that handles database-backed configuration safely and efficiently.

## Architecture & Core Classes

- **SettingsServiceProvider**: Bootstraps the package, binds singletons, and registers cache invalidation events.
- **SettingManager**: The primary entry point for reading and writing settings (implements `SettingRepository`).
- **DefinitionRepository**: Manages definitions of valid settings to ensure type safety and validation.
- **SettingModel**: The Eloquent model representing the settings table.
- **Commands**: Offers CLI tools like `settings:sync`, `settings:reset`, `settings:cache`, `settings:clear`, and `settings:list`.

## Agent Guidelines
1. **Always** inject the `SettingRepository` contract or use the `SettingManager` instance to interact with settings.
2. Rely on the package's automated cache invalidation logic; do not flush the settings cache manually unless writing a console command.
3. If creating a new setting, define it via `DefinitionRepository` and consider fallback values for stability.
