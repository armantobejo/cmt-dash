"""Shared parity-harness constants.

Kept out of conftest.py to avoid module-name collisions between
tests/conftest.py and tests/parity/conftest.py when the harness imports
helpers by path (pytest rootdir-relative imports).
"""
from __future__ import annotations

ROLE_CREDS = {
    "superadmin": ("root", "root-pw"),
    "admin": ("admin1", "admin-pw"),
    "management": ("manager1", "mgmt-pw"),
    "staff": ("staff1", "staff-pw"),
    "customer": ("cust1", "cust-pw"),
}
