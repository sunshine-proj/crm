# AI Optimizer

AI Optimizer is a provider-independent, local-first toolkit that helps AI coding assistants use relevant project context and delegate deterministic development work to local tools.

The CLI and Python core are the primary MVP (Phases 1–3). Provider integrations and MCP (Phase 4) expose core capabilities without moving business logic into provider-specific modules. The MVP does not make LLM calls, modify code automatically, or require a vector database.

## Installation and Quick Start

**Status:** the MVP (Phases 1–3) and the agent integrations (Phase 4: Claude Code, GitHub Copilot in VS Code, Codex, MCP server) are implemented. Phase 5 extensions and the Phase 0 benchmark are not started.

Requires Python 3.12+.

```sh
python -m venv .venv && source .venv/bin/activate
pip install -e ".[dev]"
```

Then, inside the project you want to optimize:

```sh
aiopt init                                   # create .ai-optimizer/ and detect a validate task
aiopt analyze                                # project-map.json, dependencies.json, project-info.json
aiopt cache update                           # incremental SHA-256 index (rerun is near-instant)
aiopt context search "authentication"        # ranked, bounded keyword search
aiopt context build --task "Implement password reset" --max-tokens 4000
aiopt trust                                  # review the tasks, then confirm
aiopt task run validate                      # allowlisted, timed, reported
aiopt metrics                                # local statistics (token figures are estimates)
```

Connect an assistant (Claude Code, GitHub Copilot in VS Code, or Codex):

```sh
pipx install 'ai-optimizer[mcp]'             # a stable absolute path that editors can launch
aiopt integrations install claude --dry-run  # preview, then run again without --dry-run
```

Add `--json` to any command for machine-readable output. See [docs/usage.md](docs/usage.md) for the command
reference, configuration, and the task security model.

Development:

```sh
pytest && ruff check . && mypy
```

## Document Responsibilities

| File | Purpose |
| --- | --- |
| `README.md` | Overview, structure, architecture, and implementation progress checklist |
| `PRD.md` | Authoritative product requirements, security model, architecture, roadmap, and phase acceptance criteria |
| `AGENTS.md` | Development instructions, architectural principles, coding standards, and rules for AI coding agents |
| `CLAUDE.md` | Imports `AGENTS.md` for Claude Code |
| `SKILLS.md` | Definition of reusable agent skills, their workflows, and failure handling |
| `docs/usage.md` | Command reference, configuration, and the task security model |

Scope and requirements live in `PRD.md`. This file only tracks progress against them.

## Proposed Project Structure

```text
ai-optimizer/
├── AGENTS.md
├── CLAUDE.md
├── PRD.md
├── SKILLS.md
├── README.md
├── pyproject.toml
├── src/
│   └── ai_optimizer/
│       ├── core/
│       ├── intelligence/
│       ├── cache/
│       ├── context/
│       ├── tasks/
│       ├── integrations/      # adapters, MCP server, skills/
│       └── metrics/
├── tests/
├── docs/
└── examples/
```

A managed project gets its own `.ai-optimizer/` directory (`config.yaml`, `tasks/` and `profiles/` are committed; `cache/` and `reports/` are generated and gitignored). See `PRD.md` section 9.

## Architecture

```text
             AI CODING ENVIRONMENTS
        Claude Code | Copilot | Codex
                      |
              SKILLS / INSTRUCTIONS
                      |
                CLI / MCP SERVER
                      |
              SERVICE LAYER (core)
                      |
   +----------+-------+--------+----------+
   |          |       |        |          |
 PROJECT   CONTEXT   TASKS   METRICS    CORE
INTELLIGENCE   |       |        |      (config,
   |           |       |        |       models)
   +-----+-----+       |        |
         |             |        |
       CACHE ----------+--------+
         |
   LOCAL STORAGE (SQLite / JSON / Markdown)
```

Modules depend downward. Integrations and the CLI call the service layer; the core never imports from `integrations`. See `PRD.md` section 5.

## Decisions and Assumptions

Recorded in `PRD.md` section 14. In short:

- MVP is Phases 1–3. The service layer is integration-ready from Phase 1; the MCP server ships in Phase 4.
- Claude Code is the first integration target; Copilot and Codex follow.
- Task execution is gated by an explicit trust step and a user-level allowlist.
- Retrieval uses SQLite FTS5 keyword search. Semantic search stays optional.
- Token counts are estimates (characters ÷ 4) and are always labeled as such.

