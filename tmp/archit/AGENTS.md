# AGENTS.md

# Architectural Design Platform — Agent Instructions

## 1. Project Mission

Build a modern, professional architectural design platform for residential
buildings, apartments, interior design, landscape design, technical drawings,
3D visualization, and construction documentation.

The application should allow users to:

- Create architectural projects from scratch.
- Start from reusable house and apartment templates.
- Draw and edit precise 2D floor plans.
- Design multistory buildings.
- Create walls, doors, windows, roofs, slabs, stairs, and other elements.
- Generate 3D representations from architectural plans.
- Design interiors and furnish rooms.
- Design gardens, terraces, pools, and outdoor environments.
- Generate elevations, sections, dimensions, and technical drawings.
- Produce realistic visualizations of planned properties.
- Explore properties through interactive 3D walkthroughs.
- Import and export supported CAD and BIM formats.
- Save, duplicate, revise, and share projects.

The long-term objective is to create a reliable architectural design
environment that supports both creative exploration and professional
building documentation.

The application must not be merely a drawing canvas or a 3D visualization
tool. It must maintain a consistent architectural model from which its
different representations can be derived.

## 2. Core Product Principles

Follow these principles in every implementation.

### 2.1 One authoritative architectural model

The building model is the source of truth.

Floor plans, elevations, sections, 3D geometry, dimensions, room areas,
schedules, and exports must derive from the same underlying data.

Avoid independently maintained representations that can become inconsistent.

### 2.2 Precision

Architectural dimensions must be stored and calculated consistently.

Visual appearance must never override geometric correctness.

### 2.3 Professional usability

The interface must support precise editing while remaining approachable
for new users.

### 2.4 Premium visual quality

Visual quality is a core product requirement, not a finishing task.

Every feature must be implemented with both functional correctness and
polished UI/UX in mind.

### 2.5 Modularity

Keep the building model, geometry engine, drawing tools, rendering system,
persistence layer, and import/export functionality clearly separated.

### 2.6 Reliability

Important operations must be validated, reversible where appropriate,
and covered by tests.

### 2.7 Interoperability

Treat CAD and BIM integration as a fundamental architectural requirement.

### 2.8 Incremental development

Build a small, reliable system first.

Avoid implementing advanced features before their underlying dependencies
are sufficiently stable.

## 3. Technology Stack

Use TypeScript as the primary programming language.

Use Node.js for backend execution and development tooling.

Recommended technologies:

- TypeScript: application logic, domain model, geometry, and APIs.
- React: user interface.
- Vite: frontend development and production builds.
- Node.js: backend runtime.
- Fastify: backend API.
- Three.js: interactive 3D visualization.
- SVG: vector drawing and scalable 2D representations.
- Canvas: high-volume interactive drawing when appropriate.
- PostgreSQL: persistent application data.
- Zod or an equivalent schema library: runtime validation.
- Vitest: unit and integration testing.
- Playwright: browser testing.
- S3-compatible object storage: large assets, exports, and rendered images.

Evaluate additional technologies when specific requirements justify them.

Potential specialized integrations include:

- OpenCascade.js for selected CAD and solid-modeling operations.
- web-ifc for IFC interoperability.
- Autodesk Platform Services for applicable CAD workflows.
- Redis-backed job queues for expensive background operations.

These are candidates, not mandatory dependencies.

Do not introduce dependencies without evaluating their suitability,
maintenance, licensing, browser compatibility, and integration costs.

Three.js is a rendering engine, not a complete architectural CAD or BIM
engine.

Implement or integrate the geometry, constraint, architectural modeling,
and technical drawing capabilities required by the product.

## 4. Repository Inspection and Agent Workflow

Before making changes:

1. Inspect the repository structure.
2. Read package.json and relevant configuration files.
3. Identify the existing application architecture.
4. Review relevant domain types and model relationships.
5. Inspect existing geometry and rendering implementations.
6. Read relevant tests and documentation.
7. Check existing Git changes when available.
8. Identify applicable skills in .agents/skills/.

