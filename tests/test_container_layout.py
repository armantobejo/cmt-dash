"""Regression: tools/ must resolve in both the repo and container layouts.

Docker bakes src/app -> /app/app and tools -> /app/tools, while local dev
uses <repo>/src/app + <repo>/tools. queries.py used to assume the repo
layout (parents[2] / "tools"), which resolves to /tools inside the image and
crashed the container at startup with:

    ModuleNotFoundError: No module named 'schema'

See the fix in src/app/queries.py (dual-path resolution, same convention
as src/app/sync.py).
"""
from __future__ import annotations

import os
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

_REPO = Path(__file__).resolve().parent.parent
_APP = _REPO / "src" / "app"
_TOOLS = _REPO / "tools"

# Import app.main (exactly what uvicorn does) and print where queries.py
# resolved tools/ to. Any ModuleNotFoundError crashes the subprocess.
_CONTAINER_CHECK = (
    "import app.main\n"
    "from pathlib import Path\n"
    "print(Path(app.main.q._TOOLS))"
)


def _norm(p: Path) -> str:
    return os.path.normcase(str(p))


def test_container_layout_imports_app_and_uses_container_tools():
    """Simulate the image layout (/app/app + /app/tools) and import the app.

    Reproduces the exact crash path: uvicorn imports app.main, which imports
    queries, which must find tools/schema.py on the path.
    """
    with tempfile.TemporaryDirectory() as tmp:
        root = Path(tmp) / "app"
        shutil.copytree(_APP, root / "app")
        shutil.copytree(_TOOLS, root / "tools")
        proc = subprocess.run(
            [sys.executable, "-c", _CONTAINER_CHECK],
            cwd=str(root),
            capture_output=True,
            text=True,
        )
        assert proc.returncode == 0, (
            f"container-layout import failed:\n"
            f"stdout:\n{proc.stdout}\nstderr:\n{proc.stderr}"
        )
        resolved = Path(proc.stdout.strip())
        assert _norm(resolved) == _norm(root / "tools"), (
            f"tools/ resolved to {resolved}, expected {root / 'tools'}"
        )


def test_repo_layout_resolves_tools_to_repo_path():
    """Local dev layout: <repo>/src/app/queries.py must use <repo>/tools."""
    import app.queries  # noqa: F401  (importable via conftest's sys.path)

    assert _norm(Path(app.queries._TOOLS)) == _norm(_TOOLS), (
        f"tools/ resolved to {app.queries._TOOLS}, expected {_TOOLS}"
    )
