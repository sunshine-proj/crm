---
name: geometry-engine
description: Implement precise 2D and 3D geometry, wall connections, offsets, intersections, snapping, constraints, room boundaries, and collision checks. Use whenever architectural geometry or dimensional accuracy is involved.
---

# Geometry Engine

## Objective

Provide reliable geometry operations for architectural editing.

## Coordinate System

Document and enforce:

- World coordinate conventions.
- Axis directions.
- Units.
- Angle conventions.
- Numerical tolerances.
- Transformation order.

Use a consistent mapping between 2D floor-plan coordinates and 3D world
coordinates.

For example, a floor plan may use X and Y, while the 3D scene uses X, Y,
and Z. Choose one convention and document it.

Do not mix screen pixels with physical dimensions.

## Required Operations

Implement reusable operations for:

- Points and vectors.
- Distances and angles.
- Segment intersections.
- Polygon containment.
- Polygon validity.
- Line offsets.
- Wall outlines.
- Wall intersections and joins.
- Point projection.
- Snapping candidates.
- Bounding boxes.
- Coordinate transformations.
- Collision and clearance checks.

Prefer well-tested geometry libraries for difficult operations.

## Numerical Robustness

Handle:

- Zero-length segments.
- Coincident points.
- Nearly parallel lines.
- Collinear segments.
- Invalid polygons.
- Self-intersecting boundaries.
- Very small edges.
- Floating-point rounding.
- Boundary-touching cases.

Do not compare floating-point coordinates using exact equality when a
tolerance-based comparison is required.

Do not solve numerical instability by increasing tolerances arbitrarily.

## Architectural Constraints

Support validation of:

- Wall thickness.
- Wall endpoints.
- Wall intersections.
- Opening extents.
- Room boundaries.
- Object placement.
- Clearance distances.
- Level elevations.

Separate geometric feasibility from building-code compliance.

A geometrically valid layout is not automatically code-compliant.

## Performance

Use spatial indexing when object counts justify it.

Limit calculations to affected regions when possible.

Avoid recomputing the entire building after every pointer movement.

For interactive previews, temporary geometry may be simplified, but
committed model geometry must follow the same defined rules as the
authoritative model.

## Testing

Include tests with known coordinates and expected results.

Cover ordinary cases, boundary cases, degenerate geometry, and numerical
edge cases.

Verify dimensional accuracy rather than relying on visual inspection.