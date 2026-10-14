---
name: three-dimensional-visualization
description: Implement the Three.js viewport, architectural mesh generation, cameras, materials, lighting, object selection, navigation, and walkthroughs.
---

# Three-Dimensional Visualization

## Objective

Present an accurate, interactive 3D representation of the architectural
model.

## Core Principle

The 3D scene is a derived view of the authoritative building model.

Never treat renderer objects as the only source of architectural data.

## Geometry Generation

Generate 3D geometry from architectural parameters.

Support incremental updates when objects change.

Examples:

- Walls generate solid geometry.
- Openings modify their host walls.
- Slabs generate surfaces and thickness.
- Roofs generate appropriate surfaces or solids.
- Stairs generate steps and connecting geometry.
- Furniture instances reference reusable asset definitions.

Validate geometry before passing it to the renderer.

Avoid duplicate or overlapping surfaces where they cause visual artifacts.

## Object Identity

Maintain a reliable mapping between architectural object identifiers
and their corresponding 3D representations.

Selection in 3D must identify the underlying architectural entity.

Editing an object through the 3D viewport must update the shared model
through the same validated command system used elsewhere.

## Navigation

Support:

- Orbit.
- Pan.
- Zoom.
- Fit to building.
- Standard orthographic views.
- Perspective view.
- Top view.
- Front and side elevations.
- Level visibility.
- Walkthrough navigation.

Prevent navigation controls from interfering with object-editing tools.

## Materials and Lighting

Separate material definitions from individual object instances.

Support reusable material presets and project-specific overrides.

Keep physical material properties distinct from visual preview settings.

Provide appropriate lighting and shadow options.

Do not equate a visually realistic material with verified physical
performance.

## Performance

- Reuse geometry and materials where appropriate.
- Dispose of unused GPU resources.
- Avoid rebuilding unaffected objects.
- Consider instancing repeated furniture and vegetation.
- Use simplified preview geometry for expensive models when appropriate.
- Preserve accurate authoritative dimensions.
- Handle context loss and renderer initialization failures gracefully.

## Walkthroughs

Use collision-aware navigation where practical.

Prevent the camera from passing through walls and solid obstacles.

Allow configurable eye height and movement speed.

Do not assume a visually accessible path satisfies accessibility
regulations.

## Testing

Verify that:

- Model changes update the 3D representation.
- Object identifiers survive geometry regeneration.
- Dimensions match the authoritative model.
- Camera controls work.
- Resources are disposed of correctly.
- Large scenes remain usable.