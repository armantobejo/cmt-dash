"""T032: sync_db emits structured JSON events when SYNC_LOG_JSON=1."""
from __future__ import annotations

import json
import os
import subprocess
import sys
from pathlib import Path

_SCRIPT = Path(__file__).resolve().parent.parent / "tools" / "sync_db.py"


def test_emit_json_format_when_env_set(monkeypatch, capsys):
    """emit() must print JSON lines when SYNC_LOG_JSON=1."""
    monkeypatch.syspath_prepend(str(_SCRIPT.parent))
    monkeypatch.setenv("SYNC_LOG_JSON", "1")
    import importlib
    import sync_db as s

    importlib.reload(s)
    s.emit("sync_start", dbf_dir="TESTDIR")
    out = capsys.readouterr().out.strip()
    record = json.loads(out)
    assert record["event"] == "sync_start"
    assert record["dbf_dir"] == "TESTDIR"


def test_emit_text_format_without_env(monkeypatch, capsys):
    """emit() must print human-readable lines when SYNC_LOG_JSON unset."""
    monkeypatch.syspath_prepend(str(_SCRIPT.parent))
    monkeypatch.delenv("SYNC_LOG_JSON", raising=False)
    import importlib
    import sync_db as s

    importlib.reload(s)
    s.emit("table_synced", table="NA", rows=5, ms=10)
    out = capsys.readouterr().out.strip()
    assert out.startswith("[sync] table_synced:")
    assert "table=NA" in out


def test_main_emits_start_and_complete_on_empty_dir(monkeypatch, tmp_path):
    """Running sync_db against an empty DBF dir: JSON start + fail events."""
    empty = tmp_path / "empty_dbf"
    empty.mkdir()
    db_file = tmp_path / "out.db"

    env = dict(os.environ)
    env.update(
        {
            "SYNC_LOG_JSON": "1",
            "DBF_DIR": str(empty),
            "DATABASE_URL": str(db_file),
        }
    )
    proc = subprocess.run(
        [sys.executable, str(_SCRIPT)],
        capture_output=True, text=True, env=env, timeout=60,
    )
    lines = [json.loads(l) for l in proc.stdout.splitlines() if l.strip().startswith("{")]
    events = [l["event"] for l in lines]
    assert "sync_start" in events, f"missing sync_start in {events}"
    assert "sync_fail" in events, f"missing sync_fail in {events}"