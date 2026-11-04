# AI Optimizer — Development Instructions

## Project Overview

AI Optimizer is a provider-independent, local-first framework designed to optimize the usage of AI coding assistants.

Its purpose is to reduce unnecessary AI interactions, minimize context consumption, automate deterministic development operations, and provide reusable project knowledge.

The framework should support multiple coding assistants, including:

- Claude Code
- GitHub Copilot
- OpenAI Codex
- Other AI coding assistants through standardized integrations

AI Optimizer is infrastructure for AI coding assistants, not another autonomous coding agent.

## Core Architectural Principles

All development must follow these principles.

### Provider Independence

The core framework must not depend on any specific AI provider. Provider-specific functionality belongs in integration modules.

The core must remain usable without API keys or external AI services.

### Local-First Architecture

Project analysis, indexing, caching, retrieval, task execution, and reporting should run locally. External services must be optional.

### Deterministic Operations

Operations that can be performed reliably using conventional code should not require AI.

Examples include:

- Running tests
- Formatting source code
- Checking dependencies
- Detecting changed files
- Building project indexes
- Running linters
- Validating configurations

### Context Efficiency

AI agents should receive relevant project information rather than unnecessary project-wide information. Prefer targeted context retrieval over loading entire repositories.

### Incremental Processing

Avoid rebuilding the entire project index when only a few files have changed. Use file metadata and SHA-256 hashes to detect changes.

### Security by Default

Never execute arbitrary commands from untrusted configuration without validation.

Use:

- Explicit command allowlists
- Argument-based subprocess execution
- Execution timeouts
- Controlled working directories
- Clear permission boundaries

Never expose secrets through generated context reports or logs.

## Technology Stack

Initial technology choices:

| Component | Technology |
| --- | --- |
| Language | Python 3.12+ |
| CLI | Typer |
| Configuration | YAML |
| Metadata database | SQLite |
| File hashing | SHA-256 |
| File monitoring | Watchdog |
| Data validation | Pydantic |
| Testing | Pytest |
| Packaging | `pyproject.toml` |
| Reports | JSON / Markdown |
| Optional semantic search | ChromaDB |

Avoid introducing unnecessary dependencies.

Semantic search and vector databases should remain optional until keyword-based retrieval demonstrates insufficient results.

## Architectural Boundaries

Organize the code into independent modules.

### Core

Contains shared application functionality:

- Configuration management
- Project discovery
- Logging
- Error handling
- Shared data models

### Cache

Responsible for:

- File indexing
- Metadata storage
- Cache invalidation
- Incremental updates
- Cache status

### Context

Responsible for:

- Project analysis
- Context retrieval
- Context profiles
- Context package generation
- Relevance ranking

### Tasks

Responsible for:

- Task definitions
- Task registry
- Task validation
- Task execution
- Execution reports

### Integrations

Responsible for:

- Claude Code
- GitHub Copilot
- Codex
- MCP interface
- Other future integrations

Provider-specific code must not leak into the core modules.

### Metrics

Responsible for:

- Context size estimation
- Token estimation
- Operation statistics
- Provider-reported usage where available
- Optimization reporting

Never present estimated token savings as actual provider billing savings.

## Coding Standards

- Use Python type hints.
- Prefer small, focused modules.
- Use clear function and class names.
- Follow PEP 8.
- Use docstrings for public APIs.
- Avoid unnecessary abstractions.
- Prefer dependency injection where appropriate.
- Handle errors explicitly.
- Avoid global mutable state.
- Keep filesystem operations testable.
- Use `pathlib` for filesystem operations.
- Use `subprocess.run` with argument arrays rather than `shell=True`.

## Testing Requirements

Every significant feature must have automated tests.

Test categories:

- Unit tests
- Integration tests
- CLI tests
- Cache invalidation tests
- Task execution tests
- Configuration validation tests
- Security-related tests

Tests must not modify the user's actual project or execute destructive commands. Use temporary directories and isolated test fixtures.

## Development Workflow

Before implementing a feature:

1. Read `PRD.md`.
2. Review the existing architecture.
3. Check whether similar functionality already exists.
4. Identify affected modules.
5. Define expected behavior.
6. Implement the smallest reasonable solution.
7. Add automated tests.
8. Update documentation.

Avoid implementing features outside the current development phase unless explicitly requested.

## AI Agent Instructions

When working on this repository:

- Prefer existing project conventions.
- Consult project documentation before making architectural decisions.
- Avoid unnecessary project-wide exploration.
- Do not duplicate functionality across integrations.
- Do not introduce provider-specific dependencies into the core.
- Do not regenerate caches unnecessarily.
- Do not introduce AI API calls into deterministic operations.
- Keep implementations modular and extensible.

If requirements are ambiguous, document assumptions before making significant architectural decisions.

## Definition of Done

A feature is complete when:

- Its requirements are implemented.
- Automated tests pass.
- Relevant error conditions are handled.
- Security implications are considered.
- Documentation is updated.
- No unnecessary dependencies are introduced.

## Long-Term Direction

AI Optimizer should evolve into a reusable local development infrastructure that provides a common optimization layer for multiple AI coding assistants.

The architecture must support future integrations without requiring changes to the core framework.
