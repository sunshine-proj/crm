"""Context tests: terms, search, profiles, packages, budgets and cache auto-refresh."""

from __future__ import annotations

import json
from collections.abc import Callable
from pathlib import Path

import pytest

from ai_optimizer import services
from ai_optimizer.cache.db import open_existing
from ai_optimizer.cache.indexer import update_index
from ai_optimizer.context.profiles import (
    BUILTIN_PROFILES,
    get_profile,
    load_profiles,
    parse_profile,
)
from ai_optimizer.context.search import Hit, bound_hits, build_match_query, search_index
from ai_optimizer.context.terms import extract_terms
from ai_optimizer.core.errors import CacheError, ConfigError, PermissionDeniedError
from ai_optimizer.core.project import Project

Writer = Callable[[Path, str, str], Path]


# --- terms and search --------------------------------------------------------------------


def test_extract_terms_handles_case_styles_and_stopwords() -> None:
    assert extract_terms("Implement the PasswordResetService and reset_token") == [
        "password", "reset", "service", "token",
    ]  # fmt: skip
    assert extract_terms("a to of") == []
    assert len(extract_terms(" ".join(f"word{chr(97 + i)}" for i in range(26)))) <= 12


def test_match_query_quotes_terms() -> None:
    assert build_match_query(['pass"word', "reset"]) == '"password"* OR "reset"*'


def test_search_ranks_path_and_symbols_above_content(project: Project) -> None:
    update_index(project.paths, project.config)
    conn = open_existing(project.paths.db_file)
    try:
        hits = search_index(conn, ["password", "reset"])
    finally:
        conn.close()
    assert hits[0].path == "src/shop/auth.py"
    assert {h.path for h in hits} >= {"docs/auth.md", "tests/test_auth.py"}
    assert all(h.score >= 0 for h in hits)


def test_search_prefix_matching(project: Project) -> None:
    update_index(project.paths, project.config)
    conn = open_existing(project.paths.db_file)
    try:
        assert any(h.path.endswith("cart.py") for h in search_index(conn, ["cart"]))
        assert any(h.path.endswith("cart.py") for h in search_index(conn, ["add"]))
    finally:
        conn.close()


def test_bound_hits_limits_count_and_bytes() -> None:
    hits = [Hit(f"f{i}.py", "source", "python", 1.0, "x" * 100) for i in range(10)]
    kept, omitted = bound_hits(hits, limit=3, max_bytes=10_000)
    assert len(kept) == 3 and omitted == 7
    kept, omitted = bound_hits(hits, limit=10, max_bytes=400)
    assert 1 <= len(kept) < 10
    assert bound_hits(hits, limit=10, max_bytes=1)[0] == hits[:1]


def test_search_excerpts_are_redacted(project: Project, write: Writer) -> None:
    write(project.root, "src/shop/config.py", 'DB_PASSWORD = "supersecretvalue"\n')
    result = services.search_context(project, "password")
    text = json.dumps(result)
    assert "supersecretvalue" not in text


def test_search_service_validation(project: Project) -> None:
    with pytest.raises(ConfigError):
        services.search_context(project, "the and of")
    with pytest.raises(ConfigError):
        services.search_context(project, "cart", limit=0)
    with pytest.raises(ConfigError):
        services.search_context(project, "cart", limit=101)


def test_search_service_returns_bounded_results(project: Project) -> None:
    result = services.search_context(project, "password reset", limit=1)
    assert len(result["results"]) == 1
    assert result["omitted"] >= 1
    assert result["results"][0]["path"] == "src/shop/auth.py"


# --- auto refresh ------------------------------------------------------------------------


def with_mode(project: Project, mode: str) -> Project:
    config = project.config.model_copy(
        update={"cache": project.config.cache.model_copy(update={"auto_refresh": mode})}
    )
    return Project(paths=project.paths, config=config)


def test_refresh_mode_builds_and_updates_cache(project: Project, write: Writer) -> None:
    assert not project.paths.db_file.exists()
    services.search_context(project, "cart")
    assert project.paths.db_file.exists()
    write(project.root, "src/shop/orders.py", "def place_order(): pass\n")
    result = services.search_context(project, "place order")
    assert result["results"][0]["path"] == "src/shop/orders.py"


