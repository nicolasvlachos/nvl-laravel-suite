---
name: backend-media
description: Use when implementing or reviewing nvl/media uploads, storage, associations, collections, image variations, asset delivery, localized metadata, lifecycle operations, API payloads, or media-enabled Eloquent models.
---

# Backend Media

Keep file lifecycle, database lifecycle, associations, transformations, and localized metadata inside the package's public Actions and Services.

## Integrate an owner

- Implement the package media contract and use `InteractsWithMedia`.
- Define allowed collections and variation behavior in the owner or package configuration.
- Attach and detach with `AttachMediaAction` and `DetachMediaAction`.
- Do not manipulate association rows directly.

## Upload and mutate

- Use `UploadMediaAction` for ingestion and `DeleteMediaAction` for deletion.
- Use `UpdateMediaMetadataAction` with `UpdateMediaPayload` for title, alt text, caption, tags, visibility, and related metadata.
- Use locale-keyed title/alt/caption rows through `TranslationWriter`.
- Keep filename, hash, MIME type, disk, folder, digest, visibility, and storage metadata nonlocalized.
- Keep database transactions in Actions and filesystem details in Services.
- Dispatch lifecycle events after commit where database state must be durable.

## Read and deliver

- Use `MediaQueryService` for list/detail queries.
- Use `withResolvedTranslations($locale)` for localized lists.
- Use `MediaAssetService` and URL/path services; never assemble storage paths in consumers.
- Use public and private asset routes according to visibility and authorization.
- Resolve title, alt, and caption per field with deterministic fallback.

## Images and operations

- Generate configured variations through package variation Actions/commands.
- Run `media:storage-health` for consistency checks.
- Run `media:regenerate` to rebuild variations.
- Run `media:migrate-disk` for controlled storage moves.
- Validate SVGs before persistence and keep temporary-file cleanup explicit.

## Verify

Test upload rollback, deduplication, MIME and size validation, SVG rejection, association idempotency, private authorization, variation regeneration, URL/path parity across disks, metadata patch/replace semantics, field-level locale fallback, query counts, and physical-file cleanup.
