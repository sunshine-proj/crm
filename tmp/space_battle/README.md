The central gameplay idea is orbital dogfighting with limited propulsion fuel. Players must manage momentum, rotate their satellites using maneuvering thrusters, and fire weapons while anticipating the opponent's trajectory. Thruster fuel regenerates over time, so players must balance aggressive maneuvers against conserving resources.

The visual direction is cinematic and grounded in spacecraft engineering: a dark starfield, a detailed planet, realistic satellite bodies and solar panels, visible thruster bursts, and projectiles that travel through space.


## 1. Project structure

I'll use the following structure, suitable for a repository managed by an AI coding agent:

orbital-combat/
├── AGENTS.md
├── PRD.md
├── SKILL.md
└── skills/
    ├── game-design/
    │   └── SKILL.md
    ├── orbital-physics/
    │   └── SKILL.md
    ├── gameplay-programming/
    │   └── SKILL.md
    ├── ai-opponent/
    │   └── SKILL.md
    ├── visual-art/
    │   └── SKILL.md
    ├── audio-design/
    │   └── SKILL.md
    ├── ui-ux/
    │   └── SKILL.md
    └── testing/
        └── SKILL.md

The root AGENTS.md will establish shared project rules and coordinate the skills. PRD.md will define the product requirements and acceptance criteria. Each skill will contain actionable instructions, implementation guidelines, and quality checks for its particular discipline.


## 2. Recommended implementation order

The documents are designed to work together. I recommend implementing the game in the following order.
Phase	Work	Completion condition
1. Foundation	Set up TypeScript, Three.js, Vite, and the game loop	The game launches and renders a scene
2. Environment	Create the planet and starfield	The arena looks like a space environment
3. Satellite models	Build both spacecraft with solar panels, engines, and weapons	Both satellites have readable, consistent orientations
4. Movement	Implement rotation, thrust, momentum, and fuel	The player can change its trajectory and manage propulsion
5. Combat	Add firing, projectiles, collisions, health, and destruction	Two satellites can damage and destroy one another
6. AI	Add enemy aiming, shooting, and maneuvering	A complete match can be played against the computer
7. Interface	Add HUD, menus, pause, and restart	The game can be played without developer intervention
8. Polish	Add engine effects, impact effects, audio, and lighting	Combat feels responsive and visually satisfying
9. Verification	Test gameplay, performance, and repeated restarts	The game satisfies the PRD acceptance criteria
Three important implementation decisions

1. Use a genuine movement simulation.

The satellites should not merely follow circular animations. Thrust must change velocity, and the resulting velocity must influence subsequent movement.

For a first version, I recommend a two-dimensional orbital simulation with a three-dimensional presentation. This gives you understandable combat and attractive spacecraft without requiring a full six-degree-of-freedom simulator.

2. Separate the weapon from the main engine.

The spacecraft should have a clearly defined forward weapon axis and a rearward main engine. Rotation changes the direction of both, but the weapon fires forward while the main engine produces acceleration in the opposite direction.

Small attitude-control thrusters should visually communicate rotation.

3. Make combat readable before making it spectacular.

The player should always be able to identify the opponent, understand where projectiles are traveling, and see changes in health and fuel. Engine flames, explosions, lighting, and particles should enhance this information rather than obscure it.

