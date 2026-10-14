# AGENTS.md — Orbital Combat

## 1. Project Mission

Build a polished, playable orbital-combat game featuring two satellites
fighting around a planet.

The player controls a satellite using:
- Arrow Left / Arrow Right: rotate using attitude-control thrusters.
- Arrow Up: accelerate forward using the main propulsion system.
- Space: fire the satellite's weapon.

The opponent controls its own satellite and attempts to destroy the player.

Propulsion fuel is limited but regenerates over time. Combat requires
positioning, momentum management, aiming, and resource conservation.

## 2. Product Priorities

Prioritize these goals in order:

1. Responsive, understandable controls.
2. Satisfying orbital movement and momentum.
3. Fair and engaging satellite combat.
4. Attractive, cohesive space visuals.
5. Reliable collision detection and damage handling.
6. Clear feedback for fuel, health, hits, and destruction.
7. Maintainable architecture and testability.

Do not sacrifice playable combat to add decorative features.

## 3. Technology

Unless the existing repository establishes a different stack, use:

- TypeScript for game logic.
- Three.js for 3D rendering.
- Vite for development and production builds.
- Vitest for unit tests.
- Playwright for browser-level tests when appropriate.

Use the browser as the initial deployment target.

Keep gameplay logic independent of Three.js wherever practical.
Rendering code must not be responsible for authoritative game rules.

Do not introduce additional dependencies without a clear benefit.

## 4. Architecture

Separate the application into these conceptual systems:

- Game lifecycle and state management.
- Input handling.
- Orbital simulation.
- Satellite state and propulsion.
- Weapon simulation and collision detection.
- Damage and destruction.
- Opponent AI.
- Rendering and visual effects.
- Audio.
- HUD and menus.
- Configuration and balancing.
- Automated tests.

Use explicit interfaces between systems.

The simulation should be updateable independently of rendering.
Use a fixed simulation timestep or another deterministic update strategy.

Avoid placing the entire game inside one component or source file.

Suggested source layout:

src/
  app/
  game/
    Game.ts
    GameState.ts
    GameConfig.ts
  simulation/
    PhysicsWorld.ts
    OrbitSystem.ts
    SatelliteController.ts
    CombatSystem.ts
    ProjectileSystem.ts
  ai/
    OpponentAI.ts
  rendering/
    SceneManager.ts
    PlanetRenderer.ts
    SatelliteRenderer.ts
    EffectsRenderer.ts
  input/
    InputManager.ts
  ui/
    HUD.ts
    MainMenu.ts
    ResultsScreen.ts
  audio/
    AudioManager.ts
  tests/

Adapt this structure to the actual implementation rather than
creating empty files solely to match the tree.

## 5. Core Gameplay Rules

### Satellite movement

- Satellites orbit a central planet.
- Each satellite has position, velocity, orientation, health, and fuel.
- Forward thrust changes velocity.
- Rotation is controlled by attitude-control thrusters.
- Releasing movement controls must not automatically cancel momentum.
- Fuel decreases when propulsion is used.
- Fuel regenerates gradually when propulsion is not consuming it.
- Fuel must remain between zero and its configured maximum.

### Combat

- Satellites can fire projectiles in the direction their weapons face.
- Projectiles travel through the simulation and can hit satellites.
- A successful hit applies damage.
- A satellite is destroyed when its health reaches zero.
- The match ends when either satellite is destroyed.
- A destroyed satellite cannot continue firing or receiving ordinary
  gameplay commands.

### Controls

- Left Arrow: rotate counterclockwise.
- Right Arrow: rotate clockwise.
- Up Arrow: activate the main engine.
- Space: fire.
- Escape: pause or open the pause menu.

Prevent browser scrolling and unwanted browser shortcuts while the
gameplay canvas has focus.

Support continuous rotation while a rotation key is held.

Use configurable key bindings rather than embedding key codes
throughout the simulation.

## 6. Physics Rules

Use a coherent simulation model.

- Position and velocity must use consistent world units.
- Apply thrust as acceleration or force.
- Apply gravity if the selected orbital model includes it.
- Do not teleport satellites to create the appearance of movement.
- Do not reset velocity when thrust is released.
- Use collision radii or another explicit collision model.
- Avoid tunneling by using swept collision tests or sufficiently small
  fixed simulation steps.
