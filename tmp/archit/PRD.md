Real Estate Architecture & 3D Property Design Platform

Your idea is much more than a house-plan drawing application. You're describing a complete architectural design, building documentation, interior design, landscape planning, and 3D visualization platform.

The goal should be to let a user start with a template or a blank project, design a property using precise measurements, produce technical drawings that professionals can work with, furnish and decorate the property, and then explore and visualize the result in 3D.

The most important architectural decision is to build the application around one shared, dimensionally accurate building model. Floor plans, elevations, sections, 3D geometry, furniture layouts, and exported CAD files should all derive from this model rather than being separate drawings that must be synchronized manually.

This is essential if you want the application to progress from a visual design tool into a professional product that architects, engineers, and builders can rely on.

I'll recommend a technology stack, outline the product requirements, and provide ready-to-use AGENTS.md and skills files for AI coding agents.
1. Recommended technology stack

Since you plan to use Node.js, I recommend keeping Node.js for the backend and development tooling while using TypeScript for most application code.
Programming languages
Technology	Purpose	Recommendation
TypeScript	Frontend, backend, geometry, application logic	Primary language
JavaScript	Small scripts and tooling where TypeScript is unnecessary	Optional
GLSL / WGSL	Custom GPU shaders and advanced rendering	Only when needed
SQL	Database queries and migrations	Through a typed ORM where practical
Python	Optional integrations with specialized geometry or AI tools	Introduce only when justified

Why TypeScript instead of plain JavaScript?

An architectural design application has complicated data relationships. A wall has dimensions, materials, layers, connections, openings, and structural properties. A window belongs to a wall. A room is bounded by surfaces. A roof interacts with walls and other roof surfaces.

TypeScript makes these relationships easier to define and validate.

It also helps coding agents understand the project, reduces accidental inconsistencies, and makes large refactors safer.

You can use Node.js to execute TypeScript through an appropriate development toolchain.
Recommended application architecture

I would start with a modular monolith rather than microservices.

That means one application with clearly separated subsystems, not dozens of independently deployed services.

Architecture Design Platform
│
├── Web Application
│   ├── Project Dashboard
│   ├── 2D Plan Editor
│   ├── Technical Drawing Editor
│   ├── 3D Viewport
│   ├── Interior Design
│   ├── Landscape Design
│   └── Property Walkthrough
│
├── Shared Building Model
│   ├── Levels and Floors
│   ├── Walls and Partitions
│   ├── Doors and Windows
│   ├── Rooms and Spaces
│   ├── Slabs and Foundations
│   ├── Roofs and Stairs
│   ├── Furniture and Fixtures
│   └── Materials and Finishes
│
├── Geometry Engine
│   ├── 2D Geometry
│   ├── Constraints and Dimensions
│   ├── Wall Connections
│   ├── Opening Placement
│   ├── Room Detection
│   ├── 3D Geometry Generation
│   └── Collision Detection
│
├── Documentation Engine
│   ├── Floor Plans
│   ├── Elevations
│   ├── Sections
│   ├── Dimensions and Annotations
│   ├── Schedules
│   └── Drawing Sheets
│
├── Visualization Engine
│   ├── Real-Time 3D
│   ├── Materials and Lighting
│   ├── Furniture and Landscaping
│   ├── Walkthroughs
│   └── Photorealistic Rendering
│
├── Export and Import
│   ├── IFC
│   ├── DXF
│   ├── DWG Integration
│   ├── glTF / GLB
│   └── PDF and SVG
│
├── Backend
│   ├── Authentication and Authorization
│   ├── Project Management
│   ├── Versioning and Revisions
│   ├── Asset Management
│   ├── Collaboration
│   └── Background Jobs
│
└── Infrastructure
    ├── PostgreSQL
    ├── Object Storage
    ├── Redis / Job Queue
    └── Rendering Workers

