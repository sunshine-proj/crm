# AI Optimizer — Usage Guide

## Commands

| Command | Purpose |
| --- | --- |
| `aiopt init [--force]` | Create `.ai-optimizer/` (config, tasks, profiles) and a `validate` task from detected tools |
| `aiopt config validate` | Validate config, task and profile files |
| `aiopt analyze` / `aiopt project info` | Generate and show project metadata |
| `aiopt cache update [--full]` / `status` / `clear` | Maintain the SQLite index |
| `aiopt context search "text" [--limit N]` | Ranked keyword search |
| `aiopt context build [--profile P] [--task T] [--max-tokens N] [--format md\|json] [--output PATH]` | Build a context package |
| `aiopt profile list` / `show NAME` | Inspect context profiles |
| `aiopt task list` / `show NAME` / `run NAME [--allow-destructive]` | Task registry and execution |
| `aiopt trust [--revoke]` | Review and trust (or untrust) a project's tasks |
| `aiopt metrics` | Local statistics |
| `aiopt integrations list` / `install PROVIDER` / `uninstall PROVIDER` | Manage assistant integrations (`claude`, `copilot`, `codex`) |
| `aiopt mcp serve` | Run the MCP server over stdio (started by your editor) |

Global options: `--project-dir PATH`, `--json`, `-v/--verbose`, `-q/--quiet`, `--version`.

Exit codes: `0` success, `1` unexpected error, `2` usage or configuration error, `3` task or validation failure, `4` trust or permission denied.

The project root is found by walking up from the working directory to the nearest `.ai-optimizer/`, `.git`, `pyproject.toml` or `package.json`.

## Project layout

```text
.ai-optimizer/
├── config.yaml      # committed
├── tasks/           # committed
├── profiles/        # committed
├── cache/           # generated, gitignored (index.db, project-*.json)
└── reports/         # generated, gitignored (task reports, metrics.jsonl)
```

## Configuration (`config.yaml`)

Unknown keys are rejected. All keys are optional.

```yaml
schema_version: 1
project: {name: my-project}
index:
  exclude: [".git/", "node_modules/", ".venv/"]   # gitignore-style; replaces the default list
  max_file_size_bytes: 1048576
  respect_gitignore: true
  extra_sensitive: ["tokens.*"]                    # added to the built-in sensitive-file patterns
cache:
  auto_refresh: refresh        # refresh: update when stale | warn: report only | off: never
context:
  default_limit: 10
  max_output_bytes: 65536
  default_max_tokens: 8000
  convention_files: [AGENTS.md, CLAUDE.md, CONTRIBUTING.md]
tasks:
  default_timeout_seconds: 300
  max_output_bytes: 65536
  allowed_commands: [pytest, ruff]   # optional: can only narrow the user-level allowlist
```

## What is indexed

Text files under the project root, except: `.gitignore`d paths, configured exclusions, files over the size limit, binary files, symlinks that leave the project, and sensitive files (`.env*`, `*.pem`, `*.key`, `*.p12`, `*.pfx`, `id_rsa*`, `id_ed25519*`, `.npmrc`, `.pypirc`, `.netrc`, `credentials*`, `secrets.*`, `*.kdbx`, `.ssh/`, `.aws/`). `.gitignore` is a convenience, not a security control; the sensitive patterns apply regardless.

Updates compare size and modification time first and hash (SHA-256) only files that changed. Updates are transactional, and a corrupt or incompatible database is rebuilt automatically.

## Context profiles

Built-in: `frontend`, `backend`, `database`, `testing`, `security`, `refactoring`, `code-review`. Override or add profiles in `.ai-optimizer/profiles/<name>.yaml`:

```yaml
description: Payment code
include: ["src/payments/**", "tests/payments/**"]
exclude: ["**/*.snap"]
keywords: [payment, invoice, refund]
kind_weights: {source: 1.5, test: 1.0, doc: 0.5}   # kinds: source, test, doc, config, other
max_tokens: 6000
convention_files: [docs/payments.md]
```

`refactoring` and `code-review` use `changed_only: true`: files changed by the most recent cache update that detected changes, plus related tests and docs. Set `changed_since: <git ref>` to use `git diff` instead.

Package text comes from deterministic keyword extraction, not an LLM, so its quality is bounded by keyword matching. Token figures are estimates (characters ÷ 4), never provider-reported usage.

## Tasks

`.ai-optimizer/tasks/validate.yaml`:

```yaml
name: validate
description: Lint, type check and test.
destructive: false
steps:
  - name: lint
    command: [ruff, check, .]
    timeout_seconds: 120
  - name: test
    command: [pytest, -q]
    depends_on: [lint]
    on_failure: stop          # or continue
    cwd: .                    # relative to the project root
```

Commands are argument arrays, never shell strings. Steps run sequentially in dependency order. A step whose dependency did not pass is skipped. Reports are written to `.ai-optimizer/reports/` as JSON and Markdown.

## Task security model

A repository's task files are untrusted input.