Do not assume that a file, dependency, or subsystem exists without checking.

Do not replace existing implementations without understanding their behavior.

Preserve unrelated user changes.

For every task:

1. Understand the requested behavior.
2. Identify affected subsystems and dependencies.
3. Define expected behavior and relevant invariants.
4. Choose the smallest safe implementation.
5. Add or update tests.
6. Implement the change.
7. Run relevant tests and checks.
8. Review downstream effects.
9. Update documentation when necessary.
10. Report changes, verification, and limitations.

Do not make unrelated architectural changes without justification.

Do not claim that tests or visual verification passed unless they were
actually performed.

## 5. Architecture and Module Boundaries

Keep the following responsibilities separate:

- Application interface.
- Project management.
- Architectural domain model.
- Geometry engine.
- Constraint system.
- 2D drawing engine.
- 3D visualization.
- Technical documentation.
- Interior and landscape design.
- Import and export.
- Persistence and revision management.
- Authentication and authorization.
- Background processing.

Rendering code must not become the authoritative source of architectural
dimensions or relationships.

UI components must not directly mutate unrelated model internals.

Use explicit commands, transactions, or controlled domain operations
for important changes.

Avoid circular dependencies.

Prefer a modular monolith during initial development.

Introduce separate services only when there is a clear operational or
scaling requirement.

Use a TypeScript monorepo when it improves code sharing and module boundaries.

Do not create excessive packages merely to match a proposed directory tree.

## 6. Authoritative Building Model

The shared architectural model should progressively support:

- Projects.
- Buildings.
- Levels and floors.
- Rooms and spaces.
- Walls and partitions.
- Wall assemblies and materials.
- Doors and windows.
- Openings.
- Slabs and foundations.
- Columns and beams.
- Roofs.
- Stairs and railings.
- Dimensions and annotations.
- Furniture and fixtures.
- Lighting.
- Finishes.
- Landscape objects.
- Site elements.

Introduce entities as their associated functionality is implemented.

Use stable identifiers for editable architectural objects.

Represent relationships explicitly.

Do not rely on array positions, display names, or rendering-object references
as persistent identifiers.

Distinguish between:

- Authoritative architectural parameters.
- Reference geometry.
- Derived geometry.
- Rendering state.
- Temporary editing state.
- UI state.

Derived geometry must be rebuildable from authoritative model data.

Do not persist transient renderer objects as the architectural model.

## 7. Units, Coordinates, and Precision

Establish a documented coordinate system and unit policy.

Prefer meters for internal physical dimensions and radians for angular
calculations unless a documented project-wide decision specifies otherwise.

Support metric and imperial display settings.

Convert units at application boundaries.

Avoid repeated conversions that accumulate rounding errors.

Distinguish between:

- Stored precision.
- Display precision.
- Geometric tolerance.
- Construction tolerance.
- Validation tolerance.

Never silently modify a stored architectural dimension merely to make
the interface or drawing appear cleaner.

Screen pixels must never be treated as physical architectural dimensions.

Document the mapping between 2D plan coordinates and 3D world coordinates.

Use explicit transformations between screen, viewport, and world space.

All drawings and views must preserve the same underlying physical dimensions.

## 8. Geometry Engine

Implement reusable, testable geometry operations.

The geometry layer should progressively support:

- Points and vectors.
- Distances and angles.
- Lines and segments.
- Intersections.
- Polygon operations.
- Offsets.
- Wall outlines.
- Wall joins.
- Room boundary detection.
- Point-in-polygon tests.
- Bounding boxes.
- Coordinate transformations.
- Snapping.
- Collision detection.
- Clearance checks.
- 3D transformations.
- Solid and surface geometry generation.

Handle degenerate geometry deliberately.

Examples include:

- Zero-length segments.
- Coincident points.
- Nearly parallel lines.
- Self-intersecting polygons.
- Invalid room boundaries.
- Nearly zero-length edges.
- Openings outside host walls.
- Inconsistent wall intersections.

