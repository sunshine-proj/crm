# Skill: Game Design

## Purpose

Design and balance the core orbital-combat experience.

Ensure that movement, aiming, fuel management, and combat work together
to create an engaging game.

## Responsibilities

- Define gameplay rules.
- Design the combat loop.
- Balance fuel consumption and regeneration.
- Define damage and victory conditions.
- Establish difficulty levels.
- Evaluate player feedback and pacing.
- Prevent frustrating or repetitive gameplay.

## Core Design Principles

### 1. Momentum creates tactical decisions

The player must understand that thrust changes velocity.

Releasing thrust does not stop the satellite.

Rotation changes the direction of the spacecraft's weapon and thrust
axis but should not automatically change its velocity.

Players should be able to learn these relationships through play.

### 2. Fuel creates opportunities and constraints

Fuel should encourage tactical decisions without becoming tedious.

The player should have enough fuel for short maneuvers and repeated
combat adjustments.

Regeneration should allow recovery after a period of conservation.

Avoid fuel settings that force the player to wait for long periods
without meaningful decisions.

### 3. Combat must be readable

Players should understand:
- Where their weapon is pointing.
- Where projectiles are traveling.
- Whether a shot connected.
- How much health remains.
- Whether the opponent is attacking.
- Whether propulsion is available.

### 4. Damage must feel meaningful

Successful hits should produce immediate visual feedback.

Health changes must be visible in the HUD.

Satellite destruction should feel conclusive.

## Match Design

A typical match consists of:

1. Initial positioning.
2. Observation and aiming.
3. Initial maneuvering.
4. Sustained combat.
5. Tactical fuel management.
6. Satellite destruction.
7. Results and restart.

The match should not depend on scripted outcomes.

## Balance Parameters

Keep these parameters configurable:

- Starting fuel.
- Maximum fuel.
- Fuel regeneration.
- Thrust consumption.
- Rotation consumption.
- Satellite health.
- Projectile damage.
- Firing cooldown.
- Projectile speed.
- Opponent accuracy.
- Opponent aggression.

Use playtesting to determine final values.

Do not change multiple unrelated balance parameters at once when
investigating a specific gameplay problem.

## Acceptance Criteria

- A new player can understand the controls quickly.
- Fuel management creates meaningful decisions.
- Hits are noticeable.
- Matches have a clear ending.
- The opponent can be defeated through skill.
- The game remains playable when the player makes mistakes.
- Restarting a match is quick.

## Deliverables

When implementing a game-design task, document:
- The intended player experience.
- The relevant rules.
- Proposed configuration values.
- Edge cases.
- Acceptance criteria.
