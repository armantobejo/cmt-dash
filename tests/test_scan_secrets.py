"""T040: secret scanner catches obvious leaks, stays quiet on config."""
from __future__ import annotations

import os
import sys
from pathlib import Path

import pytest

# Make tools/ importable for the scanner module.
TOOLS = Path(__file__).resolve().parent.parent / "tools"
sys.path.insert(0, str(TOOLS))


def test_scanner_detects_aws_key(tmp_path):
    from scan_secrets import scan_file

    f = tmp_path / "leaked.py"
    f.write_text('key = "AKIAIOSFODNN7EXAMPLE"\n')
    hits = scan_file(f)
    assert hits, "AWS key pattern should be detected"
    assert hits[0].start == "AKIAIOSFODNN7EXAMPLE"
    assert "aws" in hits[0].kind.lower()


def test_scanner_ignores_clean_file(tmp_path):
    from scan_secrets import scan_file

    f = tmp_path / "clean.py"
    f.write_text("# config value = 'not.a.secret'\ndatabase_url = os.environ['DATABASE_URL']\n")
    hits = scan_file(f)
    assert not hits


def test_scanner_ignores_comments_only(tmp_path):
    from scan_secrets import scan_file

    f = tmp_path / "commented.py"
    f.write_text("# use AKIAIOSFODNN7EXAMPLE as the key\n# TODO: rotate GITHUB_PAT=ghp_xxxxxxxxxxxxxxxxxxxx\n")
    hits = scan_file(f)
    # Commented-out secrets are still flagged by default (they leak once
    # uncommented).  The .gitleaks.toml has a separate allowlist for docs.
    assert any(h.kind == "aws_access_key" for h in hits)


def test_scanner_skips_gitignored_paths(tmp_path):
    from scan_secrets import scan_tree

    (tmp_path / "leaked.py").write_text('AWS_ACCESS_KEY_ID = "AKIAIOSFODNN7EXAMPLE"\n')
    (tmp_path / "data").mkdir()
    (tmp_path / "data" / "cmt.db").write_text('password = "AKIAIOSFODNN7EXAMPLE"\n')
    hits = scan_tree(tmp_path)
    assert any(h.path.endswith("leaked.py") for h in hits)
    assert not any(h.path.endswith("cmt.db") for h in hits), ".db files should be skipped"