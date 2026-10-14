# Skill: Testing and Quality Assurance

## Purpose

Ensure that orbital simulation, combat, AI, input, and presentation
work reliably together.

## Responsibilities

- Unit testing.
- Integration testing.
- Browser testing.
- Regression testing.
- Simulation stability.
- Performance checks.
- Acceptance testing.

## Testing Strategy

Use three main levels.

### 1. Unit tests

Test isolated game rules without rendering.

Examples:
- Fuel consumption.
- Fuel regeneration.
- Rotation.
- Acceleration.
- Damage.
- Weapon cooldown.
- Projectile collision.
- Match-state transitions.

### 2. Integration tests

Test interactions between systems.

Examples:
- Thrust changes satellite velocity and fuel.
- A projectile hit reduces enemy health.
- Destruction ends the match.
- Restart clears old projectiles.
- Pause stops the simulation.
- AI obeys fuel restrictions.

### 3. Browser tests

Use Playwright when appropriate.

Verify:
- The game loads.
- The title screen is displayed.
- A match can start.
- Keyboard controls work.
- The HUD updates.
- Pause and restart work.
- No critical runtime errors occur.

## Deterministic Simulation

Where practical:
- Use fixed simulation steps.
- Control random seeds.
- Inject time into systems that need it.
- Avoid using wall-clock time directly in gameplay rules.
- Separate simulation from rendering.

These practices make failures reproducible.

## Physics Tests

Verify that:
- Thrust changes velocity.
- Velocity persists after thrust stops.
- Rotation follows the documented convention.
- Fuel consumption is consistent with elapsed simulation time.
- Fuel remains within valid limits.
- Gravity points toward the planet when enabled.
- Long-running simulation remains numerically stable.

Do not require exact floating-point equality when testing simulated
values.

Use appropriate tolerances.

## Combat Tests

Verify that:
- Firing respects cooldown.
- Projectiles travel in the correct direction.
- Owners cannot be damaged by their own projectiles unless explicitly
  designed otherwise.
- Valid collisions apply damage.
- A projectile cannot apply damage repeatedly after impact.
- Zero health triggers destruction.
- A match ends exactly once.

## Lifecycle Tests

Verify that:
- Pause stops simulation updates.
- Resume restores normal updates.
- Restart resets satellite state.
- Restart clears projectiles and transient combat state.
- Old event handlers do not accumulate.
- Destroyed satellites cannot fire.
- Focus loss does not leave controls stuck.

## AI Tests

Verify that:
- The opponent can act without player input.
- It aims toward the player.
- It respects cooldown and fuel rules.
- It handles missing or destroyed targets.
- Its behavior remains bounded.
- Restart resets its internal state.

## Rendering Tests

Where possible, verify:
- The scene initializes.
- Both satellites are created.
- Required HUD elements appear.
- Resizing updates the camera and renderer.
- Disposed resources are not reused incorrectly.

Use visual regression tests only where they provide sufficient value.

## Performance Checks

Measure:
- Simulation update cost.
- Rendering frame time.
- Active projectile count.
- Active effect count.
- Memory behavior during repeated restarts.

Avoid arbitrary optimization targets that cannot be measured.

## Commands

Use the scripts defined by the actual repository.

For a typical Vite and TypeScript project, these may include:

    npm run test
    npm run typecheck
    npm run build

If browser tests are configured:

    npm run test:e2e

Do not assume a command exists without checking package.json.

## Reporting

For each significant task, report:
- Tests added.
- Tests executed.
- Pass/fail results.
- Known limitations.
- Reproduction steps for failures.

Never claim successful verification without actually running
the relevant checks.

## Acceptance Criteria

- Core mechanics have automated tests.
- Important system interactions are tested.
- The production build succeeds.
- Restart and pause do not introduce regressions.
- Critical gameplay behavior is reproducible.
