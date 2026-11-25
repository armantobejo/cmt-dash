#!/usr/bin/env python3
"""Recompute the numbers embedded in the Q-11 decision brief.

Reads ``docs/report-dbf-coverage/inventory.json`` (produced by
``tools/dbf_inventory.py --json ...``) and prints the markdown block that lives
between the ``q11-numbers:begin/end`` markers in
``docs/decisions/q11-second-layer-ingest-brief.md``.  When the brief exists the
printed block is compared byte-for-byte against it, so any figure that has
drifted away from the measured evidence fails loudly instead of reading stale.

Tiers follow docs/report-dbf-coverage.md section 4.  ``A*`` marks a table that
section 4 did not classify but whose report (the evidence doc / the blocked
report items) needs it — i.e. a proposed addition the owner still has to accept.

Run:  python tools/q11_brief_numbers.py        # exit 0 = brief matches evidence
"""
from __future__ import annotations

import json
import sys
from pathlib import Path

INVENTORY = Path("docs/report-dbf-coverage/inventory.json")
BRIEF = Path("docs/decisions/q11-second-layer-ingest-brief.md")
BEGIN = "<!-- q11-numbers:begin -->"
END = "<!-- q11-numbers:end -->"
COMPANIES = ["CME", "CMT", "CSM", "MAL", "SBY"]

# (table, tier, report it serves)
ROWS = [
    # Tier A — ingest candidates (coverage doc section 4) + proposed (A*)
    ("ARS", "A", "3.2.2 Receipt A/R - Invoice; 3.2.5 SOA receipt half (F-24, F-27)"),
    ("ARS_R", "A", "3.2.2 Receipt A/R - Invoice (UPD_INV.PRG join); 3.2.5 F-27"),
    ("AR_AGENT", "A", "3.2.2 agent DN/CN receipts (UPD_DN.PRG join) (F-24)"),
    ("AR_AGENT_D", "A", "3.2.2 agent DN/CN receipts (UPD_DN.PRG join) (F-24)"),
    ("AP", "A", "3.2.3 Payment A/P - Vendor (UPD_OC.PRG join) (F-25)"),
    ("AP_R", "A", "3.2.3 Payment A/P - Vendor (UPD_OC.PRG join) (F-25)"),
    ("AP_AGENT", "A", "3.2.3 Payment A/P - Agent (F-25)"),
    ("AP_AGENT_D", "A", "3.2.3 Payment A/P - Agent (F-25)"),
    ("BAYAR", "A", "3.2.3 Payment - Cash/Bank (F-25)"),
    ("BAYAR_R", "A", "3.2.3 Payment - Cash/Bank (F-25)"),
    ("TERIMA", "A", "3.2.2 Receipt - Cash/Bank (F-24)"),
    ("TERIMA_R", "A", "3.2.2 Receipt - Cash/Bank (F-24)"),
    ("CLEARING_D", "A*", "3.2.2/3.2.3 cash-vs-bank journal detail (F-24, F-25)"),
    ("RC", "A", "3.2.3 refund-cost receipts (F-25)"),
    ("RC_D", "A", "3.2.3 refund-cost receipts (F-25)"),
    ("RCREQ", "A", "3.2.3 Payment A/P - Refund Cost; 3.2.8 request listing (F-25, F-30)"),
    ("RCREQ_D", "A", "3.2.3 Payment A/P - Refund Cost; 3.2.8 request listing (F-25, F-30)"),
    ("SI", "A*", "3.2.2/3.2.3 intraship A/R, named by the receipt/payment item (F-31)"),
    ("SI_D", "A*", "3.2.2/3.2.3 intraship A/R, named by the receipt/payment item (F-31)"),
    ("QUOTATION", "A", "3.2.6 Rate by Destination (F-28)"),
    ("QUOTATION_R", "A", "3.2.6 Rate by Destination (F-28)"),
    ("VENDOR", "A", "3.2.6 Rate by Destination (vendor); 3.2.7 By Shipping Lines (F-29)"),
    ("VENDOR_D", "A", "3.2.6 Rate by Destination vendor tariff (F-28)"),
    ("MANIFEST", "A", "3.2.1 Projects/Jobs - Detail manifest (F-23)"),
    ("COST", "A", "3.2.8 Recurring Costs (F-30) - dictionary only, holds no rates (2.8)"),
    ("SAGING", "A", "3.2.2/3.2.3 bucket boundaries for all four aging rows (F-24, F-25)"),
    # Tier B — report is derived; its archive needs no ingest
    ("SOA", "B", "3.2.5 Statement of Account - archive, derived source (F-27)"),
    ("SOA_D", "B", "3.2.5 Statement of Account - archive, derived source (F-27)"),
    # Tier C — named for their answer, empty in all five companies
    ("AR_CONTRA", "C", "3.2.2 Contra Bond - In (availability answer, not an ingest)"),
    ("AR_CONTRA_R", "C", "3.2.2 Contra Bond - In (availability answer, not an ingest)"),
    ("AP_CONTRA", "C", "3.2.3 Contra Bond - Out (availability answer, not an ingest)"),
    ("AP_CONTRA_R", "C", "3.2.3 Contra Bond - Out (availability answer, not an ingest)"),
    # Tier D — verify before deciding
    ("NA_D", "D", "which legacy screen reads NA_D (5.1: it is not OPRCOST_R)"),
    ("CARGO_R", "D", "3.2.1 Projects/Jobs - Detail container lines (F-23)"),
    ("NA_R", "D", "3.2.1 Projects/Jobs - Detail container lines (F-23)"),
    ("PERMOHONAN_E", "D", "3.2.8 Petty Cash Request Listing (CSM/MAL only) (F-30)"),
    ("CRDRNOTE", "D", "3.2 debit/credit-note journalism (F-31) - no 3.2 row binds it yet"),
    ("CRDRNOTE_R", "D", "3.2 debit/credit-note journalism (F-31) - no 3.2 row binds it yet"),
    ("CRDRNOTE_S", "D", "3.2 debit/credit-note journalism (F-31) - no 3.2 row binds it yet"),
]


