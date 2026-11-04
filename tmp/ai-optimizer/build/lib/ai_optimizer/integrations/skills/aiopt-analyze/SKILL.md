---
name: aiopt-analyze
description: Detect and summarize a project's languages, frameworks, package managers, dependencies and development commands with AI Optimizer (aiopt). Use when starting work in an unfamiliar repository or when the project setup may have changed.
---

# Purpose

Get deterministic project facts from local analysis instead of reading manifests by hand.

# Workflow

1. Run `aiopt analyze`. It writes metadata to `.ai-optimizer/cache/` and prints a summary.
2. Use `aiopt project info` later to show the same information without re-analyzing.
3. Report the detected languages, frameworks, package managers and development commands. Prefer these detected commands over guessing.

If the AI Optimizer MCP tools are available, `analyze_project` and `get_project_info` do the same.

# Failure handling

- `aiopt` not found: tell the user to install it (`pip install ai-optimizer`) and stop.
- No `.ai-optimizer/` directory: analysis still works. Suggest `aiopt init` to the user; do not run it without their approval.
- `Warning:` lines in the output mean a manifest could not be parsed; mention them.

# Output

Two to five lines: primary language, frameworks, package manager, and the validation commands you will use.