Use robust geometry algorithms or established libraries where ordinary
floating-point calculations are insufficient.

Do not solve numerical instability by increasing tolerances arbitrarily.

Do not duplicate important geometry calculations across UI components.

Keep geometry independent of rendering whenever practical.

## 9. Parametric Architectural Elements

Architectural objects must preserve meaningful parameters.

A wall may contain:

- Reference line or centerline.
- Thickness.
- Height.
- Base elevation.
- Construction type.
- Material layers.
- Start and end connections.
- Openings.
- Level association.
- Structural classification when applicable.

A door or window may contain:

- Width and height.
- Position along the host wall.
- Sill height where applicable.
- Frame parameters.
- Opening parameters.
- Orientation.
- Type and material.
- Host-wall relationship.

A slab may contain:

- Boundary geometry.
- Thickness.
- Elevation.
- Material.
- Level association.

Roofs, stairs, furniture, and other elements should also preserve their
relevant domain-specific parameters.

Do not model every architectural element as an untyped collection of
coordinates and rendering properties.

Validate parameters before committing changes.

## 10. Model Integrity and Relationships

Enforce consistency between related objects.

Examples:

- An opening must reference a valid host wall.
- An opening must fit within permitted host-wall boundaries.
- A room must have valid boundary geometry.
- An element assigned to a level must reference an existing level.
- A stair must have valid level connections when required.
- A furniture instance must have a valid placement transform.
- Deleting a host object must not leave invalid dependent objects.
- Moving a wall must update or invalidate affected geometry.
- Changing level elevations must update dependent representations.

Define explicit policies for deleting, moving, splitting, merging, and
rehosting related objects.

Avoid silently deleting unrelated objects to resolve invalid relationships.

When a change cannot be applied safely, report the problem and preserve
the last valid model.

## 11. 2D Architectural Drawing Editor

The 2D editor is a core application feature.

Support the progressive implementation of:

- Wall creation and editing.
- Door and window placement.
- Room boundaries.
- Slabs and structural elements.
- Stairs.
- Dimensions and annotations.
- Grid lines and reference lines.
- Furniture and fixtures.
- Layers and visibility.
- Selection and multi-selection.
- Move, rotate, resize, and delete.
- Copy and paste.
- Undo and redo.
- Snapping and alignment.
- Numeric input.
- Zoom and pan.
- Keyboard shortcuts.
- Selection filters.
- Object property editing.

Keep drawing input, tool state, geometry calculations, model commands,
and rendering separate.

Walls must be architectural objects, not merely thick decorative lines.

Use explicit transformations between screen and world coordinates.

Hit testing must remain reliable at different zoom levels.

Provide clear visual feedback during snapping, selection, and placement.

The drawing canvas must remain usable and uncluttered.

## 12. Constraint-Based Editing

Progressively introduce constraints such as:

- Fixed dimensions.
- Alignment.
- Parallelism.
- Perpendicularity.
- Equal spacing.
- Distance constraints.
- Window centering.
- Wall alignment across levels.
- Corridor widths.
- Furniture clearances.

When a user changes a constrained element, update dependent geometry
when the constraints permit it.

Detect conflicting constraints.

Do not silently violate constraints to complete an edit.

Provide understandable feedback when constraints cannot be satisfied.

Begin with a small set of reliable constraints before introducing a
general-purpose solver.

## 13. Room Detection and Area Calculations

Support automatic detection of enclosed rooms where practical.

Calculate room areas and other supported measurements from the model.

Define the measurement convention explicitly.

For example, distinguish between area measured to wall finishes,
structural boundaries, and centerlines.

Support:

- Room names and numbers.
- Room area labels.
- Floor area summaries.
- Room schedules.
- Furniture-to-room relationships.
- Basic space-planning checks.

Do not present calculated areas without identifying the applicable
measurement convention when it matters.

## 14. Three-Dimensional Visualization

