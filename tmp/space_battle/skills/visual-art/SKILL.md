# Skill: Visual Art and Rendering

## Purpose

Create a cinematic space environment and realistic-looking satellites
while maintaining excellent gameplay readability.

## Responsibilities

- Three.js scene composition.
- Planet rendering.
- Starfield generation.
- Satellite models.
- Materials and lighting.
- Engine and attitude-thruster effects.
- Projectile visuals.
- Impact and destruction effects.
- Camera behavior.
- Rendering performance.

## Art Direction

The visual style is realistic, cinematic, and restrained.

The scene should feel like a spacecraft engagement in deep space.

Use:
- Dark navy and black backgrounds.
- Natural planetary colors.
- Metallic spacecraft materials.
- Blue-white or warm engine emissions.
- Restrained highlights.
- High-contrast projectiles.
- Subtle atmospheric lighting.

Avoid excessive neon colors and visual clutter.

## Planet

The planet is the primary environmental landmark.

Include:
- A spherical body.
- Surface detail or procedural texture.
- Directional lighting.
- A subtle atmospheric rim where appropriate.
- Optional clouds or surface features.

The planet should create depth without obscuring the satellites.

If a detailed planet shader is too expensive, use a simpler material
with well-designed lighting.

## Starfield

Generate stars using a deterministic or seeded distribution where
practical.

Use varied brightness and size.

Avoid excessive star density that competes with projectiles.

Stars should not be confused with combat objects.

## Satellite Design

Each spacecraft should include:

1. Central structural body.
2. Solar-panel support arms.
3. Solar panels with visible cell divisions.
4. Rear main engine.
5. Small attitude-control thrusters.
6. Forward-facing weapon.
7. Appropriate structural details.

Use geometry and materials that create a believable spacecraft
silhouette.

Do not depend entirely on tiny surface details to communicate shape.

## Orientation and Coordinate Mapping

Document the relationship between:
- Simulation coordinates.
- Three.js world coordinates.
- Satellite orientation.
- Main engine direction.
- Weapon direction.
- Thruster direction.

The visible weapon must point in the actual firing direction.

The main engine must be mounted opposite the weapon's forward axis.

Avoid orientation offsets that make aiming visually misleading.

## Thruster Effects

Main engine effects should:
- Appear at the rear engine nozzle.
- Point away from the satellite in the exhaust direction.
- Activate during main thrust.
- Scale with thrust intensity when appropriate.

Attitude thrusters should:
- Appear at the appropriate spacecraft ports.
- Produce short bursts.
- Correspond to the rotation command.
- Avoid activating when no rotation thrust is requested.

Visual exhaust must not change gameplay physics.

## Projectiles

Projectiles should have:
- A clear launch point.
- A visible direction of travel.
- Strong enough contrast to remain visible.
- A lifetime consistent with their simulation lifecycle.

Do not let projectile rendering determine projectile position or damage.

## Impact and Destruction

Use a short, readable impact effect.

Destruction may include:
- A bright flash.
- Expanding particles.
- Short-lived debris.
- Fading remnants.

Pool temporary effects when beneficial.

Dispose of unused GPU resources appropriately.

## Camera

Keep the planet and combat readable.

Avoid excessive camera shake.

Ensure the player can identify both satellites and understand the
direction of combat.

The camera may follow the combat area or use a fixed arena view,
provided the player can still aim effectively.

## Performance

- Reuse geometry and materials.
- Limit expensive transparent layers.
- Avoid unnecessary real-time shadows.
- Pool repeated effects where beneficial.
- Clean up geometries, textures, and materials.
- Handle resizing and device pixel ratio sensibly.

## Acceptance Criteria

- Both satellites are recognizable.
- Solar panels are clearly visible.
- The engine and weapon are on opposite sides.
- Thruster effects match player actions.
- Projectile visuals match projectile motion.
- The planet and starfield establish depth.
- Visual effects do not hide critical gameplay information.
- The scene remains performant during combat.
