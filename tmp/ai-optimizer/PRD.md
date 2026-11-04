# AI Optimizer — Product Requirements Document

**Version:** 1.0

**Status:** Initial Specification

## 1. Product Vision

AI Optimizer is a provider-independent, local-first development framework designed to improve the efficiency of AI coding assistants.

It provides shared project knowledge, intelligent context retrieval, deterministic task execution, reusable workflows, and usage analytics.

The objective is to minimize unnecessary AI operations while improving development productivity.

AI Optimizer does not replace AI coding assistants. It provides the infrastructure that makes them more efficient.

## 2. Problem Statement

Modern AI coding assistants repeatedly perform activities that could be handled more efficiently by local development tools.

Examples include:

- Rediscovering project architecture
- Repeatedly inspecting dependency information
- Searching for relevant source files
- Reconstructing database schemas
- Executing routine validation commands
- Repeating the same development workflows
- Loading unnecessary project context

These activities consume time and potentially increase AI context usage and costs.

Different AI providers also have different integration mechanisms, resulting in duplicated configurations and workflows.

AI Optimizer addresses these problems through a shared, provider-independent infrastructure.

## 3. Product Objectives

Primary objectives:

- Reduce unnecessary AI context consumption.
- Eliminate AI usage for deterministic operations.
- Maintain reusable project knowledge.
- Provide intelligent context retrieval.
- Standardize workflows across coding assistants.
- Automate repetitive development operations.
- Measure optimization effectiveness.
- Support multiple AI coding assistants.

## 4. Target Users

Primary users:

- Software developers using AI coding assistants
- Development teams working with multiple AI providers
- Developers maintaining large repositories
- Teams interested in reducing AI usage costs
- Developers building standardized AI-assisted workflows

## 5. Product Architecture

AI Optimizer consists of six logical layers.

### 5.1 Core Engine

Responsibilities:

- Application configuration
- Project discovery
- Shared services
- Data models
- Logging
- Error handling

### 5.2 Project Intelligence

Responsibilities:

- Project structure analysis
- Programming language detection
- Framework detection
- Dependency analysis
- API structure discovery
- Database schema discovery
- Architecture metadata generation

### 5.3 Context Management

Responsibilities:

- File indexing
- Context retrieval
- Keyword search
- Relevance ranking
- Context profiles
- Context package generation
- Incremental cache updates

### 5.4 Task Execution

Responsibilities:

- Task registry
- YAML task definitions
- Command validation
- Local execution
- Execution reports
- Workflow composition

### 5.5 Agent Integration

Responsibilities:

- Claude Code integration
- GitHub Copilot integration
- Codex integration
- MCP server
- Agent-specific instructions
- Agent Skills support

The integration layer must remain independent of the core business logic.

### 5.6 Metrics and Optimization

Responsibilities:

- Context size measurement
- Estimated token consumption
- Local operation statistics
- Provider usage tracking
- Cache effectiveness
- Optimization reports

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
- Available development commands

Output:

- `project-map.json`
- `dependencies.json`
- `project-info.json`

### FR-02: Project Cache

The system must maintain a persistent local cache.

Technology:

- SQLite for indexing and metadata
- JSON for generated structured metadata
- Markdown for human-readable documentation

The cache must support:

- Incremental updates
- File change detection
- SHA-256 hashing
- Cache invalidation
- Cache status inspection

### FR-03: Context Retrieval

Users and AI agents must be able to search project information.

Example:

```sh
aiopt context search "authentication and permissions"
```

The system should return:

- Relevant source files
- Related documentation
- Relevant project conventions
- Related dependencies
- Relevant cached architecture information

Retrieval must prioritize relevance and avoid unnecessarily large results.

### FR-04: Context Profiles

Users must be able to define reusable context profiles.

Example:

```sh
aiopt context build --profile backend
```

Initial profiles:

- `frontend`
- `backend`
- `database`
- `testing`
- `security`
- `refactoring`
- `code-review`

Profiles must be configurable through YAML.

### FR-05: Context Package Generation

The system must generate compact context packages for AI agents.

Example:

```sh
aiopt context build --task "Implement password reset"
```

Generated output should contain:

- Task description
- Relevant project architecture
- Relevant files
- Coding conventions
- Relevant dependencies
- Available validation commands

Context packages must be exportable as Markdown or JSON.

### FR-06: Task Registry

Users must be able to define reusable development tasks.

Example:

```sh
aiopt task list
```

Tasks should support:

- Named steps
- Dependencies between steps
- Command execution
- Timeouts
- Execution reports
- Configurable failure behavior

### FR-07: Safe Task Execution

Example:

```sh
aiopt task run validate
```

Task execution must:

- Validate configuration.
- Enforce command allowlists.
- Avoid unrestricted shell execution.
- Apply execution timeouts.
- Capture output and exit codes.
- Generate structured reports.

