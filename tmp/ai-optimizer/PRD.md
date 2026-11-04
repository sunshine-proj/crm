# AI Optimizer — Product Requirements Document

**Version:** 0.2

**Status:** Draft — revised specification. The MVP (Phases 1–3) is implemented in package version 0.1.0.

**Document roles:** This document is authoritative for scope, requirements, architecture and phase acceptance criteria. `README.md` tracks implementation progress as a checklist. `AGENTS.md` defines development rules. `SKILLS.md` defines agent skills.

## 1. Product Vision

AI Optimizer is a provider-independent, local-first development framework designed to improve the efficiency of AI coding assistants.

It provides shared project knowledge, targeted context retrieval, deterministic task execution, reusable workflows, and usage analytics.

The objective is to minimize unnecessary AI operations while improving development productivity.

AI Optimizer does not replace AI coding assistants. It provides the infrastructure that makes them more efficient.

## 2. Problem Statement

Modern AI coding assistants repeatedly perform activities that could be handled more efficiently by local development tools.

Examples include:

- Rediscovering project architecture
- Repeatedly inspecting dependency information
- Searching for relevant source files
- Executing routine validation commands
- Repeating the same development workflows
- Loading unnecessary project context

These activities consume time and potentially increase AI context usage and costs.

Different AI providers also have different integration mechanisms, resulting in duplicated configurations and workflows.

AI Optimizer addresses these problems through a shared, provider-independent infrastructure.

### 2.1 Core Assumption and Risk

The product's value depends on an assumption that has not yet been validated: coding assistants will use AI Optimizer output (context packages, cached metadata, task reports) instead of, or in addition to, their built-in search and file-reading tools, and doing so measurably reduces exploration effort.

Assistants already ship capable built-in search. Comparable approaches also exist: repository-packing tools, repo-map techniques, and code-search MCP servers. AI Optimizer's intended differentiators are provider independence, a persistent incremental cache, secure deterministic task execution, and honest measurement. Phase 0 (section 12) exists to test this assumption before the larger investment.

## 3. Product Objectives

Primary objectives:

- Reduce unnecessary AI context consumption.
- Eliminate AI usage for deterministic operations.
- Maintain reusable project knowledge.
- Provide targeted context retrieval.
- Standardize workflows across coding assistants.
- Automate repetitive development operations.
- Measure optimization effectiveness.
- Support multiple AI coding assistants.

### 3.1 Non-Goals

- Acting as an autonomous coding agent.
- Making LLM calls or modifying project source code automatically (MVP).
- Replacing assistant-native tools or permission systems.
- Cloud synchronization or hosted services.
- Guaranteeing token or billing savings; only measured, labeled values are reported.

## 4. Target Users

Primary users:

- Software developers using AI coding assistants
- Development teams working with multiple AI providers
- Developers maintaining large repositories
- Teams interested in reducing AI usage costs
- Developers building standardized AI-assisted workflows

## 5. Product Architecture

AI Optimizer consists of seven logical layers. Each maps to one Python module under `src/ai_optimizer/`.

| Layer | Module | Responsibility |
| --- | --- | --- |
| 5.1 Core Engine | `core` | Configuration, project discovery, logging, errors, shared models |
| 5.2 Project Intelligence | `intelligence` | Project analysis and metadata generation |
| 5.3 Cache | `cache` | File index, hashing, invalidation, incremental updates |
| 5.4 Context Management | `context` | Retrieval, ranking, profiles, context packages |
| 5.5 Task Execution | `tasks` | Task registry, validation, secure execution, reports |
| 5.6 Agent Integration | `integrations` | Provider adapters, skills installation, MCP server |
| 5.7 Metrics | `metrics` | Size and token estimation, statistics, reports |

**Dependency direction:** `integrations` and the CLI depend on the service layer of the other modules; the other modules never import from `integrations`. Core capabilities are exposed as plain Python service functions so that the CLI, MCP server and any future interface call the same code. Provider-specific code lives only in `integrations`.

### 5.1 Core Engine

- Application configuration
- Project root discovery
- Shared services and data models
- Logging and error handling

### 5.2 Project Intelligence

- Project structure analysis
- Programming language detection
- Framework detection
- Dependency analysis
- Development command detection (read from manifests; never executed during analysis)
- Architecture metadata generation

API structure discovery and database schema discovery are post-MVP (see section 12, Phase 5 and `SKILLS.md` future skills).

### 5.3 Cache

- File indexing and metadata storage
- Change detection and cache invalidation
- Incremental updates and recovery
- Cache status

### 5.4 Context Management

