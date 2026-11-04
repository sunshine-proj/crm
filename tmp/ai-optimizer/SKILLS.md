# AI Optimizer — Agent Skills Specification

**Version:** 0.2

## 1. Purpose

This document defines reusable agent skills for interacting with AI Optimizer.

Skills provide standardized workflows that can be used by different AI coding assistants.

The implementation must avoid duplicating business logic between agents. Skills invoke shared AI Optimizer CLI commands (preferred) or MCP tools, and contain only workflow guidance.

The four MVP skills are implemented. They ship inside the package (`src/ai_optimizer/integrations/skills/`) and are installed into a project with `aiopt integrations install <provider>`. They depend only on commands defined in the PRD CLI specification.

## 2. Skill Design Principles

Every skill should:

- Have one clearly defined responsibility.
- Use existing AI Optimizer capabilities.
- Avoid unnecessary project exploration.
- Load only relevant context.
- Prefer deterministic local operations.
- Produce predictable, concise outputs.
- Handle errors explicitly.
- Avoid unnecessary AI interactions.

Skills support progressive disclosure: only the short `description` is always loaded; detailed instructions load when the skill is used. The `description` determines when an assistant selects the skill, so it must state what the skill does and when to use it.

## 3. MVP Skills

These four skills are the initial release. They wrap deterministic AI Optimizer commands.

### Skill 1: `aiopt-analyze`

**Purpose:** Analyze a project and generate or update its metadata.

**Workflow:**

1. Check that `.ai-optimizer/` exists; if not, see the failure handling in section 6.
2. Run `aiopt analyze`.
3. Run `aiopt project info` and report the detected languages, frameworks, dependencies and structure.

**CLI:** `aiopt analyze`, `aiopt project info`

**MCP:** `analyze_project`, `get_project_info`

**Output:** project metadata, project map and dependency information.

### Skill 2: `aiopt-context`

**Purpose:** Retrieve relevant project knowledge for a development task.

**Example:** `aiopt-context "Implement password reset"`

**Workflow:**

1. Derive a short search query from the requested task.
2. Run `aiopt context search` and review the bounded, ranked results.
3. If a full package is needed, run `aiopt context build --task "<task>"` (optionally with `--profile` and `--max-tokens`).
4. Read only the files the package points to.

**CLI:** `aiopt context search`, `aiopt context build`

**MCP:** `search_context`, `build_context`, `get_context_profile`

**Output:** relevant files, project metadata, dependencies, coding conventions and available validation commands.

### Skill 3: `aiopt-validate`

**Purpose:** Run project validation using deterministic local operations.

**Workflow:**

1. Run `aiopt task list` to confirm the configured `validate` task exists.
2. Run `aiopt task run validate`.
3. Summarize failures and warnings from the structured report.

The skill runs only configured tasks. The agent must not choose, assemble or run validation commands itself, and must not repeat successful validation unless files changed afterwards.

**CLI:** `aiopt task list`, `aiopt task run validate`

**MCP:** `list_tasks`, `run_task`

**Output:** lint, type-check, test and (when configured) build results from the execution report.

### Skill 4: `aiopt-refresh`

**Purpose:** Update cached project knowledge after significant project changes.

**Workflow:**

1. Run `aiopt cache status`.
2. If the cache is stale, run `aiopt cache update`.
3. Report added, changed and removed files.

Normal retrieval commands already warn about or refresh a stale cache according to `cache.auto_refresh`, so this skill is needed mainly after large changes such as branch switches.

**CLI:** `aiopt cache status`, `aiopt cache update`

**MCP:** `get_cache_status`, `refresh_cache`

**Output:** a short summary of the update.

## 4. Future Skills

These skills are outside the MVP. The first four are general coding workflows that are mostly prompt guidance, so they should be added only if Phase 0 and Phase 5 measurements show they improve results.

| Skill | Purpose | Prerequisite |
| --- | --- | --- |
| `aiopt-fix-bug` | Standard workflow to investigate and fix defects using retrieved context and `aiopt-validate` | MVP skills proven |
| `aiopt-review` | Focused review of changed files, using the `code-review` profile | MVP skills proven |
| `aiopt-refactor` | Controlled refactoring; large or breaking changes need approval; user-invoked only | MVP skills proven |
| `aiopt-dependencies` | Dependency report; separates confirmed findings from potential issues; never removes dependencies | New `aiopt deps` command and FR in the PRD |
| `api-analysis` | Analyze API routes, endpoints and schemas | API discovery in Phase 5 |
| `database-analysis` | Analyze database schemas and migrations | Schema discovery in Phase 5 |
| `security-analysis` | Identify security-related code issues | — |
| `test-generation` | Generate tests for selected modules | — |
| `documentation` | Generate or update technical documentation | — |
| `performance-analysis` | Investigate performance bottlenecks | — |
| `context-optimization` | Improve context selection and retrieval | Phase 5 metrics |
| `token-analysis` | Analyze context and usage statistics | Phase 5 metrics |

