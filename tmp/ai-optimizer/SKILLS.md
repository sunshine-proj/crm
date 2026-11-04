# AI Optimizer — Agent Skills Specification

**Version:** 1.0

## 1. Purpose

This document defines reusable AI agent skills for interacting with AI Optimizer.

Skills provide standardized workflows that can be used by different AI coding assistants.

The implementation should avoid duplicating business logic between agents. Skills should invoke shared AI Optimizer CLI commands or MCP tools whenever possible.

## 2. Skill Design Principles

Every skill should:

- Have one clearly defined responsibility.
- Use existing AI Optimizer capabilities.
- Avoid unnecessary project exploration.
- Load only relevant context.
- Prefer deterministic local operations.
- Produce predictable outputs.
- Handle errors explicitly.
- Avoid unnecessary AI interactions.

Skills should support progressive disclosure: agents should load detailed instructions only when the corresponding skill is needed.

## 3. Initial Skills

### Skill 1: `project-analysis`

**Purpose:** Analyze a project and generate or update its metadata.

**Example invocation:**

```text
/project-analysis
```

**Workflow:**

1. Identify the project root.
2. Check existing AI Optimizer configuration.
3. Run project analysis.
4. Update metadata cache.
5. Review generated project information.
6. Report detected frameworks, dependencies, and structure.

**CLI:**

```sh
aiopt analyze
```

**Output:**

- Project metadata
- Project map
- Dependency information
- Analysis report

### Skill 2: `project-context`

**Purpose:** Retrieve relevant project knowledge for a development task.

**Example:**

```text
/project-context "Implement password reset"
```

**Workflow:**

1. Understand the requested task.
2. Identify the relevant project area.
3. Search indexed project information.
4. Retrieve relevant documentation.
5. Identify coding conventions.
6. Prepare a compact context package.
7. Present the relevant files and information.

**CLI:**

```sh
aiopt context search "password reset"
aiopt context build --task "Implement password reset"
```

**Output:**

- Relevant files
- Architecture information
- Dependencies
- Coding conventions
- Suggested validation commands

### Skill 3: `validate-project`

**Purpose:** Run project validation using deterministic local operations.

**Example invocation:**

```text
/validate-project
```

**Workflow:**

1. Inspect available validation tasks.
2. Identify applicable validation commands.
3. Execute the configured validation workflow.
4. Collect errors and warnings.
5. Generate a validation report.
6. Summarize results.

**CLI:**

```sh
aiopt task run validate
```

**Output:**

- Lint results
- Type-checking results
- Test results
- Build results, when configured
- Validation report

The agent should not independently repeat successful validation commands unless necessary.

### Skill 4: `fix-bug`

**Purpose:** Provide a standardized workflow for investigating and fixing software defects.

**Example:**

```text
/fix-bug "Login fails after token refresh"
```

**Workflow:**

1. Analyze the reported problem.
2. Retrieve relevant project context.
3. Identify affected modules.
4. Inspect the relevant source code.
5. Determine the probable root cause.
6. Implement the smallest appropriate correction.
7. Add or update tests.
8. Run relevant validation tasks.
9. Report changes and remaining issues.

The skill should use AI reasoning for diagnosis and implementation, while delegating deterministic operations to AI Optimizer.

### Skill 5: `code-review`

**Purpose:** Perform focused code reviews.

**Example invocation:**

```text
/code-review
```

**Workflow:**

1. Identify changed files.
2. Retrieve related architecture information.
3. Inspect relevant tests.
4. Review changes for correctness.
5. Identify potential security issues.
6. Identify performance problems.
7. Check consistency with project conventions.
8. Produce findings prioritized by severity.

**Review categories:**

- Correctness
- Security
- Performance
- Maintainability
- Test coverage
- Architecture consistency

**Output:**

A structured review report containing findings, affected files, severity, and recommended corrections.

### Skill 6: `refactor`