- Keyword search and relevance ranking
- Context profiles
- Context package generation

### 5.5 Task Execution

- Task registry and YAML task definitions
- Command validation and trust enforcement
- Local execution and execution reports
- Step dependencies

### 5.6 Agent Integration

- Claude Code integration (first target)
- GitHub Copilot and Codex integrations (after the first target is stable)
- MCP server
- Agent skills and instruction files installation

The integration layer must not contain business logic and must not be imported by the core.

### 5.7 Metrics

- Context size measurement
- Estimated token consumption
- Local operation statistics
- Cache effectiveness
- Optimization reports
- Provider-reported usage, where supplied (post-MVP)

## 6. Functional Requirements

### FR-01: Project Discovery

The system must identify project roots and analyze repository structure.

It should detect:

- Programming languages
- Frameworks
- Package managers
- Dependencies
- Important configuration files
- Common project directories
- Available development commands (from manifests such as `pyproject.toml`, `package.json` scripts and `Makefile` targets)

MVP ecosystem support: Python and JavaScript/TypeScript. Other languages receive generic file, language and directory detection only.

Analysis must be deterministic: running it twice on an unchanged project produces identical output. Analysis must not modify the target project outside `.ai-optimizer/`.

Output (written to `.ai-optimizer/cache/`):

- `project-map.json`
- `dependencies.json`
- `project-info.json`

Each file contains a `schema_version` field.

### FR-02: Project Cache

The system must maintain a persistent local cache.

Technology:

- SQLite for indexing and metadata (WAL mode; schema versioned with migrations)
- JSON for generated structured metadata
- Markdown for human-readable documentation

The cache must support:

- Incremental updates: compare file size and modification time first; compute SHA-256 only for files whose metadata changed
- Detection of added, changed, removed and renamed files
- Cache invalidation
- Cache status inspection
- Recovery from interrupted indexing (updates are transactional)
- Recovery from corruption: a corrupt or incompatible cache is reported and rebuilt, and never blocks other commands

Indexing rules:

- Honor `.gitignore` and configured exclusions.
- Skip sensitive files by default (see section 7, Security).
- Skip binary files and files larger than a configurable limit (default 1 MiB).
- Do not follow symlinks that resolve outside the project root.

The cache directory is not meant to be committed (see section 9).

### FR-03: Context Retrieval

Users and AI agents must be able to search project information.

Example:

```sh
aiopt context search "authentication and permissions"
```

Search uses SQLite FTS5 with BM25 ranking over indexed file content, paths, and extracted symbols and headings where available. No additional dependency is required.

The system should return, bounded by `--limit` (default 10) and a total output size limit:

- Relevant source files with path, score and short matched excerpts
- Related documentation
- Relevant project conventions
- Related dependencies
- Relevant cached project metadata

Retrieval must prioritize relevance and avoid unnecessarily large results. If the cache is missing or stale, the command reports this and either refreshes incrementally or warns, according to the `cache.auto_refresh` setting.

### FR-04: Context Profiles

Users must be able to define reusable context profiles.

Example:

```sh
aiopt context build --profile backend
```

A profile is a named scope over the project, defined in YAML:

- Include and exclude path globs
- Priority keywords
- File-kind weights (for example source, tests, docs)
- Size budget
- Convention files to always include

Initial profiles:

- Domain profiles: `frontend`, `backend`, `database`, `testing`, `security`
- Change-oriented profiles: `refactoring` and `code-review`, which scope to files changed since the last cache update (or since a configured git ref, when git is available) plus their related tests and documentation

Profiles must be configurable through YAML, validated on load, and rejected with a clear error if malformed or containing unknown settings.

### FR-05: Context Package Generation

The system must generate compact context packages for AI agents.

Examples:

```sh
aiopt context build --task "Implement password reset"
aiopt context build --profile backend --task "Implement password reset" --max-tokens 4000
```

`--profile` and `--task` may be combined; at least one is required. Task text is converted into search terms deterministically (keyword extraction, no LLM). Package quality is therefore bounded by keyword matching, and the documentation must not suggest otherwise.

Generated output should contain:

- Task description
- Relevant project metadata
- Relevant files
- Coding conventions (taken from project instruction files such as `AGENTS.md`, `CLAUDE.md`, `CONTRIBUTING.md` and configured convention files)
- Relevant dependencies
- Available validation commands

Packages must respect the size budget (`--max-tokens`, an estimate, or the profile budget) and report what was omitted because of it. Packages must be exportable as Markdown or JSON (`--format`).

### FR-06: Task Registry

Users must be able to define reusable development tasks.

Example:

```sh
aiopt task list
aiopt task show validate
```

