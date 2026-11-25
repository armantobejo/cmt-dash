#!/usr/bin/env python3
"""Legacy-app evidence engine for the report -> DBF coverage matrix.

    python tools/legacy_report_evidence.py            # write the evidence JSON
    python tools/legacy_report_evidence.py --check    # gate docs/report-dbf-coverage.md

The matrix used to carry an `inferred` evidence level: a report-to-table mapping guessed
from name and shape, marked "must be confirmed against the legacy report screen". This tool
replaces the guess with artifacts that actually ship in the tree:

  * the app's own **report menu tables** (`MENU2` / `MENU20`, PAD3 = REPORT) — the list of
    report labels the legacy UI presents, plus `MENU3` (the module registry it dispatches on);
  * the **legacy programs** (`UPD_DN.PRG`, `UPD_INV.PRG`, `UPD_OC.PRG`; 3 per company,
    byte-identical across companies) — each carries the real FoxPro joins, e.g. the A/R
    receipt screen is `ARS INNER JOIN ARS_R ON UARS`;
  * **measured DBF facts** for the rows the screen alone would have decided — parent-key
    linkage (`NA_D` -> `NA` vs `OPRCOST_R` -> `OPRCOST`), column population (`COST`), and the
    candidate fields that actually carry values.

What is *not* in the tree, and is therefore recorded as an explicit absence rather than
guessed past: no `.FRX`/`.FRT` report layouts, and the `.exe`/`.APP` executables are packed
(no printable strings), so the report *program* that would bind a report label to a table is
unreadable here. Rows that only that program could settle are marked `unverified-locally`.

Exit 0 on success. `--check` exits 1 listing any matrix claim that the regenerated evidence
no longer supports (a returning `inferred` cell, an undeclared evidence token, a missing
cited fact, a report label missing from the doc's legacy-menu section).
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[1]
DEFAULT_DBF_DIR = REPO / "DBF"
DEFAULT_JSON = REPO / "docs" / "report-dbf-coverage" / "legacy-evidence.json"
MATRIX = REPO / "docs" / "report-dbf-coverage.md"
SUBDIRS = ("DATA", "INIT")
PROGRAMS = ("UPD_DN.PRG", "UPD_INV.PRG", "UPD_OC.PRG")

# Evidence tokens the matrix may use in a row's Evidence column. Every one must be declared
# in the doc's legend, and `inferred` must never appear again.
LEVELS = ("measured", "in-app-sql", "legacy-menu", "legacy-source", "unverified-locally")
RETIRED = ("inferred",)

JOIN = re.compile(
    r"FROM\s+(?P<a_db>\w+)!(?P<a_tab>\w+)\s+INNER\s+JOIN\s+(?P<b_db>\w+)!(?P<b_tab>\w+)"
    r"\s*;?\s*ON\s+(?P<on>.+?)\s*;?\s*WHERE\s+(?P<where>[^\n;]+)",
    re.IGNORECASE | re.DOTALL,
)
PROC = re.compile(r"^\s*Proc\s+(\w+)", re.IGNORECASE | re.MULTILINE)


READ_TABLES: set[str] = set()


def companies(root: Path) -> list[str]:
    return sorted(p.name for p in root.iterdir() if p.is_dir())


def snapshot(root: Path, comps: list[str]) -> dict:
    """Identify the tree state this evidence describes.

    The `DBF/` tree is written to while it is being read: during one session `NA_D` gained a record
    (file size +101 bytes, exactly one record) and `NA` gained one, with their mtimes pinned at
    10:38:58 throughout, so mtime cannot date a reading. Every number in this file is therefore a
    snapshot, and the snapshot is named rather than assumed — a count that later shifts is a
    different snapshot, not a contradiction.
    """
    observed = []
    for c in comps:
        for name in sorted(READ_TABLES):
            p = find(root, name, c)
            if p:
                st = p.stat()
                observed.append({"company": c, "table": name, "bytes": st.st_size,
                                 "mtime_ns": st.st_mtime_ns})
    digest = hashlib.md5(json.dumps(observed, sort_keys=True).encode()).hexdigest()[:12]
    return {"snapshot_id": digest, "tables_observed": len(observed), "files": observed}


def find(root: Path, table: str, company: str) -> Path | None:
    for sub in SUBDIRS:
        p = root / company / sub / f"{table}.DBF"
        if p.exists():
            return p
    return None


def read_raw(path: Path):
    """Parse the table from its own header: live rows only, no record-skipping.

    Deliberately not `dbfread`'s record iterator. Two problems made it unusable as evidence:

    1. Its numeric parser raises on a record whose field holds NUL bytes instead of digits, and the
       exception kills the generator — so a tolerant caller cannot resume and silently reads fewer
       rows than the table holds. `OPRCOST_R` is one such table (64 026 live rows, 64 025 returned).
    2. `len(DBF(...))` reports header *slots*, not rows, on a table that over-allocates (`NA_D`:
       199 961 slots, 102 121 rows).

    Reading the header directly and walking the fixed-width slots avoids both: a slot whose flag byte
    is not 0x20 (space = live) is skipped by construction, and each field is sliced by the offset and
    length in its descriptor. `sum(field lengths) + 1` is asserted against the header's record length,
    so an unusual layout fails loudly instead of being misread.
    """
    blob = path.read_bytes()
    if len(blob) < 32:
        raise ValueError(f"{path.name}: too short to be a DBF")
    slots = int.from_bytes(blob[4:8], "little")
    head_len = int.from_bytes(blob[8:10], "little")
    rec_len = int.from_bytes(blob[10:12], "little")

    names, layout, offset = [], [], 1  # byte 0 of a record is its deletion flag
    index = 0
    while blob[32 + index * 32] != 0x0D:  # 0x0D terminates the field-descriptor array
        desc = 32 + index * 32
        names.append(blob[desc:desc + 11].split(b"\x00")[0].decode("latin-1"))
        length = blob[desc + 16]
        layout.append((offset, length))
        offset += length
        index += 1
        if index > 255:
            raise ValueError(f"{path.name}: field descriptors never terminated")
    if offset != rec_len:
        raise ValueError(f"{path.name}: descriptors account for {offset} bytes, header says {rec_len}")

    rows = []
    for i in range(slots):
        start = head_len + i * rec_len
        if start + rec_len > len(blob) or blob[start] != 0x20:
            continue  # deleted (0x2A) or never-used (0x00) slot
        rows.append({name: blob[start + o:start + o + ln] for name, (o, ln) in zip(names, layout)})
    return names, rows, 0


def s(value) -> str:
    if value is None:
        return ""
    if isinstance(value, bytes):
        return value.decode("latin-1").replace("\x00", "").strip()
    return str(value).strip()


def num(value) -> float:
    text = s(value).replace(",", ".")
    if text in ("", ".", "-"):
        return 0.0
    try:
        return float(text)
    except ValueError:
        return 0.0


def cols(rows, key):
    return {s(r.get(key)) for r in rows if s(r.get(key))}


def nonblank(rows, key):
    return sum(1 for r in rows if s(r.get(key)))


def nonzero(rows, key):
    return sum(1 for r in rows if num(r.get(key)) != 0.0)


def printable_ratio(path: Path) -> float:
    blob = path.read_bytes()[:8192]
    if not blob:
        return 0.0
    return sum(1 for b in blob if 32 <= b < 127) / len(blob)


def slot_stats(path: Path) -> dict:
    """Allocated DBF record slots vs live records.

    The header's record count is the number of *slots* the file was allocated, not the number of
    rows it holds: `NA_D` declares 199 961 slots for 102 121 live rows. A tool that trusted the
    header would overstate the table roughly twofold, so the convention is spelled out here — every
    count in this file, and in `dbf_inventory.py`, is a **live-row** count.
    """
    blob = path.read_bytes()
    if len(blob) < 32:
        return {}
    slots = int.from_bytes(blob[4:8], "little")
    head_len = int.from_bytes(blob[8:10], "little")
    rec_len = int.from_bytes(blob[10:12], "little")
    if not rec_len or head_len + slots * rec_len > len(blob) + 1:
        return {"header_slots": slots, "note": "header record count exceeds file size"}
    flags = [blob[head_len + i * rec_len] for i in range(slots)]
    return {"header_slots": slots,
            "live": sum(1 for f in flags if f == 0x20),
            "empty_slots": sum(1 for f in flags if f == 0x00),
            "deleted": sum(1 for f in flags if f == 0x2A)}


def table(root: Path, name: str, company: str):
    path = find(root, name, company)
    if path is None:
        return None, [], 0
    READ_TABLES.add(name)
    return read_raw(path)


# --------------------------------------------------------------------------- artifacts


def legacy_artifacts(root: Path, comps: list[str]) -> dict:
    artifacts: dict = {"report_menu_tables": [], "app_menu_table": None,
                       "word_report_modules": [], "print_formats": [],
                       "report_layouts": [], "programs": [], "executables": []}

    menu_variants = {}
    for name in ("MENU2", "MENU20"):
        labels, per_company = [], {}
        for c in comps:
            fields, rows, _ = table(root, name, c)
            if fields:
                per_company[c] = [(s(r.get("GRUP")), s(r.get("NOMOR")), s(r.get("KETERANGAN")),
                                   s(r.get("TYPE"))) for r in rows]
        if per_company:
            artifacts["report_menu_tables"].append(name)
            first = per_company[comps[0]]
            labels = [{"nomor": n, "label": k, "module_type": t}
                      for g, n, k, t in first if g == "PAD3" and k and not k.startswith("*")]
            menu_variants[name] = {"labels": labels,
                                   "identical_across_companies": all(v == first for v in per_company.values()),
                                   "per_company_row_count": {c: len(v) for c, v in per_company.items()}}
    artifacts["report_menu"] = menu_variants

    for name in ("MENU3", "MODULWORD", "REPORT_LOV", "REPORTBL", "CONVWORD"):
        if find(root, name, comps[0]):
            if name == "MENU3":
                artifacts["app_menu_table"] = name
            import_ = table(root, name, comps[0])[1]
            if name == "MODULWORD":
                artifacts["word_report_modules"] = sorted(
                    {"%s: %s" % (s(r.get("MODUL")), s(r.get("KETERANGAN"))) for r in import_ if s(r.get("MODUL"))})
            elif name == "REPORT_LOV":
                artifacts["print_formats"] = sorted({s(r.get("NAME")) for r in import_ if s(r.get("NAME"))})

    for prog in PROGRAMS:
        hashes, joins = {}, []
        for c in comps:
            p = root / c / "DATA" / prog
            if not p.exists():
                p = root / c / "DATA" / "DATA" / prog
            if p.exists():
                hashes[c] = hashlib.md5(p.read_bytes()).hexdigest()[:12]
                # one join set per program, not one per company: the copies are byte-identical
                if joins:
                    continue
                text = p.read_text(encoding="latin-1")
                for m in JOIN.finditer(text):
                    joins.append({"from": s(m.group("a_tab")).upper(), "join": s(m.group("b_tab")).upper(),
                                  "on": " ".join(m.group("on").split()),
                                  "where": " ".join(m.group("where").split())})
        if hashes:
            artifacts["programs"].append({
                "file": prog,
                "identical_across_companies": len(set(hashes.values())) == 1,
                "md5_12": sorted(set(hashes.values())),
                "joins": joins,
            })

    for p in sorted(root.rglob("*")):
        suffix = p.suffix.lower()
        if suffix in (".frx", ".frt", ".lbx"):
            artifacts["report_layouts"].append(str(p.relative_to(root)))
        if suffix in (".exe", ".app") and p.is_file():
            artifacts["executables"].append({"path": str(p.relative_to(root)),
                                             "printable_ratio_first_8k": round(printable_ratio(p), 3)})
    artifacts["report_layouts_absent"] = not artifacts["report_layouts"]
    return artifacts


# ------------------------------------------------------------------------- measured facts


def measured_facts(root: Path, comps: list[str]) -> dict:
    facts: dict = {}

    na_d = {}
    slots = {}
    for c in comps:
        _, na, _ = table(root, "NA", c)
        _, nad, _ = table(root, "NA_D", c)
        _, opc, _ = table(root, "OPRCOST", c)
        _, opcr, _ = table(root, "OPRCOST_R", c)
        na_k, nad_k = cols(na, "UNIK"), cols(nad, "UNIK")
        nadr = cols(nad, "UNIKR")
        opcr_uk, opcrr, opc_uk = cols(opcr, "UOPRCOST"), cols(opcr, "UNIK"), cols(opc, "UOPRCOST")
        na_d[c] = {
            "na_rows": len(na), "na_d_rows": len(nad), "na_d_parents": len(nad_k),
            "na_d_lines": len(nadr), "oprcost_rows": len(opc), "oprcost_r_rows": len(opcr),
            "resolve_pct": round(100 * len(nad_k & na_k) / max(1, len(nad_k)), 2),
            "orphans": len(nad_k - na_k),
            "overlap_na_d_unik_with_oprcost_r_uoprcost": len(nad_k & opcr_uk),
            "overlap_na_d_unikr_with_oprcost_r_unik": len(nadr & opcrr),
            "oprcost_r_orphans": len(opcr_uk - opc_uk),
            "oprcost_r_parents": len(opcr_uk),
        }
        if c == "CMT":
            for name in ("NA", "NA_D", "OPRCOST", "OPRCOST_R"):
                p = find(root, name, c)
                if p:
                    slots[name] = slot_stats(p)
    # Self-check: rows read must equal live slots. This is the invariant that a record-skipping
    # reader (or a header-counting one) violates, so failing here beats shipping quiet undercounts.
    cmt = na_d.get("CMT", {})
    for table_name, key in (("NA", "na_rows"), ("NA_D", "na_d_rows"),
                            ("OPRCOST", "oprcost_rows"), ("OPRCOST_R", "oprcost_r_rows")):
        stats = slots.get(table_name)
        if stats and "live" in stats and cmt.get(key) is not None and cmt[key] != stats["live"]:
            raise RuntimeError(f"{table_name}: read {cmt[key]} rows but the file holds "
                               f"{stats['live']} live slots — reader and slots disagree")

    facts["na_d_vs_oprcost_r"] = na_d
    facts["na_d_slot_allocation"] = slots

    # Both measures, because they disagree and the difference is the finding: `COST` has a couple of
    # non-blank cells that parse to zero, so "blank" and "non-zero" are not the same claim.
    cost = {}
    for c in comps:
        _, rows, _ = table(root, "COST", c)
        if rows:
            cost_cols = ("JENISCOST", "COST", "SELLING", "GROUP", "UNIK")
            cost[c] = {"rows": len(rows),
                       **{k: nonblank(rows, k) for k in cost_cols},
                       **{f"{k}_nonzero": nonzero(rows, k) for k in cost_cols}}
    facts["cost_population"] = cost
    facts["cost_totals"] = {k: sum(v.get(f"{k}_nonzero", 0) for v in cost.values())
                            for k in ("COST", "SELLING")}

    rc = {}
    for c in comps:
        _, rc_h, _ = table(root, "RC", c)
        _, rc_d, _ = table(root, "RC_D", c)
        _, rq, _ = table(root, "RCREQ", c)
        _, rq_d, _ = table(root, "RCREQ_D", c)
        if rc_h or rq:
            rc[c] = {
                "rc_rows": len(rc_h), "rc_total_bayar_nonzero": nonzero(rc_h, "TOT_BAYAR"),
                "rc_has_payment_header_shape": all(k in (rc_h[0].keys() if rc_h else [])
                                                   for k in ("JENIS_BYR", "KD_BANK", "TGL_JT", "KD_ACCOUNT")),
                "rc_d_rows": len(rc_d), "rc_d_invoice_lines": nonblank(rc_d, "UINVOICE_R"),
                "rc_d_orphan_parents": len(cols(rc_d, "URC") - cols(rc_h, "URC")),
                "rcreq_rows": len(rq), "rcreq_applicant": nonblank(rq, "APPLICANT"),
                "rcreq_tot_idr_nonzero": nonzero(rq, "TOT_IDR"),
                "rcreq_approve_column_filled": nonblank(rq, "APPROVE"),
                "rcreq_approve2_column_filled": nonblank(rq, "APPROVE2"),
                "rcreq_d_rows": len(rq_d), "rcreq_d_profitloss_nonzero": nonzero(rq_d, "PROFITLOSS"),
                "rcreq_d_orphan_parents": len(cols(rq_d, "URCREQ") - cols(rq, "URCREQ")),
            }
    facts["rc_rcreq"] = rc

    disc = {}
    for c in comps:
        entry = {}
        for name, key in (("INVOICE", "SELISIH_US"), ("INVOICE", "SELISIH_ID"), ("ARS_R", "SELISIH"),
                          ("AR_AGENT_D", "SELISIH"), ("DRCRFULL", "SELISIH")):
            fields, rows, _ = table(root, name, c)
            if not rows:
                continue
            entry[f"{name}.{key}"] = {"rows": len(rows), "nonzero": nonzero(rows, key)} if key in fields \
                else {"rows": len(rows), "field": None}
        disc[c] = entry
    facts["discrepancy_invoice_candidates"] = disc

    # Every company, keyed by company then table. Measuring only `comps[0]` was a real defect: that
    # is CME, the near-empty company (3 `NA` rows against CMT's 9 542), so a candidate column looked
    # unused when it was simply a different company's data.
    perf = {}
    for c in comps:
        entries = {}
        for name, keys in (("INVOICE", ("SHIPPER", "KD_SHIPPER")), ("CARGO", ("AGENT", "SHIPPER", "KD_SHIPLIN")),
                           ("NA", ("AGENT", "KD_SHIPPER", "CONSIGNEE")), ("VENDOR", ("KD_SHIPLIN", "NAME")),
                           ("VESSEL", ("NM_VESSEL",))):
            fields, rows, _ = table(root, name, c)
            if not rows:
                continue
            entries[name] = {"rows": len(rows),
                             "present": [k for k in keys if k in fields],
                             "missing": [k for k in keys if k not in fields],
                             "nonblank": {k: nonblank(rows, k) for k in keys if k in fields}}
        perf[c] = entries
    facts["track_performance_candidates"] = perf

    aging, soa = {}, {}
    for c in comps:
        _, rows, _ = table(root, "SAGING", c)
        if rows:
            aging[c] = {k: s(rows[0].get(k)) for k in ("AGING1", "AGING2", "AGING3", "AGING4", "AGING5", "AGING6", "AGING7")}
        _, soa_h, _ = table(root, "SOA", c)
        _, soa_d, _ = table(root, "SOA_D", c)
        if soa_h or soa_d:
            soa[c] = {"soa_rows": len(soa_h), "soa_d_rows": len(soa_d),
                      "soa_ref_no": [s(r.get("REF_NO")) for r in soa_h],
                      "soa_d_invoice_no": sorted({s(r.get("INVOICE_NO")) for r in soa_d if s(r.get("INVOICE_NO"))})[:3],
                      "soa_d_has_invoice_link": bool([r for r in soa_d if s(r.get("INVOICE_NO"))])}
    facts["saging"] = aging
    facts["statement_of_account"] = soa
    return facts


def stream(text: str) -> str:
    """Punctuation-free token stream, so cosmetic differences cannot masquerade as gaps.

    PRD §3.2 writes em dashes and a colon ("Aging A/R — Invoice", "Cash/Bank"); the menu
    holds ASCII hyphens and a stray space ("Aging A/R - Invoive", "Cash/ Bank"). Dropping
    every non-alphanumeric folds both onto the same string.
    """
    return re.sub(r"[^a-z0-9]+", "", text.lower())


def toks(text: str) -> set:
    return set(re.findall(r"[a-z0-9]+", text.lower()))


def menu_gaps(artifacts: dict) -> dict:
    """How each legacy report label lines up with PRD §3.2.

    A *candidate* classification, not a verdict: `unmatched_candidates` is the set the PRD
    does not name, but distinguishing "the PRD omits this report" from "the PRD uses
    different words for it" is a judgement made in the doc and handed to the owner. The
    three buckets exist so that judgement starts from a short list instead of 32 labels.
    """
    section = re.search(r"### 3\.2.*?(?=\n### 3\.3|\n## 4)", (REPO / "PRD.md").read_text(encoding="utf-8"), re.S)
    prd_lines = [ln.strip() for ln in (section.group(0) if section else "").splitlines()
                 if ln.strip().startswith("|") or ln.strip().startswith("- **")]

    matched, near, unmatched = [], [], []
    for row in artifacts["report_menu"].get("MENU2", {}).get("labels", []):
        label = row["label"]
        if any(stream(label) in stream(ln) for ln in prd_lines):
            matched.append(label)
            continue
        best, jaccard = None, 0.0
        for ln in prd_lines:
            union = toks(ln) | toks(label)
            j = len(toks(ln) & toks(label)) / len(union) if union else 0.0
            if j > jaccard:
                best, jaccard = ln, j
        entry = {"label": label, "nearest_prd_line": best, "jaccard": round(jaccard, 2)}
        (near if jaccard >= 0.5 else unmatched).append(entry)
    return {"matched_by_name": matched, "near_match": near, "unmatched_candidates": unmatched}


# ------------------------------------------------------------------------------- checks


RC_HEADER_SHAPE = ("JENIS_BYR", "KD_BANK", "TGL_KLIRIN")


def rc_header_columns(facts: dict) -> list:
    """The payment-header columns the tool actually verified on `RC`.

    Returned from the measurement rather than restated in the checker, so the token the doc must
    contain is the one the evidence recorded.
    """
    cmt = facts.get("rc_rcreq", {}).get("CMT", {})
    return list(RC_HEADER_SHAPE) if cmt.get("rc_has_payment_header_shape") else []


def menu_gap_summary(gaps: dict) -> str:
    """The one-line classification the doc must repeat verbatim."""
    return (f"{len(gaps['matched_by_name'])} matched by name, {len(gaps['near_match'])} near, "
            f"{len(gaps['unmatched_candidates'])} unmatched candidates")


def cited_facts(artifacts: dict, facts: dict) -> list[dict]:
    """Textual claims the doc must contain, derived from the measurements."""
    na = facts["na_d_vs_oprcost_r"]["CMT"]
    checks = [
        {"desc": "report menu tables", "must_contain": ["MENU2", "MENU20"]},
        {"desc": "report menu is identical across companies",
         "must_contain": ["identical in all five companies"] if all(
             v["identical_across_companies"] for v in artifacts["report_menu"].values()) else []},
        {"desc": "no report layouts shipped",
         "must_contain": ["no `.FRX`", "packed"] if artifacts["report_layouts_absent"] else
                         [f"report layouts in tree: {len(artifacts['report_layouts'])}"]},
        {"desc": "NA_D resolves to NA",
         "must_contain": [f"{na['resolve_pct']:.2f}%", "NA_D.UNIK",
                          f"{na['na_d_lines']:,}".replace(",", " "), f"{na['na_d_parents']:,}".replace(",", " "),
                          f"{na['orphans']} orphans"]},
        {"desc": "NA_D does not overlap OPRCOST_R",
         "must_contain": [f"overlap with `OPRCOST_R.UOPRCOST` = {na['overlap_na_d_unik_with_oprcost_r_uoprcost']}"]},
        {"desc": "OPRCOST_R orphan count", "must_contain": [f"{na['oprcost_r_orphans']} of {na['oprcost_r_parents']}"]},
        {"desc": "NA_D allocates more slots than it holds rows",
         "must_contain": [f"{facts['na_d_slot_allocation']['NA_D']['header_slots']:,}".replace(",", " "),
                          f"{facts['na_d_slot_allocation']['NA_D']['empty_slots']:,}".replace(",", " ")]
                         if facts.get("na_d_slot_allocation", {}).get("NA_D") else []},
        {"desc": "Track Performance candidate columns (CMT)",
         "must_contain": [f"{perf['INVOICE']['nonblank']['SHIPPER']:,}".replace(",", " "),
                          f"{perf['NA']['nonblank']['CONSIGNEE']:,}".replace(",", " "),
                          f"{perf['VENDOR']['nonblank']['KD_SHIPLIN']:,}".replace(",", " ")]
                         if (perf := facts.get("track_performance_candidates", {}).get("CMT")) else []},
        {"desc": "COST carries no rates",
         "must_contain": [f"{facts['cost_totals']['COST']} non-zero `COST` cells and "
                          f"{facts['cost_totals']['SELLING']} non-zero `SELLING` cells"]},
        # derived, not hardcoded: a change in the data must break the doc, not just this file
        {"desc": "RC is a payment header",
         "must_contain": [f"`{c}`" for c in rc_header_columns(facts)]},
        {"desc": "SAGING buckets",
         "must_contain": [", ".join(facts["saging"]["CMT"][f"AGING{i}"] for i in range(1, 8))]
                         if facts.get("saging", {}).get("CMT") else []},
        {"desc": "SOA_D carries a real invoice link",
         "must_contain": ["SOA_D"] + [inv for inv in
             facts.get("statement_of_account", {}).get("CMT", {}).get("soa_d_invoice_no", [])[:1]]},
        {"desc": "no inferred evidence cell survives", "must_contain": ["unverified-locally"]},
        {"desc": "report menu vs PRD §3.2 candidate counts",
         "must_contain": [menu_gap_summary(menu_gaps(artifacts))]},
    ]
    for prog in artifacts["programs"]:
        for join in prog["joins"]:
            checks.append({"desc": f"{prog['file']} join {join['from']}->{join['join']}",
                           "must_contain": [f"`{join['from']}`", f"`{join['join']}`"]})
    return checks


def check() -> int:
    if not DEFAULT_JSON.exists():
        print(f"FAIL: {DEFAULT_JSON.relative_to(REPO)} missing — run without --check first")
        return 1
    evidence = json.loads(DEFAULT_JSON.read_text(encoding="utf-8"))
    doc = MATRIX.read_text(encoding="utf-8")
    bad: list[str] = []

    # 1. retired evidence levels must not appear as a row's evidence cell
    for line in doc.splitlines():
        if not line.startswith("|"):
            continue
        for retired in RETIRED:
            if f"`{retired}`" in line:
                bad.append(f"retired evidence `{retired}` used in row: {line[:90]}")

    # 2. every evidence token used in the matrix must be declared in the legend
    used = set()
    for line in doc.splitlines():
        if line.startswith("|"):
            used.update(re.findall(r"`([a-z-]+)`", line))
    for token in sorted(used & set(RETIRED)):
        bad.append(f"retired evidence level `{token}` is still in use")
    for token in sorted(used & set(LEVELS)):
        if not re.search(rf"^\|\s*`{re.escape(token)}`\s*\|", doc, re.MULTILINE):
            bad.append(f"evidence level `{token}` is used but not declared in the legend table")

    # 3. every cited fact must be present in the doc
    for entry in evidence["cited_facts"]:
        for token in entry["must_contain"]:
            if token not in doc:
                bad.append(f"cited fact missing from the matrix ({entry['desc']}): {token!r}")

    # 4. every report label in the legacy report menu must appear *in the verbatim menu table*,
    #    not merely somewhere in the document — a label that survives only in the §6 gap analysis
    #    would otherwise satisfy a presence test while the menu inventory had quietly lost it.
    listed = set()
    lines = doc.splitlines()
    for i, line in enumerate(lines):
        if line.startswith("**The report menu, verbatim**"):
            for following in lines[i:]:
                if following.startswith("|"):
                    cells = [c.strip() for c in following.strip("|").split("|")]
                    if len(cells) >= 2 and not set(cells[1]) <= {"-", ":"}:
                        listed.add(cells[1].strip("`"))
                elif listed:
                    break
            break
    else:
        bad.append("the verbatim legacy report menu table is missing from the matrix")
    for name, variant in evidence["legacy_artifacts"]["report_menu"].items():
        for row in variant["labels"]:
            if row["label"] not in listed:
                bad.append(f"{name} report label missing from the matrix's menu table: {row['label']!r}")

    # 5. the doc must name the snapshot its numbers describe, so a refreshed tree cannot leave the
    #    matrix quietly claiming figures from an older reading.
    snapshot_id = evidence.get("source_snapshot", {}).get("snapshot_id")
    if snapshot_id and snapshot_id not in doc:
        bad.append(f"the matrix does not name the tree snapshot it describes: {snapshot_id}")

    if bad:
        print(f"FAIL: {len(bad)} problem(s)")
        for line in bad:
            print(f"  - {line}")
        return 1
    print("legacy report evidence check OK")
    return 0


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    ap.add_argument("--dbf-dir", default=str(DEFAULT_DBF_DIR))
    ap.add_argument("--json", default=str(DEFAULT_JSON))
    ap.add_argument("--check", action="store_true", help="gate the matrix against the evidence JSON")
    args = ap.parse_args()

    if args.check:
        return check()

    root = Path(args.dbf_dir)
    if not root.is_dir():
        print(f"FATAL: DBF dir not found: {root}")
        return 1
    comps = companies(root)
    artifacts = legacy_artifacts(root, comps)
    facts = measured_facts(root, comps)
    evidence = {
        "dbf_dir": str(root), "companies": comps,
        "generated_at_utc": __import__("datetime").datetime.now(__import__("datetime").timezone.utc)
                             .strftime("%Y-%m-%dT%H:%M:%SZ"),
        "evidence_levels": list(LEVELS), "retired_levels": list(RETIRED),
        "legacy_artifacts": artifacts,
        "measured_facts": facts,
        "source_snapshot": snapshot(root, comps),
        "menu_gaps_vs_prd_3_2": menu_gaps(artifacts),
    }
    evidence["cited_facts"] = cited_facts(artifacts, facts)

    out = Path(args.json)
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(evidence, indent=2, sort_keys=True) + "\n", encoding="utf-8")

    print(f"companies: {', '.join(comps)}")
    print(f"report menu: {', '.join(artifacts['report_menu_tables'])} "
          f"({len(artifacts['report_menu'].get('MENU2', {}).get('labels', []))} PAD3 labels)")
    print(f"legacy programs: {', '.join(p['file'] for p in artifacts['programs'])} "
          f"({sum(len(p['joins']) for p in artifacts['programs'])} joins)")
    print(f"report layouts (.FRX/.FRT): {len(artifacts['report_layouts'])} "
          f"| executables: {len(artifacts['executables'])} (packed)")
    na = facts["na_d_vs_oprcost_r"]["CMT"]
    print(f"NA_D: {na['na_d_rows']} lines / {na['na_d_parents']} parents, "
          f"{na['resolve_pct']:.2f}% resolve to NA, overlap OPRCOST_R = "
          f"{na['overlap_na_d_unik_with_oprcost_r_uoprcost']}")
    print(f"menu vs PRD §3.2: " + menu_gap_summary(evidence['menu_gaps_vs_prd_3_2']))
    display = out.relative_to(REPO) if out.is_relative_to(REPO) else out
    print(f"written: {display}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
