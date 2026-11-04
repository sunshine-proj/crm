"""Language and file-kind classification by path."""

from __future__ import annotations

from pathlib import PurePosixPath

EXTENSION_LANGUAGE: dict[str, str] = {
    ".py": "python",
    ".pyi": "python",
    ".js": "javascript",
    ".jsx": "javascript",
    ".mjs": "javascript",
    ".cjs": "javascript",
    ".ts": "typescript",
    ".tsx": "typescript",
    ".go": "go",
    ".rs": "rust",
    ".java": "java",
    ".kt": "kotlin",
    ".rb": "ruby",
    ".php": "php",
    ".cs": "csharp",
    ".c": "c",
    ".h": "c",
    ".cpp": "cpp",
    ".cc": "cpp",
    ".hpp": "cpp",
    ".swift": "swift",
    ".scala": "scala",
    ".sh": "shell",
    ".sql": "sql",
    ".html": "html",
    ".css": "css",
    ".scss": "css",
    ".vue": "vue",
    ".svelte": "svelte",
    ".md": "markdown",
    ".rst": "restructuredtext",
    ".txt": "text",
    ".adoc": "asciidoc",
    ".yaml": "yaml",
    ".yml": "yaml",
    ".toml": "toml",
    ".json": "json",
    ".ini": "ini",
    ".cfg": "ini",
}

DOC_LANGUAGES = frozenset({"markdown", "restructuredtext", "text", "asciidoc"})
CONFIG_LANGUAGES = frozenset({"yaml", "toml", "json", "ini"})
NON_PROGRAMMING = DOC_LANGUAGES | CONFIG_LANGUAGES

CONFIG_FILENAMES = frozenset({"Makefile", "Dockerfile", "docker-compose.yml", ".gitignore"})
TEST_DIRS = frozenset({"tests", "test", "__tests__", "spec", "specs"})


def detect_language(path: str) -> str | None:
    """Return the language name for a posix relative path, or None if unknown."""
    return EXTENSION_LANGUAGE.get(PurePosixPath(path).suffix.lower())


def is_programming_language(language: str | None) -> bool:
    return language is not None and language not in NON_PROGRAMMING


def classify_kind(path: str, language: str | None) -> str:
    """Classify a file as test, doc, config, source or other."""
    posix = PurePosixPath(path)
    name = posix.name
    stem = posix.stem
    if (
        any(part in TEST_DIRS for part in posix.parts[:-1])
        or stem.startswith("test_")
        or stem.endswith("_test")
        or ".test." in name
        or ".spec." in name
        or name == "conftest.py"
    ):
        return "test"
    if language in DOC_LANGUAGES or (posix.parts and posix.parts[0] == "docs"):
        return "doc"
    if language in CONFIG_LANGUAGES or name in CONFIG_FILENAMES:
        return "config"
    if is_programming_language(language):
        return "source"
    return "other"