1. **Trust.** `aiopt task run` refuses to run until you run `aiopt trust`, which shows every command and asks for confirmation. Trust is recorded outside the repository (`$AIOPT_STATE_DIR`, default `~/.local/state/ai-optimizer/trust.json`) and is bound to a hash of `config.yaml`, `tasks/` and `profiles/`. Any change requires trusting again.
2. **Allowlist.** Only allowlisted executables run. Defaults cover Python tooling: `pytest`, `ruff`, `mypy` and `python -m pytest|ruff|mypy`. Add more in `~/.config/ai-optimizer/allowlist.yaml` (`$AIOPT_CONFIG_DIR`):

   ```yaml
   commands:
     - name: npm
       args_prefix: [test]       # argv[1:] must start with this
     - name: go
   env_passthrough: [PYTHONPATH]  # environment variables passed to child processes
   ```

   A project's `tasks.allowed_commands` can only restrict this list, never extend it.
3. **Denied by default**, even if a project asks: shells and command runners (`sh`, `bash`, `cmd`, `powershell`, `env`, `sudo`, `xargs`, `npx`, …), inline interpreter code (`python -c`, `node -e`), and path-based executables (`./x`, `/usr/bin/x`). Only an explicit entry in your own allowlist re-enables a denied runner.
4. **Execution.** Argument arrays only (no shell), a working directory that must resolve inside the project root, a per-step timeout that kills the whole process group, a scrubbed environment (`PATH`, `HOME`, locale and a few more; no inherited secrets), bounded output, and redaction of common secret patterns in reports.
5. **Validation first.** Every step is checked before any step runs, so a disallowed command anywhere in a task blocks the whole task.
6. **Destructive operations.** Tasks marked `destructive: true`, and commands such as `rm`, `git clean`, `git reset --hard` or `git push --force`, run only with `--allow-destructive`.

### Residual risk

- Allowed tools run with your user's privileges and are **not sandboxed**. Filesystem boundaries are enforced for AI Optimizer's own file access (indexing, outputs, working directories), not for what an allowed program does. Allowlisting a tool such as `pytest` means trusting the project's test code. Use a container or VM for untrusted repositories.
- Secret redaction is best effort.
- `aiopt trust` refuses to run without an interactive terminal and has no bypass flag, so an assistant's non-interactive shell cannot grant trust. It is not a defence against an agent that can edit files in your home directory (the trust record is `trust.json` in your state directory). Keep the assistant's own permission system on, and run `aiopt trust` yourself, for example in the VS Code integrated terminal.

## Agent integrations

`aiopt integrations install <provider>` adds the files an assistant needs. Every run is idempotent, `--dry-run` previews it, and `aiopt integrations uninstall <provider>` removes exactly what was added (files you changed are left alone). JSON files containing comments are never rewritten; the command reports the snippet to add by hand instead.

| Provider | Writes |
| --- | --- |
| `claude` | `.claude/skills/aiopt-*/SKILL.md`; an instruction block in `CLAUDE.md` (or `AGENTS.md` if `CLAUDE.md` imports it); permission rules in `.claude/settings.json`; the server in `.mcp.json`; with `--with-hooks`, a `SessionStart` status hook |
| `copilot` | `.github/copilot-instructions.md` block; `.github/skills/aiopt-*` (skipped if `.claude/skills` has them, since VS Code reads both); the server in `.vscode/mcp.json` |
| `codex` | an instruction block in `AGENTS.md`; the server in `.codex/config.toml` |

Options: `--command PATH` (executable the editor launches), `--no-mcp`, `--no-permissions` (Claude), `--with-hooks` (Claude), `--force`, `--dry-run`.

### VS Code on Linux

Editors start tools with their own environment, which often lacks a virtualenv's `bin/`. Install once where it is always on `PATH`:

```sh
pipx install 'ai-optimizer[mcp]'     # or: uv tool install 'ai-optimizer[mcp]'
aiopt integrations install claude
```

If you use a virtualenv instead, pass the absolute path: `aiopt integrations install claude --command "$(which aiopt)"`. The installer warns when the command is not on `PATH`.

After installing, in VS Code:

- **Claude Code extension:** run `/mcp` to approve the project server and `/skills` to confirm the four skills. The extension reads the same `.claude/settings.json` as the CLI. Allow rules from a committed settings file apply only after you trust the workspace; the `aiopt trust` deny rule applies immediately.
- **GitHub Copilot:** open Chat in Agent mode and check the tools list for `aiopt`. VS Code asks you to trust the MCP server on first start.
- Run `aiopt trust` yourself in the integrated terminal when the assistant reports that the project is not trusted.

### Permission rules written for Claude Code

Allowed without prompting: `aiopt context search|build`, `cache status|update`, `analyze`, `project info`, `profile list|show`, `task list|show`, `metrics`, the exact command `aiopt task run validate`, and the read-mostly MCP tools. Denied: `aiopt trust`. Everything else, including `aiopt task run <other>`, `cache clear`, `init`, `--allow-destructive` and the MCP `run_task` tool, still prompts. Review them in `.claude/settings.json`; `--no-permissions` skips them.

### MCP tools

`get_project_info`, `analyze_project`, `search_context`, `get_context_profile`, `build_context`, `get_cache_status`, `refresh_cache`, `list_tasks`, `run_task`. They call the same service functions as the CLI, so trust, allowlist and timeout rules are identical. There is no destructive option and no way to trust a project through MCP. Results are compact: passing task steps return no output and failing steps return only the last 2,000 characters; the full report is written to `.ai-optimizer/reports/`.

### Session hook

With `--with-hooks`, Claude Code runs `aiopt hook session-start` when a session starts. It prints two lines (index size, cache freshness, trust state) and never refreshes the cache. It prints nothing in projects without `.ai-optimizer/`.