Use Three.js for interactive 3D visualization where appropriate.

Generate 3D geometry from the authoritative architectural model.

Support:

- Perspective and orthographic cameras.
- Orbit, pan, and zoom.
- Fit-to-building.
- Standard architectural views.
- Level visibility.
- Object selection.
- Material assignment.
- Lighting and shadows.
- Furniture and landscape assets.
- Technical shaded views.
- Wireframe views.
- Material previews.
- Realistic visualization.
- Walkthrough navigation.

Maintain a reliable mapping between architectural identifiers and
rendered objects.

Editing through the 3D viewport must update the shared model through
validated commands.

Keep rendering simplifications separate from authoritative dimensions.

Update affected geometry incrementally where practical.

Dispose of unused GPU resources.

Do not allow rendering approximations to silently alter architectural data.

## 15. Technical Drawings and Construction Documentation

Progressively support:

- Dimensioned floor plans.
- Furniture plans.
- Exterior elevations.
- Building sections.
- Roof plans.
- Foundation plans.
- Structural layouts.
- Door and window schedules.
- Room and area schedules.
- Material schedules.
- Drawing sheets.
- Title blocks.
- Revision identifiers.
- Drawing metadata.

Generate drawing geometry and dimensions from the authoritative model.

Separate model data from drawing-specific presentation settings.

Support:

- Drawing scales.
- Line weights.
- Layers.
- Symbols.
- Dimension styles.
- Annotation visibility.
- Page sizes.
- Sheet layouts.
- Legends and notes.

Do not generate technical sections by simply taking arbitrary screenshots
of the 3D viewport.

Do not invent missing construction information.

Report incomplete information and unsupported drawing elements.

Distinguish conceptual drawings from verified construction documentation.

## 16. CAD and BIM Interoperability

Treat each format as a distinct interoperability target.

Potential formats include:

- PDF.
- SVG.
- DXF.
- DWG.
- IFC.
- glTF.
- GLB.
- Additional formats when justified.

Create dedicated import and export adapters.

Keep format-specific logic outside the core architectural model.

Validate incoming files before importing them.

Preserve supported dimensions, units, geometry, layers, relationships,
and metadata.

Document unsupported features and information loss.

Do not claim complete format fidelity without testing.

Do not assume that renaming one file format creates another valid format.

For each supported format, define:

- Supported versions.
- Supported entities.
- Unit behavior.
- Metadata mappings.
- Known limitations.
- Validation procedures.

Test exported files with independent readers where practical.

Do not overwrite the original project during conversion.

## 17. Interior Design and Asset Management

Support reusable assets for:

- Furniture.
- Kitchen cabinets.
- Bathroom fixtures.
- Lighting.
- Doors and windows.
- Decorative objects.
- Materials and finishes.
- Plants and trees.
- Outdoor furniture.
- Landscape elements.

Separate catalog definitions from project instances.

Each project instance should have its own identifier, position, rotation,
level association, visibility, and permitted material overrides.

Support precise placement, alignment, collision warnings, and clearance checks.

Validate asset dimensions and transformations.

Track asset provenance and licensing requirements.

Do not redistribute copyrighted assets without appropriate authorization.

Avoid loading the entire asset catalog into memory simultaneously.

Provide graceful fallbacks for missing assets.

## 18. Landscape and Site Design

Progressively support:

- Terraces and balconies.
- Pools.
- Patios.
- Plants and trees.
- Lawns and planting areas.
- Driveways and paths.
- Fences and gates.
- Outdoor furniture.
- Outdoor lighting.
- Garages and outbuildings.
- Terrain and elevation data.

Use a consistent project coordinate system.

Keep landscape geometry logically separate from building geometry.

Distinguish visual landscaping from engineered grading, drainage,
and other civil-engineering calculations.

## 19. Realistic Rendering and AI Visualization

Support multiple visualization levels:

- Basic 3D preview.
- Textured materials.
- Realistic lighting.
- Interior visualization.
- Exterior visualization.
- High-quality still images.
- Walkthroughs.
- Optional virtual reality.