def counts_for(inventory: dict, table: str) -> str:
    """Row counts per company as `CME/CMT/CSM/MAL/SBY` (missing table -> '-')."""
    out = []
    for company in COMPANIES:
        info = inventory["companies"][company]["tables"].get(table)
        out.append("-" if info is None else str(info["records"]))
    return "/".join(out)


def build_block(inventory: dict) -> str:
    lines = [
        "| Table | Tier | Report it serves (PRD section) | Rows CME/CMT/CSM/MAL/SBY |",
        "|---|---|---|---|",
    ]
    for table, tier, serves in ROWS:
        lines.append(f"| `{table}` | {tier} | {serves} | {counts_for(inventory, table)} |")
    empty = inventory.get("empty_in_all", [])
    if isinstance(empty, dict):
        empty = list(empty)
    lines.append("")
    lines.append(f"- Tables empty in every company (Tier C): **{len(empty)}**")
    contra = ["AR_CONTRA", "AR_CONTRA_R", "AP_CONTRA", "AP_CONTRA_R"]
    contra_counts = [counts_for(inventory, t) for t in contra]
    all_zero = all(c == "0/0/0/0/0" for c in contra_counts)
    lines.append(
        "- Contra tables ("
        + ", ".join(f"`{t}` {c}" for t, c in zip(contra, contra_counts))
        + f"): all zero in all five = **{all_zero}**"
    )
    return "\n".join(lines)


def main() -> int:
    if not INVENTORY.exists():
        print(f"missing {INVENTORY}; run: python tools/dbf_inventory.py --json {INVENTORY}", file=sys.stderr)
        return 1
    inventory = json.loads(INVENTORY.read_text(encoding="utf-8"))
    block = build_block(inventory)
    print(block)

    if not BRIEF.exists():
        print(f"\nMISSING brief {BRIEF} - paste the block above between its markers.", file=sys.stderr)
        return 1
    text = BRIEF.read_text(encoding="utf-8")
    if BEGIN not in text or END not in text:
        print(f"MISSING markers in {BRIEF}", file=sys.stderr)
        return 1
    embedded = text.split(BEGIN, 1)[1].split(END, 1)[0].strip("\n")
    if embedded != block:
        print("\nSTALE: brief block differs from inventory.json — paste the printed block.", file=sys.stderr)
        return 1
    print(f"\nOK: {BRIEF} counts match {INVENTORY}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
