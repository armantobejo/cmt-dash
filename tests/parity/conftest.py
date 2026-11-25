"""Fixtures for the parity-baseline harness.

Deliberately a SEPARATE conftest from tests/conftest.py: this harness runs in
its own temp dir with its own synthetic dataset (parity_dataset.py), including
a tiny real DBF tree so F-11 (POST /sync) and F-10 (company DB switcher) run
end-to-end without touching production data.

Env is set at import time (BEFORE app imports) per the repo convention.
"""
from __future__ import annotations

import os
import sys
import tempfile
from pathlib import Path

import pytest

_REPO_ROOT = Path(__file__).resolve().parents[2]
_SRC = _REPO_ROOT / "src"
_TOOLS = _REPO_ROOT / "tools"
_HERE = Path(__file__).resolve().parent

# Prepend (not append) so `app` and `schema` resolve to this repo's copies.
for p in (str(_HERE), str(_SRC), str(_TOOLS)):
    if p not in sys.path:
        sys.path.insert(0, p)

# This conftest is only allowed to touch the process environment when the
# parity harness is the EXPLICIT pytest target (pytest tests/parity). pytest
# loads every conftest in the tree even for unrelated runs, and import-time
# env vars here would otherwise override tests/conftest.py's and break the
# app test suite in a combined `pytest tests/` run.
_ARGSTR = " ".join(sys.argv[1:]).replace("\\", "/")
_PARITY_MODE = "tests/parity" in _ARGSTR or _ARGSTR.strip().rstrip("/") == "parity"

if _PARITY_MODE:
    _TMP = Path(tempfile.mkdtemp(prefix="cmt_parity_"))
    _DATA_DIR = _TMP / "data"
    _DBF_DIR = _TMP / "dbf"
    _DB_PATH = _DATA_DIR / "cmt.db"
    # Create the data dir up front: tests/conftest.py's session-scoped
    # _seed_test_data autouse fixture runs before the `dataset` fixture and
    # calls db.init_schema() against this DB_PATH — the directory must exist.
    _DATA_DIR.mkdir(parents=True, exist_ok=True)

    os.environ["DATABASE_URL"] = str(_DB_PATH)
    os.environ["DATA_DIR"] = str(_DATA_DIR)
    os.environ["DBF_DIR"] = str(_DBF_DIR)
    os.environ["CMT_COOKIE_SECURE"] = ""
    os.environ["SYNC_LOG_JSON"] = ""
    os.environ["CMT_USERS"] = (
        "root:root-pw:superadmin,"
        "admin1:admin-pw:admin,"
        "manager1:mgmt-pw:management,"
        "staff1:staff-pw:staff,"
        "cust1:cust-pw:customer:Trisco"
    )

# IMPORTANT: stub the sync subprocess BEFORE the first /sync call. The parity
# dataset is hand-seeded; a real `tools/sync_db.py` run would wipe and rebuild
# data/cmt.db from the tiny DBF tree and destroy fixture state. The baseline
# records the /sync HTTP contract (status, permission gating), not subprocess
# behavior; P3's byte-comparison covers real sync output separately.
# NOTE: main.py does `from .sync import run_sync`, so the patch target is
# app.main.run_sync, not app.sync.run_sync.
from unittest.mock import patch as _patch  # noqa: E402

from parity_dataset import build_dataset, DEFAULTS  # noqa: E402
from parity_constants import ROLE_CREDS  # noqa: E402

_SYNC_STUB = {
    "ok": True,
    "stdout": "[parity-stub] sync skipped in baseline harness",
    "stderr": "",
    "return_code": 0,
}

if not _PARITY_MODE:
    # Combined run: tests/conftest.py owns the env and this harness's tests
    # are excluded from collection (collect_ignore_glob there). Define a
    # placeholder so pytest sees no fixtures from here at all.
    def dataset():  # pragma: no cover - never collected
        raise RuntimeError("parity harness must run standalone: pytest tests/parity")

    def client(dataset):  # pragma: no cover - never collected
        raise RuntimeError("parity harness must run standalone: pytest tests/parity")

else:

    @pytest.fixture(scope="session", autouse=True)
    def dataset() -> dict:
        """Build the synthetic dataset once for the whole harness session."""
        return build_dataset(data_dir=_DATA_DIR, dbf_dir=_DBF_DIR, defaults=DEFAULTS)

    @pytest.fixture(scope="session")
    def client(dataset):
        """Session-scoped FastAPI TestClient over the parity dataset.

        follow_redirects is disabled so redirect contracts (303/302 + Location)
        are captured exactly as the app emits them.
        """
        from fastapi.testclient import TestClient
        import app.main as _main

        _patch.object(_main, "run_sync", return_value=dict(_SYNC_STUB)).start()
        # raise_server_exceptions=False: known app defects (KG-5 missing
        # get_invoice_detail, KG-6 broken customer-filter SQL in get_summary)
        # must be CAPTURED as 500 responses in the baseline, not raised.
        with TestClient(_main.app, follow_redirects=False,
                        raise_server_exceptions=False) as c:
            yield c



