# AGENTS.md — Classic Brick Game PWA

## 1. Project Overview

Build a Progressive Web App (PWA) that recreates the experience of playing a classic handheld Brick Game device from the 1990s and early 2000s.

The application must simulate both the physical handheld device and its built-in LCD games.

The experience should feel like using a real retro handheld:

- A recognizable physical handheld console.
- A grey, monochrome LCD screen with visible pixel-based graphics.
- Physical-looking buttons with tactile visual feedback.
- A game selection menu navigated using the console's buttons.
- Ten playable retro games.
- Classic electronic sound effects.
- Pixel-perfect game graphics and simple arcade mechanics.
- Responsive layouts for mobile, tablet, and desktop.
- PWA support, including offline play.

**Core principle:** This is a simulation of a physical handheld game console, not a conventional website containing ten separate games.

The console must remain the central visual element throughout the experience.

---

## 2. Required Technology

Use the following technologies unless the existing project already establishes an alternative stack.

- React
- TypeScript
- Vite
- HTML5 Canvas for rendering games
- CSS for the physical console, screen, buttons, and responsive layout
- Web Audio API for synthesized retro sound effects
- Service worker for offline support
- Web App Manifest for PWA installation

Use strict TypeScript settings.

Avoid unnecessary dependencies. Prefer small, focused libraries over large UI frameworks.

Do not use an external game engine unless the project requirements change substantially.

The app should run as a normal website and be installable as a PWA.

---

## 3. The Ten Required Games

Implement all ten games as distinct, playable experiences.

### Game 1: Tetris

**Objective:** Arrange falling blocks to complete horizontal lines.

Requirements:

- Seven standard tetromino shapes.
- Grid-based collision detection.
- Piece rotation.
- Horizontal movement.
- Soft drop.
- Automatic falling.
- Line clearing.
- Score tracking.
- Increasing difficulty.
- Game-over detection.
- Next-piece preview, if screen space permits.

Use a compact grid suitable for the monochrome handheld LCD.

### Game 2: Racing

**Objective:** Drive along a scrolling road and avoid other vehicles.

Requirements:

- Player vehicle controlled horizontally.
- Scrolling road with lane markings.
- Enemy vehicles that spawn above the screen.
- Collision detection.
- Increasing speed and difficulty.
- Score based on distance or time survived.
- Game-over screen.

The graphics should resemble simple pixel-art racing games from early LCD handhelds.

### Game 3: Snake

**Objective:** Collect food and grow without colliding with walls or the snake's own body.

Requirements:

- Grid-based movement.
- Directional controls.
- Food spawning.
- Snake growth.
- Score tracking.
- Wall collision.
- Self-collision.
- Increasing difficulty as the snake grows.
- Game-over screen.

Prevent the player from reversing directly into the opposite direction.

### Game 4: Tank

**Objective:** Control a tank and destroy incoming enemies.

Requirements:

- Player-controlled tank.
- Directional movement.
- Shooting.
- Enemy spawning.
- Projectile collision detection.
- Basic obstacles or barriers.
- Score tracking.
- Increasing difficulty.
- Health or lives.
- Game-over screen.

Use a simple top-down battlefield with monochrome pixel graphics.

### Game 5: Arkanoid / Breakout

**Objective:** Bounce a ball using a paddle to destroy bricks.

Requirements:

- Horizontal paddle movement.
- Ball physics.
- Wall collision.
- Paddle collision.
- Brick destruction.
- Multiple brick rows.
- Score tracking.
- Multiple lives.
- Ball reset after losing a life.
- Victory when all bricks are destroyed.

Ensure the ball cannot become trapped moving indefinitely along a horizontal or vertical axis.

### Game 6: Frogger

**Objective:** Guide a frog across traffic and obstacles to reach a safe destination.

Requirements:

- Grid-based movement.
- Road lanes.
- Moving vehicles.
- Safe starting area.
- Destination area.
- Collision detection.
- Multiple attempts or lives.
- Score tracking.
- Increasing difficulty.

Keep the visual design simple enough to be readable on a small monochrome screen.