Preserve a model-based rendering workflow that reflects actual geometry.

AI-assisted visualization may help explore styles and alternative designs.

However, generated images must not silently replace authoritative
architectural geometry or dimensions.

Clearly distinguish illustrative AI output from a visualization generated
directly from the building model.

Do not present AI-generated imagery as proof of construction accuracy.

Keep expensive rendering jobs asynchronous when appropriate.

## 20. Project Templates and Duplication

Users must be able to start from blank projects or reusable templates.

Support:

- House templates.
- Apartment templates.
- Multistory templates.
- Custom user templates.
- Template previews.
- Project duplication.
- Independent editing.
- Custom template creation.

Duplicating a project must create independent project-owned entities
with new identifiers.

Preserve internal relationships and hierarchy.

Immutable catalog assets may be shared.

Do not share mutable architectural entities between independent projects.

Validate duplicated projects before saving.

## 21. Undo, Redo, and Editing Transactions

Important edits must be reversible.

Use commands or transactions that record model changes.

Support undo and redo for:

- Adding and deleting objects.
- Moving and rotating objects.
- Changing dimensions.
- Adding and removing openings.
- Modifying materials.
- Changing level assignments.
- Editing furniture and fixtures.

Group related changes into logical transactions.

An operation that updates several related architectural elements must
not leave the project in an inconsistent intermediate state.

Undo must restore model data and relationships, not merely the previous
rendered image.

## 22. Persistence and Versioning

Persist the authoritative architectural model and required project metadata.

Use versioned schemas.

Validate saved data before loading it.

Support migrations when schemas change.

Distinguish autosaved changes from explicit revisions.

Prevent concurrent saves from silently overwriting newer project state.

For large projects, evaluate incremental persistence and background saving.

Use PostgreSQL for structured application data.

Use object storage for large assets, models, textures, exports, and renders
where appropriate.

Do not serialize transient rendering state as architectural data.

Test saving, loading, duplication, recovery, and schema migration.

## 23. Security and Access Control

Validate API inputs.

Enforce project permissions on the server.

Do not trust client-reported authorization.

Treat imported files as untrusted input.

Limit upload sizes and file-processing resources.

Prevent path traversal and unsafe file handling.

Protect secrets and credentials.

Use appropriate authentication and session security.

Enforce resource limits for background workers.

Record significant project changes where auditability is required.

## 24. Performance and Scalability

Architectural projects may contain thousands of objects and large assets.

Profile before optimizing.

Avoid unnecessary React re-renders.

Keep expensive geometry calculations out of frequent UI rendering paths.

Use spatial indexing when appropriate.

Consider Web Workers for expensive browser computations.

Use instancing for repeated visual assets where beneficial.

Avoid unnecessary full-model rebuilds.

Dispose of unused geometry, textures, and materials.

Keep imports, exports, and expensive renders responsive.

Move expensive operations to background workers when justified.

Do not sacrifice geometric correctness for performance without explicitly
defining and validating the trade-off.

## 25. Testing and Validation

Use multiple levels of testing.

### Unit tests

Test:

- Unit conversion.
- Coordinate transformations.
- Geometric intersections.
- Polygon validity.
- Wall dimensions.
- Opening placement.
- Room boundaries.
- Level elevations.
- Model relationships.
- Serialization.
- Constraint behavior.

### Integration tests

Test:

- 2D-to-3D synchronization.
- Geometry regeneration.
- Undo and redo.
- Template duplication.
- Save and reload.
- Schema migrations.
- Drawing generation.
- Import and export.

### Browser tests

Use Playwright for critical user workflows.

Test project creation, drawing, editing, saving, loading, and navigation.

### Interoperability tests

Validate exported files with independent readers where practical.

Compare geometry, dimensions, units, supported properties, and metadata.

### Regression tests

When fixing a geometry or model bug:

1. Reproduce the failure.
2. Add a regression test.
3. Implement the fix.
4. Run the regression test.
5. Run relevant neighboring tests.

Do not claim that tests passed unless they were executed.

## 26. Visual Design, UI/UX, and Design System

### 26.1 Design Vision

The application must look modern, premium, sophisticated, and subtly futuristic.

It should feel like a next-generation architectural design platform
combining professional CAD tools, premium creative software, and immersive
3D visualization.

The interface should communicate:

- Precision.
- Technical excellence.
- Creativity.
- Professionalism.
- Reliability.
- Modern architectural aesthetics.
- Sophisticated technology.
- Ease of use.

The application must not look like a generic admin dashboard or an
unfinished collection of default UI components.

Visual quality is a core product requirement, not a finishing task.

Every new feature must be designed and implemented with both functional
correctness and visual polish in mind.

### 26.2 Visual Direction

Use a refined, futuristic aesthetic without sacrificing usability.

Preferred characteristics:

- Clean, carefully structured layouts.
- Generous but efficient spacing.
- Sophisticated surfaces.
- Subtle borders.
- Restrained shadows.
- Crisp typography.
- Carefully selected accent colors.
- Minimal visual clutter.
- Consistent iconography.
- Smooth, purposeful animations.
- High-quality 2D and 3D presentation.

Futuristic design should come from typography, composition, interaction,
visual hierarchy, and rendering quality.

Avoid excessive neon, decorative gradients, unnecessary glassmorphism,
oversized headings, and distracting effects.

The interface should feel premium because it is coherent and carefully
designed, not because every component has visual effects.

### 26.3 Color System

Implement a centralized design-token system using CSS custom properties
or an equivalent mechanism.

The default theme should be a sophisticated dark interface suitable
for long architectural design sessions.

Suggested palette:

- Main background: deep charcoal or near-black.
- Primary surfaces: dark graphite.
- Secondary surfaces: slightly lighter charcoal.
- Borders: subtle neutral gray.
- Primary text: soft white.
- Secondary text: muted gray.
- Primary accent: restrained electric blue or architectural cyan.
- Secondary accent: subtle violet, used sparingly.
- Success, warning, and error: accessible semantic colors.

Use accent colors primarily for interactive states, selection, focus,
and important actions.

Avoid using accent colors on every element.

Keep drawing-canvas colors configurable independently of the application
theme.

Architectural linework and dimensions must remain readable against
their backgrounds.

Provide a polished light theme when practical.

Theme changes must never alter the building model, dimensions, geometry,
or material definitions.

### 26.4 Design Tokens

Centralize reusable design decisions, including:

- Colors.
- Typography.
- Spacing.
- Corner radii.
- Borders.
- Shadows.
- Icon sizes.
- Control heights.
- Layering and z-index.
- Animation durations.
- Focus indicators.

Do not introduce arbitrary values throughout individual components
when an existing design token can be used.

Extend the design system when a genuine new requirement appears.

### 26.5 Typography

Use a modern, highly legible sans-serif typeface.

Typography should feel technical, precise, and contemporary.

Establish a consistent hierarchy for:

- Page titles.
- Section headings.
- Panel headings.
- Body text.
- Labels.
- Form controls.
- Dimensions and measurements.
- Tooltips.
- Secondary information.

Use tabular numerals where appropriate for dimensions, coordinates,
measurements, and property values.

Avoid excessively small text and decorative fonts.

Maintain readable contrast.

Technical annotations must remain legible at supported drawing scales
and viewport sizes.

### 26.6 Application Layout

Design the application as a professional creative workspace.

The drawing canvas and 3D viewport should receive the greatest share
of the available workspace.

The interface should provide appropriate combinations of:

- Main navigation.
- Project controls.
- Contextual toolbars.
- Drawing and 3D workspaces.
- Properties inspector.
- Layers panel.
- Object hierarchy.
- Asset browser.
- Status bar.
- Contextual feedback.

Do not display every tool simultaneously.

Group related functionality and reveal contextual controls when useful.