This is a logical architecture, not a requirement to create every module immediately. Start with the components needed for a reliable 2D-to-3D workflow.
Recommended libraries and platforms
Area	Recommended technology	Why
Frontend	React + TypeScript	Complex editing interfaces and reusable panels
Frontend tooling	Vite	Fast development and production builds
Backend	Node.js + TypeScript + Fastify	API, validation, project management
2D drawing	SVG and Canvas	SVG for interactive vectors; Canvas for high-volume rendering
3D visualization	Three.js	Interactive building models, lighting, materials, and cameras
3D geometry	A dedicated geometry layer; evaluate OpenCascade.js	More advanced solid geometry and CAD-oriented operations
BIM interoperability	IFC and web-ifc	Reading, inspecting, and integrating building information models
Database	PostgreSQL	Projects, users, revisions, and structured building data
Spatial data	PostGIS, if needed	Geographic data and site planning
File storage	S3-compatible object storage	Models, textures, exports, and renders
API validation	Zod or an equivalent schema library	Validate requests and shared data contracts
Testing	Vitest + Playwright	Unit, integration, and browser testing
Background jobs	A Redis-backed queue, when needed	Rendering, imports, exports, and expensive computations

Three.js provides a practical foundation for the interactive 3D viewport and supports exporting scenes to glTF and GLB. 【turn0search4】

For BIM interoperability, web-ifc is worth evaluating early. It provides JavaScript APIs for working with IFC building models and uses WebAssembly for its geometry-related functionality. 【turn0search0】

For advanced solid modeling, OpenCascade.js is another candidate. It brings OpenCascade CAD functionality to JavaScript through WebAssembly, but you should validate its capabilities and integration complexity against your actual requirements before committing to it. 【turn0search10】

Important: Three.js is a rendering engine, not a complete architectural CAD or BIM engine. You will still need your own building data model, geometry rules, dimensioning, constraints, technical drawing generation, and validation systems.
What about DWG, DXF, and other CAD formats?

Treat file interoperability as a dedicated subsystem, not an afterthought.

I recommend supporting formats in this order:

    PDF — printable plans and technical documentation.
    SVG — scalable vector drawings and diagrams.
    DXF — editable 2D drawings and selected 3D data.
    IFC — building elements and structured BIM information.
    GLB/glTF — 3D visualization and asset exchange.
    DWG — integration with proprietary CAD workflows.

The exact order should depend on your target customers.

DXF and DWG are not interchangeable. DXF is a practical initial target for editable CAD drawings. DWG integration needs separate evaluation because preserving the format's behavior, layers, dimensions, and compatibility with different CAD applications can be difficult.

For DWG-related cloud workflows, investigate Autodesk Platform Services and its AutoCAD Automation API. Autodesk documents cloud-based automation for creating, processing, and modifying DWG files. 【turn0search3】【turn0search9】

Do not promise complete CAD interoperability merely because an exporter produces a file. Validate the exported result in the target applications.
2. Define the product's core capabilities

Your platform has several related but distinct workspaces. They should share the same project and building model.
A. Project management and templates

Users should be able to:

    Create a project from scratch.
    Choose a house or apartment template.
    Browse templates by building size, number of floors, architectural style, and room count.
    Preview plans and 3D models before choosing a template.
    Copy a template into a new independent project.
    Modify the copy without affecting the original.
    Save custom templates.
    Duplicate projects and create revisions.
    Organize projects by client, property, or development.

A copied template should be a genuine independent project, including its own identifiers and editable building data. Shared furniture-library assets and other immutable resources can remain references.
B. 2D architectural drawing

This should be the first major editing workspace.

Users should be able to draw and modify:

    Exterior and interior walls.
    Doors and windows.
    Rooms and room boundaries.
    Floor slabs.
    Columns and structural elements.
    Stairs.
    Dimensions and annotations.
    Grid lines and reference lines.
    Furniture and fixtures.
    Electrical and plumbing symbols, when those systems are implemented.

Essential drawing features include:

    Metric and imperial units.
    Configurable grid and snapping.
    Precise numeric input.
    Object selection and multi-selection.
    Move, rotate, resize, and delete.
    Undo and redo.
    Copy and paste.
    Layers and visibility controls.
    Alignment and distribution.
    Dimension constraints.
    Wall connection handling.
    Zoom, pan, and fit-to-screen.
    Keyboard shortcuts.
    Selection filters.

A critical requirement: walls should be editable architectural objects, not just thick lines. A wall needs a centerline or reference geometry, thickness, height, construction type, material layers, and connections to other building elements.

The application should derive its 2D appearance from that structured information.
C. Multistory building design

