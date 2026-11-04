---
name: aiopt-context
description: Retrieve the project files, conventions, dependencies and validation commands relevant to a development task using AI Optimizer (aiopt). Use before exploring an unfamiliar part of the codebase or before starting a feature or bug fix.
---

# Purpose

Get a compact, ranked context package instead of scanning the repository.

# Workflow

1. Turn the request into two to six keywords, or a one-sentence task.
2. Run `aiopt context search "<keywords>"` for a quick ranked list, or `aiopt context build --task "<task>" --max-tokens 4000` for a full package. Add `--profile <name>` to focus the result; `aiopt profile list` shows the choices.
3. Read only the files the result points to. Search the wider repository only if the result is empty or clearly irrelevant.
4. Apply the conventions and use the validation commands listed in the package.

If the AI Optimizer MCP tools are available, `search_context`, `build_context` and `get_context_profile` do the same.

# Failure handling

- `aiopt` not found: tell the user to install it and stop.
- Warnings about a stale cache: run `aiopt cache update` and retry.
- Empty results: broaden or change the keywords once, then fall back to normal exploration.
- Token counts in the package are estimates, not provider usage; do not present them as billing figures.

# Output

State which files and conventions you will rely on, and how you will validate the change.
