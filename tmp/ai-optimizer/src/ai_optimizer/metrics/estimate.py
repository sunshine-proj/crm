"""Provider-neutral size and token estimation."""

from __future__ import annotations

import math

ESTIMATION_METHOD = "characters/4 heuristic; estimate, not provider-reported"
CHARS_PER_TOKEN = 4


def estimate_tokens(text: str) -> int:
    """Estimate the token count of ``text``. This is an approximation only."""
    return math.ceil(len(text) / CHARS_PER_TOKEN)


def estimate_tokens_from_bytes(size: int) -> int:
    """Estimate tokens for ``size`` bytes of text (same heuristic as ``estimate_tokens``)."""
    return math.ceil(size / CHARS_PER_TOKEN)
