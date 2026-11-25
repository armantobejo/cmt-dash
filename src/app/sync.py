"""
Wrapper for the main sync script.
Runs tools/sync_db.py in a subprocess, capturing output.
"""
import os
import subprocess
from pathlib import Path

_PROJECT_ROOT = Path(__file__).resolve().parent

# In container, script is at /app/tools/sync_db.py
# Locally, it's at <project_root>/tools/sync_db.py
_SYNC_SCRIPT_CONTAINER = _PROJECT_ROOT.parent / "tools" / "sync_db.py"
_SYNC_SCRIPT_LOCAL = _PROJECT_ROOT.parents[1] / "tools" / "sync_db.py"

if _SYNC_SCRIPT_CONTAINER.exists():
    _SYNC_SCRIPT = _SYNC_SCRIPT_CONTAINER
else:
    _SYNC_SCRIPT = _SYNC_SCRIPT_LOCAL
# Sync runs all companies in one subprocess; default 10 minutes, overridable
# via SYNC_TIMEOUT_SECONDS (e.g. for very large DBF sets).
_SYNC_TIMEOUT = int(os.environ.get("SYNC_TIMEOUT_SECONDS", "600"))


def run_sync(dbf_dir: str, db_path: str, python_exe: str) -> dict:
    """
    Run tools/sync_db.py as a subprocess.
    Returns {ok, stdout, stderr, return_code}.
    """
    env = os.environ.copy()
    env["DBF_DIR"] = dbf_dir
    env["DATABASE_URL"] = db_path

    try:
        proc = subprocess.run(
            [python_exe, str(_SYNC_SCRIPT)],
            capture_output=True,
            text=True,
            env=env,
            timeout=_SYNC_TIMEOUT,
            check=True   # Raise CalledProcessError for non-zero exit
        )
        return {
            "ok": True,
            "stdout": proc.stdout,
            "stderr": proc.stderr,
            "return_code": proc.returncode,
        }
    except (subprocess.CalledProcessError, subprocess.TimeoutExpired) as e:
        return {
            "ok": False,
            "stdout": e.stdout or "",
            "stderr": e.stderr or f"Execution failed: {e}",
            "return_code": e.returncode if hasattr(e, 'returncode') else -1,
        }
    except FileNotFoundError:
        return {
            "ok": False,
            "stdout": "",
            "stderr": f"FATAL: Python executable not found at '{python_exe}'. "
                      f"Ensure it's in the system PATH.",
            "return_code": -1,
        }
