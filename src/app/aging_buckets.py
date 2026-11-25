"""Aging bucket boundaries.

Source of truth: DBF/CMT/DATA/sAging.dbf (1 row), fields AGING1..AGING7
= 1, 30, 31, 60, 61, 90, 90. Verified via tools/dbf_inventory.py.
Buckets are days overdue, inclusive upper bound.
"""
# Cited from SAGING (do not inline elsewhere).
SAGING = (1, 30, 31, 60, 61, 90, 90)
BUCKETS = ["0-30", "31-60", "61-90", "90+"]


def bucket_for(days_overdue: int) -> str:
    if days_overdue <= 30:
        return "0-30"
    if days_overdue <= 60:
        return "31-60"
    if days_overdue <= 90:
        return "61-90"
    return "90+"