### Game 7: Space Invaders

**Objective:** Destroy descending enemy formations before they reach the player.

Requirements:

- Player-controlled spaceship.
- Horizontal movement.
- Shooting.
- Multiple enemy rows.
- Enemy movement patterns.
- Enemy projectiles, if practical.
- Optional destructible shields.
- Score tracking.
- Increasing difficulty.
- Game-over and victory conditions.

Use classic pixel-style alien silhouettes.

### Game 8: Ping-Pong

**Objective:** Keep the ball in play and defeat an AI-controlled opponent.

Requirements:

- Player-controlled paddle.
- AI opponent.
- Ball movement and collision detection.
- Paddle-based ball deflection.
- Score tracking.
- Winning score.
- Increasing AI difficulty, if appropriate.
- Serve and restart mechanics.

Use a vertical playfield with a center divider and a compact scoreboard.

### Game 9: Football

**Objective:** Play a simplified arcade football game.

Requirements:

- A small top-down pitch.
- Player-controlled footballer.
- AI-controlled opponent.
- Ball movement and kicking.
- Goal detection.
- Scoreboard.
- Match timer or target score.
- Restart after a goal.
- Match victory and defeat states.

The game should be immediately playable and understandable using the handheld's limited controls.

### Game 10: Shooting Gallery

**Objective:** Shoot targets that appear in different positions.

Requirements:

- Player-controlled aiming cursor or crosshair.
- Targets that appear and disappear.
- Shooting controls.
- Hit detection.
- Score tracking.
- Time limit or limited ammunition.
- Increasing difficulty.
- Game-over screen.

Use simple pixel-art targets and recognizable hit feedback.

---

## 4. Physical Handheld Design

The console must look like a classic standalone Brick Game device.

### Overall appearance

Create a custom CSS-rendered handheld console rather than presenting the games inside a generic browser panel.

The device should feature:

- A compact, vertically oriented plastic body.
- Rounded corners.
- A dark grey or charcoal plastic shell.
- Subtle bevels and inset surfaces.
- A recessed rectangular LCD display.
- A directional control area.
- Separate action buttons.
- A small speaker grille.
- A retro product label or decorative model name.
- Subtle material shading and shadows.

Use CSS gradients, borders, pseudo-elements, and layered shadows to simulate molded plastic.

Avoid excessive glossy gradients, neon colors, glassmorphism, and futuristic styling.

The result should resemble an actual inexpensive handheld game from the late 1990s or early 2000s.

### Color palette

Use a restrained retro palette.

Suggested colors:

| Element | Color |
| --- | --- |
| Console shell | `#353535` |
| Shell highlights | `#555555` |
| Shell shadows | `#1B1B1B` |
| LCD background | `#AEB4A0` |
| LCD highlight | `#C6CBB8` |
| LCD pixels | `#343A30` |
| Secondary text | `#707565` |
| Button surfaces | `#292929` |
| Button highlights | `#484848` |

The LCD must remain monochrome.

Do not use colored game graphics inside the LCD.

### Console proportions

Design the console around a portrait aspect ratio.

The console should:

- Be centered in the available viewport.
- Preserve its proportions across screen sizes.
- Remain large enough to make the LCD readable.
- Fit within the viewport without requiring page scrolling on typical mobile devices.
- Adapt to landscape mobile orientation without clipping essential controls.

Use CSS sizing functions such as `clamp()`, `min()`, and viewport-relative units.

Do not stretch the console independently along its horizontal and vertical axes.

---

## 5. Monochrome LCD Rendering

The LCD is the visual centerpiece of the application.

Render all game graphics on an HTML Canvas element contained within the simulated LCD bezel.

### Visual requirements

- Grey-green monochrome background.
- Dark pixels.
- Sharp, hard pixel edges.
- No antialiasing for game sprites.
- No smooth gradients inside the game area.
- No modern colorful interface elements.
- Pixel-based text and numbers.
- A fixed logical resolution scaled to the physical display.
- Subtle LCD texture or pixel-grid detailing, implemented with CSS if desired.

Use `image-rendering: pixelated` where applicable.

