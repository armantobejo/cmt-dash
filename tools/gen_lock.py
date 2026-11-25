"""Regenerate src/requirements.lock from installed package metadata (no network).

Computes the pinned transitive closure of src/requirements.txt's top-level deps
and prints `pkg==version` lines, sorted A-Z. Re-run after upgrading deps:

    python tools/gen_lock.py > src/requirements.lock
"""
from importlib.metadata import requires, version
from packaging.requirements import Requirement

TOP_LEVEL = ["fastapi", "uvicorn", "jinja2", "aiofiles", "python-multipart", "httpx", "dbfread"]


def main():
    seen = set()
    order = []

    def visit(name):
        norm = name.lower().replace("_", "-")
        if norm in seen:
            return
        seen.add(norm)
        for req in requires(norm) or []:
            rq = Requirement(req)
            if rq.marker and not rq.marker.evaluate():
                continue
            visit(rq.name)
        order.append(norm)

    for top in TOP_LEVEL:
        visit(top)

    lines = []
    for name in sorted(order, key=str.lower):
        try:
            lines.append(f"{name}=={version(name)}")
        except Exception as e:
            print(f"WARN: {name} -> {e}")
    print("\n".join(lines))


if __name__ == "__main__":
    main()
