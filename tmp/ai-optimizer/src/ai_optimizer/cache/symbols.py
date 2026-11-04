"""Lightweight symbol and heading extraction to improve retrieval."""

from __future__ import annotations

import re

MAX_SYMBOLS = 500

_PATTERNS: dict[str, list[re.Pattern[str]]] = {
    "python": [re.compile(r"^\s*(?:async\s+)?(?:def|class)\s+([A-Za-z_]\w*)", re.MULTILINE)],
    "javascript": [
        re.compile(r"(?:function\*?|class)\s+([A-Za-z_$][\w$]*)"),
        re.compile(r"(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=\s*(?:async\s*)?(?:\(|function)"),
    ],
    "go": [
        re.compile(r"^func\s+(?:\([^)]*\)\s*)?([A-Za-z_]\w*)", re.MULTILINE),
        re.compile(r"^type\s+([A-Za-z_]\w*)", re.MULTILINE),
    ],
    "markdown": [re.compile(r"^#{1,6}\s+(.+?)\s*$", re.MULTILINE)],
}
_PATTERNS["typescript"] = [
    *_PATTERNS["javascript"],
    re.compile(r"(?:interface|type|enum)\s+([A-Za-z_$][\w$]*)"),
]
_GENERIC = [re.compile(r"\b(?:class|interface|struct|enum|trait|fn|func|def)\s+([A-Za-z_]\w*)")]


def extract_symbols(language: str | None, text: str) -> str:
    """Return space-separated symbol names found in ``text``."""
    if language is None:
        return ""
    patterns = _PATTERNS.get(language)
    if patterns is None:
        if language in {"restructuredtext", "text", "asciidoc", "yaml", "toml", "json", "ini"}:
            return ""
        patterns = _GENERIC
    names: list[str] = []
    for pattern in patterns:
        names.extend(pattern.findall(text))
        if len(names) >= MAX_SYMBOLS:
            break
    return " ".join(names[:MAX_SYMBOLS])