Set Canvas image smoothing to false when scaling pixel graphics.

A practical starting point is a logical resolution of approximately 160 × 144 pixels. The precise resolution may vary by game, but the physical LCD dimensions should remain consistent.

Scale the logical canvas to fit the LCD without distorting the pixel aspect ratio.

### LCD states

The display must support these states:

1. Attract or startup screen.
2. Game selection menu.
3. Game instructions.
4. Active gameplay.
5. Paused gameplay.
6. Game-over screen.
7. Victory screen.
8. Score display.

Use the same visual language for every state.

### Display overlays

When appropriate, show:

- Game number.
- Game title.
- Current score.
- High score.
- Remaining lives.
- Level or speed.
- Start prompt.

Do not crowd the LCD with interface elements that obscure gameplay.

---

## 6. Game Selection Experience

The application opens directly into the handheld's game selection screen.

The user must be able to browse all ten games using the console controls.

### Initial state

On startup:

- Display the physical handheld.
- Render the LCD menu.
- Highlight the first game.
- Display the game number and title.
- Show a subtle blinking start prompt.

Example:

text

```
+------------------+
|    BRICK GAME    |
|                  |
|   GAME SELECT    |
|                  |
|   01 TETRIS      |
|                  |
|   HIGH SCORE     |
|       0000       |
|                  |
|  PRESS START     |
+------------------+
```

The ASCII illustration is conceptual. The actual interface must be rendered using the monochrome pixel display.

### Navigation

The game list must loop continuously.

- Pressing the previous-game control selects the preceding game.
- Pressing the next-game control selects the following game.
- Moving backward from game 1 selects game 10.
- Moving forward from game 10 selects game 1.
- The selected game number and title update immediately.
- Navigation should produce a short electronic click or selection sound.

Do not require mouse interaction to navigate the menu.

### Starting a game

When the user presses the Start button:

1. Transition to the selected game's instruction or ready screen.
2. Display a brief countdown or ready animation when appropriate.
3. Start the game after the user confirms, or automatically after a short delay if that better matches the game.
4. Begin gameplay with the correct controls enabled.

The start interaction must be reliable on both touch devices and desktop browsers.

### Returning to the menu

Provide a dedicated menu or back control.

Returning to the menu must:

- Stop the active game loop.
- Stop game-specific timers.
- Clear active input states.
- Stop or appropriately reset sound effects.
- Preserve the current score as a high score when applicable.
- Return to the game selection screen.

Do not allow a previous game loop to continue running in the background.

---

## 7. Physical Controls and Input Mapping

The interface should resemble the physical controls of a classic Brick Game.

Implement the following logical controls:

- UP
- DOWN
- LEFT
- RIGHT
- A
- B
- START
- MENU

These are logical actions, not necessarily eight separate physical buttons.

### Suggested physical layout

Use:

- A cross-shaped directional pad.
- Two circular action buttons.
- A small Start button.
- A Menu button, which may also function as a Back button.

Label the action buttons with short retro labels such as A and B.

Use a physical-looking recessed D-pad rather than ordinary flat website buttons.

### Keyboard controls

Support the following keyboard mapping:

| Key | Action |
| --- | --- |
| Arrow Up | Move up |
| Arrow Down | Move down |
| Arrow Left | Move left |
| Arrow Right | Move right |
| Z | Action A |
| X | Action B |
| Enter | Start / Confirm |
| Escape | Pause or return to menu |
| Backspace | Return to menu, where appropriate |

Prevent browser scrolling when directional keys are used for gameplay.

Do not interfere with normal keyboard navigation when the game is not active.

### Touch controls

All physical controls must respond to touch.

Requirements:

- Use sufficiently large touch targets.
- Support holding directional buttons.
- Support repeated movement where appropriate.
- Avoid triggering unwanted browser gestures.
- Provide immediate visual feedback.
- Prevent accidental double activation.

Prefer Pointer Events so mouse, touch, and pen input share the same implementation.

Ensure held buttons are released when the pointer is canceled, the window loses focus, or the user navigates away.

### Game-specific control mapping

