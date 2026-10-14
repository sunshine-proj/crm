---
name: interior-landscape-design
description: Implement furniture placement, interior finishes, kitchens, bathrooms, lighting, plants, pools, patios, outdoor furniture, and landscape planning.
---

# Interior and Landscape Design

## Objective

Allow users to design interior spaces and exterior environments using
reusable assets placed within the shared architectural model.

## Asset Architecture

Separate reusable catalog definitions from project instances.

Catalog definitions may describe:

- Furniture.
- Kitchen cabinets.
- Sanitary fixtures.
- Lighting.
- Plants and trees.
- Outdoor furniture.
- Decorative objects.
- Materials and finishes.

Instances should have stable identifiers and their own placement data.

Keep asset dimensions, visual geometry, and placement constraints explicit.

## Placement

Support:

- Position.
- Rotation.
- Alignment.
- Level assignment.
- Optional snapping.
- Collision warnings.
- Clearance checks.
- Precise numeric positioning.

Use actual asset dimensions where available.

Warn about intersections with walls, doors, windows, and other furniture
when those intersections matter.

Do not automatically move objects without informing the user.

## Materials

Support reusable materials and instance-specific overrides.

Allow users to assign materials to appropriate surfaces or objects.

Preserve material assignments when switching between 2D and 3D views.

## Interior Design

Support progressive implementation of:

- Furniture layouts.
- Kitchen and bathroom planning.
- Built-in storage.
- Lighting fixtures.
- Flooring.
- Wall finishes.
- Ceiling finishes.
- Decorative accessories.

## Landscape Design

Support:

- Plants and trees.
- Terrain and planting areas.
- Paths and driveways.
- Patios and terraces.
- Pools.
- Fences and gates.
- Outdoor furniture.
- Outdoor lighting.

Keep landscape objects in the same project coordinate system as the
building.

Distinguish visual landscaping from engineered grading and drainage.

## Asset Licensing

Track asset sources and licensing requirements.

Do not redistribute third-party models or textures without authorization.

## Testing

Test placement, transforms, persistence, material assignments, asset
loading, and collision warnings.