- Keep numerical tolerances and simulation parameters configurable.

A simplified, stable orbital model is preferable to an unstable
attempt at perfect physical realism.

## 7. Visual Direction

The game should feel like a cinematic spacecraft engagement.

Required visual characteristics:

- Dark space background.
- Dense but restrained starfield.
- Large, detailed planet.
- Realistic satellite silhouettes.
- Metallic satellite bodies.
- Visible solar panels with cell patterns.
- Main propulsion nozzle on the rear of each satellite.
- Small attitude-control thrusters around the spacecraft.
- A weapon mounted opposite the main engine.
- Visible weapon projectiles.
- Short-lived thrust and impact effects.
- Readable damage and destruction effects.

Keep spacecraft orientation visually consistent with their physics
and weapon direction.

Use lighting, material response, scale, and contrast to create depth.

Visual effects must never obscure critical combat information.

## 8. User Interface

The gameplay HUD must communicate:

- Player health.
- Opponent health.
- Player fuel.
- Weapon cooldown or firing readiness, if applicable.
- Pause state.
- Match outcome.

Use readable typography and high-contrast indicators.

The initial experience should include:
- A title screen.
- A start button.
- Brief control instructions.
- A gameplay screen.
- Win/loss feedback.
- A restart action.

Avoid unnecessary UI elements during the initial playable release.

## 9. Game Balance

Store gameplay tuning values in a central configuration.

At minimum, configure:
- Maximum fuel.
- Fuel consumption.
- Fuel regeneration.
- Main engine acceleration.
- Rotation speed.
- Rotation-thruster fuel consumption.
- Maximum health.
- Projectile speed.
- Projectile damage.
- Fire cooldown.
- Projectile lifetime.
- Orbital gravity.
- Opponent difficulty.

Avoid unexplained numeric constants scattered throughout the code.

The player must be able to recover from ordinary mistakes.
Fuel regeneration should encourage tactical pauses without creating
long periods of inactivity.

## 10. Performance

Target smooth gameplay on ordinary desktop hardware.

- Avoid unnecessary object creation in the main simulation loop.
- Reuse projectile and visual-effect objects where beneficial.
- Dispose of GPU resources correctly.
- Avoid excessive draw calls for repeated visual elements.
- Keep simulation frequency independent of display refresh rate.
- Handle browser resizing.
- Pause or reduce simulation activity when appropriate.

Measure performance before introducing complex optimizations.

## 11. Engineering Practices

- Enable TypeScript strict mode.
- Use descriptive names and narrow interfaces.
- Keep functions focused.
- Avoid duplicated gameplay rules.
- Avoid unexplained global mutable state.
- Separate gameplay configuration from implementation.
- Add tests for critical mechanics.
- Handle cleanup for listeners, timers, and rendering resources.
- Do not leave unfinished TODOs in core gameplay paths.
- Do not claim a test passed unless it was actually run.

Before completing a task:

1. Run the relevant tests.
2. Run TypeScript checks.
3. Run the production build.
4. Resolve new errors and regressions.
5. Summarize changes and remaining limitations.

## 12. Definition of Done

A feature is complete when:

- Its acceptance criteria are satisfied.
- It integrates with the existing architecture.
- It has appropriate tests.
- It does not break existing gameplay.
- Its controls and feedback are understandable.
- It works at different window sizes where relevant.
- It introduces no known critical console errors.

## 13. Scope Control

Implement a complete, small playable game before adding advanced features.

Do not add multiplayer, progression systems, elaborate menus, or
complex campaign mechanics unless explicitly requested.

Prefer a reliable single-player combat loop over a large unfinished
feature set.

## 14. Skill Instructions

Before implementing a substantial task, consult the applicable skill:

- Game design: skills/game-design/SKILL.md
- Orbital physics: skills/orbital-physics/SKILL.md
- Gameplay programming: skills/gameplay-programming/SKILL.md
- Opponent AI: skills/ai-opponent/SKILL.md
- Visual art: skills/visual-art/SKILL.md
- Audio: skills/audio-design/SKILL.md
- UI/UX: skills/ui-ux/SKILL.md
- Testing: skills/testing/SKILL.md

If instructions conflict, follow this precedence:

1. Explicit user requirements.
2. This AGENTS.md.
3. PRD.md.
4. The relevant skill file.
5. Local implementation preferences.
