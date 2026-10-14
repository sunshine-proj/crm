Below is a ready-to-use `AGENTS.md` for your **Worms PWA** project. It provides coding instructions for AI agents such as Codex, Claude Code, and other agentic coding tools.

It is tailored to your stack (vanilla JavaScript, Node.js, HTML5 Canvas, and PWA), with instructions for implementing game physics, destructible terrain, weapons, AI opponents, mobile controls, and offline support.

Create a file named **`AGENTS.md`** in the root of your `worms-pwa` directory and paste in the following content.

```
# AGENTS.md

## Project Overview

This project is a browser-based, turn-based, 2D artillery game inspired by
classic Worms-style gameplay.

The application is built with vanilla JavaScript, HTML5 Canvas, Node.js,
HTML, and CSS. It is designed as a Progressive Web App (PWA) with offline
support.

The goal is to evolve the existing prototype into a complete, playable,
mobile-friendly artillery game while keeping the codebase lightweight,
maintainable, and easy to run locally.

### Core Principles

- Preserve the existing technology stack unless a change is necessary.
- Prefer vanilla JavaScript over introducing frameworks.
- Keep the game playable throughout development.
- Prioritize correctness, deterministic behavior, and maintainability.
- Avoid unnecessary dependencies.
- Use original procedural graphics and effects.
- Do not use copyrighted game sprites, textures, sounds, or other proprietary
  assets.
- Preserve offline functionality when changing application files.
- Make incremental changes rather than rewriting working systems without
  justification.
- Never claim that a feature works without verifying it.

---

## 1. Technology Stack

### Required Technologies

- JavaScript (ES6+).
- HTML5 Canvas for rendering the battlefield.
- HTML5, CSS3, and responsive layout techniques.
- Node.js for the development server and any existing server-side features.
- Service workers for caching the application shell.
- Web APIs for input, audio, storage, and other browser functionality.

### Dependency Policy

Before adding a dependency:

1. Determine whether the functionality can be implemented using existing
   browser APIs or project utilities.
2. Check whether the dependency significantly improves reliability or
   maintainability.
3. Prefer small, actively maintained dependencies when necessary.
4. Avoid dependencies that require a complex build system for a small feature.
5. Document any significant new dependency.

Do not introduce React, Vue, Angular, TypeScript, a bundler, or another
framework unless explicitly requested or justified by a substantial
architectural improvement.

Preserve the project's existing module system and package configuration.

Do not change the module system merely for stylistic reasons.

---

## 2. Initial Repository Inspection

Before making changes, inspect the repository.

Read the following files if they exist:

- `package.json`
- `server.js` or the existing server entry point
- `index.html`
- Existing JavaScript source files
- Existing CSS files
- `manifest.json` or `manifest.webmanifest`
- `service-worker.js`, `sw.js`, or the existing service worker
- Existing tests and test configuration
- Existing project documentation

Identify the actual directory structure before editing files.

### Repository Rules

- Do not assume filenames or paths that have not been verified.
- Reuse existing utilities instead of creating duplicates.
- Follow established naming and formatting conventions.
- Do not overwrite working implementations without understanding them.
- Do not remove existing features unless explicitly requested or required.
- Keep changes focused on the requested task.
- Do not modify generated files or dependencies directly unless necessary.

If the repository differs from the documentation, treat the existing code
as the primary source of truth and update the documentation when appropriate.

---

## 3. Application Architecture

Keep rendering, physics, terrain, combat, input, and user interface logic
separate whenever practical.

Prefer small, cohesive modules with clear responsibilities.

A possible architecture for future development is:
```

worms-pwa/ ├── AGENTS.md ├── package.json ├── server.js ├── index.html ├── manifest.webmanifest ├── service-worker.js ├── css/ │ └── style.css ├── js/ │ ├── main.js │ ├── game.js │ ├── renderer.js │ ├── terrain.js │ ├── physics.js │ ├── projectile.js │ ├── weapons.js │ ├── worm.js │ ├── player.js │ ├── turn-manager.js │ ├── input.js │ ├── particles.js │ ├── audio.js │ ├── ai.js │ └── storage.js └── tests/

