#!/usr/bin/env python3
"""Gate for the PRD data-scope record (kanban: prd-data-scope-two-dbf-layers).

    python tools/check_prd_scope.py     # exit 0 only when the PRD structure holds

Checks the acceptance criteria of that todo against PRD.md:
  1. exactly one `### 1.2b Data scope` section, sitting between `### 1.2` and `### 1.3`
  2. exactly one `### 3.1 One-to-one port map`, one `| F-31 |` parity row, and Q-10 before Q-11
  3. sync-scope statements agree: no `14 tables`, exactly one `18-table` statement on each of
     the §1.4 sync bullet / §3 item 6 / §3.1 Q-11 port-map row, and no line repeats a sentence
     (the signature of the duplicated-content corruption this gate exists to catch)
  4. every markdown table has a consistent pipe count per table
  5. `## 1. Product overview` exists before `### 1.1`
  6. the DBF counts recorded in the PRD match the measured inventory (110 in `DATA/`, 148–151
     with `INIT/`, 151 names, 148 common), and any stale figure appears only inside a correction
  7. `AR_R` is never named as an existing table — any mention must sit in an absence statement
  8. `§1.2b` names every report-bearing table and marks every table that `inventory.json` reports
     as empty in all five companies
  9. every `F-22`..`F-32` row present in §4
  10. `#### 3.2.1`..`#### 3.2.9` subsections present
  11. one `| Q-09 |` row present
  12. view-layer product name consistent: `Filament` in §1.2, §3.2 and Q-09
  13. report labels named in §3.2 resolve against `docs/report-dbf-coverage/legacy-evidence.json`
      (no unmatched candidates)
  14. §3.2.9 carries its four labelled rows: Volume, Lifting, Sales, Gross Profit & Loss

Exit 0 = clean; exit 1 = the failing criteria, printed one per line.
"""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[1]
PRD = REPO / "PRD.md"

SENTENCE = re.compile(r"[^.;]{60,}?[.;]")


def load_lines() -> list[str]:
    return PRD.read_text(encoding="utf-8", newline="").splitlines()


def find(lines: list[str], pred) -> list[int]:
    return [i for i, s in enumerate(lines) if pred(s)]


def check_sections(lines: list[str], bad: list[str]) -> None:
    """Criteria 1, 2, 5."""
    h12b = find(lines, lambda s: s.startswith("### 1.2b Data scope"))
    if len(h12b) != 1:
        bad.append(f"criterion 1: expected 1 '### 1.2b Data scope' section, found {len(h12b)}")
    else:
        h12 = find(lines, lambda s: s.startswith("### 1.2 "))
        h13 = find(lines, lambda s: s.startswith("### 1.3 "))
        if not h12 or not h13 or not (h12[0] < h12b[0] < h13[0]):
            bad.append("criterion 1: '### 1.2b' is not positioned between '### 1.2' and '### 1.3'")

    h31 = find(lines, lambda s: s.startswith("### 3.1 One-to-one port map"))
    if len(h31) != 1:
        bad.append(f"criterion 2: expected 1 '### 3.1 One-to-one port map', found {len(h31)}")

    f31 = find(lines, lambda s: "| F-31 | Ship Finance" in s)
    if len(f31) != 1:
        bad.append(f"criterion 2: expected 1 '| F-31 |' parity row, found {len(f31)}")

    q10 = find(lines, lambda s: s.startswith("| Q-10 |"))
    q11 = find(lines, lambda s: s.startswith("| Q-11 |"))
    if len(q10) != 1 or len(q11) != 1:
        bad.append(f"criterion 2: expected 1 Q-10 and 1 Q-11 row, found {len(q10)} and {len(q11)}")
    elif q10[0] > q11[0]:
        bad.append("criterion 2: Q-10 must be listed before Q-11")

    h1 = find(lines, lambda s: s.startswith("## 1. Product overview"))
    h11 = find(lines, lambda s: s.startswith("### 1.1 "))
    if len(h1) != 1:
        bad.append(f"criterion 5: expected 1 '## 1. Product overview', found {len(h1)}")
    if not h11 or (h1 and h1[0] > h11[0]):
        bad.append("criterion 5: '## 1. Product overview' must precede '### 1.1'")


