# Product Requirements Document — Orbital Combat

## 1. Product Overview

### Working title

Orbital Combat

### Product type

Single-player orbital space-combat game.

### Platform

Desktop web browser, with responsive presentation and keyboard controls.

### Genre

Physics-driven spacecraft combat.

### High-level concept

Two armed satellites engage in a fight around a planet.

The player controls a satellite that must maneuver using limited
propulsion fuel, rotate to aim its weapon, and destroy an AI-controlled
opponent before being destroyed.

Both satellites retain momentum. Every thrust changes their trajectory,
and every combat encounter creates a changing geometric relationship
between the two spacecraft.

Fuel regenerates, allowing the player to alternate between aggressive
maneuvering and periods of conservation.

### Product vision

Create a visually impressive, immediately understandable space-combat
game in which movement and aiming feel connected to the physical
behavior of a spacecraft.

The game should be approachable within seconds but reward practice.

## 2. Product Goals

1. Deliver a complete, playable combat encounter.
2. Make orbital movement feel physical and predictable enough to master.
3. Create satisfying shooting, hit detection, damage, and destruction.
4. Make fuel management a meaningful tactical decision.
5. Establish a realistic, cinematic spacecraft aesthetic.
6. Provide a challenging but fair AI opponent.
7. Run smoothly in a modern desktop browser.

## 3. Non-Goals

The initial release does not require:

- Online multiplayer.
- A campaign or narrative.
- Persistent player accounts.
- Unlockable equipment.
- Multiple planets.
- Fleet management.
- Real-world spacecraft engineering accuracy.
- Complex spacecraft construction.
- Mobile touch controls.
- Monetization systems.

These may be considered after the core game is proven fun.

## 4. Target Audience

Players who enjoy:
- Space games.
- Arcade combat.
- Physics-driven movement.
- Tactical positioning.
- Skill-based aiming.
- Short, replayable competitive encounters.

The game should not require prior knowledge of orbital mechanics.

## 5. Core Gameplay Loop

1. Start a match.
2. Observe the player's satellite and the enemy's satellite.
3. Rotate the spacecraft to align its weapon.
4. Apply forward thrust to change velocity and orbit.
5. Fire at the opponent.
6. Observe projectile travel and determine whether shots connect.
7. Manage fuel while avoiding incoming attacks.
8. Continue attacking until one satellite is destroyed.
9. Display the match result.
10. Allow the player to restart immediately.

This loop should be enjoyable without progression systems or unlocks.

## 6. World and Simulation

### Arena

The arena contains:
- A central planet.
- A surrounding starfield.
- Two active satellites.
- Weapon projectiles.
- Optional transient visual effects.

The camera should keep the planet and combat readable.

The initial game may use a two-dimensional orbital simulation presented
with 3D spacecraft models and visual depth.

The orbital simulation must define clear rules for gravity, movement,
collision, and arena boundaries.

### Satellite movement

Each satellite has:
- Position.
- Velocity.
- Orientation.
- Angular velocity, if supported by the chosen control model.
- Health.
- Current fuel.
- Maximum fuel.
- Weapon cooldown.
- Alive/destroyed state.

Forward thrust changes velocity in the direction of the main engine's
forward axis.

When thrust stops, existing velocity remains.

The satellite must not simply rotate around the planet at a fixed
position on a predefined circular path if the product promises that
the player can change its trajectory.

### Orientation

Left and Right Arrow rotate the satellite.

Small attitude-control thrusters should visibly activate during rotation.

The weapon direction must be derived from the satellite's orientation
and the weapon's defined mounting direction.

The main engine must remain at the rear of the satellite, opposite the
forward weapon direction.

### Fuel

Fuel has a configurable maximum value.

Fuel decreases while propulsion is active.

Fuel regeneration restores fuel gradually, subject to the configured
rules.

Fuel never drops below zero or exceeds its maximum.

When fuel is depleted:
- Main propulsion cannot accelerate the satellite.
- Fuel-consuming attitude control must follow its defined availability
  policy.
- The player can continue using any actions that do not require fuel,
  including firing if ammunition and cooldown permit.

The initial balance should make fuel scarcity noticeable without
frequently leaving the player unable to influence the match.

## 7. Combat System

### Weapons

Each satellite has a forward-facing weapon.

Pressing Space fires when the weapon is ready.

