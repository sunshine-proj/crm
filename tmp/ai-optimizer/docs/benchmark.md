# Measuring whether AI Optimizer helps

AI Optimizer's value rests on an assumption: assistants that use its context packages and local tasks explore less and waste fewer tokens than assistants that do not. This guide shows how to test that with measurements instead of impressions. It has two parts that answer different questions.

| Question | Tool | Evidence type |
| --- | --- | --- |
| Does retrieval find the right files, and how small is the context compared with naive alternatives? | `aiopt bench run` | Measured locally on labeled cases. Token figures are estimates. |
| Do assistants that use aiopt actually spend fewer tokens and tool calls? | `aiopt usage import` + `aiopt usage report` | Provider-reported counts from real sessions. |
| Is any of this cheaper on your bill? | Neither. | Provider-reported tokens are not converted to cost. Check your provider's usage page. |

## Part A — retrieval benchmark

### Writing cases

A spec is a YAML file (commit it, for example as `.ai-optimizer/bench/<name>.yaml`):

```yaml
name: my-project
k: 5                      # depth at which recall is scored
cases:
  - id: password-reset
    query: how does the password reset email get sent     # what a developer would ask
    expect: ["src/auth/reset.py", "src/mail/templates/*.html"]   # globs; all should be found
    # optional: task: (text for the package, defaults to query)   profile: backend
```

Guidance that keeps the numbers honest:

- **Write the cases before running them**, and do not tune queries until they pass.
- Phrase queries as a person would, not by copying identifiers from the code.
- Include hard cases: files whose names do not match the question, and cases with more than one expected file.
- Use at least 15 cases from real tasks (issue titles and review questions are good sources).
- Whoever wrote the code tends to write easy queries. Ask someone else for a few.

### Running it

```sh
aiopt bench run .ai-optimizer/bench/my-project.yaml --cases
aiopt bench run SPEC --min-recall 0.8 --min-package-recall 0.9   # exit code 3 if below: usable in CI
```

What is reported:

- **recall@k**: the share of expected globs matched by the top-k search results. **All expected found**: cases where every expected glob matched. **MRR**: mean reciprocal rank of the first matching result, which rewards putting the answer first.
- **Package recall**: the same, for the generated context package. A package holds more items than k (about 15 by default), so it is shown next to **search recall at the same depth**. If those two are close, the package's advantage comes from returning more files, not from better selection.
- **Size baselines**, all estimated as characters ÷ 4: the whole repository; every indexed file containing any query term (roughly what a keyword search followed by reading hits would load); and the top-k files read in full.
- Benchmark runs do not touch your operational metrics.

What it does **not** tell you: whether an assistant solves tasks better or cheaper. It measures retrieval only.

### Example: this repository

16 cases written before any results were seen (`.ai-optimizer/bench/self.yaml`), 70 indexed files, k = 5:

| Measure | Result |
| --- | --- |
| Search recall@5 | 84% |
| All expected files found in the top 5 | 81% (13 of 16) |
| MRR | 0.61 |
| Package recall (about 15 items) | 94% |
| Search recall at the same depth (about 15 items) | 97% |
| Mean package size | about 2,000 estimated tokens |
| Package size vs whole repository / vs files containing a query term / vs top-5 files | 2% / 2% / 16% (estimates) |

What this shows, and what it does not:

- **Plain search is already decent here, and the package does not find more than search does.** At equal depth the package (94%) is slightly below search (97%). The package's value is elsewhere: it bundles conventions, relevant dependencies and validation commands in one budgeted document. Whether that saves an assistant work is a Part B question.
- **Results move with the corpus.** An earlier run on 68 files gave MRR 0.66 and package recall 100%. Adding two files (this guide and a test file) produced the numbers above. Rerun rather than quote.
- **A real weakness showed up:** documentation is capped at 5 items in a package, and skill files are markdown, so `skill-validate` lost its slot to the new guide (the plain search ranks it 13th). This has not been "fixed", because tuning against a 16-case benchmark would overfit it. Any change to retrieval should be judged on a larger set of cases.
- The other misses: an answer spanning a source file and a documentation page (`exit-codes`), and one relevant file ranked 8th (`sensitive-files`).

It is one small repository, the author of the cases also wrote the code, and keyword matching suits descriptive file names. "2% of the whole repository" is unremarkable at 70 files. Treat these numbers as a regression baseline and a worked example, not a general claim.

## Part B — assistant A/B protocol

This part measures behavior and needs real sessions. Only Claude Code is supported today. It reads the assistant's own session logs and stores counts only (tokens, tool names, whether `aiopt` was called), never message content.

### Design before you start

1. **Pick tasks.** At least 5 tasks that are representative, independent, and each with an objective acceptance check (a test that passes, a bug that no longer reproduces). Use at least 2 repositories if you can.
2. **Define the arms.**
   - `baseline`: integration removed (`aiopt integrations uninstall claude`; make sure no `aiopt` MCP server is approved).
   - `aiopt`: integration installed (`aiopt integrations install claude`).
3. **Hold everything else fixed:** model, permission mode, prompt text, starting commit (reset the working tree before each run), and a fresh conversation per run.
4. **Repeat.** At least 3 runs per task per arm, and at least 5 sessions per arm overall. Interleave the arms (A, B, B, A, …) to avoid drift from time of day or model updates.
5. **Write your decision rule first.** For example: "continue if the `aiopt` arm uses at least 30% fewer exploration calls, output tokens do not rise, success rate does not fall, and `aiopt_calls` is above 0 in most sessions." Deciding afterward invites rationalizing.

### Run and record

For each run: reset to the start commit, open a new Claude Code session, give the task prompt, and note whether the acceptance check passed. Then:

```sh
aiopt usage import claude                 # picks up new sessions for this project
aiopt usage list                          # find the new session id
aiopt usage label <id-prefix> baseline    # or: aiopt
```

(`--path` imports from another directory; `--since 2026-01-01T10:00` limits by start time. Importing again is safe: sessions are updated, and labels are kept.)

### Read the result

```sh
aiopt usage report            # groups vs the baseline group, with warnings for small samples
aiopt report --output reports/summary.md   # benchmark + local metrics + usage, each tagged with its source
```

How to read it:

- **Check adoption first.** If `aiopt_calls` is 0 in the `aiopt` arm, the assistant ignored the integration and there is nothing to compare. Improve the instructions or skills and rerun.
- Compare **exploration calls** (Read, Grep, Glob), **output tokens**, and total **tool calls**. Cache-read and cache-write tokens are reported separately and never summed with the others, because they are priced differently.
- Prefer medians with few sessions; one long session can dominate a mean.
- Report the **success rate** alongside tokens. A cheaper run that fails is not a win.
- The tool prints a warning when a group has fewer than 5 sessions. Treat differences then as anecdotes.

### Known limits

- Session logs are Claude Code's internal format and may change; unknown fields are ignored and missing counts become zero, so a format change shows up as zeros rather than a crash. Verify a fresh import against `/cost` or a manual count after upgrading Claude Code.
- Subagent (Agent tool) usage is included by merging each session's `subagents/` logs.
- Token counts include cached input. They describe activity, not the price you pay.
- Other assistants are not supported yet; the usage store is provider-neutral, so an importer for another tool is a self-contained addition.