The application should support multiple levels from the beginning of the data-model design.

Users should be able to:

    Add and remove floors.
    Set floor-to-floor heights.
    Configure slab thickness.
    Align walls between levels.
    Define stair connections.
    Control which levels are visible.
    Compare floors.
    Generate a complete 3D building from all levels.

Don't assume every building has the same floor height or that every wall spans the same vertical range.
D. Automatic 3D generation

The 3D representation should update when the user modifies the 2D plan.

For example:

    The user draws a wall.
    The application generates the corresponding 3D wall.
    The user inserts a window into that wall.
    The wall's 3D geometry updates to include the opening.
    The user changes the window dimensions.
    Both the technical drawing and the 3D representation reflect the change.

The same principle should apply to doors, stairs, slabs, roofs, furniture, and other supported objects.

Use a shared building model as the authoritative source. Do not make the 2D plan and 3D model independent representations that can silently drift apart.
E. Technical drawings and construction documentation

This is one of the most demanding parts of the product.

The application should eventually generate:

    Dimensioned floor plans.
    Exterior elevations.
    Building sections.
    Roof plans.
    Foundation plans.
    Structural layouts.
    Door and window schedules.
    Room schedules.
    Area calculations.
    Material schedules.
    Drawing sheets with title blocks.
    Revision identifiers and drawing metadata.

Where appropriate, support different drawing scales, line weights, annotations, symbols, and dimension styles.

Allow users to define which objects appear in each drawing type.

For example, a furniture plan might show beds, sofas, kitchen cabinets, and sanitary fixtures, while a structural plan might emphasize columns, beams, load-bearing walls, and foundations.

Technical documentation must distinguish between what the model actually defines and what has merely been represented visually.
F. Interior design

Once the building structure exists, users should be able to furnish and decorate it.

Include:

    Furniture catalogs.
    Kitchen and bathroom layouts.
    Wardrobes and built-in storage.
    Lighting fixtures.
    Wall, floor, and ceiling finishes.
    Paint and material selection.
    Doors and windows.
    Decorative objects.
    Curtains, rugs, and accessories.
    Custom furniture dimensions.
    Object rotation and precise placement.
    Object alignment and collision warnings.

Furniture should have dimensions, placement, orientation, and appropriate object metadata.

Where possible, support reusable parametric furniture rather than relying entirely on static models.
G. Exterior and landscape design

Support site-level elements such as:

    Terraces and balconies.
    Swimming pools.
    Patios.
    Outdoor furniture.
    Plants and trees.
    Lawns and planting areas.
    Driveways and paths.
    Fences and gates.
    Outdoor lighting.
    Fireplaces and outdoor cooking areas.
    Garages and outbuildings.
    Terrain elevation and landscaping.

Keep building geometry separate from landscape geometry, while allowing both to exist in the same project coordinate system.

Site design may eventually require property boundaries, terrain data, setbacks, and geographic coordinates.
H. Realistic visualization and walkthroughs

Once the geometry and materials are established, offer several visualization modes:

    Basic shaded 3D.
    Textured 3D.
    Daylight visualization.
    Interior lighting previews.
    Photorealistic still images.
    Exterior renders.
    Walkthroughs.
    Optional virtual reality support.

For AI-assisted image generation, distinguish between two different capabilities:

    Model-based rendering: generates an image from actual geometry, materials, lights, and cameras.
    AI-assisted visualization: enhances or reinterprets an image to explore design alternatives.

The second approach can produce attractive results but may change architectural details. It should not be treated as proof that the generated image accurately represents the building model.

For professional use, preserve a way to generate views that correspond directly to the authoritative geometry.











5. Improvements I strongly recommend

These features will make the platform substantially more useful than a typical floor-plan editor.
A. Constraint-based architectural editing

Rather than allowing users to manipulate every object freely, support relationships and constraints.

For example:

    Keep two walls aligned.
    Maintain a fixed corridor width.
    Keep a window centered on a wall.
    Maintain equal distances between windows.
    Align walls on different floors.
    Keep a room's width fixed while changing its length.
    Maintain a specified distance between furniture and walls.

When a user changes one dimension, the application should update related geometry when the constraints permit it.

If constraints conflict, the application should explain the conflict rather than silently produce an invalid layout.