Tasks should support:

- Named steps
- Dependencies between steps (`depends_on`); the MVP resolves them to a topological order and runs steps sequentially, rejecting cycles at validation time
- Command execution as argument arrays
- Timeouts
- Execution reports
- Configurable failure behavior (`stop` or `continue`)
- A `destructive` flag

### FR-07: Safe Task Execution

Example:

```sh
aiopt task run validate
```

Task execution must:

- Validate configuration.
- Refuse to run in a project that has not been trusted (see section 7, Security).
- Enforce command allowlists.
- Avoid shell execution.
- Apply execution timeouts and terminate the whole process tree on timeout.
- Run in a controlled working directory inside the project root, with a scrubbed environment.
- Capture output and exit codes, with secret redaction.
- Generate structured reports.

Potentially destructive operations require explicit authorization: tasks flagged `destructive`, or commands matching built-in destructive patterns, run only when the user passes `--allow-destructive` on the CLI. They are never runnable through MCP.

### FR-08: Agent Integration

The system must support different AI coding environments.

Integrations, in order:

1. Claude Code (first target)
2. GitHub Copilot
3. OpenAI Codex

Integration methods may include:

- CLI commands
- Agent skills
- Agent instruction files
- Claude Code hooks
- MCP tools

Integrations are installed with `aiopt integrations install <provider>`. They contain thin adapters only and no business logic. No provider-specific code may exist in the core modules, and adding a provider must not require changes to them.

### FR-09: MCP Server

The framework should expose selected capabilities through MCP.

Initial tools:

- `get_project_info`
- `analyze_project`
- `search_context`
- `get_context_profile`
- `build_context`
- `get_cache_status`
- `refresh_cache`
- `list_tasks`
- `run_task`

The MCP server calls the same service layer as the CLI. `run_task` must apply the same trust, allowlist and timeout rules as CLI execution and must refuse destructive tasks. The MCP SDK is an optional dependency.

### FR-10: Metrics

The system must record:

- Number of indexed files
- Cache update operations (including files rehashed versus skipped)
- Context retrieval operations
- Context package sizes
- Estimated input tokens
- Task execution statistics

Minimal recording starts in Phase 2, when the first measurable operations appear.

Token estimates use a documented provider-neutral heuristic (characters divided by four, rounded up). The estimation method is recorded in every report. Provider tokenizers are not part of the core.

Provider-reported token usage is post-MVP. It may only be imported from data a provider integration supplies (for example, session logs or hook payloads). Reports must always distinguish estimated values from provider-reported values and must never present estimates as billing savings.

### FR-11: Project Initialization and Trust

```sh
aiopt init
aiopt trust
```

`aiopt init` creates `.ai-optimizer/` with a default `config.yaml`, example task and profile files, and a `.gitignore` for generated directories.

`aiopt trust` records that the user has reviewed this project's task and permission configuration (see section 7, Security).

## 7. Non-Functional Requirements

### Performance

Targets are provisional and must be confirmed against the Phase 0 reference repositories. Reference size: 10,000 files, 500 MB.

- Initial index: under 60 seconds.
- Incremental update with no changes: under 2 seconds, with zero files rehashed.
- Search: under 500 ms.
- Context package build: under 2 seconds.
- Local operations must not require network connectivity.

### Reliability

- Cache corruption must not prevent project access.
- Failed task execution must produce understandable reports.
- Interrupted indexing must be recoverable.
- Concurrent CLI and MCP access must not corrupt the cache (SQLite WAL and busy timeouts).

### Security

Threat model: project files, including task and profile YAML, may come from an untrusted repository, and an AI agent calling the CLI or MCP may be manipulated by content it has read (prompt injection). Task execution is therefore the primary attack surface.

**Trust model**

- Task definitions in a repository are untrusted until the user runs `aiopt trust`.
- Trust records are stored outside the repository in the user's state directory, keyed by project root path and the SHA-256 of the configuration, task and profile files. Any change to those files requires re-trust.
- The effective command allowlist is the intersection of a user-level allowlist (outside the repository) and the commands a project's tasks request. A project can never widen the user-level allowlist.
- Allowlist entries match the executable and, where needed, argument patterns. Shells and general-purpose interpreters or runners (for example `sh`, `bash`, `cmd`, `powershell`, `python -c`, `node -e`, `npx`) are denied by default.
- Commands are resolved to absolute paths at validation time.

**Execution**

- Argument arrays only; never `shell=True`.
- Working directory must resolve inside the project root.
- Child processes receive a minimal environment (an allowlist of variables); secrets are not inherited unless explicitly passed through in the user-level configuration.
- Captured output is redacted for known secret patterns. Redaction is best effort and is not a guarantee.

