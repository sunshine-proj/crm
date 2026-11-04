"""BM25 keyword search over the FTS5 index."""

from __future__ import annotations

import sqlite3
from dataclasses import asdict, dataclass
from typing import Any

from ai_optimizer.core.redact import redact

CANDIDATE_MULTIPLIER = 20
MAX_CANDIDATES = 500
EXCERPT_TOKENS = 24


@dataclass(slots=True)
class Hit:
    """One ranked search result."""

    path: str
    kind: str
    language: str | None
    score: float
    excerpt: str

    def to_dict(self) -> dict[str, Any]:
        data = asdict(self)
        data["score"] = round(self.score, 4)
        return data


def build_match_query(terms: list[str]) -> str:
    """Build an FTS5 query matching any term as a prefix."""
    return " OR ".join('"' + term.replace('"', "") + '"*' for term in terms)


def search_index(conn: sqlite3.Connection, terms: list[str], *, candidates: int = 50) -> list[Hit]:
    """Return up to ``candidates`` hits ranked by BM25 (path > symbols > content)."""
    if not terms:
        return []
    rows = conn.execute(
        """
        SELECT f.path, f.kind, f.language,
               bm25(file_fts, 6.0, 3.0, 1.0) AS rank,
               snippet(file_fts, 2, '', '', '...', ?) AS excerpt
        FROM file_fts
        JOIN files f ON f.id = file_fts.rowid
        WHERE file_fts MATCH ?
        ORDER BY rank
        LIMIT ?
        """,
        (EXCERPT_TOKENS, build_match_query(terms), min(candidates, MAX_CANDIDATES)),
    ).fetchall()
    return [
        Hit(
            path=row["path"],
            kind=row["kind"],
            language=row["language"],
            score=-float(row["rank"]),
            excerpt=redact(" ".join(str(row["excerpt"]).split())),
        )
        for row in rows
    ]


def bound_hits(hits: list[Hit], limit: int, max_bytes: int) -> tuple[list[Hit], int]:
    """Apply the result count and total output size limits; return (hits, omitted)."""
    kept: list[Hit] = []
    used = 0
    for hit in hits:
        cost = len(hit.path) + len(hit.excerpt) + 64
        if len(kept) >= limit or (kept and used + cost > max_bytes):
            break
        kept.append(hit)
        used += cost
    return kept, len(hits) - len(kept)
