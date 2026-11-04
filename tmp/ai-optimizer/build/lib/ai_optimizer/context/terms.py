"""Deterministic search-term extraction (no LLM involved)."""

from __future__ import annotations

import re

MAX_TERMS = 12

STOPWORDS = frozenset(
    {
        "a",
        "an",
        "and",
        "are",
        "as",
        "at",
        "be",
        "but",
        "by",
        "can",
        "do",
        "does",
        "for",
        "from",
        "has",
        "have",
        "how",
        "i",
        "if",
        "in",
        "into",
        "is",
        "it",
        "its",
        "make",
        "me",
        "my",
        "need",
        "not",
        "of",
        "on",
        "or",
        "our",
        "should",
        "so",
        "that",
        "the",
        "their",
        "then",
        "there",
        "these",
        "this",
        "to",
        "use",
        "using",
        "want",
        "was",
        "we",
        "what",
        "when",
        "where",
        "which",
        "while",
        "who",
        "why",
        "will",
        "with",
        "without",
        "would",
        "you",
        "your",
        "add",
        "fix",
        "implement",
        "update",
        "create",
        "build",
        "change",
        "new",
        "please",
        "also",
    }
)

_TOKEN = re.compile(r"[A-Z]+(?![a-z])|[A-Z]?[a-z]+|\d+")


def extract_terms(text: str, limit: int = MAX_TERMS) -> list[str]:
    """Split text (including snake_case and camelCase) into unique, meaningful terms."""
    terms: list[str] = []
    for token in _TOKEN.findall(text):
        lowered = token.lower()
        if len(lowered) < 2 or lowered in STOPWORDS or lowered in terms:
            continue
        terms.append(lowered)
        if len(terms) >= limit:
            break
    return terms