**Purpose:** Perform controlled refactoring using existing project conventions.

**Example:**

```text
/refactor "Refactor authentication services"
```

**Workflow:**

1. Analyze the requested scope.
2. Retrieve relevant architecture context.
3. Identify dependencies and affected modules.
4. Establish existing behavior through tests.
5. Propose a refactoring approach.
6. Implement changes incrementally.
7. Preserve existing functionality.
8. Run relevant tests and validation.
9. Summarize changes.

Large or potentially breaking refactoring operations should be approved before execution.

### Skill 7: `dependency-analysis`

**Purpose:** Analyze project dependencies without unnecessary AI exploration.

**Example invocation:**

```text
/dependency-analysis
```

**Workflow:**

1. Identify the package manager.
2. Inspect dependency manifests.
3. Collect installed dependency information where available.
4. Detect duplicate or potentially unused dependencies.
5. Identify version inconsistencies.
6. Generate a dependency report.

The skill must distinguish confirmed findings from potential issues. It must not automatically remove dependencies.

### Skill 8: `context-refresh`

**Purpose:** Update cached project knowledge when relevant project changes occur.

**Example invocation:**

```text
/context-refresh
```

**Workflow:**

1. Check cache status.
2. Identify changed files.
3. Determine affected metadata.
4. Update file indexes incrementally.
5. Invalidate outdated cache entries.
6. Regenerate affected deterministic metadata.
7. Report the update.

AI-generated architecture summaries should not be regenerated unnecessarily.

## 4. Future Skills

The following skills may be introduced after the MVP.

| Skill | Purpose |
| --- | --- |
| `api-analysis` | Analyze API routes, endpoints, and schemas |
| `database-analysis` | Analyze database schemas and migrations |
| `security-analysis` | Identify security-related code issues |
| `test-generation` | Generate tests for selected modules |
| `documentation` | Generate or update technical documentation |
| `performance-analysis` | Investigate performance bottlenecks |
| `context-optimization` | Improve context selection and retrieval |
| `token-analysis` | Analyze AI context and usage statistics |

## 5. Skill Implementation Structure

Recommended shared skill directory:

```text
skills/
├── project-analysis/
│   └── SKILL.md
├── project-context/
│   └── SKILL.md
├── validate-project/
│   └── SKILL.md
├── fix-bug/
│   └── SKILL.md
├── code-review/
│   └── SKILL.md
├── refactor/
│   └── SKILL.md
├── dependency-analysis/
│   └── SKILL.md
└── context-refresh/
    └── SKILL.md
```

Provider-specific integrations should reference these shared skill definitions where compatible.

If a provider requires a different skill format, use thin adapter files rather than duplicating the actual workflow logic.

## 6. Skill Execution Strategy

Skills should follow this priority:

1. Use existing cached project information.
2. Retrieve only relevant context.
3. Delegate deterministic operations to local tools.
4. Use AI reasoning only where necessary.
5. Validate the resulting changes.
6. Return a concise structured report.

## 7. Standard Skill Output

Where applicable, skills should produce a consistent result:

- **Task:** Description of the requested operation.
- **Context:** Relevant project information used.
- **Actions:** Operations performed.
- **Results:** Execution and validation results.
- **Files:** Files inspected or modified.
- **Issues:** Remaining problems or warnings.
- **Next steps:** Recommended follow-up actions.

## 8. Security Requirements

Skills must:

- Respect project-level permissions.
- Never bypass AI Optimizer execution restrictions.
- Never execute arbitrary untrusted commands.
- Avoid exposing secrets.
- Request approval for potentially destructive operations.
- Avoid modifying files outside the intended project scope.

## 9. Future Extensibility

The skill system should allow new skills to be added without modifying the core framework.

Skills should be discoverable and reusable across supported AI coding assistants.

The long-term goal is a shared workflow ecosystem where different AI agents use the same project intelligence and deterministic execution infrastructure.
