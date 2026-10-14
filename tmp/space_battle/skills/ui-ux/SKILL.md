# Skill: UI/UX Design

## Purpose

Design an interface that makes orbital combat understandable without
obscuring the spacecraft, planet, or projectiles.

## Responsibilities

- Title screen.
- Control instructions.
- Gameplay HUD.
- Health and fuel indicators.
- Pause menu.
- Results screen.
- Accessibility.
- Keyboard interaction.
- Responsive layout.

## Design Principles

1. Prioritize combat visibility.
2. Use concise labels.
3. Make important state changes obvious.
4. Maintain consistent visual styling.
5. Avoid unnecessary interface elements.
6. Keep controls discoverable.
7. Ensure all essential information is available visually.

## Title Screen

Include:
- Game title.
- Start button.
- Control instructions.
- Optional sound settings.

The player should be able to begin a match without navigating
multiple menus.

## Gameplay HUD

### Player health

Show current health and maximum health.

Use a progress bar or another clearly readable representation.

### Enemy health

Display enemy health separately from player health.

Make it easy to compare the two.

### Fuel# Skill: UI/UX Design

## Purpose

Design an interface that makes orbital combat understandable without
obscuring the spacecraft, planet, or projectiles.

## Responsibilities

- Title screen.
- Control instructions.
- Gameplay HUD.
- Health and fuel indicators.
- Pause menu.
- Results screen.
- Accessibility.
- Keyboard interaction.
- Responsive layout.

## Design Principles

1. Prioritize combat visibility.
2. Use concise labels.
3. Make important state changes obvious.
4. Maintain consistent visual styling.
5. Avoid unnecessary interface elements.
6. Keep controls discoverable.
7. Ensure all essential information is available visually.

## Title Screen

Include:
- Game title.
- Start button.
- Control instructions.
- Optional sound settings.

The player should be able to begin a match without navigating
multiple menus.

## Gameplay HUD

### Player health

Show current health and maximum health.

Use a progress bar or another clearly readable representation.

### Enemy health

Display enemy health separately from player health.

Make it easy to compare the two.

### Fuel

Show current fuel relative to maximum fuel.

Make fuel depletion noticeable without relying exclusively on color.

### Weapon

Optionally display cooldown or weapon readiness.

Only show information that helps the player make decisions.

## Controls

Document:

- Left Arrow: rotate left.
- Right Arrow: rotate right.
- Up Arrow: thrust.
- Space: fire.
- Escape: pause.

Explain that momentum continues after thrust is released.

## Pause Screen

Provide:
- Resume.
- Restart.
- Return to title.

Ensure that gameplay simulation actually pauses behind the interface.

## Results Screen

Clearly distinguish victory and defeat.

Include:
- Match result.
- Restart.
- Return to title.

Avoid forcing a full page reload to start another match.

## Visual Accessibility

- Use sufficient contrast.
- Avoid tiny text.
- Do not communicate important states by color alone.
- Maintain readable spacing.
- Ensure indicators work at common desktop resolutions.

## Keyboard Accessibility

- Make button focus visible.
- Avoid browser shortcuts interfering with gameplay.
- Clear held inputs on focus loss.
- Prevent accidental firing after returning from a menu.

## Responsive Behavior

Support window resizing.

Keep HUD elements within the visible viewport.

Prevent menus from overlapping essential information.

## Acceptance Criteria

- New players can identify the controls.
- Player and enemy health are distinguishable.
- Fuel status is immediately understandable.
- Pause and restart work reliably.
- Victory and defeat are unmistakable.
- The UI remains legible during combat.

Show current fuel relative to maximum fuel.

Make fuel depletion noticeable without relying exclusively on color.

### Weapon

Optionally display cooldown or weapon readiness.

Only show information that helps the player make decisions.

## Controls

Document:

- Left Arrow: rotate left.
- Right Arrow: rotate right.
- Up Arrow: thrust.
- Space: fire.
- Escape: pause.

Explain that momentum continues after thrust is released.

## Pause Screen

Provide:
- Resume.
- Restart.
- Return to title.

Ensure that gameplay simulation actually pauses behind the interface.

## Results Screen

Clearly distinguish victory and defeat.

Include:
- Match result.
- Restart.
- Return to title.

Avoid forcing a full page reload to start another match.

## Visual Accessibility

- Use sufficient contrast.
- Avoid tiny text.
- Do not communicate important states by color alone.
- Maintain readable spacing.
- Ensure indicators work at common desktop resolutions.

## Keyboard Accessibility

- Make button focus visible.
- Avoid browser shortcuts interfering with gameplay.
- Clear held inputs on focus loss.
- Prevent accidental firing after returning from a menu.

## Responsive Behavior

Support window resizing.

Keep HUD elements within the visible viewport.

Prevent menus from overlapping essential information.

## Acceptance Criteria

- New players can identify the controls.
- Player and enemy health are distinguishable.
- Fuel status is immediately understandable.
- Pause and restart work reliably.
- Victory and defeat are unmistakable.
- The UI remains legible during combat.
