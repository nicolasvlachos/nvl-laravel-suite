---
name: backend-support
description: Provides guidelines and architecture for using the nvl/support package in Laravel. Use this skill for shared utilities, DTOs, traits, and baseline logic across NVL packages.
---

# Support Package

The `nvl/support` package is a shared foundation providing utilities, abstract concepts, and structural helpers utilized by other `nvl/*` packages.

## Architecture & Core Classes

- **SupportServiceProvider**: Bootstraps shared package assets.
- **Traits & Contracts**: Houses reusable interfaces and Eloquent traits used to DRY up cross-package dependencies.
- **DTO Base Classes**: Can provide core logic for `Spatie\LaravelData` integrations shared across domains.

## Agent Guidelines
1. **Always** check this package for existing foundational traits before writing a new generic utility in another `nvl/*` package.
2. Do not introduce domain-specific logic here. Keep code generic, highly reusable, and strictly typed.
