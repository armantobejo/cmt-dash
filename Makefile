# Common development tasks for CMT Dashboard.
# Usage: make <target>   (GNU Make on Linux/macOS; `mingw32-make` on Windows)

.PHONY: up sync test test-parity test-all test-laravel parity-gate parity-vectors parity-vectors-check audit lint gates gates-fast production-audit help parity

up:         ## Start dashboard + run one-off sync (docker)
	docker compose up --build

sync:       ## Rebuild SQLite DBs from DBF/ (multi-company auto-detect)
	DBF_DIR="$(CURDIR)/DBF" DATA_DIR="$(CURDIR)/data" DATABASE_URL="$(CURDIR)/data/CMT.db" python tools/sync_db.py

test:       ## Run the pytest suite (app tests; parity harness is standalone)
	python -m pytest tests/ --ignore=tests/parity

test-parity:  ## Capture the parity baseline JSON (pytest tests/parity -q)
	python -m pytest tests/parity -q

test-all:   ## Run app tests + parity harness (separate pytest invocations)
	python -m pytest tests/ --ignore=tests/parity
	python -m pytest tests/parity -q

parity-gate:  ## Validate parity capture (meta block + checksum + schema) then diff vs python baseline
	PYTHONIOENCODING=utf-8 python tools/parity_gate.py

parity:       ## Run full parity gate: baseline test + gate + vector check
	python -m pytest tests/parity -q
	PYTHONIOENCODING=utf-8 python tools/parity_gate.py
	PYTHONIOENCODING=utf-8 python tools/gen_parity_vectors.py --check

parity-vectors:   ## Regenerate cross-app vectors from real Python (write fixture)
	PYTHONIOENCODING=utf-8 python tools/gen_parity_vectors.py

parity-vectors-check:  ## Exit 1 if the vectors fixture is stale vs the Python reference
	PYTHONIOENCODING=utf-8 python tools/gen_parity_vectors.py --check

test-laravel:  ## Run the Laravel suite (this PHP build needs pdo_sqlite/sqlite3 via -d flags)
	cd laravel && php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit

gates:      ## Run EVERY parity/schema gate: both suites + vectors + baseline diff + real-DB audits (tools/run_gates.py)
	PYTHONIOENCODING=utf-8 python tools/run_gates.py

gates-fast:  ## Gate set without the real-DB production audits (hosted-CI equivalent)
	PYTHONIOENCODING=utf-8 python tools/run_gates.py --fast

production-audit:  ## Audit both query layers' every branch against copies of the real data/*.db schemas
	PYTHONIOENCODING=utf-8 python tools/audit_production_queries.py
	cd laravel && php -d extension=pdo_sqlite -d extension=sqlite3 tools/audit_production_queries.php

audit:      ## Scan dependencies for known vulnerabilities
	pip-audit -r src/requirements.txt

lint:       ## Lint (ruff if available; no-op otherwise)
	ruff check src/ tools/ tests/ 2>/dev/null || echo "ruff not installed — skipping"

help:       ## Show available targets
	@grep -E '^[a-zA-Z_-]+:.*##' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  %-10s %s\n", $$1, $$2}'