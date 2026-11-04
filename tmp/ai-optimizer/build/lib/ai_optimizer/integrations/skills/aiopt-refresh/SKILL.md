---
name: aiopt-refresh
description: Refresh AI Optimizer's cached project index after large changes such as switching branches, pulling updates or big refactors. Use when search results look stale or after many files changed.
---

# Purpose

Keep the local index current so retrieval reflects the code on disk.

# Workflow

1. Run `aiopt cache status` to see whether the cache is stale.
2. If it is stale, run `aiopt cache update`. Only changed files are re-read.
3. Report how many files were added, changed or removed.

Normal `aiopt context` commands already refresh a stale cache automatically (unless `cache.auto_refresh` is set to `warn` or `off`), so this skill is mainly for explicit refreshes. If the AI Optimizer MCP tools are available, `get_cache_status` and `refresh_cache` do the same.

# Failure handling

- `aiopt` not found: tell the user to install it and stop.
- "Cache is unhealthy" or corrupt: `aiopt cache update` rebuilds it; no manual deletion is needed.
- Do not run `aiopt cache clear` unless the user asks.

# Output

One line: files added, changed and removed, or "cache already up to date".
