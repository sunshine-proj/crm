# AGENTS.md

Guidance for AI coding agents working on **Moon Lander**, a Progressive Web App (PWA) game.

## Project overview

The player pilots a rocket and must land it safely on the Moon. Gravity constantly pulls the rocket down. The player spends limited fuel to fire thrusters (up, left, right) and control the descent. The lunar surface is uneven and covered with rocks, and only some areas are clear enough to land on. Landing on a rock, on a steep slope, or too fast destroys the rocket.

Goals:
- Fun, responsive arcade physics that is easy to learn and hard to master.
- Works well on phones (touch first) and desktop (keyboard).
- Installable and fully playable offline.

## Tech stack

This is the planned stack. If the repo already contains a different setup, follow what exists and do not migrate without being asked.

- **Language:** TypeScript (strict mode)
- **Rendering:** HTML5 Canvas 2D, no game engine, no UI framework
- **Build tool:** Vite
- **PWA:** Web App Manifest + hand-written or Workbox service worker
- **Tests:** Vitest for unit tests (physics, terrain, landing rules)
- **Lint/format:** ESLint + Prettier
- **Dependencies:** keep to a minimum. Ask before adding any runtime dependency.

## Commands

Update this section if scripts change.

```bash
npm install          # install dependencies
npm run dev          # start dev server
npm run build        # production build to dist/
npm run preview      # serve the production build (use this to test PWA/offline)
npm run test         # run unit tests
npm run lint         # lint
npm run typecheck    # tsc --noEmit
```

Before finishing any task, run `npm run typecheck`, `npm run lint`, and `npm run test`. Fix failures you caused.

## Suggested project structure

```
/
├── AGENTS.md
├── index.html
├── public/
│   ├── manifest.webmanifest
│   ├── icons/                # 192, 512, maskable icons
│   └── sw.js                 # service worker (if not generated)
├── src/
│   ├── main.ts               # entry, wires everything together
│   ├── game/
│   │   ├── loop.ts           # fixed-timestep game loop
│   │   ├── state.ts          # game state types and reducers
│   │   ├── physics.ts        # gravity, thrust, integration (pure functions)
│   │   ├── terrain.ts        # terrain + rock generation (seeded, pure)
│   │   ├── collision.ts      # rocket vs terrain/rocks detection
│   │   ├── landing.ts        # landing success/failure rules
│   │   └── levels.ts         # difficulty configuration per level
│   ├── input/
│   │   ├── keyboard.ts
│   │   └── touch.ts          # on-screen controls
│   ├── render/
│   │   ├── renderer.ts       # canvas drawing
│   │   ├── hud.ts            # fuel, speed, altitude display
│   │   └── effects.ts        # thrust flames, particles, explosion
│   ├── audio/                # optional sound effects
│   └── storage/              # localStorage for best scores/settings
└── tests/
```

## Game design spec

### Rocket and controls

- The rocket has three thrusters: **up** (main engine), **left**, and **right** (side thrusters push the rocket sideways).
- Each thrust consumes fuel only while it is active.
- With no fuel left, the rocket falls freely under gravity.
- Controls:
  - **Keyboard:** Arrow keys or WASD. Up/W = main thrust, Left/A = push left, Right/D = push right.
  - **Touch:** three large on-screen buttons (up, left, right), multi-touch so the player can hold up and a side button together. Do not rely on hover or mouse-only input.
- Optional down control: down is gravity. Do not add a downward thruster unless the design changes.

### Physics

Physics must be deterministic, frame-rate independent, and implemented as **pure functions** that can be unit tested.

- Use a **fixed timestep** (for example 60 Hz) with an accumulator. Render with `requestAnimationFrame` and interpolate if needed. Never tie physics to render frame rate.
- State per frame: position `(x, y)`, velocity `(vx, vy)`, fuel, and thruster flags.
- Each step: `v += (a_thrust + a_gravity) * dt`, then `p += v * dt`.
- Gravity is a constant downward acceleration (Moon-like feel, tuned for gameplay rather than strict realism).
- No atmosphere, so no drag by default. Optional tiny damping for playability is acceptable if documented.
- Keep all tuning values (gravity, thrust power, fuel burn rate, max safe speeds) in a **single config object** (`src/game/config.ts`) with comments. Do not scatter magic numbers.

### Terrain and rocks

- Terrain is a horizontal heightmap polyline generated procedurally from a **seed**, so levels are reproducible and testable.
- The surface contains **rocks** (obstacles) of varying size placed on the terrain. Rocks have collision shapes (circles or polygons).
- Every level must contain **at least one clear landing zone**: a flat, rock-free segment wide enough for the rocket (width scales with difficulty).
- Generation must guarantee solvability: validate that a clear zone exists and is reachable on screen. If validation fails, adjust or regenerate.
- Mark safe zones visibly (for example highlighted pads or markers). Difficulty can reduce how obvious or how large they are.

