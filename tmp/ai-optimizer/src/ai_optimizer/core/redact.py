"""Best-effort secret redaction for reports, excerpts and logs.

Redaction is a safety net, not a guarantee; sensitive files are also never indexed.
"""

from __future__ import annotations

import re

REDACTED = "[REDACTED]"

_PRIVATE_KEY = re.compile(
    r"-----BEGIN [A-Z ]*PRIVATE KEY-----[\s\S]*?-----END [A-Z ]*PRIVATE KEY-----"
)
_TOKEN_PATTERNS = [
    re.compile(r"\bAKIA[0-9A-Z]{16}\b"),
    re.compile(r"\bgh[pousr]_[A-Za-z0-9]{20,}\b"),
    re.compile(r"\bsk-[A-Za-z0-9_-]{20,}\b"),
    re.compile(r"\bxox[abprs]-[A-Za-z0-9-]{10,}\b"),
    re.compile(r"\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{5,}\b"),
    re.compile(r"(?i)\bbearer\s+[A-Za-z0-9._~+/=-]{8,}"),
]
_ASSIGNMENT = re.compile(
    r"(?i)(\b[\w.-]*(?:password|passwd|secret(?:[_-]?key)?|token|api[_-]?key|access[_-]?key)"
    r"\b[\"']?\s*[:=]\s*)(?!\[REDACTED\])(\"[^\"\n]*\"|'[^'\n]*'|[^\s,;]+)"
)


def redact(text: str) -> str:
    """Replace likely secrets in ``text`` with a placeholder."""
    text = _PRIVATE_KEY.sub(REDACTED, text)
    for pattern in _TOKEN_PATTERNS:
        text = pattern.sub(REDACTED, text)
    return _ASSIGNMENT.sub(lambda m: m.group(1) + REDACTED, text)
