---
name: project-persistence
description: Implement project saving, loading, autosave, version history, schema migrations, template duplication, and reliable storage of architectural model data.
---

# Project Persistence and Versioning

## Objective

Ensure that architectural projects can be saved, reopened, duplicated,
and migrated without losing supported model information.

## Authoritative Data

Persist the architectural model and required project metadata.

Do not use the rendered 3D scene or a screenshot as the canonical project.

Store generated geometry only when a documented caching strategy requires it.

## Schema Versioning

Every persistent project format should have a schema version.

When the model changes:

1. Identify existing stored formats.
2. Define the required migration.
3. Preserve supported user data.
4. Validate migrated relationships.
5. Test old-to-new migrations.
6. Provide safe failure behavior for unsupported versions.

Do not silently discard fields that are no longer understood.

## Saving

Support explicit save operations and autosave where appropriate.

Prevent overlapping saves from silently overwriting newer changes.

Use revisions, optimistic concurrency, or another explicit conflict policy.

Do not report a save as successful before the persistence layer confirms it.

## Project Duplication

Create independent project-owned entities with new identifiers.

Preserve internal references and hierarchy.

Share immutable library assets where appropriate.

Never share mutable project entities between independent projects.

## Large Projects

Keep large binary assets outside ordinary relational records when appropriate.

Use object storage for large models, textures, exports, and rendered images.

Store metadata and access controls separately.

Define cleanup rules for obsolete uploads and generated artifacts.

## Testing

Test:

- Save and reload.
- Autosave recovery.
- Duplicate projects.
- Schema migration.
- Invalid data handling.
- Concurrent updates.
- Asset references.
- Storage failures.