def check_scope_statements(lines: list[str], bad: list[str]) -> None:
    """Criterion 3."""
    stale = [i + 1 for i, s in enumerate(lines) if "14 tables" in s or "14-table" in s]
    if stale:
        bad.append(f"criterion 3: stale 14-table statement on line(s) {stale}")

    anchors = {
        "section 1.4 sync bullet": lambda s: "600s default)" in s and "18-table" in s,
        "section 3 item 6": lambda s: s.startswith("  6. Subprocess/run timeout"),
        "section 3.1 Q-11 port-map row": lambda s: s.startswith(
            "| **Ship-finance / AR-AP / bank journal DBFs (Q-11, F-31)**"
        ),
    }
    for label, pred in anchors.items():
        hits = find(lines, pred)
        if len(hits) != 1:
            bad.append(f"criterion 3: {label}: expected exactly 1 line, found {len(hits)}")
            continue
        line = lines[hits[0]]
        n = line.count("18-table")
        if n != 1:
            bad.append(f"criterion 3: {label}: expected 1 '18-table' statement, found {n}")
        if label != "section 1.4 sync bullet" and "§1.2b" not in line:
            bad.append(f"criterion 3: {label}: does not reference §1.2b")

    for lineno, s in enumerate(lines, 1):
        for sent in set(SENTENCE.findall(s)):
            if s.count(sent) > 1:
                bad.append(f"criterion 3: repeated sentence on line {lineno}: {sent[:60]!r}")


def check_tables(lines: list[str], bad: list[str]) -> None:
    """Criterion 4."""
    tables: list[list[str]] = []
    cur: list[str] = []
    for s in lines:
        if s.startswith("|"):
            cur.append(s)
        elif cur:
            tables.append(cur)
            cur = []
    if cur:
        tables.append(cur)

    for t in tables:
        counts = {r.count("|") for r in t}
        if len(counts) > 1:
            bad.append(f"criterion 4: uneven table starting {t[0][:50]!r}: pipe counts {sorted(counts)}")
    print(f"  markdown tables: {len(tables)}")


def section_text(lines: list[str], prefix: str) -> str | None:
    """Text of the section starting at `prefix` up to the next same-or-higher heading."""
    start = next((i for i, s in enumerate(lines) if s.startswith(prefix)), None)
    if start is None:
        return None
    depth = len(prefix) - len(prefix.lstrip('#'))
    body = [lines[start]]
    for s in lines[start + 1:]:
        if s.startswith('#') and (len(s) - len(s.lstrip('#'))) <= depth:
            break
        body.append(s)
    return "\n".join(body)


def vestigial_paragraph(lines: list[str]) -> str | None:
    """The §1.2b paragraph that marks the empty-in-all-companies tables."""
    start = next((i for i, s in enumerate(lines) if s.startswith("**Vestigial")), None)
    if start is None:
        return None
    body = [lines[start]]
    for s in lines[start + 1:]:
        if not s.strip():
            break
        body.append(s)
    return "\n".join(body)


def tables_by_rows(inv: dict) -> dict[str, int]:
    """Max record count per table across the scanned companies."""
    out: dict[str, int] = {}
    for company in inv.get("companies", {}).values():
        for name, entry in company.get("tables", {}).items():
            out[name] = max(out.get(name, 0), entry.get("records") or 0)
    return out


def names_in(text: str, known: set[str]) -> set[str]:
    """Backticked table names in `text`, restricted to `known` — so field names and paths
    (`KD_CITY`, `dbf_inventory.py`) cannot be mistaken for tables."""
    found = {m.group(1).upper()
             for m in re.finditer(r"`([A-Za-z][A-Za-z0-9_]*)(?:\.DBF)?`", text)}
    return {n for n in found if n in known}