```

This structure is illustrative, not mandatory.

Do not create every listed file simply to match the example. Introduce
modules when their responsibilities justify separation.

### Module Responsibilities

- `main.js`: Application initialization and startup.
- `game.js`: Game state and high-level coordination.
- `renderer.js`: Canvas rendering and visual effects.
- `terrain.js`: Terrain generation, collision queries, and destruction.
- `physics.js`: Gravity, movement, collision detection, and projectile motion.
- `projectile.js`: Projectile state and simulation.
- `weapons.js`: Weapon definitions and firing behavior.
- `worm.js`: Worm state, movement, health, and damage.
- `player.js`: Player information and ownership of worms.
- `turn-manager.js`: Turn transitions, timing, and win conditions.
- `input.js`: Keyboard, pointer, and touch input.
- `particles.js`: Explosion debris and other particle effects.
- `audio.js`: Sound effects and audio lifecycle.
- `ai.js`: Computer-controlled player decisions.
- `storage.js`: Persistent settings and statistics.

Keep a single source of truth for important game state.

Avoid duplicating worm health, current player, terrain state, or projectile
state across unrelated modules.

---

## 4. Game State and Lifecycle

Represent the game lifecycle explicitly.

Suggested states include:

- `initializing`
- `aiming`
- `projectileFlying`
- `explosion`
- `wormFalling`
- `turnTransition`
- `gameOver`

Adapt the states to the existing architecture rather than introducing
unnecessary complexity.

### State Management Rules

- Only the appropriate state may accept a firing action.
- Prevent multiple projectiles from being fired simultaneously unless
  the game explicitly supports them.
- Prevent duplicate turn transitions.
- Resolve explosions and their damage before completing a turn.
- Do not advance a turn while a projectile or relevant explosion is active.
- Handle worms falling after terrain destruction before declaring the turn
  complete.
- Detect victory and defeat reliably.
- Prevent user input from modifying state after the game has ended.
- Reset all relevant state when starting a new game.

State transitions should be explicit and easy to test.

Avoid relying on arbitrary delays when a state transition can be triggered
by a simulation event.

---

## 5. Game Loop and Rendering

Use `requestAnimationFrame()` for rendering and the main simulation loop.

### Simulation Rules

- Separate simulation updates from drawing.
- Use elapsed time or a fixed simulation timestep rather than assuming
  every frame takes the same amount of time.
- Clamp unusually large elapsed-time values after tab suspension.
- Avoid frame-rate-dependent movement and projectile trajectories.
- Ensure the game behaves consistently at different display refresh rates.
- Avoid creating unnecessary objects during every rendering frame.
- Stop or reduce unnecessary work when the application is inactive.

For physics that benefit from reproducibility, prefer a fixed timestep
with an accumulator.

Rendering may interpolate between simulation states when appropriate.

### Canvas Rules

- Support different viewport sizes and device pixel ratios.
- Separate logical game coordinates from canvas display dimensions.
- Preserve the battlefield's aspect ratio where appropriate.
- Keep collision calculations independent of CSS scaling.
- Resize the canvas without accidentally resetting terrain or game state.
- Rebuild canvas-dependent resources when necessary after resizing.
- Avoid redrawing expensive static content unnecessarily.

Canvas drawing must not change game state.

Physics updates must not depend on whether a particular visual effect
was rendered.

---

## 6. Terrain Generation and Destruction

Terrain is a core gameplay system.

The battlefield should be generated procedurally using original graphics
and algorithms.

### Terrain Requirements

- Generate valid terrain within the battlefield boundaries.
- Provide suitable starting positions for all worms.
- Avoid spawning worms inside terrain or outside the battlefield.
- Ensure the terrain is compatible with the collision system.
- Support explosions that remove terrain.
- Keep rendering and collision data synchronized.
- Support terrain regeneration when starting a new game.

### Destructible Terrain

Explosions should remove terrain in a circular area or another shape
appropriate to the weapon.

When terrain is destroyed:

1. Calculate the affected region.
2. Update the collision representation.
3. Update the visual representation.
4. Apply damage to affected worms.
5. Allow unsupported worms to fall.
6. Resolve resulting collisions and state transitions.

Avoid situations where invisible terrain blocks projectiles or where
visible ground does not provide collision.

If a pixel mask is used, ensure that its coordinate system matches
the battlefield's logical coordinates.

### Terrain Performance

- Avoid scanning the entire terrain for every collision query.
- Limit terrain updates to affected regions when practical.
- Reuse buffers and data structures where appropriate.
- Ensure repeated explosions do not cause excessive memory allocation.
- Preserve correct behavior at terrain edges.

### Terrain Generation

Prefer configurable procedural generation parameters over hard-coded
landscape shapes.

Future terrain variations may include:

- Hills and valleys.
- Islands.
- Caves and tunnels.
- Narrow platforms.
- Multiple destructible terrain materials.

Do not introduce terrain types that have no defined gameplay behavior.

---

## 7. Worm Movement and Collision

Worm movement must use the same logical coordinate system as terrain
and projectile physics.

### Movement

Implement movement incrementally:

- Walking.
- Jumping.
- Gravity.
- Falling.
- Ground detection.
- Collision with terrain.
- Collision with battlefield boundaries.
- Damage and death handling.

### Collision Requirements

- Prevent worms from moving through solid terrain.
- Prevent worms from falling through the ground during normal movement.
- Handle terrain changes caused by explosions.
- Detect unsupported worms and initiate falling.
- Avoid repeatedly applying the same collision response within one update.
- Handle edge cases near vertical walls and terrain boundaries.

For fast-moving objects, use swept collision tests or substeps when
discrete collision checks could allow tunneling.

Do not use arbitrary position corrections as a substitute for a consistent
collision system.

### Death and Falling

Define the behavior of worms that fall outside the battlefield.

The implementation must consistently determine when a worm:

- Is standing on terrain.
- Is falling.
- Has taken damage.
- Has died.
- Is no longer eligible for the next turn.

Death should be processed exactly once.

---

## 8. Projectile Physics

Projectile simulation is central to artillery gameplay.

### Basic Physics

Implement projectile movement using velocity and acceleration.

A simplified model is:
```