Allow important panels to be resized, collapsed, and reopened where
appropriate.

Preserve workspace preferences between sessions when practical.

Avoid excessive modal dialogs, unnecessary nested navigation, and
permanent panels that unnecessarily reduce the drawing area.

### 26.7 Project Dashboard

The project dashboard should feel like a premium architectural studio.

Provide:

- Attractive project cards.
- Architectural previews.
- Project names and metadata.
- Recent projects.
- Template discovery.
- Search and filtering.
- New-project creation.
- Clear organization.

Use meaningful floor-plan previews or 3D thumbnails instead of generic
placeholder illustrations.

Design polished empty states with clear next actions.

Avoid generic business-dashboard layouts.

### 26.8 2D Drawing Workspace

Keep the drawing canvas visually dominant.

Support:

- Clear tool selection.
- Precise numeric input.
- Visible snapping indicators.
- Readable dimensions.
- Contextual properties.
- Selection highlights.
- Configurable grids.
- Layer visibility.
- Measurement feedback.

Use restrained visual indicators for selected objects, active tools,
constraints, snapping, and validation warnings.

Avoid decorative effects that obscure architectural linework.

Do not compromise geometric precision or dimension readability for
visual styling.

### 26.9 3D Viewport

The 3D viewport should feel immersive, polished, and responsive.

Provide:

- Smooth camera navigation.
- Balanced lighting.
- High-quality material previews.
- Clear selection feedback.
- Perspective and orthographic views.
- Camera presets.
- Contextual editing tools.
- Technical and presentation visualization modes.

Avoid excessive bloom, aggressive depth of field, exaggerated reflections,
and other effects that obscure geometry.

Technical accuracy takes priority over cinematic presentation when
the user is editing the model.

### 26.10 Component Library

Build reusable components for:

- Buttons.
- Icon buttons.
- Toolbars.
- Menus.
- Context menus.
- Property panels.
- Form fields.
- Dropdowns.
- Tabs.
- Dialogs.
- Tooltips.
- Notifications.
- Sliders.
- Color pickers.
- Project cards.
- Asset cards.
- Layer lists.
- Object trees.
- Dimension inputs.
- Status indicators.

Use consistent sizes, spacing, typography, borders, focus states,
and interaction patterns.

Use a consistent icon library.

Do not mix unrelated icon styles.

Avoid emoji as substitutes for professional interface icons.

Extend existing design-system components instead of creating unnecessary
one-off implementations.

### 26.11 Interaction Design

Provide appropriate feedback for:

- Default.
- Hover.
- Active.
- Selected.
- Focused.
- Disabled.
- Loading.
- Success.
- Warning.
- Error.

Animations should be subtle, purposeful, and responsive.

Do not delay drawing operations for decorative animation.

Provide immediate feedback during selection, dragging, snapping,
placement, and numeric editing.

Destructive operations must be distinguishable from ordinary actions.

Use animation to communicate state changes, not to conceal them.

### 26.12 Property Inspectors and Forms

Property panels must support efficient and precise editing.

Provide:

- Explicit labels.
- Appropriate units.
- Numeric validation.
- Sensible defaults.
- Inline error messages.
- Clear editable states.
- Keyboard-friendly controls.
- Immediate previews when appropriate.

Users must be able to enter exact dimensions without fighting the interface.

Do not silently round or modify user-entered dimensions.

Distinguish displayed precision from stored precision.

### 26.13 Responsive Design

Desktop and laptop screens are the primary architectural editing
environment.

Preserve sufficient space for the drawing canvas, properties panels,
toolbars, and 3D viewport.

For smaller screens, adapt the layout rather than simply shrinking
the desktop interface.

Mobile priorities include:

- Project browsing.
- 3D viewing.
- Walkthroughs.
- Basic property inspection.
- Simple object manipulation.
- Touch-friendly navigation.

Provide touch-appropriate controls.

### 26.14 Accessibility

Target WCAG 2.2 AA for the application interface.