Start with basic constraints—alignment, distance, parallelism, perpendicularity, and fixed dimensions—before building a general-purpose constraint solver.
B. Automatic room detection and area calculations

The application should recognize enclosed spaces and calculate their areas automatically.

This enables:

    Automatic room names and numbers.
    Floor area calculations.
    Room schedules.
    Living area summaries.
    Furniture-to-room associations.
    Basic space-planning checks.

However, area calculations need a defined measurement convention. For example, floor area measured to the wall finish is not necessarily the same as area measured to the wall centerline or structural boundary.

Allow the project to specify which convention applies.
C. Architectural validation engine

Build a system that checks projects for common problems.

Examples include:

    Windows extending beyond their host walls.
    Doors intersecting other objects.
    Walls that do not connect properly.
    Rooms with open or invalid boundaries.
    Stairs that do not connect the intended levels.
    Furniture blocking a designated passage.
    Inconsistent dimensions.
    Missing information in technical drawings.

Later, add configurable rules for building regulations.

Important: a geometric validation engine is not automatically a building-code compliance engine. Regulatory checks require jurisdiction-specific rules, relevant project information, and appropriate professional validation.
D. A parametric component library

Let users create reusable objects with adjustable parameters.

Examples:

    Windows with configurable width, height, and frame style.
    Doors with configurable dimensions and opening direction.
    Kitchen cabinets with adjustable widths and heights.
    Stairs with configurable rise, run, and width.
    Roofs with adjustable pitch and overhang.
    Pools with configurable dimensions and depth.

A component should have a definition, parameter constraints, and a generated representation.

Users should be able to save custom components to a personal or organization-wide library.
E. A construction information layer

If your goal is to help builders construct properties, add more than geometry.

Each architectural component could carry information such as:

    Material specification.
    Dimensions.
    Assembly type.
    Manufacturer or product reference.
    Installation notes.
    Quantity.
    Relevant drawing references.
    Revision history.

Eventually, this enables quantity takeoffs, preliminary material estimates, and more useful construction documentation.

Do not treat estimated quantities or costs as final without accounting for waste, local prices, construction methods, and other project-specific factors.
F. Revision comparison

Professional projects change frequently.

Allow users to compare revisions and identify:

    Added walls.
    Removed windows.
    Changed room dimensions.
    Updated materials.
    Modified furniture layouts.
    Changes to floor elevations.

A visual difference view combined with a structured change list would be particularly valuable.

For shared projects, also consider comments, approvals, and review workflows.
G. An AI design assistant

AI can help users interact with the model using natural language.

Examples:

    "Create a three-bedroom apartment with an open-plan kitchen and living room."
    "Add two windows to the south-facing bedroom."
    "Show alternative kitchen layouts."
    "Furnish this living room in a minimalist style."
    "Find rooms with insufficient space around the furniture."
    "Explain why the floor-plan validation reports a problem."

The key is to have AI propose structured, validated model operations, rather than allowing it to manipulate arbitrary canvas coordinates or generate an unrelated 3D scene.

A sensible workflow is:

    The user describes a change.
    AI translates it into a proposed set of operations.
    The geometry and constraint systems validate the proposal.
    The user previews the result.
    The application applies the change transactionally.
    The user can undo it.

AI-generated images can help explore styles, but AI should not silently change authoritative construction dimensions.








6. Recommended project structure

For a large application, I would use a TypeScript monorepo so that the frontend, backend, geometry engine, and shared data types can evolve independently.

A possible structure is:

architecture-platform/
├── AGENTS.md
├── package.json
├── tsconfig.base.json
├── README.md
│
├── apps/
│   ├── web/
│   │   ├── src/
│   │   │   ├── app/
│   │   │   ├── components/
│   │   │   ├── editor/
│   │   │   ├── drawing/
│   │   │   ├── viewport/
│   │   │   ├── projects/
│   │   │   └── features/
│   │   └── package.json
│   │
│   ├── api/
│   │   ├── src/
│   │   │   ├── routes/
│   │   │   ├── services/
│   │   │   ├── auth/
│   │   │   └── persistence/
│   │   └── package.json
│   │
│   └── workers/
│       ├── src/
│       │   ├── rendering/
│       │   ├── import/
│       │   └── export/
│       └── package.json
│
├── packages/
│   ├── domain/
│   │   └── src/
│   ├── geometry/
│   │   └── src/
│   ├── constraints/
│   │   └── src/
│   ├── building-model/
│   │   └── src/
│   ├── renderer-3d/
│   │   └── src/
│   ├── drawing-engine/
│   │   └── src/
│   ├── cad-adapters/
│   │   └── src/
│   ├── validation/
│   │   └── src/
│   └── shared/
│       └── src/
│
├── database/
│   ├── migrations/
│   └── seeds/
│
├── templates/
│   ├── houses/
│   └── apartments/
│
├── assets/
│   ├── furniture/
│   ├── materials/
│   └── vegetation/
│
├── tests/
│   ├── integration/
│   ├── geometry/
│   └── fixtures/
│
└── .agents/
    └── skills/
        ├── architectural-model/
        │   └── SKILL.md
        ├── geometry-engine/
        │   └── SKILL.md
        ├── floor-plan-editor/
        │   └── SKILL.md
        ├── three-dimensional-visualization/
        │   └── SKILL.md
        ├── technical-drawings/
        │   └── SKILL.md
        ├── cad-bim-interoperability/
        │   └── SKILL.md
        ├── interior-landscape-design/
        │   └── SKILL.md
        ├── testing-validation/
        │   └── SKILL.md
        └── project-persistence/
            └── SKILL.md

This is a target structure, not a requirement to create every directory at the start.

Important: don't build nine packages just because they appear in this diagram. Begin with the domain model, geometry engine, 2D editor, 3D renderer, and persistence. Extract additional packages when there is a clear architectural reason.



7. Database and project data

I recommend PostgreSQL for users, organizations, project metadata, permissions, revisions, and catalog information.

For the building model itself, choose a persistence strategy deliberately.

You have two reasonable options:
Option A: Versioned project documents

Store the building model as a versioned document, with relational records for users, projects, permissions, and revisions.

Advantages:

    Easy to duplicate projects.
    Simple initial implementation.
    Convenient for saving complete project states.
    Straightforward schema validation.

Disadvantages:

    Updating individual objects can become expensive.
    Concurrent editing requires careful conflict handling.
    Very large projects may require more sophisticated storage.

Option B: Structured architectural entities

Store architectural objects and their relationships as individual records.

Advantages:

    More granular updates.
    Better structured queries.
    Easier to inspect and audit individual elements.
    Useful for collaboration and reporting.

Disadvantages:

    More complicated relationship management.
    More complex project duplication.
    Requires careful transactional updates.

My recommendation

Start with a hybrid approach:

    PostgreSQL for project metadata and important structured entities.
    Versioned project documents for reliable project snapshots and initial persistence.
    Object storage for large models, textures, assets, and exports.
    A revision system that allows restoration of earlier project states.

As the platform grows, introduce more granular persistence where real workloads justify it.

Regardless of the approach, the saved model must remain authoritative. Rendering caches should always be rebuildable.
8. Development roadmap

The biggest risk is trying to implement architecture, CAD, BIM, interior design, AI rendering, and walkthroughs simultaneously.

Instead, build a sequence of working milestones.
Phase	Main objective	Deliverable
1	Foundation	Project dashboard, templates, shared building model
2	2D editor	Walls, openings, dimensions, snapping, undo/redo
3	Building geometry	Multistory buildings and automatic 3D generation
4	Core documentation	Floor plans, elevations, sections, PDF and SVG
5	CAD interoperability	DXF export and validated import workflows
6	Interior design	Furniture library, materials, kitchens and bathrooms
7	Exterior design	Pools, terraces, plants, outdoor furniture and terrain
8	Visualization	Lighting, realistic materials and high-quality renders
9	BIM	IFC import/export, metadata, component properties
10	Professional workflows	Revisions, validation reports and construction documentation
11	Walkthroughs	First-person navigation and optional immersive viewing
12	Advanced capabilities	AI-assisted design, collaboration and advanced integrations

These phases are a suggested dependency order, not a promise about development time.

You can bring forward individual features when they help validate the product. For example, a basic 3D preview should be available early to verify that the shared model works.
What should the first working version include?

I would limit the first release to the following.

