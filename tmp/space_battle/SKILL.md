# Skill — Orbital Combat Development

## Purpose

Guide an AI coding agent through the development of Orbital Combat.

This skill coordinates game design, orbital simulation, gameplay
programming, opponent AI, visuals, audio, UI, and testing.

## When to Use

Use this skill when:
- Starting work on the game.
- Planning a new gameplay feature.
- Coordinating multiple development disciplines.
- Reviewing whether a feature is ready for integration.
- Deciding implementation order.
- Investigating cross-system bugs.

## Required Context

Before making substantial changes, inspect:

- AGENTS.md
- PRD.md
- Existing source code.
- Existing tests.
- Package configuration.
- Relevant skill files under skills/.

Do not assume that the repository is empty.

Preserve working functionality unless a change is required.

## Workflow

### 1. Define the change

Identify:
- The player-facing behavior.
- The systems involved.
- The expected inputs and outputs.
- The acceptance criteria.
- Potential interactions with existing mechanics.

### 2. Select skills

Consult the relevant specialist instructions.

Examples:
- Trajectory changes: orbital-physics.
- Weapon handling: gameplay-programming.
- Enemy aiming: ai-opponent.
- Satellite appearance: visual-art.
- HUD changes: ui-ux.
- Regression verification: testing.

### 3. Design the implementation

Before coding, establish:
- State ownership.
- Interfaces.
- Update order.
- Configuration requirements.
- Failure conditions.
- Testing strategy.

Avoid adding abstractions that are unnecessary for the feature.

### 4. Implement incrementally

Implement the smallest coherent change.

Keep simulation, rendering, and presentation responsibilities separate.

Avoid combining unrelated refactoring with gameplay changes.

### 5. Verify

Run the relevant automated tests.

Check:
- Type correctness.
- Simulation behavior.
- Rendering integration.
- Input handling.
- Resource cleanup.
- Build output.

### 6. Integrate

Confirm that the change works with the full gameplay loop.

Update configuration and documentation when behavior changes.

### 7. Report

Summarize:
- What changed.
- Which files changed.
- Tests executed.
- Known limitations.
- Follow-up work.

## Architectural Principles

- Simulation owns gameplay truth.
- Rendering displays simulation state.
- Input translates player actions into commands.
- AI produces commands through the same gameplay interfaces.
- Configuration owns balance parameters.
- Tests verify observable behavior.

Do not let the AI directly manipulate rendering objects to bypass
gameplay rules.

Do not let visual effects determine damage or collision outcomes.

## Physics and Rendering

Use a fixed simulation timestep where practical.

Use interpolation for rendering between simulation states if necessary.

Never make game speed depend directly on frame rate.

Represent gameplay positions and velocities in a consistent coordinate
system.

Define the relationship between simulation coordinates and Three.js
coordinates explicitly.

## Feature Priority

Implement features in this order:

1. Stable movement.
2. Reliable combat.
3. Complete match lifecycle.
4. Functional opponent AI.
5. Readable UI.
6. Visual polish.
7. Audio and optional enhancements.

A simple working implementation is preferable to an elaborate
unfinished implementation.

## Completion Checklist

- [ ] Acceptance criteria satisfied.
- [ ] Relevant skill files consulted.
- [ ] Tests added or updated.
- [ ] Tests executed.
- [ ] TypeScript checks pass.
- [ ] Production build succeeds.
- [ ] No known critical regressions.
- [ ] Documentation reflects significant behavior changes.