def enclosing_sentence(text: str, name: str) -> str:
    """The clause around a backticked table name.

    Boundaries are `. ` OR a line break: a bullet's line ending is not a `. `, so without the
    newline the quoted clause would run back into the previous paragraph and mislead the reader
    about which sentence the tool is complaining about.
    """
    m = re.search(r"`" + re.escape(name) + r"(?:\.DBF)?`", text)
    if not m:
        return ""
    starts = [i for i in (text.rfind(". ", 0, m.start()) + 2 if text.rfind(". ", 0, m.start()) != -1 else None,
                          text.rfind("\n", 0, m.start()) + 1 if text.rfind("\n", 0, m.start()) != -1 else None)
              if i is not None]
    ends = [i for i in (text.find(". ", m.end()) if text.find(". ", m.end()) != -1 else None,
                        text.find("\n", m.end()) if text.find("\n", m.end()) != -1 else None)
            if i is not None]
    start = max(starts) if starts else 0
    end = min(ends) if ends else len(text)
    return text[start:end].replace("\n", " ")


def check_data_scope(lines: list[str], bad: list[str]) -> None:
    """Criteria 6-8: the recorded facts must match the measured inventory."""
    text = "\n".join(lines)

    # 6. Measured figures present; stale estimates only inside a correction statement.
    for want in ("110 DBF files", "148–151", "151 names, 148 common", "SBY 112"):
        if want not in text:
            bad.append(f"criterion 6: measured figure missing: {want!r}")
    # Any approximate count-shaped figure ("~87 DBF files", "~90 files") must sit inside a
    # correction statement. Scoped to count words so a "~500ms" performance figure is unaffected.
    for m in re.finditer(r"~\s?[0-9]+(?:\s+\w+){0,2}", text):
        tail = text[m.end():m.end() + 25]
        head = text[m.start():m.end()]
        if not re.search(r"DBF|dbf|file|table|row", head + tail, re.I):
            continue
        window = text[max(0, m.start() - 140):m.end() + 140]
        if not re.search(r"wrong|replac|estimate", window, re.I):
            bad.append(f"criterion 6: approximate count {head.strip()!r} stated without a correction")

    # 7. AR_R may only appear inside a clause that says it does not exist.
    for m in re.finditer(r"\bAR_R\b", text):
        window = text[max(0, m.start() - 100):m.end() + 100].replace("\n", " ")
        if not re.search(r"not present|no longer|removed|absent", window, re.I):
            bad.append(f"criterion 7: AR_R named without an absence statement — ...{window.strip()[:120]}...")

    # 8. §1.2b must name the report-bearing tables and mark the vestigial ones.
    inv_path = REPO / "docs" / "report-dbf-coverage" / "inventory.json"
    if not inv_path.exists():
        bad.append("criterion 8: docs/report-dbf-coverage/inventory.json missing (evidence artifact)")
        return
    inv = json.loads(inv_path.read_text(encoding="utf-8"))
    body = section_text(lines, "### 1.2b ")
    if body is None:
        bad.append("criterion 8: §1.2b section not found")
        return
    required = ["SAGING", "TERIMA", "TERIMA_R", "SOA", "SOA_D", "MANIFEST", "COST", "RC",
                "RC_D", "RCREQ", "RCREQ_D", "VENDOR_D", "CARGO_R", "NA_R", "NA_D",
                "QUOTATION", "QUOTATION_R", "SALDO_VENDOR", "SALDO_AGENT"]
    for name in required:
        if not re.search(r"\b" + re.escape(name) + r"\b", body):
            bad.append(f"criterion 8: §1.2b does not name {name}")
    # The vestigial paragraph is the one that literally starts "**Vestigial" — the names must
    # appear THERE (not just anywhere in §1.2b), otherwise the marking is not a marking.
    vestigial = vestigial_paragraph(lines)
    if vestigial is None:
        bad.append("criterion 8: §1.2b has no '**Vestigial' paragraph")
        return
    empties = inv.get("empty_in_all", [])
    for name in empties:
        if not re.search(r"\b" + re.escape(name) + r"\b", vestigial):
            bad.append(f"criterion 8: vestigial paragraph does not list {name}")

    # ...and the reverse: the paragraph must not call a populated table vestigial.
    populated = tables_by_rows(inv)
    known = set(populated)
    for name in names_in(vestigial, known):
        if populated.get(name, 0) > 0:
            bad.append(f"criterion 8: vestigial paragraph calls populated table {name} vestigial")
    print(f"  vestigial tables checked in the '**Vestigial' paragraph: {len(empties)}")

    # criterion 7b (reverse of 8): a table presented as report-bearing must actually hold rows.
    body_12b = section_text(lines, "### 1.2b ")
    if body_12b is None:
        return
    for name in names_in(body_12b, known):
        if populated.get(name, 0) != 0:
            continue
        sentence = enclosing_sentence(body_12b, name)
        if not re.search(r"empty|vestigial|no rows", sentence, re.I):
            bad.append(f"criterion 7b: `{name}` shown as report-bearing but is empty in all five "
                       f"companies (no emptiness marker in: {sentence.strip()[:80]!r})")
    print(f"  table names cross-checked for data-bearing claims: {len(names_in(body_12b, known))}")