## Incremental Implementation Checklist

Complete these steps in order. Keep each step independently testable and avoid starting a later phase until its prerequisites and the acceptance criteria in `PRD.md` section 12 are met.

### Phase 0 — Validation and Baseline

- [ ] Define the benchmark protocol (at least five tasks, two reference repositories).
- [ ] Record baseline measurements without AI Optimizer and confirm or revise the performance targets.

### Phase 1 — Foundation and Project Discovery

- [x] Initialize the Python package, `pyproject.toml`, `src/` layout, test setup, and Ruff/mypy configuration.
- [x] Add the Typer CLI entry point with global options (`--project-dir`, `--json`, `-v`, `-q`), exit codes, and consistent logging and error handling.
- [x] Define and validate project configuration with Pydantic and safe YAML loading, including defaults, schema version, and excluded paths.
- [x] Implement `aiopt init` and `aiopt config validate`.
- [x] Discover the project root and common project files without modifying the target project (outside `.ai-optimizer/`).
- [x] Generate deterministic project, dependency, and structure metadata as JSON (Python and JavaScript/TypeScript).
- [x] Expose functionality through a service layer that the CLI calls.
- [x] Add unit and CLI tests using temporary project directories; document installation and the first `aiopt analyze` workflow.

### Phase 2 — Local Cache and Context Retrieval

- [x] Create the versioned SQLite schema (WAL mode) and file index; honor `.gitignore`, exclusions, and the default sensitive-file list.
- [x] Implement size/mtime pre-checks with SHA-256 incremental updates, rename and deletion detection, cache invalidation, and recoverable (transactional) indexing.
- [x] Handle a corrupt or incompatible cache by reporting and rebuilding.
- [x] Add cache status, update, and clear commands, with tests for unchanged, changed, added, renamed, and removed files.
- [x] Implement FTS5 keyword search returning bounded, relevance-ranked results with source paths and excerpts.
- [x] Add configurable YAML context profiles and validate malformed or unknown profile settings.
- [x] Build context packages for a profile and/or task with size budgets; support Markdown and JSON output; test contents and limits.
- [x] Record minimal local metrics (indexed files, rehashed versus skipped, package sizes).

### Phase 3 — Safe Task Automation

- [x] Define and validate YAML task definitions: steps, `depends_on` ordering (cycle rejection), timeouts, failure behavior, and the `destructive` flag.
- [x] Implement `aiopt trust` with out-of-repository trust records bound to configuration hashes.
- [x] Implement the user-level command allowlist; deny shells and general-purpose interpreters by default.
- [x] Execute argument arrays only, in a controlled working directory, with a scrubbed environment and process-tree timeouts.
- [x] Require `--allow-destructive` for destructive operations; test rejected commands and timeout behavior.
- [x] Capture exit codes and redacted output in structured execution reports; add a reusable validation task.
- [x] Document task permissions and residual risk (child processes are not sandboxed); test working-directory and path containment for aiopt's own file access.

### Phase 4 — Agent Integrations

- [x] Stabilize core service interfaces so integrations reuse core behavior rather than duplicate it.
- [x] Add the four shared MVP skills (`aiopt-analyze`, `aiopt-context`, `aiopt-validate`, `aiopt-refresh`) with structural and reference tests.
- [x] Add the Claude Code adapter (instructions, skills, hooks) and `aiopt integrations install claude`.
- [x] Expose selected core operations through an optional MCP server (`aiopt mcp serve`), applying the same trust and allowlist rules as the CLI and refusing destructive tasks.
- [x] Add GitHub Copilot and Codex adapters once the first adapter is stable.
- [x] Test integrations as adapters and verify the core remains usable without provider SDKs, credentials, or network access.

### Phase 5 — Metrics and Optional Extensions

- [ ] Expand local indexing, retrieval, package-size, and task-execution metrics; add `aiopt metrics` reports.
- [ ] Label token counts as estimates (with method) and distinguish them from provider-reported usage.
- [ ] Add optimization reports and tests that validate reported values and their source.
- [ ] Consider provider usage import, API/database schema discovery, additional skills, and semantic retrieval only when measurements show a need; keep them optional.
