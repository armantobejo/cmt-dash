# ADR: P5 Parity Gate Architecture and Meta Block Schema

## Context
- PRD phase P5 parallel run requires server-side route parity between Laravel and Python apps.
- Current `laravel.json` captures raw HTTP responses (status, headers, body) per domain.
- `python.json` baseline contains machine-readable structure: `meta` + 9 domains with domain-specific signatures.
- Need deterministic checks on capture artifacts (checksums, schema, invariants) before baseline comparison.
- Version skew must be handled gracefully.

## Decision
Define a versioned meta block schema with explicit fields, types, and versioning. Implement a parity gate that:
1. Validates schema conformance and checksum integrity of the candidate artifact.
2. Performs semantic invariant checks (9 domains populated, status 200, body valid, captured_at recent).
3. Compares candidate against `python.json` baseline using `tools/compare_parity.py`.
4. Produces JSON report with exit codes:
   - `0`: pass (no differences)
   - `1`: pass but schema/checksum mismatches (manual review required)
   - `2`: schema violation or I/O failure
   - `3`: baseline diff detected (intentional change needed)

## Rationale
- **Schema versioning** (`schema_version` field) enables backward compatibility and explicit migration paths.
- **Checksum validation** ensures reference frames haven't been tampered with (SHA256 of reference frame matches captured frame).
- **Semantic invariants** (9 domains, status 200, body valid JSON/HTML) ensure capture quality without full diff.
- **Baseline comparison** already exists (`tools/compare_parity.py`) — gate validates capture quality first, then diffs.
- **Exit codes** allow CI to distinguish between "needs review" (1) vs "fatal error" (2-3).

## Alternatives Considered
- **No versioning**: Would break on schema changes; no explicit migration path.
- **Skip checksum validation**: Would allow tampered frames; violates determinism requirement.
- **Compare only domains**: Misses schema drift, checksum errors, and I/O failures.
- **Use raw file hashes**: Too coarse; schema-level validation is more precise.

## Consequences
- **Positive**: Clear contract, automated validation, CI integration, versioned artifacts.
- **Negative**: Additional file (schema) and ADR maintenance; need to validate capture quality before diff.
- **Mitigations**: 
  - Schema validation fails fast with actionable error.
  - Version skew handled via explicit migration path in ADR.
  - Schema file versioned in repo (e.g., `schemas/parity-meta-block.schema.json`).

## Implementation Plan
1. **Write ADR**: Document schema fields, types, versioning, and gate contract.
2. **Write JSON schema**: `laravel/resources/schemas/parity-meta-block.schema.json` with:
   - `schema_version: string` (semver)
   - `source: "laravel"` (hardcoded)
   - `generator: "php artisan parity:capture"` (or equivalent)
   - `checklist: "docs/parity-checklist.yaml"` (path)
   - `gate: "PRD section 8, phase P5"` (string)
   - `roles: array of strings (role names)`
   - `domains: array of strings (the 9 domain keys)`
   - `captured_at: string (ISO8601)`
   - `content_hash: string (SHA256 of sorted domains dict)`
   - `domains: array of objects with:
     - `status: int`
     - `headers: array of strings`
     - `body: string (raw JSON or HTML)`
     - `checksum: string (SHA256 of body)`
     - `fixture: string (the domain fixture key)`
3. **Update Makefile**: add `parity` target that:
   - Runs `php artisan parity:capture`
   - Runs `python tools/compare_parity.py` with proper exit codes
   - Fails if exit code != 0
4. **Add tests**: `test_parity_gate` in test suite that verifies:
   - Schema validation passes
   - Capture produces valid `laravel.json`
   - Compare_parity exits with expected codes on known good/bad inputs
5. **Documentation**: update `README.md` with new workflow and schema location.

## Related
- t_84fd9428: Define p5 capture harness architecture and API contract (client-side)
- t_903d425c: Define API contract and route for P5 parity capture (server-side)
- t_1fee5be4: Implement baseline comparison engine (new, depends on this ADR)

## References
- docs/decisions/p5-parity-diff-contract.md
- tools/compare_parity.py
- laravel/tests/Parity/ParityBaselineTest.php
- laravel/app/Parity/ParityActions.php
- tests/parity/baseline/python.json
- laravel/storage/app/parity/laravel.json