Provide:

- Sufficient text contrast.
- Visible keyboard focus.
- Keyboard-accessible controls.
- Accessible names for icon-only buttons.
- Proper form labels.
- Meaningful error messages.
- Reduced-motion support.
- Usable touch targets.
- Screen-reader support where appropriate.

Do not rely on color alone to communicate warnings, selection, or errors.

Provide keyboard alternatives for important pointer-based operations.

### 26.15 Empty, Loading, and Error States

Empty states should explain what the user can do next.

Examples include:

- No projects created.
- No objects selected.
- No available furniture.
- No drawing views.
- No saved templates.

Use meaningful loading indicators for expensive operations.

Errors must be clear and actionable.

Do not expose raw stack traces or internal implementation details
in ordinary user-facing error messages.

Where appropriate, explain how to resolve invalid geometry or incomplete
architectural information.

### 26.16 Visual Consistency

Before implementing a UI feature:

1. Inspect the existing design system.
2. Reuse established components and tokens.
3. Follow existing layout and interaction patterns.
4. Identify missing reusable components.
5. Extend the design system when necessary.
6. Verify the result against existing screens.

Do not introduce arbitrary colors, spacing values, typography scales,
or interaction patterns without justification.

Document intentional exceptions.

### 26.17 Visual Verification

For significant UI changes:

1. Run the application.
2. Inspect the affected screen in a browser when tools are available.
3. Verify layout, spacing, alignment, and typography.
4. Test hover, focus, selected, disabled, and error states.
5. Check supported desktop viewport sizes.
6. Test the main interaction flow.
7. Check for clipping, overflow, and layout shifts.
8. Verify that the drawing canvas and 3D viewport remain usable.
9. Confirm that existing workflows have not regressed.

Use screenshots or browser-based visual comparisons when available.

Do not claim visual verification was completed if the interface was
not actually inspected.

### 26.18 Definition of Done for UI Work

A UI feature is complete when:

- It follows the established design system.
- It looks polished and consistent with the application.
- Its purpose is clear.
- Its interactions provide appropriate feedback.
- It supports keyboard navigation where applicable.
- It remains readable at supported screen sizes.
- It does not obstruct architectural editing.
- It performs acceptably.
- It handles empty, loading, and error states.
- It has been tested at the appropriate level.

The final interface must feel like a cohesive premium architectural
design product, not a collection of independently implemented screens.

## 27. Accessibility, Privacy, and Professional Responsibility

The application may store private architectural plans, property details,
and client information.

Protect project data through appropriate access controls and secure
storage.

Do not expose private project data to unauthorized users.

Do not include sensitive project information in unnecessary logs.

Architectural visualization must not be confused with engineering
verification.

Do not invent structural calculations.

Do not claim code compliance without an appropriate, defined, and validated
ruleset.

Structural engineering, fire safety, accessibility, energy performance,
and building-code compliance require specialized rules, inputs, and
verification.

Distinguish conceptual designs from construction documentation.

Where appropriate, identify information that requires qualified
professional review.

## 28. Git and File Safety

Inspect existing changes before substantial edits.

Preserve unrelated user work.

Do not discard user changes.

Do not rewrite Git history without authorization.

Do not commit unless requested or required by the established workflow.

Avoid unnecessary dependency and lockfile changes.

Do not modify files outside the project without a clear reason.

## 29. Definition of Done

A task is complete when:

- The requested behavior is implemented.
- Architectural relationships remain valid.
- Dimensions and units remain consistent.
- Relevant tests pass.
- Existing behavior is preserved where required.
- 2D and 3D representations remain synchronized.
- Persistence remains reliable.
- Interoperability is tested when affected.
- The interface follows the shared design system.
- Significant UI changes receive appropriate visual verification.
- Documentation is updated where necessary.
- Verification limitations are reported honestly.

The objective is a reliable architectural design platform whose building
model, technical drawings, 3D representations, and exports remain
consistent, with a polished, modern, and futuristic user experience.