Project management

    Create a project.
    Start from a blank project or a house/apartment template.
    Duplicate and rename projects.

2D editor

    Draw walls.
    Add doors and windows.
    Define floor dimensions.
    Add rooms.
    Display dimensions.
    Move, resize, and delete elements.
    Undo and redo.

3D preview

    Generate walls and openings.
    Add slabs and simple roofs.
    Navigate around the building.
    Change basic materials.

Persistence

    Save and reopen projects.
    Preserve dimensions and object relationships.
    Maintain a basic revision history.

Export

    Generate PDF floor plans.
    Generate SVG drawings.
    Export basic DXF drawings after validating the format implementation.

A complete construction-documentation system should come later, after the underlying building model has proved reliable.
9. Additional requirements before development

There are several important decisions worth making early.
1. Identify the primary customer

Are you initially targeting:

    Architects and architectural studios?
    Interior designers?
    Builders and contractors?
    Real estate developers?
    Homeowners designing their own properties?

You can eventually serve multiple groups, but they have different needs.

For example, homeowners prioritize ease of use and visualization. Architects need precise editing and documentation. Builders need dependable dimensions, specifications, revisions, and construction details.

My recommendation is to start with residential architects and interior designers, then expand toward construction workflows.
2. Define whether the application is conceptual or professional

There is a major difference between producing a convincing architectural visualization and producing drawings that can safely guide construction.

If construction use is an objective, plan for:

    Explicit units and scales.
    Dimension validation.
    Drawing revisions.
    Construction assemblies.
    Structural and material metadata.
    Applicable building-code checks.
    Export validation.
    Qualified professional review.

Do not market early-stage generated plans as automatically approved or construction-ready.
3. Define the initial CAD interoperability target

Before writing exporters, select the first external application and define exactly what must survive export.

For example:

    Can walls and openings be edited in the receiving application?
    Must dimensions remain editable?
    Are layers and line weights preserved?
    Is the target a 2D drawing, a 3D model, or a BIM model?
    Must object properties remain available?
    Which units and coordinate conventions are expected?

Test the answers with real sample files early.
4. Establish asset licensing

Furniture, plants, textures, and architectural components can involve licensing restrictions.

Every asset should have documented provenance and usage permissions.

This becomes particularly important if you allow users to distribute templates, share projects, or sell custom assets.
5. Plan for large projects

A building model may contain thousands of objects, detailed furniture, large textures, and complex geometry.

Plan for incremental updates, background processing, memory management, and efficient rendering. Avoid implementing a design that requires rebuilding every wall and mesh after every mouse movement.
10. Final recommendations

If I were starting this project today, I would make these five decisions first:

    Use TypeScript throughout the application and Node.js on the backend. React and Vite are a practical frontend combination.
    Build an authoritative architectural model before building extensive editing tools. This is the foundation for reliable 2D plans, 3D geometry, dimensions, and exports.
    Use Three.js for visualization, but keep architectural geometry and constraints in a separate domain layer.
    Treat IFC and CAD interoperability as first-class architectural concerns. Validate actual exported files rather than relying on filenames or superficial visual checks.
    Build the smallest complete workflow first: create a project, draw a floor plan, generate 3D geometry, save it, reopen it, and export a drawing.

The most valuable early milestone is not a photorealistic render. It is a small house that a user can edit in 2D, inspect in 3D, save and reopen without losing dimensional accuracy, and export into a useful technical drawing.

Once that works reliably, every additional capability—furniture planning, landscape design, visualization, walkthroughs, and AI-assisted editing—has a much stronger foundation.
Useful technical references

    Three.js documentation — 3D rendering and scene management.
    Three.js GLTFExporter — exporting supported 3D scenes to glTF and GLB. 【turn0search4】
    That Open Engine / web-ifc documentation — JavaScript IFC integration. 【turn0search0】
    OpenCascade.js — CAD geometry capabilities through WebAssembly. 【turn0search10】
    Autodesk Platform Services — CAD integrations, model viewing, and automation APIs. 【turn0search7】

One last architectural recommendation: keep AGENTS.md focused on universal project rules, and keep specialized implementation guidance in the individual skill files. That separation will make the project easier for AI coding agents to navigate as the codebase grows.