A shot:
- Originates at the weapon muzzle.
- Travels along the weapon's forward direction.
- Has a configurable speed and lifetime.
- Can collide with the opposing satellite.
- Applies configured damage on a successful hit.
- Is removed after impact or expiration.

Projectile visuals must be easy to distinguish from stars and thrust
effects.

### Damage

Both satellites begin with their configured maximum health.

Successful hits reduce health.

Health is clamped between zero and maximum health.

A satellite is destroyed when health reaches zero.

Damage feedback should include a visual impact and a clear HUD change.

### Destruction

Destruction must:
- Disable further control of the destroyed satellite.
- Prevent it from firing.
- Trigger a visible destruction effect.
- End the match.
- Display whether the player won or lost.

The game must not award both victory and defeat for the same match.

### Combat readability

Players must be able to distinguish:
- Their own satellite.
- The enemy satellite.
- Friendly or player-fired projectiles.
- Incoming projectiles.
- Successful hits.
- Fuel depletion.
- Satellite destruction.

## 8. Opponent AI

The enemy is controlled by an autonomous opponent.

Its initial behavior should:
- Maintain an appropriate distance from the planet.
- Rotate to aim at the player.
- Fire when a reasonable shot is available.
- Use propulsion to adjust its trajectory.
- Avoid wasting all its fuel immediately.
- React to changes in the player's position.
- Continue functioning without access to player-only input.

The opponent should have configurable difficulty.

Higher difficulty may improve prediction, tactical decisions, or reaction
time. It should not rely on hidden cheating or unavoidable attacks.

## 9. Controls

| Input | Action |
|---|---|
| Left Arrow | Rotate counterclockwise |
| Right Arrow | Rotate clockwise |
| Up Arrow | Main engine thrust |
| Space | Fire weapon |
| Escape | Pause/resume |

The controls screen must explain that thrust changes velocity rather
than directly setting movement speed.

Inputs must work consistently when keys are held.

The game must avoid repeating browser actions such as page scrolling
while gameplay controls are active.

## 10. Visual Requirements

### Environment

- Dark space background.
- Procedurally generated or authored stars.
- A prominent planet with visible surface detail.
- Atmospheric rim lighting where appropriate.
- Depth and scale through lighting and composition.
- Restrained bloom or glow effects.

### Satellites

Both satellites must have recognizable spacecraft silhouettes.

Required design elements:
- Main spacecraft body.
- Solar panels on articulated or rigid support structures.
- Visible solar cell patterns.
- Rear main propulsion engine.
- Small lateral attitude-control thrusters.
- Forward-facing weapon.
- Metallic materials and physically plausible lighting.

The player and enemy should be visually distinguishable without
requiring radically different spacecraft designs.

### Effects

- Short main-engine exhaust.
- Small directional attitude-thruster bursts.
- Visible projectiles.
- Impact flashes.
- Optional damage sparks.
- Destruction explosion or debris effect.

Effects must be brief enough to preserve visual clarity.

### Rendering quality

The game should look polished at common desktop resolutions.

Models, materials, lighting, and effects should share a coherent style.

## 11. User Interface

### Title screen

Must contain:
- Game title.
- Start button.
- Controls summary.
- Optional sound settings.

### Gameplay HUD

Must display:
- Player health.
- Enemy health.
- Player fuel.
- Weapon readiness or cooldown if needed.
- Pause control or pause instructions.

### Pause screen

Must support:
- Resume.
- Restart.
- Return to title.

### Results screen

Must display:
- Victory or defeat.
- Restart action.
- Return-to-menu action.

UI elements must remain legible against the background.

## 12. Audio

Audio is a supporting feature, not a blocker for the initial simulation.

Recommended sounds:
- Main engine ignition and thrust.
- Short attitude-thruster bursts.
- Weapon firing.
- Projectile impacts.
- Satellite damage.
- Destruction.
- UI interactions.

Because the setting is space, external spacecraft sounds are stylized
effects rather than claims of literal sound propagation in vacuum.

Audio must have volume controls and must not prevent gameplay if
muted or unavailable.

## 13. Technical Requirements

- Use TypeScript and Three.js unless the existing project requires
  another stack.