velocityX += windAcceleration \* deltaTime velocityY += gravity \* deltaTime

positionX += velocityX \* deltaTime positionY += velocityY \* deltaTime

```

Adapt the equations to the chosen coordinate system and timestep.

If positive Y points downward, gravity should increase downward velocity.

### Launch Velocity

Calculate initial velocity from the firing angle and power.

Use consistent angle units internally. Prefer radians for trigonometric
calculations.

Convert degrees to radians at input boundaries when necessary.

### Collision Detection

Check projectiles against:

- Terrain.
- Worms.
- Battlefield boundaries.
- Weapon-specific collision conditions.

Use continuous collision detection or sufficiently small simulation
steps where necessary.

A projectile must not pass through a worm or a thin terrain feature simply
because it moved a large distance between updates.

### Wind

If wind is enabled:

- Define wind strength and direction explicitly.
- Apply wind consistently during simulation.
- Make its effect visible to players.
- Keep the behavior predictable and testable.

Do not introduce random trajectory changes without making them part
of the game's documented rules.

---

## 9. Weapons and Explosions

Implement weapons through a shared weapon interface rather than embedding
all weapon behavior in the firing button handler.

### Weapon Configuration

Each weapon should define its relevant properties, such as:

- Identifier.
- Display name.
- Projectile speed or launch parameters.
- Gravity behavior.
- Blast radius.
- Damage parameters.
- Terrain destruction behavior.
- Fuse duration, when applicable.
- Special collision or detonation rules.

Use sensible defaults where appropriate, but do not silently apply
one weapon's behavior to another.

### Initial Weapon Set

Prioritize:

1. Bazooka or rocket.
2. Timed grenade.
3. Additional weapons after the shared system is reliable.

### Explosion Processing

An explosion should:

- Detonate exactly once.
- Apply damage according to the selected weapon.
- Destroy the appropriate terrain region.
- Spawn visual effects.
- Trigger falling and collision updates when necessary.
- Notify the game state when its processing is complete.

### Damage

Use a consistent damage calculation based on the weapon and distance
from the explosion.

For example, damage may decrease with distance from the blast center.

Define how direct hits, blast edges, and terrain obstruction affect damage
before implementing more complex behavior.

Prevent one explosion from applying damage repeatedly across multiple frames.

Keep damage calculations independent of particle rendering.

### Timed Grenades

Grenades should maintain their own fuse timer.

Their behavior must not depend on the frame rate.

Define the behavior when a grenade collides with terrain, a worm,
or a battlefield boundary.

If bouncing is supported, use a consistent collision response and
avoid infinite bouncing or unstable penetration corrections.

---

## 10. Turn Management

The game uses turn-based artillery gameplay.

### Turn Rules

- Only the active player may control the current worm.
- A turn begins in a defined state.
- The active worm may aim and fire when permitted.
- The projectile resolves its flight and collision.
- Explosions and damage are processed.
- Worms affected by terrain destruction are allowed to fall.
- The game checks whether either player has won.
- If the match continues, the active player changes.

Do not switch turns before projectile resolution and required physics
updates have completed.

### Aiming

- Keep angle and power within defined bounds.
- Display the current aiming direction.
- Keep the aiming UI synchronized with the active worm.
- Update the trajectory preview when angle, power, or wind changes.
- Prevent firing when the game state does not allow it.

### Health and Victory

- Clamp health values to their allowed range.
- Process death only once.
- Detect when all worms belonging to a player have died.
- Prevent a new turn from starting after the match ends.
- Display an understandable victory or defeat message.

Keep turn-management rules separate from rendering and input handling.

---

## 11. AI Opponent

The AI should use the same game rules and weapon constraints as a human
player.

### AI Behavior

Start with a simple implementation:

1. Select a valid weapon.
2. Evaluate possible firing angles and power levels.
3. Simulate candidate trajectories.
4. Estimate terrain collisions and potential damage.
5. Select a reasonable shot.
6. Execute the shot through the normal game interface.

### AI Constraints

- Do not allow the AI to modify health directly to simulate a hit.
- Do not bypass projectile collision or explosion processing.
- Respect the active turn and weapon inventory.
- Keep AI calculations separate from rendering.
- Prevent AI decisions from blocking the browser for excessive periods.
- Handle cases where no useful shot is available.
- Ensure the AI eventually acts without requiring human input.

Use the same simulation rules for AI predictions and actual projectile
behavior wherever practical.

Perfect aiming is not required. Reasonable, varied, and understandable
decisions are preferable to expensive optimization.

---

## 12. Input and Controls

Support keyboard, mouse, and touch input.

### Keyboard Controls

Preserve the existing mappings unless a change is necessary:

- `A` / Left Arrow: Decrease aiming angle.
- `D` / Right Arrow: Increase aiming angle.
- `W` / Up Arrow: Increase firing power.
- `S` / Down Arrow: Decrease firing power.
- `Space`: Fire.
- `New Game`: Restart the battle.

Prevent keyboard input from unintentionally triggering actions while
the user interacts with unrelated form controls.

Avoid repeated firing caused by key repeat or duplicate event handlers.

### Mouse and Touch

- Make aiming and power controls usable on touchscreens.
- Ensure interactive controls have sufficiently large touch targets.
- Prevent accidental page scrolling during active touch interactions
  when appropriate.
- Avoid registering the same pointer action multiple times.
- Use Pointer Events when they simplify cross-device input handling.
- Ensure controls remain usable in portrait and landscape orientations.

### Input Architecture

Keep input processing separate from game rules.

Input handlers should request game actions rather than directly modifying
unrelated internal state.

All input methods must respect the same game-state restrictions.

---

## 13. User Interface and Accessibility

The interface should be clear, responsive, and easy to use.

### Required UI Elements

Preserve and improve the existing elements:

- Battlefield.
- Worms.
- Health bars.
- Aiming angle control.
- Firing power control.
- Fire button.
- Current player indicator.
- New Game control.

Add weapon selection, wind indicators, turn timers, or other controls
as their gameplay systems are implemented.

### UI Rules

- Use responsive layouts.
- Ensure important controls are visible on small screens.
- Keep text readable over the battlefield.
- Provide clear visual feedback for disabled actions.
- Avoid covering essential gameplay areas with controls.
- Maintain adequate contrast.
- Give buttons and controls meaningful accessible labels.
- Ensure interactive elements can be operated using a keyboard.
- Avoid relying exclusively on color to communicate important information.

Use semantic HTML for controls rather than drawing all interactive
elements directly on the canvas.

The canvas should be responsible for game visuals, not the entire
application interface.

---

## 14. Visual Effects and Audio

Use original procedural graphics and browser APIs.

### Visual Effects

Possible effects include:

- Explosions.
- Smoke.
- Debris.
- Terrain particles.
- Projectile trails.
- Damage indicators.
- Worm movement animations.
- Wind indicators.

### Performance

- Reuse particle objects when profiling demonstrates that pooling helps.
- Limit particle counts on low-powered devices.
- Avoid unbounded particle creation.
- Ensure visual effects cannot change game outcomes.
- Remove expired effects and release unnecessary references.

### Audio

Use the Web Audio API or original audio assets.

Requirements:

- Handle browsers that require user interaction before audio playback.
- Avoid playing sounds repeatedly during one event.
- Provide an option to mute audio.
- Avoid starting unnecessary audio processing when muted.
- Ensure audio failures do not interrupt gameplay.

Do not download or include proprietary game sounds.

---

## 15. Progressive Web App and Offline Support

Offline functionality is an important project requirement.

Preserve the application's ability to launch after its initial successful
load when the necessary application files are cached.

### Service Worker

When modifying the service worker:

- Inspect the current caching strategy first.
- Cache the actual application entry point and required local assets.
- Use a deliberate cache version.
- Remove obsolete caches when appropriate.
- Ensure cache updates do not leave the application in a broken state.
- Handle failed network requests gracefully.
- Avoid caching unnecessary development or debugging resources.
- Test the update lifecycle after changing cached files.

### Cache Versioning

When application files change in ways that require cache invalidation,
update the cache version according to the existing service-worker strategy.

Do not assume that editing a JavaScript file automatically updates
the cached copy in every browser.

### Offline Behavior

The application should:

- Launch offline after its required assets have been cached.
- Avoid requiring a network connection for core gameplay.
- Handle unavailable network resources gracefully.
- Continue to work without external fonts, sprites, or scripts whenever
  practical.

Match persistence is separate from application-shell caching.

Do not claim that match state persists across reloads unless it is
explicitly implemented and tested.

### PWA Manifest

Preserve valid manifest metadata, application name, display mode,
theme colors, and available icons.

Ensure icon paths refer to files that actually exist.

---

## 16. Persistence

Use browser storage only when a feature requires persistence.

Suitable options include:

- `localStorage` for small settings.
- IndexedDB for larger structured data or more complex persistence.

Potential persisted data includes:

- Sound settings.
- Control preferences.
- Difficulty settings.
- Statistics.
- Match history.
- Saved match state, if implemented.

### Persistence Rules

- Validate stored data before using it.
- Handle missing, malformed, or outdated data.
- Provide sensible defaults.
- Avoid storing unnecessary information.
- Do not assume browser storage is always available.
- Keep persistence failures from crashing the game.

If match saving is introduced, define a versioned save format and
handle incompatible versions safely.

---

## 17. Node.js Server

Preserve the existing Node.js server architecture.

The application should start using the documented command:
```

