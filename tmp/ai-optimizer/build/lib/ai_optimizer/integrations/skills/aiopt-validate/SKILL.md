---
name: aiopt-validate
description: Run the project's configured validation (lint, type checks, tests) through AI Optimizer (aiopt) and summarize failures. Use after making code changes, instead of assembling lint or test commands yourself.
---

# Purpose

Run validation as a trusted, allowlisted local task with a structured report.

# Workflow

1. Run `aiopt task list` to confirm a `validate` task exists and whether the project is trusted.
2. Run `aiopt task run validate`.
3. On failure, read the failing step's output in the summary, fix the cause, and run it again.

Do not choose or build validation commands yourself, and do not repeat a passing run unless files changed since. If the AI Optimizer MCP tools are available, `list_tasks` and `run_task` do the same.

# Failure handling

- Exit code 4 or "not trusted": stop and ask the user to review `.ai-optimizer/` and run `aiopt trust` in their own terminal. Never run `aiopt trust` yourself.
- Exit code 4 with "denied" or "allow-destructive": do not retry with `--allow-destructive` unless the user explicitly asks.
- No `validate` task: suggest `aiopt init` to the user; do not create tasks that run new commands without their approval.
- Exit code 3: the task ran and failed; report the failure and do not rerun blindly.

# Output

Pass or fail, the failing step with the key error lines, and what you changed in response.
