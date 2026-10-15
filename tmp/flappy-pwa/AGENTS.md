# AGENTS.md

Guidance for AI coding agents working on this repository. Read this fully before making changes.

## Project overview

A Flappy Bird-style game built as an installable, offline-capable Progressive Web App (PWA). The player taps/clicks/presses Space to flap, avoids pipes, and tries to beat their high score.

**Goals:** smooth 60 FPS gameplay, instant load, full offline play, installable on mobile and desktop.
**Non-goals:** multiplayer, backend services, accounts, ads, tracking.

> **Assumed stack** (update this section if the repo differs): TypeScript, Vite, HTML5 Canvas 2D (no game engine), Vitest for unit tests, Playwright for E2E, `vite-plugin-pwa` (Workbox) for the service worker.

## Commands

| Task | Command |
|---|---|
| Install | `npm ci` |
| Dev server | `npm run dev` |
| Type check | `npm run typecheck` |
| Lint | `npm run lint` |
| Format | `npm run format` |
| Unit tests | `npm test` |
| E2E tests | `npm run test:e2e` |
| Production build | `npm run build` |
| Preview build (required for testing the service worker) | `npm run preview` |
| Lighthouse PWA audit | `npm run lighthouse` |

Always run `npm run typecheck && npm run lint && npm test` before declaring a task done. Run `npm run build` if you touched PWA, config, or asset files.

**Important:** the service worker is disabled or unreliable in `dev`. Verify PWA behavior only against `npm run build && npm run preview`.

## Project structure

```
.
├── AGENTS.md
├── index.html
├── public/
│   ├── manifest.webmanifest      # Web app manifest
│   ├── icons/                    # 192, 512, maskable 512, apple-touch-icon
│   └── offline.html              # Fallback if something is truly uncached
├── src/
│   ├── main.ts                   # Entry: wires canvas, input, loop, SW registration
│   ├── game/
│   │   ├── state.ts              # Game state types + initial state
│   │   ├── update.ts             # Pure update(state, dt, input) logic
│   │   ├── physics.ts            # Gravity, flap impulse, collision (pure)
│   │   ├── pipes.ts              # Pipe spawning/recycling (seedable RNG)
│   │   ├── score.ts              # Scoring + high score persistence
│   │   └── constants.ts          # All tunable numbers live here
│   ├── render/
│   │   ├── renderer.ts           # Canvas drawing (reads state, never mutates it)
│   │   ├── sprites.ts            # Sprite/asset loading
│   │   └── ui.ts                 # Menu, HUD, game-over overlays
│   ├── input/
│   │   └── input.ts              # Pointer, touch, keyboard → unified "flap" event
│   ├── audio/
│   │   └── audio.ts              # Web Audio wrapper (unlock on first gesture)
│   ├── pwa/
│   │   ├── register-sw.ts        # SW registration + update prompt
│   │   └── install-prompt.ts     # beforeinstallprompt handling
│   └── styles.css
├── tests/
│   ├── unit/                     # Vitest: physics, collision, scoring
│   └── e2e/                      # Playwright: load, play, offline, install
└── vite.config.ts
```

## Architecture rules

1. **Separate logic from rendering.** `src/game/*` is pure TypeScript: no `document`, `window`, `canvas`, or `Date.now()`. It takes state, input, and `dt`, and returns new state. This keeps it unit-testable and deterministic.
2. **Renderer is read-only.** `src/render/*` reads state and draws. It must never change game state.
3. **Fixed-timestep update loop.** Use `requestAnimationFrame` with an accumulator (e.g. 1/120 s steps) so physics behave identically on 60 Hz, 120 Hz, and 144 Hz displays. Never tie physics to frame rate. Clamp large `dt` (e.g. after the tab was backgrounded) to avoid tunneling through pipes.
4. **Single source of truth for tuning.** Gravity, flap strength, pipe gap, pipe speed, spawn interval, and similar values belong in `constants.ts`. No magic numbers elsewhere.
5. **Unified input.** All input sources (pointer, touch, Space/ArrowUp) produce one `flap` action. Use Pointer Events, not separate mouse and touch handlers. Call `preventDefault` where needed to avoid scroll, zoom, and double-tap delay.
6. **Deterministic randomness.** Pipe heights come from a seedable RNG passed into game logic so tests can reproduce runs.
7. **No allocation in the hot path.** Reuse/recycle pipe objects. Avoid creating arrays or closures every frame.

## Game behavior spec

- States: `menu → ready → playing → gameOver → (restart) ready`.
- Flap sets vertical velocity to a fixed upward impulse (not additive).
- Score increments once per pipe pair when the bird's x passes the pair's trailing edge.
- Collision: bird hitbox is a circle or slightly shrunk rectangle (more forgiving than the sprite); pipes and ground/ceiling are fatal.
- High score persists in `localStorage` under the key `flappy:highScore`. Wrap access in try/catch (private mode / storage disabled) and fall back to memory.
- Pause automatically on `visibilitychange` (tab hidden) and resume only on explicit input.
- Respect `prefers-reduced-motion` (disable screen shake and heavy flashes).

## Canvas and rendering

- Use a fixed **logical resolution** (e.g. 288×512) and scale to fit the viewport, preserving aspect ratio and letterboxing as needed.
- Account for `devicePixelRatio` for crisp rendering; scale the backing store, not the logical coordinates.
- Disable image smoothing for pixel-art sprites (`ctx.imageSmoothingEnabled = false`).
- Handle `resize` and `orientationchange` without restarting the game.
- Use `touch-action: none` and `user-select: none` on the canvas container.

