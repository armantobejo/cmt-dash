"""Compare a Laravel parity run against the captured Python baseline.

Usage:
    # 1. Capture the Python baseline (committed to the repo):
    pytest tests/parity/ -q

    # 2. In the Laravel rebuild, implement the same capture harness (the
    #    parity_dataset schema + the route list in test_parity_baseline.py
    #    are the port spec) and emit laravel.json with the same structure.

    # 3. Diff:
    python tools/compare_parity.py tests/parity/baseline/laravel.json
    python tools/compare_parity.py tests/parity/baseline/laravel.json --baseline other.json

Exit code 0 = parity (or differences only in waived paths); 1 = differences.
"""
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

DEFAULT_BASELINE = Path(__file__).resolve().parents[1] / "tests" / "parity" / "baseline" / "python.json"


def _diff(x, y, path: str, out: list[dict]) -> None:
    if type(x) is not type(y):
        out.append({"path": path, "kind": "type", "python": _short(x), "laravel": _short(y)})
        return
    if isinstance(x, dict):
        for k in sorted(set(x) | set(y)):
            kp = f"{path}/{k}"
            if k not in x:
                out.append({"path": kp, "kind": "missing_in_python", "laravel": _short(y[k])})
            elif k not in y:
                out.append({"path": kp, "kind": "missing_in_laravel", "python": _short(x[k])})
            else:
                _diff(x[k], y[k], kp, out)
    elif isinstance(x, list):
        if len(x) != len(y):
            out.append({"path": path, "kind": "length",
                        "python": len(x), "laravel": len(y)})
            return
        for i, (xi, yi) in enumerate(zip(x, y)):
            _diff(xi, yi, f"{path}[{i}]", out)
    elif x != y:
        out.append({"path": path, "kind": "value", "python": _short(x), "laravel": _short(y)})


def _short(v, n: int = 90):
    s = json.dumps(v, ensure_ascii=False) if not isinstance(v, str) else v
    return s if len(s) <= n else s[: n - 3] + "..."


def compare(baseline_path: Path, candidate_path: Path) -> list[dict]:
    base = json.loads(baseline_path.read_text(encoding="utf-8"))["domains"]
    cand = json.loads(candidate_path.read_text(encoding="utf-8"))["domains"]
    out: list[dict] = []
    for domain in sorted(set(base) | set(cand)):
        if domain not in base:
            out.append({"path": domain, "kind": "domain_missing_in_python"})
        elif domain not in cand:
            out.append({"path": domain, "kind": "domain_missing_in_laravel"})
        else:
            _diff(base[domain], cand[domain], domain, out)
    return out


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("candidate", type=Path, help="laravel.json produced by the Laravel harness")
    ap.add_argument("--baseline", type=Path, default=DEFAULT_BASELINE,
                    help=f"python.json baseline (default: {DEFAULT_BASELINE})")
    ap.add_argument("--max", type=int, default=50, help="max differences to print")
    args = ap.parse_args()

    diffs = compare(args.baseline, args.candidate)
    if not diffs:
        print(f"PARITY: {args.candidate} matches {args.baseline}")
        return 0

    print(f"{len(diffs)} difference(s) between {args.baseline} and {args.candidate}:\n")
    for d in diffs[: args.max]:
        py = d.get("python", "-")
        la = d.get("laravel", "-")
        print(f"  [{d['kind']}] {d['path']}")
        if py != "-" or la != "-":
            print(f"      python : {py}")
            print(f"      laravel: {la}")
    if len(diffs) > args.max:
        print(f"  ... and {len(diffs) - args.max} more")
    return 1


if __name__ == "__main__":
    sys.exit(main())
