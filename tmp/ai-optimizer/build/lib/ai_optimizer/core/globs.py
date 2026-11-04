"""Glob and .gitignore pattern matching without external dependencies."""

from __future__ import annotations

import re
from dataclasses import dataclass
from functools import lru_cache


def _translate(pattern: str) -> str:
    """Translate a glob into regex source. ``*`` stops at ``/``; ``**`` crosses it."""
    out: list[str] = []
    i, n = 0, len(pattern)
    while i < n:
        char = pattern[i]
        if char == "*":
            if pattern[i : i + 2] == "**":
                i += 2
                if pattern[i : i + 1] == "/":
                    i += 1
                    out.append("(?:.*/)?")
                else:
                    out.append(".*")
                continue
            out.append("[^/]*")
        elif char == "?":
            out.append("[^/]")
        elif char == "[":
            end = pattern.find("]", i + 2)
            if end == -1:
                out.append(re.escape(char))
            else:
                body = pattern[i + 1 : end]
                if body.startswith("!"):
                    body = "^" + body[1:]
                out.append("[" + body.replace("\\", "\\\\") + "]")
                i = end
        else:
            out.append(re.escape(char))
        i += 1
    return "".join(out)


@lru_cache(maxsize=512)
def glob_regex(pattern: str) -> re.Pattern[str]:
    """Compile a full-path glob (``**/*.py`` matches ``a.py`` and ``x/y/a.py``)."""
    return re.compile("^" + _translate(pattern) + "$")


def match_glob(pattern: str, path: str) -> bool:
    """Return True if the posix-style relative ``path`` matches the glob."""
    return glob_regex(pattern).match(path) is not None


def match_any(patterns: list[str], path: str) -> bool:
    return any(match_glob(pattern, path) for pattern in patterns)


@dataclass(frozen=True, slots=True)
class IgnoreRule:
    regex: re.Pattern[str]
    negated: bool
    dir_only: bool


def parse_ignore_lines(lines: list[str], base: str = "") -> list[IgnoreRule]:
    """Parse .gitignore-style lines into rules relative to ``base`` (posix, no slash)."""
    rules: list[IgnoreRule] = []
    prefix = re.escape(base + "/") if base else ""
    for raw in lines:
        line = raw.rstrip("\n").rstrip("\r")
        if not line.strip() or line.lstrip().startswith("#"):
            continue
        line = line.rstrip()
        negated = line.startswith("!")
        if negated:
            line = line[1:]
        dir_only = line.endswith("/")
        line = line.rstrip("/")
        if not line:
            continue
        anchored = "/" in line
        line = line.lstrip("/")
        body = _translate(line)
        source = f"^{prefix}{body}$" if anchored else f"^{prefix}(?:.*/)?{body}$"
        rules.append(IgnoreRule(re.compile(source), negated, dir_only))
    return rules


def is_ignored(rules: list[IgnoreRule], path: str, is_dir: bool) -> bool:
    """Evaluate rules in order; the last matching rule wins."""
    ignored = False
    for rule in rules:
        if rule.dir_only and not is_dir:
            continue
        if rule.regex.match(path):
            ignored = not rule.negated
    return ignored
