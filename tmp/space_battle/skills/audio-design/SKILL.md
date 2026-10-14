# Skill: Audio Design

## Purpose

Provide restrained, responsive audio feedback that reinforces
spacecraft movement, weapon firing, impacts, and match outcomes.

## Responsibilities

- Audio event design.
- Sound playback.
- Volume controls.
- Audio resource management.
- Event-to-sound mapping.
- Optional synthesized sound effects.

## Audio Direction

Use a cinematic science-fiction sound palette.

Although the game takes place in space, sounds represent a stylized
gameplay presentation rather than literal acoustic propagation.

Prefer concise, distinctive effects over constant background noise.

## Required Sound Events

Where assets are available, support:

- Main engine activation.
- Main engine thrust.
- Attitude-thruster bursts.
- Weapon firing.
- Projectile impact.
- Satellite damage.
- Satellite destruction.
- UI interaction.
- Victory and defeat.

## Implementation

Create a central AudioManager.

Gameplay systems should emit audio events rather than directly
creating audio resources.

The AudioManager should:
- Load or initialize sound resources.
- Play the appropriate sound.
- Control master volume.
- Support mute.
- Handle unavailable audio assets gracefully.
- Avoid creating unnecessary overlapping sounds.

Follow browser autoplay restrictions.

Initialize audio after an appropriate user interaction when necessary.

## Sound Behavior

- Engine audio should reflect active thrust.
- Attitude-thruster audio should remain short.
- Weapon audio should correspond to actual successful firing.
- Impact audio should correspond to confirmed collisions.
- Destruction audio should play once per destroyed satellite.

Avoid repeated sounds caused by duplicate event handling.

## Accessibility

Do not rely exclusively on audio to communicate critical game state.

Health, fuel, hits, and match outcomes must remain visually readable.

## Testing

Verify:
- Audio can be muted.
- Volume changes work.
- Missing assets do not crash gameplay.
- Destruction sounds play only once.
- Restart does not create duplicate audio handlers.
- Gameplay remains functional without audio.

## Acceptance Criteria

Audio improves feedback without interfering with gameplay.

The game remains fully playable when muted.