Define controls individually for each game.

Examples:

| Game | Primary controls |
| --- | --- |
| Tetris | Left, Right, Down, A to rotate, B for an optional secondary action |
| Racing | Left and Right to steer, A for acceleration |
| Snake | Directional controls |
| Tank | Directions to move, A to fire |
| Arkanoid | Left and Right to move the paddle |
| Frogger | Directional controls |
| Space Invaders | Left and Right to move, A to shoot |
| Ping-Pong | Up and Down to move the paddle |
| Football | Directions to move, A to kick |
| Shooting Gallery | Directions to aim, A to shoot |

Every game must display its controls on the instruction screen.

Avoid assigning conflicting controls without clear game-specific handling.

---

## 8. Classic Sound Effects

Use the Web Audio API to create synthesized retro electronic sounds.

The application should evoke the simple beeps of inexpensive handheld game consoles.

Do not rely on external audio files for basic effects.

### Required sound effects

Implement reusable sound functions for:

- Button click.
- Menu navigation.
- Game start.
- Movement or action confirmation.
- Shooting.
- Collision.
- Collecting an item.
- Clearing a line.
- Scoring.
- Losing a life.
- Game over.
- Victory.

Not every sound must be used by every game.

### Sound design

Prefer:

- Square-wave oscillators.
- Short sine or triangle tones where appropriate.
- Simple pitch sweeps.
- Brief frequency changes.
- Short percussive electronic blips.
- Minimal reverb or no reverb.

Keep sound effects short and recognizable.

Avoid modern orchestral sounds, realistic sound effects, long background music, and excessive distortion.

Example sound concepts:

- Menu movement: a very short, high-pitched blip.
- Selection: two ascending tones.
- Shooting: a quick descending frequency sweep.
- Collecting an item: a short, bright two-note sequence.
- Game over: a descending series of low tones.
- Victory: a short ascending arpeggio.

Implement a shared audio manager rather than creating unrelated audio logic in each game.

### Audio settings

Provide:

- Sound enabled by default when browser policies permit.
- A visible mute toggle.
- Persistent mute preference.
- Safe audio initialization after a user gesture.
- Proper cleanup of audio resources.

Do not assume audio can start before user interaction.

Audio must never prevent gameplay if browser audio initialization fails.

---

## 9. Animation and Tactile Feedback

Animations should be subtle and consistent with physical handheld hardware.

Implement:

- Button depression when pressed.
- Button release when activated.
- A small visual response when navigating the menu.
- Brief LCD flicker during selected transitions.
- A blinking start prompt.
- Short game-over transitions.
- Immediate feedback when actions succeed.

Avoid excessive shaking, elaborate particle effects, cinematic transitions, or large modern overlays.

The physical device should feel tactile without compromising usability.

Respect the `prefers-reduced-motion` media query.

Ensure LCD flicker is subtle, brief, and never causes discomfort.

---

## 10. Game Engine Architecture

Keep the physical console UI separate from game logic.

Each game must be independently implemented but share common infrastructure.

Suggested structure:

text

