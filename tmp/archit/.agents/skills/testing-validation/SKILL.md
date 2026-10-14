---
name: testing-validation
description: Design and implement tests for architectural geometry, model integrity, drawing generation, CAD/BIM interoperability, project persistence, and critical user workflows.
---

# Testing and Validation

## Objective

Prevent regressions that could cause incorrect dimensions, invalid geometry,
broken projects, or misleading technical drawings.

## Testing Strategy

Use multiple levels of testing.

### Unit Tests

Test individual geometry functions, model operations, unit conversions,
validation rules, and serialization logic.

### Integration Tests

Test interactions between the model, geometry engine, rendering layer,
drawing generator, and persistence system.

### Browser Tests

Test complete workflows using a real browser.

### Interoperability Tests

Test exported files with independent readers where practical.

## Essential Invariants

Verify that:

- Stored dimensions remain consistent after editing.
- Unit conversion preserves physical dimensions.
- Object identifiers remain stable when appropriate.
- Related objects reference valid entities.
- Opening placement respects host-wall constraints.
- Derived geometry matches authoritative model parameters.
- Undo and redo restore valid states.
- Project duplication does not share mutable project entities.
- Export does not silently modify the source model.
- Save and reload preserve supported information.

## Geometry Fixtures

Create fixtures for common architectural scenarios.

Include:

- Intersecting walls.
- Walls with openings.
- Multiple levels.
- Rooms with nonrectangular boundaries.
- Slabs and roof geometry.
- Furniture near walls.
- Invalid or degenerate geometry.

Use known expected dimensions and coordinates.

## Regression Testing

When fixing a geometry or model bug:

1. Reproduce the failure.
2. Add a regression test.
3. Implement the fix.
4. Run the regression test.
5. Run relevant neighboring tests.

## Reporting

Distinguish:

- Tests that passed.
- Tests that failed.
- Tests that were not run.
- Behaviors verified only by manual inspection.
- Known limitations.

Never claim that an export is fully compatible without adequate testing.