### Landing rules

A landing is **successful** only if all of these hold at first contact with the ground:

1. The rocket touches **clear flat ground** inside a valid landing zone, not a rock.
2. Vertical speed is below the safe threshold.
3. Horizontal speed is below the safe threshold.
4. The rocket tilt is within the allowed angle (if rotation is implemented).

Otherwise the rocket **crashes**. Touching any rock is always a crash. Thresholds live in config.

### Game flow

- Start screen, then play, then a result screen (landed or crashed) with retry/next level.
- Score can combine remaining fuel, landing softness, and time. Define the formula in one place.
- Progressive difficulty: more rocks, narrower landing zones, less fuel, stronger gravity.
- Store best score and settings in `localStorage`. Handle storage being unavailable.

## PWA requirements

The game must remain a valid installable PWA at all times.

- `manifest.webmanifest` with `name`, `short_name`, `start_url`, `display: "standalone"`, `orientation`, `theme_color`, `background_color`, and icons (192, 512, and a maskable icon).
- A **service worker** that precaches the app shell and all game assets so the game works **fully offline** after the first load.
- Use a versioned cache name and clean up old caches on activation. Make updates deliver predictably, such as showing an "update available" prompt rather than silently breaking a session.
- Serve over HTTPS in production (localhost is fine for development).
- Test PWA behavior against `npm run preview` or a production build, **not** the dev server.
- Verify with Lighthouse (PWA/installability) when changing manifest or service worker.

## UX and performance

- **Mobile first:** responsive canvas that scales to the viewport and handles devices with different pixel ratios (`devicePixelRatio`) and orientation changes.
- Prevent unwanted browser behavior during play: disable pull-to-refresh, double-tap zoom, text selection, and context menus on the game area (`touch-action: none`).
- Target a steady **60 FPS** on mid-range phones. Avoid allocating objects every frame in the game loop. Reuse objects and cache static terrain drawing in an offscreen canvas where it helps.
- Pause automatically when the tab is hidden (`visibilitychange`) and resume cleanly without a huge time jump.
- Accessibility: provide sufficient contrast, readable HUD text, and respect `prefers-reduced-motion` for screen shake and heavy effects. Do not rely on color alone to mark safe zones.
- Keep the initial bundle small. Avoid large assets. Prefer procedurally drawn graphics or lightweight SVG/WebP.

## Code conventions

- TypeScript `strict` enabled. No `any` unless justified with a comment.
- Keep game logic **separate from rendering and DOM**. Physics, terrain, collision, and landing code must not touch the DOM or canvas so they can be tested in Node.
- Prefer small pure functions and plain data objects over deep class hierarchies.
- Use a seeded PRNG (for example mulberry32) instead of `Math.random()` inside terrain generation and anything that must be reproducible.
- Name files in `kebab-case` or the existing convention, types in `PascalCase`, and constants in `UPPER_SNAKE_CASE` or the config object.
- Comment the **why** for tuning values and non-obvious math. Do not add noise comments.
- No `console.log` left in committed code, except in clearly marked debug helpers.

## Testing guidelines

Write unit tests for:
- **Physics:** gravity-only fall, thrust direction and magnitude, fuel depletion stops thrust, results independent of frame rate.
- **Terrain:** same seed gives the same terrain, every generated level has a valid clear landing zone, rocks do not overlap the landing zone.
- **Collision and landing:** rock contact is always a crash, safe-speed landing on a clear zone succeeds, too fast or off-zone fails.

Bug fixes should come with a regression test when practical. Do not weaken or delete tests to make them pass.

## Boundaries for agents

**Do:**
- Make focused, minimal changes related to the task.
- Keep gameplay constants centralized and tunable.
- Update this file when commands, structure, or rules change.

**Ask first:**
- Adding a game engine, UI framework, or any runtime dependency.
- Changing the physics model, control scheme, or landing rules.
- Changing the build tool, hosting setup, or service worker caching strategy.

**Never:**
- Commit secrets, API keys, or analytics/tracking without explicit approval.
- Break offline support or the installable PWA status.
- Make physics depend on frame rate or use `Math.random()` for reproducible content.
- Add third-party network requests at runtime (the game should work with no network).
- Remove or rewrite unrelated code while fixing something else.

## Definition of done

A change is done when:
1. It typechecks, lints, and passes tests.
2. The game plays correctly on desktop (keyboard) and mobile-sized viewport (touch).
3. The production build still installs and runs offline.
4. New gameplay rules or constants are reflected in config and, where relevant, in this file.
