---
name: floor-plan-editor
description: Build and maintain the interactive 2D architectural editor, including walls, doors, windows, dimensions, snapping, selection, drawing tools, layers, keyboard shortcuts, and undo/redo.
---

# 2D Floor-Plan Editor

## Objective

Provide a precise and responsive interface for editing architectural
objects in a shared building model.

## Interaction Architecture

Separate:

- Pointer and keyboard input.
- Tool state.
- Selection state.
- Temporary previews.
- Model-editing commands.
- Geometry calculations.
- Canvas or SVG rendering.

Input handlers should request commands instead of directly mutating
unrelated application state.

## Coordinate Transformations

Maintain explicit transformations between:

- Screen coordinates.
- Viewport coordinates.
- Architectural world coordinates.

Support zooming, panning, resizing, and high-DPI displays without changing
the underlying architectural dimensions.

Hit testing must remain reliable at different zoom levels.

## Drawing Tools

Design tools for:

- Selection.
- Wall creation.
- Door and window placement.
- Room and boundary creation.
- Dimension placement.
- Furniture placement.
- Move and rotate.
- Measurement.
- Pan and zoom.

Each tool should define its supported interaction states and cancellation
behavior.

## Snapping

Support configurable snapping to:

- Grid points.
- Endpoints.
- Midpoints.
- Wall centerlines.
- Wall faces.
- Intersections.
- Alignment guides.

Show clear visual feedback for the active snap target.

Avoid snapping to invisible or irrelevant objects.

## Wall Editing

Walls are architectural entities, not decorative lines.

Creating or moving a wall must update its reference geometry and derived
representations.

Wall joins must remain valid after editing.

Placing an opening must validate its host wall and position.

## Undo and Redo

Represent edits as reversible commands or transactions.

Undo must restore architectural data and relationships, not merely redraw
the previous screen.

Group multi-step operations when they represent one logical user action.

## User Experience

Support:

- Numeric dimension entry.
- Keyboard shortcuts.
- Multi-selection.
- Copy and paste.
- Clear selection feedback.
- Escape to cancel the current operation.
- Helpful validation messages.
- Accessible controls.

## Testing

Test coordinate conversions, snapping, selection, object placement,
cancellation, undo/redo, and model synchronization.

Use browser tests for important editing workflows.