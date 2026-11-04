# AI Optimizer

## Document Responsibilities

The three documents will have distinct responsibilities:

| File | Purpose |
| --- | --- |
| `AGENTS.md` | Development instructions, architectural principles, coding standards, and rules for AI coding agents |
| `PRD.md` | Product requirements, vision, objectives, functional requirements, architecture, and implementation roadmap |
| `SKILLS.md` | Definition of reusable agent skills, their responsibilities, expected inputs and outputs, and execution workflows |

## Proposed Project Structure

```text
ai-optimizer/
├── AGENTS.md
├── PRD.md
├── SKILLS.md
├── README.md
├── pyproject.toml
├── src/
│   └── ai_optimizer/
├── tests/
├── docs/
└── examples/
```

## Recommended Next Step

I suggest we establish one additional architectural decision before starting implementation: the relationship between AI Optimizer and MCP.

My recommendation is:

- The CLI is the primary interface for humans and scripts.
- MCP is the primary standardized interface for AI agents.
- Skills are reusable agent workflows.
- Python Core contains all actual business logic.
- Provider integrations are thin adapters.

This gives us the following architecture:

```text
             AI CODING ENVIRONMENTS
                      |
          +-----------+-----------+
          |           |           |
        Claude     Copilot      Codex
          |           |           |
          +-----------+-----------+
                      |
              SKILLS / INSTRUCTIONS
                      |
               MCP SERVER / CLI
                      |
              AI OPTIMIZER CORE
                      |
       +--------------+--------------+
       |              |              |
   PROJECT         CONTEXT          TASK
 INTELLIGENCE      ENGINE         EXECUTOR
       |              |              |
       +--------------+--------------+
                      |
                LOCAL STORAGE
                      |
             SQLite / JSON / MD
```

One important recommendation: I would implement MCP during Phase 2 or Phase 3, not postpone it until the very end. Designing the core around clean service interfaces from the beginning will make MCP integration considerably easier.
