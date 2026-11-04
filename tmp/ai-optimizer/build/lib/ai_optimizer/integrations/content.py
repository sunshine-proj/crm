"""Provider-neutral content shared by every adapter (written once, reused everywhere)."""

from __future__ import annotations

from importlib import resources

from ai_optimizer.integrations.engine import OWNED_MARKER

SKILL_NAMES = ("aiopt-analyze", "aiopt-context", "aiopt-validate", "aiopt-refresh")
SERVER_NAME = "aiopt"

INSTRUCTIONS = """\
## AI Optimizer

This project uses AI Optimizer (`aiopt`) for local context retrieval and deterministic tasks.
Prefer it over broad file exploration:

- Before exploring an unfamiliar area, run `aiopt context build --task "<task>" --max-tokens 4000`
  (or `aiopt context search "<keywords>"`), then read only the files it lists.
- Run validation with `aiopt task run validate` instead of assembling lint or test commands
  yourself. Do not repeat a passing run unless files changed.
- After large changes (branch switch, big refactor), run `aiopt cache update`.
- Add `--json` for machine-readable output. If the `aiopt` MCP server is available, prefer
  its tools.
- Never run `aiopt trust` or pass `--allow-destructive` yourself. If a command reports that
  the project is not trusted (exit code 4), ask the user to review `.ai-optimizer/` and run
  `aiopt trust`.
- Token counts reported by `aiopt` are estimates, not provider usage."""


def load_skill(name: str) -> str:
    """Return a shared skill with the ownership marker inserted after its frontmatter."""
    if name not in SKILL_NAMES:
        raise ValueError(f"Unknown skill: {name}")
    text = (resources.files("ai_optimizer.integrations") / "skills" / name / "SKILL.md").read_text(
        encoding="utf-8"
    )
    _, frontmatter, body = text.split("---\n", 2)
    return f"---\n{frontmatter}---\n\n{OWNED_MARKER}\n{body}"
