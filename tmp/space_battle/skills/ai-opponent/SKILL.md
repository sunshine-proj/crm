# Skill: Opponent AI

## Purpose

Create an autonomous satellite opponent that can maneuver, aim, shoot,
and manage fuel while remaining fair to the player.

## Responsibilities

- Target tracking.
- Weapon aiming.
- Firing decisions.
- Trajectory adjustment.
- Fuel management.
- Defensive movement.
- Difficulty configuration.

## Design Principles

The AI should:
- Use the same physical movement rules as the player.
- Respect fuel limits.
- Respect weapon cooldowns.
- React to observable game state.
- Make occasional imperfect decisions at lower difficulties.
- Avoid unavoidable or instantaneous attacks.

The AI must not directly manipulate the player's state.

## AI Architecture

Separate decision-making from execution.

Suggested components:

OpponentAI:
Selects desired actions.

AimingController:
Determines the desired weapon orientation.

MovementController:
Chooses rotation and thrust commands.

CombatController:
Decides when to fire.

FuelPolicy:
Prevents wasteful propulsion behavior.

The AI must issue commands through the same gameplay interfaces
used by the player.

## Aiming

The simplest aiming strategy points the weapon toward the player's
current position.

A more advanced strategy predicts the player's future position.

For constant relative velocity and a projectile with fixed speed,
calculate an intercept when a valid solution exists.

Use a robust numerical method or an analytical intercept calculation.

If there is no valid intercept, aim toward the current target position
or another reasonable fallback.

Account for the weapon's actual orientation and mounting direction.

Avoid repeatedly switching between widely different aiming solutions.

## Firing Decisions

Consider firing when:
- The weapon is ready.
- The player is within a useful range.
- The predicted shot has a reasonable chance of hitting.
- The opponent is alive and the match is active.

Do not require a perfect aim solution for every shot.

Lower difficulty can use less accurate aim or delayed reactions.

## Movement

The opponent should occasionally change its trajectory to improve
its firing angle or avoid predictable incoming attacks.

Possible behaviors:
- Pursue a favorable firing position.
- Maintain a useful distance.
- Apply short thrust bursts.
- Rotate toward a predicted target position.
- Conserve fuel when current momentum is sufficient.

Avoid constant full thrust.

## Fuel Management

Track current and maximum fuel.

Use propulsion only when the expected tactical benefit justifies
its cost.

Allow fuel to regenerate when practical.

Prevent the AI from repeatedly requesting unavailable thrust.

The AI must obey the same configured fuel rules as the player.

## Difficulty

Implement a small number of meaningful difficulty settings.

For example:

Easy:
- Slower decisions.
- Less accurate aim.
- Limited trajectory prediction.

Normal:
- Basic predictive aiming.
- Moderate maneuvering.
- Sensible fuel conservation.

Hard:
- Better interception estimates.
- More effective evasive movement.
- Improved fuel-aware positioning.

Difficulty should change behavior rather than grant hidden health
or damage advantages by default.

## Performance

AI decisions do not need to execute on every rendering frame.

Use a configurable decision interval where appropriate.

Keep behavior deterministic in tests by controlling randomness.

## Testing

Test:
- Target selection.
- Rotation toward a target.
- Intercept prediction.
- Firing cooldown compliance.
- Fuel conservation.
- Behavior when no intercept exists.
- Behavior when the target is destroyed.
- Behavior after restart.

## Acceptance Criteria

- The opponent can move without player input.
- It can rotate and fire at the player.
- It respects fuel and cooldown limits.
- It cannot act after destruction.
- It can be defeated by a competent player.
- Its behavior does not depend on direct access to player input.
