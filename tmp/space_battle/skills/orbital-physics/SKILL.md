# Skill: Orbital Physics

## Purpose

Implement a stable and understandable simulation of satellites moving
under thrust and, where configured, planetary gravity.

## Responsibilities

- Position and velocity integration.
- Orbital motion.
- Main engine thrust.
- Attitude-control behavior.
- Coordinate transformations.
- Collision geometry.
- Simulation stability.

## Coordinate System

Use a consistent two-dimensional gameplay plane for the initial game.

Represent position and velocity using two-component vectors.

Define one coordinate convention and use it everywhere.

For example:
- X increases to the right.
- Y increases upward.
- Satellite orientation is an angle in radians.
- Positive rotation is counterclockwise.

Document how these coordinates map to the Three.js scene.

## State Model

Each satellite must have:

- Position.
- Velocity.
- Orientation.
- Angular velocity, if the selected model uses angular momentum.
- Mass, if force-based simulation is used.
- Health.
- Fuel.

Keep physics state independent of Three.js mesh objects.

## Velocity and Position

For a simplified acceleration-based model:

    velocity += acceleration * deltaTime
    position += velocity * deltaTime

Use a fixed simulation timestep where practical.

Apply acceleration before updating position according to the chosen
integration scheme.

Document the integration method.

For better orbital stability, consider semi-implicit Euler integration
or another suitable numerical method.

## Main Engine

Define a unit vector representing the satellite's forward direction.

For orientation angle theta:

    forward = (cos(theta), sin(theta))

Apply forward acceleration only while thrust is requested and sufficient
fuel is available.

For an acceleration-based implementation:

    velocity += forward * thrustAcceleration * deltaTime

Fuel consumption should be proportional to active thrust duration.

Do not directly increase position to simulate acceleration.

Do not reset velocity when thrust is released.

## Rotation

Arrow Left and Arrow Right change orientation.

The rotation model must be consistent.

For a simple arcade model:

    orientation += rotationInput * rotationSpeed * deltaTime

For a more physical model, implement angular acceleration and angular
velocity.

Do not mix these approaches without a documented reason.

Rotation thrusters should activate visually in response to the
corresponding rotation command.

If rotation consumes fuel, apply the configured fuel rule consistently.

## Planetary Gravity

If gravity is enabled, calculate acceleration toward the planet.

For a simplified point-mass model:

    acceleration = -mu * relativePosition / distance^3

Where:
- mu is the configured gravitational parameter.
- relativePosition points from the planet center to the satellite.
- distance is the magnitude of relativePosition.

Handle distances close to zero safely.

Prevent the satellite from passing through the planet by implementing
a collision or impact policy.

Do not combine a fixed circular-orbit animation with an independent
gravity simulation for the same satellite.

Choose one authoritative movement model.

## Orbital Stability

- Use consistent units.
- Avoid extremely large acceleration values.
- Clamp or validate configurable parameters.
- Test long-running trajectories.
- Handle planet collisions.
- Handle escape from the intended combat region.
- Avoid accidental velocity resets.
- Use deterministic update ordering.

If the game uses simplified arcade movement rather than full orbital
mechanics, document the simplifications.

## Collision Detection

Use explicit collision radii.

For projectile collisions, consider swept segment-circle collision
tests between the previous and current projectile positions.

This prevents fast projectiles from passing through small targets
between simulation steps.

Resolve each projectile hit at most once.

## Testing

Add tests for:

- Forward direction at known orientations.
- Rotation direction.
- Acceleration under thrust.
- Momentum preservation.
- Fuel depletion.
- Fuel regeneration.
- Gravity direction.
- Planet collision.
- Projectile collision.
- Long-running simulation stability.

## Acceptance Criteria

- Releasing thrust preserves existing momentum.
- Thrust follows the satellite's orientation.
- Rotation behaves consistently.
- Fuel never exceeds its bounds.
- The simulation does not depend on rendering frame rate.
- Projectiles cannot repeatedly damage the same target after impact.
- The chosen orbital model is stable enough for normal matches.