```
src/
├── app/
│   ├── App.tsx
│   ├── App.css
│   └── routes.ts
├── components/
│   ├── Console/
│   │   ├── Console.tsx
│   │   ├── Console.css
│   │   ├── LCDScreen.tsx
│   │   ├── ControlPad.tsx
│   │   ├── ActionButtons.tsx
│   │   └── Speaker.tsx
│   ├── GameMenu/
│   │   ├── GameMenu.tsx
│   │   └── GameMenu.css
│   └── Settings/
│       └── SoundToggle.tsx
├── games/
│   ├── types.ts
│   ├── registry.ts
│   ├── shared/
│   │   ├── GameLoop.ts
│   │   ├── Grid.ts
│   │   ├── Collision.ts
│   │   ├── Random.ts
│   │   └── Drawing.ts
│   ├── tetris/
│   │   ├── TetrisGame.ts
│   │   └── TetrisRenderer.ts
│   ├── racing/
│   │   ├── RacingGame.ts
│   │   └── RacingRenderer.ts
│   ├── snake/
│   │   ├── SnakeGame.ts
│   │   └── SnakeRenderer.ts
│   ├── tank/
│   │   ├── TankGame.ts
│   │   └── TankRenderer.ts
│   ├── arkanoid/
│   │   ├── ArkanoidGame.ts
│   │   └── ArkanoidRenderer.ts
│   ├── frogger/
│   │   ├── FroggerGame.ts
│   │   └── FroggerRenderer.ts
│   ├── space-invaders/
│   │   ├── SpaceInvadersGame.ts
│   │   └── SpaceInvadersRenderer.ts
│   ├── ping-pong/
│   │   ├── PingPongGame.ts
│   │   └── PingPongRenderer.ts
│   ├── football/
│   │   ├── FootballGame.ts
│   │   └── FootballRenderer.ts
│   └── shooting-gallery/
│       ├── ShootingGalleryGame.ts
│       └── ShootingGalleryRenderer.ts
├── input/
│   ├── InputManager.ts
│   └── controls.ts
├── audio/
│   ├── AudioManager.ts
│   └── soundPresets.ts
├── hooks/
│   ├── useGameController.ts
│   └── usePersistentSettings.ts
├── storage/
│   └── highScores.ts
├── styles/
│   ├── tokens.css
│   └── global.css
├── pwa/
│   └── registerServiceWorker.ts
└── main.tsx
```

This is a recommended structure, not a requirement to create every file before implementing functionality.

Keep related code together if the project is smaller.

Do not create empty files or unnecessary abstraction layers merely to match this tree.

### Game interface

Define a shared interface for all games.

Example:

ts

```
export type GameStatus =
  | "ready"
  | "playing"
  | "paused"
  | "gameover"
  | "victory";

export type GameAction =
  | "UP"
  | "DOWN"
  | "LEFT"
  | "RIGHT"
  | "A"
  | "B"
  | "START"
  | "MENU";

export interface GameContext {
  playSound: (
    effect: string,
    options?: { volume?: number }
  ) => void;
}

export interface GameInstance {
  start(): void;
  update(deltaTime: number): void;
  render(
    ctx: CanvasRenderingContext2D
  ): void;
  handleAction(
    action: GameAction,
    pressed: boolean
  ): void;
  pause(): void;
  resume(): void;
  reset(): void;
  destroy(): void;
  getStatus(): GameStatus;
  getScore(): number;
}
```

Adapt the interface when a game needs additional state, but keep the shared lifecycle consistent.

Avoid direct dependencies between individual games.

### Game registry

Register all ten games in one central location.

Each entry should contain:

- Unique identifier.
- Display name.
- Game number.
- Short description.
- Instructions.
- Control mapping.
- Game constructor or factory function.

The game selection menu must derive its contents from this registry.

Do not hardcode separate, inconsistent lists in the UI.

### Rendering

Use Canvas for the active game.

Keep React responsible for:

- Console layout.
- Game selection.
- Game lifecycle.
- Settings.
- Accessible controls.
- Non-game UI.

Use Canvas for:

- Game sprites.
- Pixel-based text.
- Playfields.
- Animation.
- Collision-related visual feedback.

Avoid updating React state on every animation frame.

### Game loop

Implement a shared animation loop using `requestAnimationFrame`.

Requirements:

- Use elapsed time rather than assuming a fixed display refresh rate.
- Limit unusually large frame deltas after tab suspension.
- Cleanly cancel the animation frame on teardown.
- Support pausing and resuming.
- Keep game simulation stable across devices with different refresh rates.

Use fixed-step simulation where appropriate, especially for grid-based games.

Do not create multiple active animation loops for the same game.

---

## 11. Game State Management

The application should have a clear top-level state machine.

Suggested states:

ts

```
type AppMode =
  | "boot"
  | "menu"
  | "instructions"
  | "playing"
  | "paused"
  | "gameover"
  | "victory";
```

Transitions must be predictable.

Examples:

text

```
BOOT
  |
  v
MENU
  |
  v
INSTRUCTIONS
  |
  v
PLAYING <----> PAUSED
  |
  +-------> GAMEOVER
  |
  +-------> VICTORY
```