**Boundaries and their limits**

- AI Optimizer enforces filesystem boundaries for its own file access: resolved paths, symlink handling and root containment.
- It cannot sandbox arbitrary child processes. Allowed tools run with the user's privileges. Stronger isolation (containers or OS sandboxing) is out of MVP scope and should be documented as residual risk.

**Data protection**

- Respect `.gitignore` and configurable exclusion rules. `.gitignore` is a convenience and not a security control.
- Skip sensitive files by default: `.env*`, `*.pem`, `*.key`, `*.p12`, `*.pfx`, `id_rsa*`, `id_ed25519*`, `.npmrc`, `.pypirc`, `.netrc`, `credentials*`, `secrets.*`, `*.kdbx`, and the `.ssh/` and `.aws/` directories.
- Never write secrets into reports, context packages or logs.
- Load YAML with a safe loader only.
- Skills and agent instructions are advisory. Enforcement is provided by the task executor and the provider's own permission system (see `SKILLS.md`).

### Extensibility

New integrations, analyzers, context profiles, and task types should be addable without modifying unrelated modules.

### Compatibility

Linux and macOS are supported. Windows is best effort until covered by CI. Python 3.12 or later.

## 8. CLI Specification

Global options: `--project-dir PATH`, `--json` (machine-readable output), `-v/--verbose`, `-q/--quiet`, `--version`.

Exit codes: `0` success, `1` unexpected error, `2` usage or configuration error, `3` task or validation failure, `4` trust or permission denied.

Initial commands:

```sh
aiopt init
aiopt trust [--revoke]
aiopt config validate

aiopt analyze
aiopt project info

aiopt cache update
aiopt cache status
aiopt cache clear

aiopt context search "authentication" [--limit N]
aiopt context build [--profile NAME] [--task "Fix login"] [--max-tokens N] [--format md|json] [--output PATH]

aiopt profile list
aiopt profile show NAME

aiopt task list
aiopt task show NAME
aiopt task run NAME [--allow-destructive]

aiopt metrics
```

Phase 4 additions:

```sh
aiopt integrations install <provider>
aiopt mcp serve
```

Project validation and testing run as tasks (`aiopt task run validate`, `aiopt task run test`). The command `aiopt config validate` validates AI Optimizer configuration only.

## 9. Project Configuration

Each managed project has its own configuration.

```text
.ai-optimizer/
├── config.yaml      # committed
├── tasks/           # committed
├── profiles/        # committed
├── cache/           # generated, gitignored
└── reports/         # generated, gitignored
```

Configuration should define:

- Project identification and `schema_version`
- Indexing exclusions and size limits
- Context settings (including `cache.auto_refresh`)
- Cache settings
- Task permissions requested by the project
- Context profiles
- Integration settings

User-level configuration (outside the repository) holds the command allowlist, environment pass-through and trust records.

## 10. Technology Stack

| Component | Technology |
| --- | --- |
| Language | Python 3.12+ |
| CLI | Typer |
| Configuration | YAML (PyYAML, `safe_load` only) |
| Data validation | Pydantic |
| Database | SQLite (FTS5) |
| File hashing | SHA-256 |
| Testing | Pytest |
| Linting and typing (development) | Ruff, mypy |
| MCP (optional extra) | Python MCP SDK |
| Optional semantic search | ChromaDB |
| File watching (post-MVP, optional) | Watchdog |

Watchdog is not part of the MVP. It is introduced only if a watch mode requirement is added.

## 11. MVP Scope

The first release covers Phases 1–3 and delivers a usable standalone CLI.

### MVP Features

- Python CLI
- Project discovery and metadata generation
- SQLite-based file index
- Incremental cache updates
- Keyword-based context retrieval (FTS5)
- Context profiles and context packages
- YAML task registry
- Trust-gated safe local task execution
- Markdown and JSON reports
- Minimal local metrics recording

### Explicitly Excluded from MVP

- Agent integrations, skills and MCP server (Phase 4; the service layer is designed to support them from Phase 1)
- Autonomous AI agent
- Built-in LLM API calls
- Mandatory vector database
- Automatic code modifications
- Complex multi-agent orchestration
- Provider billing integrations
- Cloud synchronization
- File watching

## 12. Development Roadmap

Each phase is complete only when its acceptance criteria hold. The `README.md` checklist tracks the individual work items.

### Phase 0 — Validation and Baseline