def test_warn_mode_reports_staleness(project: Project, write: Writer) -> None:
    services.cache_update(project)
    write(project.root, "src/shop/orders.py", "def place_order(): pass\n")
    result = services.search_context(with_mode(project, "warn"), "cart")
    assert any("stale" in w for w in result["warnings"])
    assert all(r["path"] != "src/shop/orders.py" for r in result["results"])


@pytest.mark.parametrize("mode", ["warn", "off"])
def test_missing_cache_without_refresh_is_an_error(project: Project, mode: str) -> None:
    with pytest.raises(CacheError):
        services.search_context(with_mode(project, mode), "cart")


def test_corrupt_cache_is_rebuilt_in_refresh_mode(project: Project) -> None:
    services.cache_update(project)
    project.paths.db_file.write_bytes(b"garbage" * 200)
    result = services.search_context(project, "cart")
    assert result["results"]


# --- profiles ----------------------------------------------------------------------------


def test_builtin_profiles_present() -> None:
    assert set(BUILTIN_PROFILES) == {
        "frontend", "backend", "database", "testing", "security", "refactoring", "code-review",
    }  # fmt: skip


def test_profile_file_overrides_builtin(project: Project, write: Writer) -> None:
    write(
        project.root,
        ".ai-optimizer/profiles/backend.yaml",
        "description: Custom\nkeywords: [orders]\ninclude: ['src/**']\n",
    )
    profile = get_profile(project.paths, "backend")
    assert profile.description == "Custom" and profile.keywords == ["orders"]
    listing = {p["name"]: p for p in services.list_profiles(project)["profiles"]}
    assert listing["backend"]["source"] == "project"
    assert listing["backend"]["overrides_builtin"] is True
    assert listing["frontend"]["source"] == "builtin"


@pytest.mark.parametrize(
    "text",
    [
        "unknown_setting: 1",
        "kind_weights: {nonsense: 2}",
        "kind_weights: {source: -1}",
        "name: Bad Name",
        "changed_since: '--upload-pack=evil'",
        "max_tokens: 5",
        "include: not-a-list",
    ],
)
def test_malformed_profiles_are_rejected(text: str) -> None:
    with pytest.raises(ConfigError):
        parse_profile(text, "profile.yaml", "custom")


def test_duplicate_profile_names_rejected(project: Project, write: Writer) -> None:
    write(project.root, ".ai-optimizer/profiles/a.yaml", "name: same\n")
    write(project.root, ".ai-optimizer/profiles/b.yaml", "name: same\n")
    with pytest.raises(ConfigError, match="Duplicate"):
        load_profiles(project.paths)


def test_unknown_profile_lists_choices(project: Project) -> None:
    with pytest.raises(ConfigError, match="backend"):
        get_profile(project.paths, "nope")


# --- packages ----------------------------------------------------------------------------


def test_package_contents(project: Project) -> None:
    built = services.build_context(project, task="Implement password reset")
    package, text = built["package"], built["text"]
    assert package["files"][0]["path"] == "src/shop/auth.py"
    assert [d["path"] for d in package["documentation"]] == ["docs/auth.md"]
    assert package["conventions"] == [{"source": "AGENTS.md", "content": "Use type hints.\n"}]
    assert [d["name"] for d in package["dependencies"]] == ["flask"]
    assert package["project"]["name"] == "shop"
    assert {c["command"] for c in package["validation"]["detected_commands"]} >= {"pytest"}
    assert "# Context Package" in text and "src/shop/auth.py" in text
    assert "hunter2" not in text and ".env" not in text


def test_json_package_is_valid_and_matches(project: Project) -> None:
    built = services.build_context(project, task="password reset", fmt="json")
    assert json.loads(built["text"]) == built["package"]
    assert built["package"]["budget"]["estimation_method"].endswith("not provider-reported")