def check_report_inventory(lines: list[str], bad: list[str]) -> None:
    """Criteria 9-14: report inventory (F-22..F-32, §3.2.x, Q-09)."""
    text = "\n".join(lines)
    # 9. F-22..F-32 rows present
    for n in range(22, 33):
        if f"| F-{n} |" not in text:
            bad.append(f"criterion 9: F-{n} row missing from PRD")
    # 10. §3.2.1..§3.2.9 subsections
    for n in range(1, 10):
        if not any(s.startswith(f"#### 3.2.{n}") for s in lines):
            bad.append(f"criterion 10: §3.2.{n} subsection missing")
    # 11. Q-09 row
    if not any(s.startswith("| Q-09 |") for s in lines):
        bad.append("criterion 11: Q-09 row missing")
    # 12. view-layer product name consistent: Filament appears in §1.2, §3.2, Q-09
    sec12 = section_text(lines, "### 1.2 ")
    sec32 = section_text(lines, "### 3.2 ")
    q09 = [s for s in lines if s.startswith("| Q-09 |")]
    for where, body in (("§1.2", sec12), ("§3.2", sec32)):
        if body is None or "Filament" not in body:
            bad.append(f"criterion 12: 'Filament' missing in {where}")
    if not q09 or "Filament" not in q09[0]:
        bad.append("criterion 12: 'Filament' missing in Q-09 row")
    # 14. §3.2.9 four labelled rows present (restricted to the subsection so the same
    #     labels referenced elsewhere in the PRD don't mask a deletion here).
    sec329 = section_text(lines, "#### 3.2.9 ")
    if sec329 is None:
        bad.append("criterion 14: §3.2.9 subsection missing")
    else:
        for label in ("Volume", "Lifting", "Sales", "Gross Profit & Loss"):
            if "| " + label + " |" not in sec329:
                bad.append(f"criterion 14: §3.2.9 row '{label}' missing")
    ev_path = REPO / "docs" / "report-dbf-coverage" / "legacy-evidence.json"
    if not ev_path.exists():
        bad.append("criterion 13: legacy-evidence.json missing (cannot verify)")
        return
    ev = json.loads(ev_path.read_text(encoding="utf-8"))
    gaps = ev.get("menu_gaps_vs_prd_3_2", {})
    unmatched = gaps.get("unmatched_candidates", [])
    if unmatched:
        for u in unmatched:
            bad.append(f"criterion 13: unmatched report label: {u.get('label')}")


def main() -> int:
    if not PRD.exists():
        print(f"FAIL: {PRD} not found", file=sys.stderr)
        return 1

    lines = load_lines()
    bad: list[str] = []
    check_sections(lines, bad)
    check_scope_statements(lines, bad)
    check_tables(lines, bad)
    check_data_scope(lines, bad)
    check_report_inventory(lines, bad)

    print(f"  PRD.md lines: {len(lines)}")
    if bad:
        print("PRD scope check FAILED")
        for b in bad:
            print(f"  - {b}")
        return 1
    print("PRD scope check OK")
    return 0


if __name__ == "__main__":
    sys.exit(main())
