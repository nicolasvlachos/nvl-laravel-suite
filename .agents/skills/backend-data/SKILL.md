---
name: backend-data
description: Rules and patterns for using the Nvl Data package, specifically focusing on data transfer objects (DTOs).
---

# Data Package Architecture

The `data` package standardizes DTOs and value objects across the application.

## Capabilities
- **Standardized DTOs**: Base structures for typed data transfer.
- **Transformation**: Built-in support for array/JSON conversion.

## Main Classes
- `DataServiceProvider`: Bootstraps the module.

## Usage
Agents should utilize this package to type-hint complex structures passing between controllers, actions, and services. Avoid array shapes for domain objects; prefer DTOs.
