# Skill: Gameplay Programming

## Purpose

Implement the game mechanics that connect input, simulation, weapons,
damage, and match state.

## Responsibilities

- Input handling.
- Satellite control.
- Fuel management.
- Weapon firing.
- Projectile lifecycle.
- Damage resolution.
- Match state.
- Restart and pause behavior.
- Gameplay configuration.

## Architecture

Keep these responsibilities separate:

InputManager:
Translates keyboard events into gameplay commands.

SatelliteController:
Applies movement and rotation commands.

FuelSystem:
Tracks consumption, regeneration, and limits.

WeaponSystem:
Handles firing requests and cooldowns.

ProjectileSystem:
Updates projectiles and checks collisions.

CombatSystem:
Applies damage and resolves destruction.

GameState:
Tracks the current match lifecycle.

HUD:
Displays gameplay information.

## Input Handling

Use keydown and keyup events to track held keys.

Do not repeatedly register event handlers during gameplay updates.

Clear held inputs when the browser window loses focus.

Prevent browser scrolling while gameplay controls are active.

Avoid firing multiple times from repeated keydown events unless the
weapon system intentionally supports automatic fire.

Use an explicit firing policy.

For example:
- Space pressed: attempt one shot.
- Holding Space: either repeat at a configured interval or fire once.

Choose one behavior and document it.

## Fuel System

Store maximum fuel, current fuel, consumption, and regeneration values
in configuration.

Clamp fuel to:

    0 <= fuel <= maximumFuel

Fuel-consuming actions must verify that fuel is available.

Prevent negative fuel values.

Avoid applying fuel consumption twice when systems share an update loop.

## Weapon System

A firing request should check:

1. The match is active.
2. The satellite is alive.
3. The weapon is ready.
4. The weapon is permitted to fire.

If permitted:
- Calculate the muzzle position.
- Calculate the weapon direction.
- Create a projectile.
- Start the cooldown.
- Trigger firing feedback.

Projectile direction must match the visible weapon orientation.

If projectile velocity inherits satellite velocity, apply that rule
consistently to both player and opponent weapons.

## Projectile Lifecycle

Each projectile has:
- Position.
- Velocity.
- Damage.
- Lifetime.
- Owner.
- Active state.

Remove or recycle projectiles after:
- Impact.
- Expiration.
- Match termination, when appropriate.

Prevent projectiles from damaging their owner.

## Damage Resolution

Damage must be applied by gameplay systems, not rendering effects.

When health reaches zero:
- Clamp health to zero.
- Mark the satellite as destroyed.
- Disable its control.
- Disable its weapon.
- Notify the match-state system.
- Trigger visual feedback through an event or interface.

Destruction should be processed exactly once.

## Game Lifecycle

Use explicit states, such as:

- MENU
- COUNTDOWN, if needed.
- PLAYING
- PAUSED
- VICTORY
- DEFEAT

Define valid transitions.

A paused match must not continue simulating combat.

Restart must reset:
- Satellite position.
- Velocity.
- Orientation.
- Health.
- Fuel.
- Weapon cooldown.
- Projectiles.
- Temporary effects.
- Match timers.
- AI state.
- Match result.

## Configuration

Avoid scattered gameplay constants.

Use typed configuration objects for movement, fuel, weapons, and damage.

Validate configuration values at initialization.

## Acceptance Criteria

- Controls respond reliably.
- Held keys behave consistently.
- Firing respects cooldown.
- Damage is applied once per valid hit.
- Destruction happens once.
- Pause freezes gameplay simulation.
- Restart restores a clean initial state.
- Rendering code cannot bypass gameplay rules.