- Separate simulation from rendering.
- Use configurable simulation and balance parameters.
- Handle browser resizing.
- Clean up event listeners and GPU resources.
- Avoid uncontrolled allocations in frequently executed loops.
- Support restart without reloading the page.
- Ensure pause actually stops combat simulation.
- Avoid tunneling in projectile collision detection.
- Handle focus loss without leaving thrust or firing permanently active.

## 14. Acceptance Criteria

### AC-01: Start a match

Given the title screen is visible,
when the player starts a match,
then the arena, both satellites, and gameplay HUD become visible.

### AC-02: Rotate the satellite

Given a match is active,
when Left or Right Arrow is held,
then the player satellite rotates and the appropriate attitude thrusters
visually activate.

### AC-03: Accelerate

Given the player has fuel,
when Up Arrow is held,
then the main engine activates, fuel decreases, and velocity changes.

### AC-04: Preserve momentum

Given the satellite has nonzero velocity,
when the player releases the thrust key,
then the satellite continues moving without an artificial velocity reset.

### AC-05: Regenerate fuel

Given fuel is below maximum and the regeneration conditions are met,
then fuel gradually increases and never exceeds its maximum.

### AC-06: Prevent propulsion without fuel

Given fuel is depleted,
when the player requests main engine thrust,
then no fuel-consuming acceleration is applied.

### AC-07: Fire a weapon

Given the weapon is ready,
when Space is pressed,
then a projectile emerges from the muzzle and travels in the direction
of the weapon.

### AC-08: Apply damage

Given a projectile intersects the enemy,
when collision is resolved,
then the enemy loses the configured amount of health.

### AC-09: Destroy a satellite

Given a satellite has zero health,
when destruction is resolved,
then that satellite stops participating in combat and the match ends.

### AC-10: Opponent behavior

Given a match is active,
then the enemy independently rotates, maneuvers, and fires according
to its AI policy.

### AC-11: Pause

Given a match is active,
when Escape opens the pause screen,
then combat simulation stops until the player resumes.

### AC-12: Restart

Given a match has ended,
when the player restarts,
then health, fuel, projectiles, timers, and satellite states reset.

### AC-13: Visual quality

Given a match is active,
then the scene displays a dark starfield, a planet, two recognizable
satellites with solar panels, propulsion effects, and visible projectiles.

### AC-14: Responsive presentation

Given the browser window changes size,
then the renderer and UI adapt without breaking gameplay.

### AC-15: Stability

Given an ordinary match is played and restarted repeatedly,
then no persistent duplicate input handlers, runaway projectiles,
or unbounded collections accumulate.

## 15. Suggested Initial Balance

These values are starting points for playtesting, not final requirements.

| Parameter | Starting value |
|---|---:|
| Player health | 100 |
| Enemy health | 100 |
| Maximum fuel | 100 |
| Main thrust fuel use | 18 units/second |
| Rotation fuel use | 4 units/second |
| Fuel regeneration | 8 units/second |
| Projectile damage | 20 |
| Projectile cooldown | 0.45 seconds |
| Projectile speed | 30 world units/second |
| Main engine acceleration | 5 world units/second² |

Tune these values after the basic simulation and combat loop work.

The initial game should not require a large number of successful hits
to produce a result, but the satellites should survive enough damage
for the player to learn from combat.

## 16. Delivery Milestones

### Milestone 1: Playable simulation

- Render the planet and starfield.
- Create both satellites.
- Implement rotation and thrust.
- Implement fuel consumption and regeneration.
- Verify momentum behavior.

### Milestone 2: Combat

- Add firing.
- Add projectiles and collision detection.
- Add damage and destruction.
- Add win/loss state.

### Milestone 3: Opponent

- Add target tracking.
- Add aiming and firing.
- Add fuel-aware maneuvering.
- Add difficulty settings.

### Milestone 4: Presentation

- Improve spacecraft models.
- Add propulsion and impact effects.
- Add HUD, title screen, pause screen, and results screen.
- Add audio where practical.

### Milestone 5: Quality

- Add automated tests.
- Tune gameplay balance.
- Verify browser resizing and focus handling.
- Profile performance.
- Complete acceptance testing.

## 17. Release Definition

The initial release is complete when a player can launch the game,
fight an autonomous enemy, maneuver using fuel-limited propulsion,
damage and destroy the enemy, receive a result, and immediately play
again.

All essential mechanics must work without debug tools or developer
intervention.