def test_budget_is_respected_and_reports_omissions(project: Project, write: Writer) -> None:
    for i in range(30):
        write(project.root, f"src/shop/mod{i}.py", "def reset_password_thing():\n    pass\n" * 20)
    write(project.root, "AGENTS.md", "Convention line.\n" * 400)
    small = services.build_context(project, task="reset password", max_tokens=300)
    package = small["package"]
    assert package["budget"]["estimated_tokens"] <= 300
    assert sum(package["budget"]["omitted"].values()) > 0
    assert len(package["files"]) >= 1
    large = services.build_context(project, task="reset password", max_tokens=8000)
    assert len(large["package"]["files"]) > len(package["files"])


def test_impossible_budget_warns_instead_of_looping(project: Project) -> None:
    built = services.build_context(project, task="password reset", max_tokens=200)
    assert built["package"]["files"]
    assert any("too small" in w for w in built["package"]["warnings"]) or (
        built["package"]["budget"]["estimated_tokens"] <= 200
    )


def test_profile_filters_and_weights(project: Project, write: Writer) -> None:
    write(project.root, "web/app.ts", "export function resetPassword() {}\n")
    write(project.root, "web/style.css", ".reset { color: red }\n")
    built = services.build_context(project, task="reset password", profile_name="frontend")
    paths = {f["path"] for f in built["package"]["files"]}
    assert "web/app.ts" in paths and "src/shop/auth.py" not in paths
    write(
        project.root,
        ".ai-optimizer/profiles/tests-first.yaml",
        "kind_weights: {test: 10, source: 0.1}\n",
    )
    first = services.build_context(project, task="reset password", profile_name="tests-first")
    assert first["package"]["files"][0]["kind"] == "test"
    plain = services.build_context(project, task="reset password")
    assert plain["package"]["files"][0]["kind"] == "source"


def test_profile_alone_uses_its_keywords(project: Project) -> None:
    built = services.build_context(project, profile_name="testing")
    assert built["package"]["profile"] == "testing"
    assert built["package"]["terms"]


def test_build_requires_task_or_profile(project: Project) -> None:
    with pytest.raises(ConfigError):
        services.build_context(project)
    with pytest.raises(ConfigError):
        services.build_context(project, task="the and", fmt="md")
    with pytest.raises(ConfigError):
        services.build_context(project, task="reset", fmt="xml")


def test_changed_profile_uses_last_update(project: Project, write: Writer) -> None:
    services.cache_update(project)
    write(project.root, "src/shop/cart.py", "def add_item(cart, item):\n    cart.insert(0, item)\n")
    write(project.root, "tests/test_cart.py", "def test_cart(): pass\n")
    services.cache_update(project)
    built = services.build_context(project, profile_name="code-review")
    paths = [f["path"] for f in built["package"]["files"]]
    assert "src/shop/cart.py" in paths and "tests/test_cart.py" in paths
    assert "src/shop/auth.py" not in paths


def test_changed_profile_without_changes_warns(project: Project) -> None:
    built = services.build_context(project, profile_name="refactoring")
    assert any("No changed files" in w for w in built["package"]["warnings"])


def test_conventions_are_redacted_and_bounded(project: Project, write: Writer) -> None:
    write(project.root, "AGENTS.md", "api_key = abcdefghijklmnop\n" + "x" * 9000)
    built = services.build_context(project, task="cart")
    content = built["package"]["conventions"][0]["content"]
    assert "abcdefghijklmnop" not in content
    assert len(content) < 4100


def test_output_file_must_stay_inside_project(project: Project, tmp_path: Path) -> None:
    ok = services.build_context(project, task="cart", output=Path("out/context.md"))
    assert (project.root / "out/context.md").read_text().startswith("# Context Package")
    assert ok["output_file"] is not None
    with pytest.raises(PermissionDeniedError):
        services.build_context(project, task="cart", output=tmp_path / "elsewhere.md")
    with pytest.raises(PermissionDeniedError):
        services.build_context(project, task="cart", output=Path("../escape.md"))


def test_metadata_refreshes_after_changes(project: Project, write: Writer) -> None:
    services.cache_update(project)
    write(project.root, "requirements.txt", "django>=4\n")
    result = services.cache_update(project)
    assert result["metadata_refreshed"] is True
    deps = json.loads((project.paths.cache_dir / "dependencies.json").read_text())
    assert "django" in {d["name"] for d in deps["dependencies"]}
    assert services.cache_update(project)["metadata_refreshed"] is False