Allow returning to the menu from relevant states.

Switching games must destroy the previous game instance before creating the next one.

Handle browser visibility changes by pausing gameplay when appropriate.

Never leave timers, event listeners, oscillators, or animation loops running after their owning component or game is destroyed.

---

## 12. Scoring and Persistence

Implement local persistence for:

- High scores per game.
- Sound preference.
- Optional user-selected settings.

Use `localStorage` for small amounts of data.

High scores must be stored independently for each game.

Do not require user accounts, a backend, or a remote database.

Validate persisted values before using them.

The application must remain fully playable when local storage is unavailable or throws an error.

---

## 13. PWA Requirements

Make the application installable and usable offline.

Requirements:

- A valid web app manifest.
- Appropriate application name and short name.
- Theme color.
- Background color.
- Portrait-oriented default display.
- Installable application icons.
- Service worker registration.
- Caching of the application shell and required assets.
- Offline access after the first successful load.

Do not cache dynamic runtime data unnecessarily.

Avoid relying on external images, remote fonts, or hosted game assets for core functionality.

All ten games must remain available offline.

Provide suitable icons representing the retro handheld.

Do not claim full offline functionality until the service worker and offline behavior have been tested.

---

## 14. Responsive Design

The application must work on:

- Small mobile screens.
- Large mobile screens.
- Tablets.
- Desktop browsers.

### Mobile

Prioritize a full-console experience.

- Center the handheld.
- Fit the entire device within the viewport.
- Keep all controls visible.
- Ensure touch targets are comfortably sized.
- Avoid page scrolling during gameplay.
- Respect safe-area insets.

### Desktop

Center the device within a neutral background.

Use generous empty space around the console rather than expanding the device to fill the entire screen.

Optional decorative elements may reinforce the retro aesthetic, but must not distract from the handheld.

### Orientation

Support portrait orientation as the default.

When the device is in landscape orientation:

- Scale the console appropriately.
- Keep the LCD visible.
- Avoid clipping controls.
- Do not force orientation locking.

---

## 15. Accessibility

Although the application simulates a retro device, basic accessibility is required.

- All controls must be keyboard accessible.
- Provide visible focus indicators.
- Give interactive controls accessible labels.
- Ensure buttons have meaningful names for screen readers.
- Do not rely solely on sound to communicate events.
- Provide a mute control.
- Support reduced motion.
- Maintain sufficient contrast around the physical controls and surrounding interface.

The monochrome LCD may use deliberately limited contrast for authenticity, but essential surrounding interface elements must remain legible.

---

## 16. Performance Requirements

The application must run smoothly on typical mobile devices.

- Target 60 FPS where practical.
- Avoid unnecessary React re-renders.
- Avoid allocating large objects on every frame.
- Keep collision detection efficient.
- Limit the number of active projectiles and particles.
- Use small, reusable pixel sprites.
- Stop inactive game loops.
- Clean up all event listeners and timers.
- Avoid large dependencies.

Prioritize responsive input and consistent gameplay over visual complexity.

---

## 17. Testing Requirements

Add automated tests for important game logic.

At minimum, test:

- Game registry completeness.
- Menu navigation and wraparound.
- Game selection.
- Input mapping.
- Starting and stopping games.
- Pause and resume behavior.
- High-score persistence.
- Tetris line clearing.
- Snake growth and collision.
- Racing collisions.
- Tank projectile hits.
- Arkanoid brick collisions.
- Frogger goal and collision detection.
- Space Invaders enemy destruction.
- Ping-Pong scoring.
- Football goal detection.
- Shooting Gallery hit detection.

Use unit tests for deterministic logic.

Where practical, add end-to-end tests for:

- Launching the application.
- Navigating between games.
- Starting a game.
- Playing with keyboard controls.
- Returning to the menu.
- Toggling sound.
- Reopening the application with persisted preferences.

Tests should not depend on real audio output.

---

## 18. Implementation Order

Build the application incrementally.

### Phase 1: Physical console

Implement:

- Application shell.
- Physical handheld styling.
- LCD screen.
- Directional pad.
- Action buttons.
- Start and Menu controls.
- Responsive layout.

At the end of this phase, the application should look like a handheld console even before the games are implemented.

### Phase 2: Menu and input

Implement:

- Game registry.
- Game selection screen.
- Circular navigation through all ten games.
- Keyboard controls.
- Touch controls.
- Game instructions.
- State transitions.

Use placeholder game screens temporarily if necessary.

### Phase 3: Shared game infrastructure

Implement:

- Canvas rendering.
- Shared game interface.
- Input manager.
- Animation loop.
- Audio manager.
- Game lifecycle management.
- Score persistence.

### Phase 4: Core games

Implement Tetris and Snake first to validate grid-based gameplay and the rendering system.

Then implement:

- Racing.
- Tank.
- Arkanoid.
- Frogger.
- Space Invaders.
- Ping-Pong.
- Football.
- Shooting Gallery.

Each game must be independently playable before moving to the next.

### Phase 5: Retro polish

Add:

- Synthesized sound effects.
- Button press animations.
- LCD texture.
- Menu transitions.
- Game-over animations.
- High-score screens.
- Retro typography.

Keep the device and LCD visually consistent across all games.

### Phase 6: PWA and quality assurance

Implement:

- Application manifest.
- Icons.
- Service worker.
- Offline caching.
- Automated tests.
- Responsive testing.
- Performance checks.
- Keyboard and touch verification.

---

## 19. Definition of Done

The project is complete only when all of the following conditions are met.

### Visual experience

- The app looks like a classic Brick Game handheld.
- The device has a convincing plastic shell and physical buttons.
- The LCD uses a consistent grey monochrome aesthetic.
- Pixel graphics remain crisp at different display sizes.
- The layout works on mobile and desktop.

### Game selection

- All ten games appear in the menu.
- The user can cycle through the complete list in either direction.
- The selection wraps correctly at both ends.
- The user can start the selected game.
- The user can return to the menu at any time.

### Gameplay

- All ten games are playable.
- Each game has working controls.
- Each game has appropriate collision detection.
- Each game has scoring or another clear objective.
- Each game has proper win, loss, or completion conditions.
- Restarting a game works correctly.

### Audio

- Menu and gameplay actions produce appropriate retro sound effects.
- Audio respects the mute setting.
- Audio failures do not prevent gameplay.
- Audio resources are properly cleaned up.

### Technical quality

- No game leaves background loops running.
- Switching games does not leak event listeners or timers.
- High scores persist between sessions.
- The application works without a backend.
- The PWA can run offline after its assets have been cached.
- The application builds successfully.
- Essential game logic is covered by tests.

---

## 20. Development Guidelines for Coding Agents

When working on this project:

1. Inspect the existing repository before making changes.
2. Follow existing conventions when they are consistent with these requirements.
3. Implement working functionality rather than static mockups.
4. Do not leave any of the ten games as non-interactive placeholders in the completed application.
5. Keep game logic separate from rendering and physical console components.
6. Prefer deterministic game logic that is easy to test.
7. Do not introduce unnecessary backend services.
8. Do not use copyrighted console branding or proprietary game assets without authorization.
9. Create original CSS styling and pixel-art graphics inspired by the general aesthetic of classic handheld games.
10. Test each major feature before proceeding to the next implementation phase.
11. Fix TypeScript errors, lint errors, and runtime exceptions introduced by changes.
12. Keep the project documentation and available scripts up to date.
13. Verify the production build before considering the work complete.

**Final objective:** Deliver a polished, installable, offline-capable retro handheld simulator in which users can pick up the virtual Brick Game, navigate its menu using physical-looking controls, and immediately play ten classic-style monochrome arcade games with authentic electronic sounds.

### Recommended next step

Place this file in the project root, alongside `package.json`. Coding agents can use it as their primary implementation specification.

**One important design decision:** Render the console itself with CSS and the games with Canvas. This makes the physical device feel realistic while keeping the gameplay crisp, lightweight, and responsive across mobile and desktop devices.