## PWA requirements

These are hard requirements. Do not regress them.

- **Manifest** (`public/manifest.webmanifest`): `name`, `short_name`, `start_url: "/"` (or the configured base), `scope`, `display: "standalone"`, `orientation: "portrait"`, `background_color`, `theme_color`, and icons at 192×192, 512×512, plus a **maskable** 512×512.
- **HTML head:** `<link rel="manifest">`, `<meta name="theme-color">`, `<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">`, `apple-touch-icon`, and `apple-mobile-web-app-capable`.
- **Service worker:**
  - Precache the full app shell: HTML, JS, CSS, sprites, audio, fonts, and icons. The game must work fully offline after the first load.
  - Use cache-first for static hashed assets; network-first (with cache fallback) for navigation.
  - Cache names are versioned; delete old caches on `activate`.
  - Do not call `skipWaiting()` automatically. Show a non-blocking "Update available, reload" prompt and let the user choose, so a refresh never interrupts a run.
  - Never cache third-party requests or analytics (there should be none).
- **Installability:** handle `beforeinstallprompt` (stash the event, show a custom "Install" button on the menu screen only, never during play). Provide guidance text for iOS Safari ("Share → Add to Home Screen").
- **Audio:** must be unlocked on the first user gesture (iOS/Safari autoplay policy).
- **Lighthouse:** PWA/installable checks pass, Performance ≥ 90, Accessibility ≥ 90 on a mobile profile.

### When you change assets

If you add, rename, or remove any sprite, sound, or font, confirm it is included in the precache manifest and that the build output lists it. A missing precache entry causes broken offline play.

## Performance budget

- Initial JS ≤ 100 KB gzipped (no heavy frameworks or game engines).
- Total precache ≤ 2 MB.
- Compress sprites into a single atlas where practical; prefer WebP/PNG for images and short OGG/MP3/AAC clips for audio.
- Keep frame time under 16 ms on a mid-range phone. Profile before adding per-frame effects.

## Code style

- TypeScript `strict` mode. No `any` without a comment explaining why.
- Prefer small pure functions and plain data objects over class hierarchies.
- Named exports; avoid default exports.
- File names: `kebab-case.ts`. Types/interfaces: `PascalCase`. Constants: `UPPER_SNAKE_CASE`.
- Comments explain *why*, not *what*. Document any tuning constant whose value is non-obvious.
- Follow the existing ESLint and Prettier config; do not reformat unrelated files.

## Testing guidelines

**Unit (Vitest)** — required for any change in `src/game/`:
- Flap sets expected velocity; gravity integrates correctly across different `dt` values.
- Collision: bird vs. pipe edges, ground, and ceiling (including edge-touching cases).
- Scoring: increments exactly once per pipe pair.
- Pipe spawn and recycle with a seeded RNG.

**E2E (Playwright)** — cover:
- App loads and the menu appears.
- Input starts and flaps the bird (keyboard and pointer).
- Game-over appears after a collision, and restart works.
- High score persists across reloads.
- **Offline:** load once, go offline (`context.setOffline(true)`), reload, and the game still runs.
- Manifest is valid and served; service worker is registered.

Do not delete or weaken a failing test to make it pass. Fix the cause or ask for guidance.

## Accessibility

- Provide a visible, focusable "Start" / "Restart" button; Enter and Space activate it.
- Expose score and state changes via an `aria-live="polite"` region (not on every frame, only on score and game-over).
- Maintain sufficient color contrast on HUD text.
- Provide a mute toggle; persist the preference.
- Do not rely on sound alone for any critical feedback.

## Security and privacy

- No network calls at runtime, no telemetry, no third-party scripts.
- Do not commit secrets, API keys, or `.env` files.
- Keep a strict Content Security Policy compatible with the build (no `unsafe-eval`).
- Add new dependencies sparingly and only when necessary. Justify each one, since bundle size is a hard constraint.

## Git and PR conventions

- Conventional Commits (`feat:`, `fix:`, `perf:`, `chore:`, `test:`, `docs:`).
- Small, focused PRs. One concern per PR.
- Include in the PR description: what changed, how it was tested, and (for visual changes) a screenshot or short clip.

## Do

- Run typecheck, lint, and tests before finishing.
- Keep `src/game/` pure and covered by tests.
- Test PWA changes against the production preview build, not dev.
- Bump the cache/version strategy correctly when changing the service worker.
- Ask for clarification when game-feel values (gravity, gap size, speed) are ambiguous. Propose values in `constants.ts` and explain the reasoning.

## Don't

- Don't add a game engine or UI framework (Phaser, Pixi, React, etc.) without explicit approval.
- Don't use `setInterval`/`setTimeout` for the game loop.
- Don't mutate game state from the renderer or input handlers (input only enqueues actions).
- Don't use `Date.now()` or `Math.random()` inside `src/game/`.
- Don't auto-reload the page on service worker updates.
- Don't embed large assets as base64 in JS/CSS.
- Don't modify `manifest.webmanifest`, icons, or the SW strategy as a side effect of unrelated work.

## Definition of done

- [ ] Feature works on desktop (keyboard + mouse) and mobile (touch), portrait orientation
- [ ] Typecheck, lint, unit, and E2E tests pass
- [ ] Production build succeeds and the app works offline from the preview server
- [ ] No regression in bundle size or Lighthouse scores
- [ ] No console errors or warnings
- [ ] Docs/AGENTS.md updated if conventions or commands changed
