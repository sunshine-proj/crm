---
name: architectural-model
description: Design and maintain the authoritative architectural data model, including buildings, levels, walls, openings, rooms, slabs, roofs, and furniture. Use when changing building entities, model relationships, dimensions, or project structure.
---

# Architectural Building Model

## Objective

Maintain a consistent, typed, editable representation of a building that
can drive 2D plans, 3D geometry, technical drawings, and exports.

## Core Rules

1. The architectural model is the source of truth.
2. Rendering objects are derived representations, not architectural data.
3. Architectural objects have stable identifiers.
4. Relationships between objects must be explicit.
5. Physical dimensions use the project's canonical unit system.
6. Invalid model changes must not silently corrupt existing data.
7. Model changes must be testable independently of the UI.

## Modeling Approach

Use typed domain entities.

Examples include:

- Project
- Building
- Level
- Wall
- Opening
- Door
- Window
- Slab
- Roof
- Room
- Stair
- FurnitureInstance
- MaterialDefinition

Keep catalog definitions separate from project instances.

Use explicit references between related entities.

Avoid using rendering-object references as persistent identifiers.

## Model Changes

Before modifying an entity:

1. Identify its dependent entities.
2. Determine which relationships must be preserved.
3. Validate the requested changes.
4. Apply the changes as a logical transaction.
5. Recompute affected derived data.
6. Verify model invariants.
7. Emit an appropriate change notification.

If a change cannot be applied safely, reject it or request clarification.

## Geometry Ownership

Store authoritative parameters and reference geometry.

Derive visual meshes, drawing outlines, and other representations from
those parameters.

Do not persist generated meshes as the only representation of an object.

Cache derived geometry only when it can be invalidated and regenerated.

## Persistence

Use versioned schemas.

Validate loaded data before accepting it into the active project.

Generate new object identifiers when duplicating project-owned objects.

Preserve internal relationships during duplication.

Never share mutable architectural entities between independent projects.

## Testing

Test:

- Entity creation and deletion.
- Relationship integrity.
- Unit consistency.
- Model validation.
- Project duplication.
- Schema migration.
- Undo and redo.
- Derived-data invalidation.

Every significant model change should have regression coverage.