npm start

```

Expected local URL:
```

http://localhost:3000

```

### Server Requirements

- Keep startup behavior simple and predictable.
- Serve the required application files correctly.
- Set appropriate MIME types for JavaScript, CSS, manifests, and other assets.
- Handle missing files without exposing internal server details.
- Avoid unnecessary server-side complexity.
- Do not introduce a database or API unless a feature requires one.

If a WebSocket server is introduced for multiplayer, keep networking
logic separate from rendering and physics.

Do not introduce multiplayer infrastructure merely to support local
single-player gameplay.

Never commit credentials, secrets, or private configuration values.

---

## 18. Testing and Verification

Every meaningful change should be verified.

Prefer automated tests for deterministic game logic and manual browser
testing for visual and interaction behavior.

### Unit Tests

Where practical, test:

- Angle and power calculations.
- Projectile movement.
- Gravity.
- Wind acceleration.
- Terrain collision queries.
- Explosion radius calculations.
- Damage calculations.
- Health clamping.
- Worm death.
- Turn transitions.
- Victory conditions.
- Grenade fuse timing.
- AI trajectory evaluation.
- Save-data validation.

### Integration Tests

Verify interactions between:

- Projectiles and terrain.
- Projectiles and worms.
- Explosions and terrain destruction.
- Terrain destruction and worm falling.
- Damage and turn management.
- Match completion and UI updates.
- Service-worker caching and application startup.

### Manual Testing

When possible, verify:

1. The application starts with `npm start`.
2. The game loads without JavaScript errors.
3. The battlefield is rendered correctly.
4. Both worms have valid starting positions.
5. Aim and power controls work.
6. Keyboard controls work.
7. Mouse and touch controls work.
8. Projectiles follow consistent trajectories.
9. Terrain collision behaves correctly.
10. Explosions damage worms and remove terrain.
11. Turns switch only after the required actions complete.
12. New Game resets the match correctly.
13. The layout works on mobile screens.
14. Offline launch works after the initial assets are cached.

Do not report manual tests as completed if they were not performed.

### Existing Test Infrastructure

Inspect `package.json` before selecting commands.

Use the existing test runner when available.

Do not invent test scripts and assume they exist.

If automated tests are missing, add a lightweight test setup only when
it provides meaningful value and fits the existing architecture.

---

## 19. Error Handling and Debugging

Errors should be observable and recoverable when practical.

### Rules

- Avoid silently swallowing unexpected exceptions.
- Do not use empty `catch` blocks without a documented reason.
- Validate external input and persisted data.
- Handle browser API failures gracefully.
- Prevent invalid numerical values from propagating through physics.
- Detect `NaN` and infinite values in critical simulation calculations
  during development or testing.
- Avoid adding permanent console noise during normal gameplay.
- Use clear error messages for development failures.

Useful invariants include:

- Worm health remains within its permitted range.
- Projectile positions and velocities remain finite.
- Terrain dimensions remain valid.
- Exactly one player owns the active turn.
- A projectile cannot detonate more than once.
- A dead worm cannot take additional turns.
- A completed match cannot return to an active state without a reset.

Use assertions in tests and development tools where appropriate.

Do not add expensive validation to every production frame without
a demonstrated need.

---

## 20. Performance

Target smooth gameplay on modern desktop and mobile browsers.

### Performance Rules

- Profile before optimizing complex code.
- Avoid unnecessary full-canvas redraws when they are measurably expensive.
- Keep collision queries efficient.
- Avoid excessive DOM updates inside the simulation loop.
- Avoid unnecessary allocations in hot loops.
- Limit active particles and temporary objects.
- Avoid synchronous operations that block rendering.
- Keep AI simulations bounded.
- Test performance on representative viewport sizes.

Correctness takes priority over premature optimization.

Do not introduce workers, complex spatial indexes, or object pooling
unless the workload justifies the added complexity.

---

## 21. Code Quality

Write code that is straightforward to understand and modify.

### JavaScript Guidelines

- Use `const` by default.
- Use `let` when reassignment is required.
- Avoid `var` in new code.
- Prefer small functions with clear responsibilities.
- Use descriptive names.
- Avoid unexplained magic numbers.
- Extract shared behavior instead of duplicating it.
- Avoid global mutable state unless the existing architecture requires it.
- Handle asynchronous operations deliberately.
- Remove unused code introduced by the change.
- Avoid unnecessary abstraction.
- Keep comments focused on non-obvious decisions.

Use consistent units for physics calculations.

Document whether angles are expressed in degrees or radians and whether
distances are measured in pixels or logical world units.

Prefer explicit configuration objects for tunable gameplay parameters.

Do not refactor unrelated files just to satisfy personal style preferences.

### CSS Guidelines

- Reuse existing design tokens and variables.
- Avoid conflicting duplicate rules.
- Prefer responsive layout techniques.
- Preserve usability at small viewport sizes.
- Keep game controls visually distinct from battlefield content.

### HTML Guidelines

- Use semantic elements where appropriate.
- Provide meaningful labels for controls.
- Avoid unnecessary inline JavaScript.
- Keep application metadata valid.
- Ensure referenced files exist.

---

## 22. Security

Even though this is primarily a local browser game, follow basic security
practices.

- Do not introduce `eval()` for game logic.
- Avoid unsafe HTML injection.
- Prefer `textContent` when displaying dynamic text.
- Validate data received from network connections.
- Validate and constrain persisted values.
- Do not expose secrets in client-side code.
- Avoid unnecessary permissions and external requests.
- Use secure WebSocket connections in HTTPS deployments.
- Do not add remote scripts or assets without a clear reason.

If multiplayer is added, validate gameplay actions on the authoritative
server rather than trusting client-reported damage or match outcomes.

---

## 23. Multiplayer Readiness

Multiplayer is a possible future feature, not a requirement for every task.

Keep the core simulation sufficiently separated from UI code that it
can eventually support synchronized gameplay.

If multiplayer is implemented:

- Define a versioned message protocol.
- Validate every incoming message.
- Track player identity and authorization.
- Enforce turn ownership on the server.
- Validate weapon selection and firing parameters.
- Prevent duplicate or stale actions.
- Define how game state is synchronized.
- Handle disconnects and reconnections.
- Avoid trusting client-reported collisions, damage, or victory.
- Consider how simulation determinism affects synchronization.

Do not add networking complexity to tasks that concern only local gameplay.

---

## 24. Agent Workflow

Follow this workflow for every coding task.

### Step 1: Understand

- Read the relevant source files.
- Identify the existing implementation and its dependencies.
- Determine the expected behavior.
- Identify edge cases and affected systems.

### Step 2: Plan

- Choose the smallest implementation that solves the problem.
- Identify any architectural consequences.
- Consider effects on game state, physics, rendering, and input.
- Determine which tests or manual checks are needed.

For straightforward tasks, a short internal plan is sufficient.

Do not create extensive planning documents for trivial changes.

### Step 3: Implement

- Make focused changes.
- Preserve existing working functionality.
- Reuse existing abstractions.
- Avoid unrelated refactoring.
- Add tests for important new behavior.
- Update documentation when behavior or commands change.

### Step 4: Verify

- Run relevant automated tests.
- Check syntax and module compatibility.
- Run the application when practical.
- Inspect browser console errors when browser testing is available.
- Verify edge cases related to the modified behavior.

If verification cannot be performed, state that limitation explicitly.

### Step 5: Review

Check the changes for:

- Regressions.
- Incorrect game-state transitions.
- Physics inconsistencies.
- Duplicate event handlers.
- Memory leaks.
- Unnecessary dependencies.
- Broken asset paths.
- Broken offline caching.
- Mobile usability problems.
- Missing tests or documentation.

### Step 6: Report

At the end of the task, summarize:

- What changed.
- Which files were modified.
- Which tests or checks were performed.
- Any known limitations.
- Any follow-up work that remains necessary.

Be concise and distinguish verified results from assumptions.

---

## 25. Git and File Safety

- Do not overwrite unrelated user changes.
- Inspect `git status` before making substantial modifications when Git is available.
- Do not reset, clean, or discard user changes without explicit permission.
- Do not rewrite Git history unless explicitly requested.
- Do not commit changes unless requested or required by the established workflow.
- Do not edit files outside the project without a clear reason.
- Avoid modifying lockfiles unless dependencies change or regeneration is necessary.

Preserve existing work even when it does not match the preferred architecture.

---

## 26. Feature Priorities

Unless the user specifies another priority, implement improvements in
the following order.

### Priority 1: Core Reliability

- Stable game loop.
- Correct projectile trajectories.
- Reliable terrain collision.
- Accurate explosion processing.
- Correct health and damage handling.
- Reliable turn management.
- Consistent game reset behavior.

### Priority 2: Worm Movement

- Walking.
- Jumping.
- Gravity and falling.
- Terrain collision.
- Death from falling outside the battlefield.

### Priority 3: Weapons

- Reliable bazooka or rocket.
- Timed grenades.
- Weapon selection.
- Weapon-specific blast parameters.
- Wind effects.

### Priority 4: Gameplay Depth

- Improved procedural landscapes.
- Computer-controlled opponents.
- Improved trajectory previews.
- Additional original visual effects.
- Sound effects.

### Priority 5: Mobile and Persistence

- Improved touch controls.
- Responsive weapon selection.
- Persistent settings.
- Statistics and match history.
- Optional saved match state.

### Priority 6: Multiplayer

- Networking architecture.
- Authoritative game-state management.
- Synchronized turns.
- Reconnection handling.

This priority order is a guideline, not permission to ignore the user's
explicit task.

Do not implement multiple unrelated features in one change unless
requested.

---

## 27. Definition of Done

A task is complete when all applicable conditions are satisfied:

- The requested behavior is implemented.
- Existing behavior is preserved unless intentionally changed.
- Code follows the repository's conventions.
- Important edge cases are handled.
- Relevant automated tests pass.
- The application starts successfully when startup verification is possible.
- No known regressions are left unexplained.
- PWA functionality remains intact when affected.
- Documentation is updated when necessary.
- Verification limitations are reported honestly.

A feature is not complete merely because code was written.

---

## 28. Instructions for Future Agents

Always remember:

1. Inspect before editing.
2. Preserve the existing stack.
3. Keep game logic independent of rendering.
4. Keep physics consistent across frame rates.
5. Treat terrain destruction as a change to both visual and collision data.
6. Resolve explosions, falling, and damage before advancing turns.
7. Make AI use the same gameplay rules as human players.
8. Preserve offline functionality.
9. Test behavior instead of assuming it works.
10. Prefer small, verifiable changes over large rewrites.
11. Never claim to have tested something that was not tested.
12. Explain important limitations and trade-offs.

The ultimate objective is a reliable, maintainable, offline-capable,
turn-based artillery game that works well on desktop and mobile browsers.
```

