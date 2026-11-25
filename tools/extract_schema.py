"""Scan all DBF files, emit YAML with tables, columns, and inferred relations.

Drop-in replacement for the old version that used the `dbf` library, `pyyaml`,
and hardcoded Windows paths (T045). Uses `dbfread` (already a project
dependency) and env-driven paths.

Usage:
    DBF_DIR=DBF SCHEMA_OUT=schema.yaml python tools/extract_schema.py

Environment:
    DBF_DIR     directory containing the .DBF files (default: <repo>/DBF)
    SCHEMA_OUT  output YAML path (default: <repo>/schema.yaml)
"""
import json
import os
from collections import defaultdict
from pathlib import Path

from dbfread import DBF

TYPE_MAP = {"C": "TEXT", "N": "REAL", "D": "DATE", "L": "BOOLEAN", "M": "MEMO", "I": "INTEGER"}

# Paths are env-driven; defaults resolve relative to the repo root so the
# script works from anywhere (no hardcoded D:/... paths).
_REPO = Path(__file__).resolve().parents[1]
DBF_DIR = Path(os.environ.get("DBF_DIR", str(_REPO / "DBF")))
OUT = Path(os.environ.get("SCHEMA_OUT", str(_REPO / "schema.json")))

# Not a data table — skipped by the original scan, kept for output parity.
SKIP_TABLES = {"CLIENTCOMP"}


def field_spec(field):
    """dbfread Field -> {name, type, size, dec} in schema.yaml's format.

    dbfread reports physical lengths (DATE=8, LOGICAL=1, MEMO=10); the original
    scan emitted 0 for those, so we normalize to keep the output stable.
    """
    if field.type == "N":
        return {
            "name": field.name,
            "type": "INTEGER" if field.decimal_count == 0 else "REAL",
            "size": field.length,
            "dec": field.decimal_count,
        }
    return {
        "name": field.name,
        "type": TYPE_MAP.get(field.type, "TEXT"),
        "size": field.length if field.type in ("C", "I", "F") else 0,
        "dec": 0,
    }


def read_table(path):
    """Read one DBF file, trying encodings like tools/sync_db.py.

    Returns (columns, nrows) where columns is a list of field_spec dicts.
    """
    for encoding in ("latin-1", "cp437", "utf-8"):
        try:
            table = DBF(
                str(path),
                encoding=encoding,
                ignore_missing_memofile=True,
                char_decode_errors="ignore",
            )
            # `_NullFlags` is a synthetic dbfread field (DBF null-flags byte);
            # it is not a real column and was never in the original scan.
            cols = [field_spec(f) for f in table.fields if f.name != "_NullFlags"]
            nrows = len(table)
            return cols, nrows
        except Exception:
            continue
    raise RuntimeError(f"unreadable with any encoding: {path.name}")





def main():
    if not DBF_DIR.exists():
        raise SystemExit(f"Error: DBF directory not found at {DBF_DIR}")

    tables = {}  # name -> {"columns": [...], "rows": int, "path": str}
    for path in sorted(DBF_DIR.iterdir()):
        if not path.name.lower().endswith(".dbf"):
            continue
        name = path.stem.upper()
        if name in SKIP_TABLES:
            print(f"SKIP {path.name} (not a data table)")
            continue
        try:
            cols, nrows = read_table(path)
            tables[name] = {"columns": cols, "rows": nrows, "path": path.name}
            print(f"  - {name}: {nrows} rows, {len(cols)} fields")
        except Exception as e:
            print(f"SKIP {path.name}: {e}")

    # Infer relations: a column name shared by >= 2 tables is a candidate FK.
    # The table with the fewest columns (then fewest rows) is the "1" side.
    column_owners = defaultdict(list)  # col_name -> [table_name]
    for tname, info in tables.items():
        for col in info["columns"]:
            column_owners[col["name"]].append(tname)

    relations = []
    for col, owners in column_owners.items():
        if len(owners) < 2:
            continue
        sorted_owners = sorted(owners, key=lambda t: (len(tables[t]["columns"]), tables[t]["rows"]))
        pk_table = sorted_owners[0]
        for fk_table in sorted_owners[1:]:
            relations.append({
                "from": f"{fk_table}.{col}",
                "to": f"{pk_table}.{col}",
                "cardinality": "many-to-one",
                "inferred_by": "column_name_match",
            })

    out = {
        "version": 1,
        "source": "DBF header scan",
        "tables": [
            {
                "name": tname,
                "source_file": info["path"],
                "row_count": info["rows"],
                "columns": info["columns"],
            }
            for tname, info in sorted(tables.items())
        ],
        "relations": relations,
    }

    OUT.parent.mkdir(parents=True, exist_ok=True)
    OUT.write_text(json.dumps(out, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"Wrote {OUT}")
    print(f"Tables: {len(tables)}, Relations: {len(relations)}")


if __name__ == "__main__":
    main()