Skill names use the `aiopt-` prefix to avoid collisions with assistants' built-in skills and commands (for example `code-review`).

## 5. Skill Implementation Structure

Shared skill directory:

```text
src/ai_optimizer/integrations/skills/
├── aiopt-analyze/
│   └── SKILL.md
├── aiopt-context/
│   └── SKILL.md
├── aiopt-validate/
│   └── SKILL.md
└── aiopt-refresh/
    └── SKILL.md
```

### SKILL.md template

```markdown
---
name: aiopt-context
description: Retrieve relevant project files, conventions and validation commands for a development task using AI Optimizer. Use before exploring an unfamiliar part of the codebase.
---

# Purpose
One sentence.

# Workflow
Numbered steps that call `aiopt` commands.

# Failure handling
What to do if `aiopt` is missing, the project is uninitialized or untrusted, or the cache is stale.

# Output
The concise result to report.
```

Skills with side effects (any future skill that modifies files) should be configured as user-invoked only where the provider supports it.

### Installation and adapters

`aiopt integrations install <provider>` copies the shared skills, with an ownership marker, to where the provider looks:

| Provider | Skills location | Notes |
| --- | --- | --- |
| Claude Code (CLI and VS Code extension) | `.claude/skills/` | Also writes instructions, permission rules and MCP configuration |
| GitHub Copilot (VS Code) | `.github/skills/` | Skipped when `.claude/skills/` already holds them, because VS Code reads both locations |
| Codex | none | Receives the shared instructions in `AGENTS.md` and the MCP server instead |

Invocation syntax such as slash commands is provider-specific and is not part of the skill definition. Files without the ownership marker are never overwritten unless `--force` is given.

If a provider requires a different format, use thin adapter files that reference the shared workflow, never duplicated workflow logic.

## 6. Skill Execution Strategy

Skills follow this priority:

1. Use existing cached project information.
2. Retrieve only relevant context.
3. Delegate deterministic operations to local tools.
4. Use AI reasoning only where necessary.
5. Validate the resulting changes.
6. Return a concise report.

### Failure handling

Every skill handles these cases and does not fall back to unrestricted exploration or direct command execution:

| Condition | Behavior |
| --- | --- |
| `aiopt` not installed, or version too old | Tell the user how to install or upgrade; stop |
| `.ai-optimizer/` missing | Suggest `aiopt init`; do not create it without user approval |
| Project not trusted (exit code 4) | Ask the user to review the configuration and run `aiopt trust`; do not attempt it autonomously |
| Cache stale or corrupt | Run `aiopt cache update` (or report the rebuild); continue |
| Task failed (exit code 3) | Report the failure from the structured report; do not rerun blindly |

## 7. Standard Skill Output

Skill reports are short by default. Include only the relevant items:

- **Task:** the requested operation.
- **Results:** execution and validation results.
- **Issues:** remaining problems or warnings.
- **Next steps:** recommended follow-up actions.

Use `--json` command output when another tool or skill consumes the result. Include inspected or modified files only when that information is needed.

## 8. Security Requirements

Skills are instructions to a model. They guide behavior but do not enforce it. Enforcement comes from the AI Optimizer task executor (trust, allowlist, timeouts) and from the provider's own permission system.

Skills must instruct the agent to:

- Run project commands only through `aiopt task run`.
- Never bypass or work around AI Optimizer execution restrictions.
- Never attempt to run `aiopt trust` or `--allow-destructive` without explicit user approval.
- Avoid exposing secrets in reports or messages.
- Request approval before potentially destructive operations.
- Stay within the intended project scope.

Provider adapters should additionally configure provider permissions where supported, for example allowing `aiopt` commands while keeping other shell commands subject to the provider's approval flow.

## 9. Testing Skills

- **Structural tests (implemented in `tests/test_integrations.py`):** every skill has valid frontmatter (`name`, `description`), a name matching its directory, and a failure-handling section.
- **Reference tests:** every `aiopt` command and MCP tool named in a skill exists in the CLI or MCP tool list.
- **Adapter tests:** adapters contain no workflow logic beyond references to shared skills.
- **Behavior evals (not yet implemented):** a small set of prompts checks that an assistant selects the intended skill and calls the intended commands. Results feed the Phase 0 benchmark comparison.

## 10. Future Extensibility

The skill system should allow new skills to be added without modifying the core framework.

Skills should be discoverable and reusable across supported AI coding assistants.

The long-term goal is a shared workflow ecosystem where different AI agents use the same project intelligence and deterministic execution infrastructure.