## Recommended project workflow

After creating `AGENTS.md`, your project root should look approximately like this:

```
worms-pwa/
├── AGENTS.md
├── package.json
├── server.js
├── index.html
├── style.css
├── game.js
├── manifest.json
└── service-worker.js
```

The exact structure will depend on your existing files.

### How coding agents should use it

- **Codex:** Reads `AGENTS.md` as repository-specific instructions when working in the project.
- **Claude Code:** Can use `AGENTS.md` as project guidance, alongside its own supported instruction files.
- **Other coding agents:** Can follow it as the project's development specification when given access to the repository.

Keep `AGENTS.md` in the repository root so agents can discover the project-wide rules. If you later introduce dedicated subsystems with substantially different requirements, you can add nested `AGENTS.md` files for those directories.

### My recommendation

For your game, the most important instruction is to **separate physics, terrain collision, rendering, and turn management**. These systems interact heavily, and implementing movement, grenades, or AI before establishing reliable foundations can introduce bugs throughout the game.

I would therefore start with this development sequence:

1. **Stabilize the game loop and physics** so projectile movement and collisions behave consistently.
2. **Implement worm movement and falling** using a shared terrain collision system.
3. **Introduce a weapon system** supporting rockets and timed grenades.
4. **Implement an AI opponent** that uses the same projectile simulation as the human player.
5. **Polish mobile controls and offline support** without breaking existing gameplay.

This gives future coding agents clear boundaries, priorities, and verification requirements while preserving your existing vanilla JavaScript and Node.js architecture.