- Define a benchmark protocol: at least five representative tasks over at least two reference repositories.
- Record baseline measurements without AI Optimizer: tool calls, files read, bytes read, wall time, and tokens where the assistant reports them.

**Acceptance:** the protocol is documented and the baseline is reproducible. Target numbers in section 7 are confirmed or revised.

### Phase 1 — Foundation

- Project initialization, CLI framework, configuration management
- `aiopt init`, `aiopt config validate`
- Project discovery and metadata generation

**Acceptance:** `aiopt analyze` on fixture projects produces schema-valid JSON that is byte-identical across repeated runs; nothing is written outside `.ai-optimizer/`; malformed configuration yields exit code 2 with a clear message; CLI tests pass.

### Phase 2 — Context Engine

- SQLite indexing, hash-based change detection, cache invalidation and recovery
- FTS5 keyword retrieval, context profiles, context package generation
- Minimal metrics recording

**Acceptance:** an update with no changes rehashes zero files; added, changed, removed and renamed files are detected; interrupted indexing and corrupt cache recover; sensitive files are never indexed (tested); search results and packages respect their limits; Markdown and JSON packages are valid.

### Phase 3 — Task Automation

- YAML task definitions, registry, trust model
- Secure executor and execution reporting
- Validation workflow

**Acceptance:** an untrusted project refuses to run tasks (exit code 4); non-allowlisted commands, shells and out-of-root working directories are rejected; timeouts terminate the process tree; reports contain exit codes and redacted output; destructive tasks require `--allow-destructive`; all tests run in temporary directories.

### Phase 4 — Agent Integrations

- Stable service-layer interfaces
- Shared skills and Claude Code adapter (instructions, skills, hooks)
- MCP server
- GitHub Copilot and Codex adapters

**Acceptance:** the core imports and runs with no provider SDKs, credentials or network; adapters contain no business logic; MCP applies the same trust and allowlist rules as the CLI; skill structure tests pass (see `SKILLS.md`).

### Phase 5 — Optimization and Analytics

- Token estimation reports and context effectiveness metrics
- Cache performance metrics
- Provider-reported usage import (optional)
- API and database schema discovery (optional)
- Optional semantic retrieval, only if measurements show keyword retrieval is insufficient

**Acceptance:** reports label every value as estimated or provider-reported and name its source; reported values are verified by tests.

## 13. Success Metrics

The project should measure, against the Phase 0 baseline:

- Reduction in files read and tool calls per benchmark task
- Reduction in context package size versus unfiltered context
- Cache hit rate and files rehashed per update
- Context retrieval relevance (share of benchmark queries whose expected files appear in the top results)
- Share of routine operations (validation, status checks) executed through local tasks
- Average execution time
- Number of reusable workflows
- Estimated versus provider-reported token usage, where available

Success is based on measured improvements rather than assumptions about token savings. Metrics are stored locally and never transmitted.

## 14. Assumptions and Open Questions

Assumptions made in this revision:

- Claude Code is the first integration target.
- The MVP is Phases 1–3; the service layer is integration-ready from Phase 1, and the MCP server ships in Phase 4.
- MVP ecosystem support is Python and JavaScript/TypeScript.
- Token estimation uses a characters-divided-by-four heuristic.
- Windows support is best effort.

Decisions made during implementation:

- The user-level allowlist ships with defaults for Python tooling only (`pytest`, `ruff`, `mypy`, and `python -m pytest|ruff|mypy`). Anything else, including `npm` and `make`, must be added by the user, because package scripts and Makefile targets run repository-defined code.
- Allowlist entries are keyed by executable name, resolved through the user's `PATH`. Path-based commands (`./x`, `/usr/bin/x`) are rejected. Inline-code flags (`-c`, `-e`) on interpreters are always rejected.
- The first index of a project is a baseline: it does not populate the "changed files" used by the `refactoring` and `code-review` profiles.
- Package relevance scores are scaled to 0–1 per query (plus a small base) so profile `kind_weights` can reorder weak matches. They are relative values, not raw BM25.
- `aiopt trust` always asks for interactive confirmation and has no bypass flag. This slows accidental or scripted trust but does not stop an agent that can pipe input; keep the agent's own permission system as the real control.
- `--output` for context packages must stay inside the project directory.

Open questions:

- How will agent behavior (tool calls, files read) be measured in Phase 0 across providers?
- How should the system detect and handle monorepos with multiple projects?

## 15. Product Direction

AI Optimizer should become a reusable local development infrastructure that can be installed across multiple repositories.

Its core should remain provider-independent, deterministic, secure, and extensible.

The long-term objective is to make AI coding assistants spend more time solving development problems and less time rediscovering information or performing routine operations.