Potentially destructive operations require explicit authorization.

### FR-08: Agent Integration

The system must support different AI coding environments.

Initial integrations:

- Claude Code
- GitHub Copilot
- OpenAI Codex

Integration methods may include:

- CLI commands
- Agent Skills
- Agent instruction files
- Claude Code hooks
- MCP tools

No integration should require modifying the core framework.

### FR-09: MCP Server

The framework should expose selected capabilities through MCP.

Initial tools:

- `get_project_info`
- `search_context`
- `get_context_profile`
- `build_context`
- `get_cache_status`
- `list_tasks`
- `run_task`

Task execution through MCP must respect the same security restrictions as CLI execution.

### FR-10: Metrics

The system must record:

- Number of indexed files
- Cache update operations
- Context retrieval operations
- Context package sizes
- Estimated input tokens
- Task execution statistics
- Provider-reported token usage when available

Reports must distinguish estimated usage from actual provider-reported usage.

## 7. Non-Functional Requirements

### Performance

- Incremental cache updates should process only changed files where possible.
- Context retrieval should be efficient for typical development repositories.
- Local operations should not require network connectivity.

### Reliability

- Cache corruption must not prevent project access.
- Failed task execution must produce understandable reports.
- Interrupted indexing must be recoverable.

### Security

- Never execute arbitrary untrusted commands.
- Never expose environment secrets in reports.
- Respect `.gitignore` and configurable exclusion rules.
- Avoid indexing sensitive files by default.
- Validate task configurations.
- Apply filesystem access boundaries.

### Extensibility

New integrations, analyzers, context profiles, and task types should be addable without modifying unrelated modules.

## 8. CLI Specification

Proposed initial commands:

```sh
aiopt analyze
aiopt project info

aiopt cache update
aiopt cache status
aiopt cache clear

aiopt context search "authentication"
aiopt context build --profile backend
aiopt context build --task "Fix login"

aiopt task list
aiopt task run validate

aiopt validate
aiopt test

aiopt metrics
```

## 9. Project Configuration

Each managed project should have its own configuration.

Example:

```text
.ai-optimizer/
├── config.yaml
├── cache/
├── tasks/
├── profiles/
└── reports/
```

Configuration should define:

- Project identification
- Indexing exclusions
- Context settings
- Cache settings
- Task permissions
- Context profiles
- Integration settings

## 10. Technology Stack

| Component | Technology |
| --- | --- |
| Language | Python 3.12+ |
| CLI | Typer |
| Configuration | YAML |
| Data validation | Pydantic |
| Database | SQLite |
| File hashing | SHA-256 |
| File watching | Watchdog |
| Testing | Pytest |
| MCP | Python MCP SDK |
| Optional semantic search | ChromaDB |

## 11. MVP Scope

The first release should focus on functionality that delivers immediate value.

### MVP Features

- Python CLI
- Project discovery
- Project metadata generation
- SQLite-based file index
- Incremental cache updates
- Keyword-based context retrieval
- Context profiles
- YAML task registry
- Safe local task execution
- Markdown and JSON reports

### Explicitly Excluded from MVP

- Autonomous AI agent
- Built-in LLM API calls
- Mandatory vector database
- Automatic code modifications
- Complex multi-agent orchestration
- Advanced provider billing integrations
- Cloud synchronization

## 12. Development Roadmap

### Phase 1 — Foundation

- Project initialization
- CLI framework
- Configuration management
- Project discovery
- Metadata generation

### Phase 2 — Context Engine

- SQLite indexing
- Hash-based change detection
- Cache invalidation
- Keyword retrieval
- Context profiles
- Context package generation

### Phase 3 — Task Automation

- YAML task definitions
- Task registry
- Secure executor
- Execution reporting
- Validation workflows

### Phase 4 — Agent Integrations

- Claude Code instructions
- Claude Code Skills
- Claude Code hooks
- GitHub Copilot instructions
- GitHub Copilot Skills
- Codex integration
- MCP server

### Phase 5 — Optimization and Analytics

- Token estimation
- Context effectiveness metrics
- Cache performance metrics
- Provider usage integration
- Optional semantic retrieval

## 13. Success Metrics

The project should measure:

- Reduction in unnecessary source-file exploration
- Reduction in context package size
- Cache hit rate
- Context retrieval relevance
- Percentage of routine operations executed locally
- Average execution time
- Number of reusable workflows
- Estimated versus actual token usage where available

Success should be based on measurable improvements rather than assumptions about token savings.

## 14. Product Direction

AI Optimizer should become a reusable local development infrastructure that can be installed across multiple repositories.

Its core should remain provider-independent, deterministic, secure, and extensible.

The long-term objective is to make AI coding assistants spend more time solving development problems and less time rediscovering information or performing routine operations.
