# Decision: p5 capture harness architecture and API contract

Status: decided · Owner: engineering · Phase gate: PRD p5 visual parity

## Scope

This is **client-side pixel-frame parity** for p5.js sketches: the browser
captures sketch output (canvas pixels), POSTs frames to Laravel, frames are
stored and compared against a reference (golden) output with a tolerance
budget. It is distinct from the server-side route parity harness at
`docs/decisions/p5-parity-diff-contract.md` (Laravel vs Python HTTP responses);
that spec and this one must not be conflated.

## Capture flow

1. p5.js sketch renders a frame.
2. Frontend grabs pixel data (`context.getImageData(0, 0, w, h)` → raw RGBA
   `Uint8ClampedArray`), base64-encodes it.
3. `POST /api/capture/p5` with JSON payload (schema below).
4. Laravel validates the payload at the trust boundary (sketch_id slug,
   frame_index/frame_total bounds, dimensions 1–4096, base64 raw RGBA of
   exactly width×height×4 bytes, optional sha256 hex). Invalid payload →
   HTTP 422.
   5. Frame is stored under `storage/app/private/parity/p5/<sketch_id>/captures/<run_id>/<frame_index>.json`
      (local disk). Reference frames live at
      `.../<sketch_id>/references/<frame_index>.json`.
6. If a reference frame exists for the same sketch_id + frame_index, pixel
   comparison runs in pure PHP over raw RGBA bytes (no GD/extension
   dependency): per-frame pass requires max channel delta ≤ tolerance,
   mean absolute error ≤ tolerance, and matching-pixel ratio ≥ tolerance.
7. Response shape below is returned. On storage failure → HTTP 500 (no
   silent data loss; the frame is either stored or the request errors).

## Technology choices

| Choice | Rationale |
|---|---|
| Laravel 12, PHP 8.2 | Existing project stack; PSR-4 autoload maps `App\CaptureHarness\*` to `app/CaptureHarness/` with no composer change. |
| stdlib + native Laravel only | No new Composer dependencies. Storage facade (local disk), validation, config, responses are native. |
| Base64 raw RGBA transport | p5 `getImageData` yields raw RGBA; base64 survives JSON transport and decodes in pure PHP — no image decode extension needed on either side. |
| Pure-PHP pixel comparison | Avoids GD/Imagick extension requirement; keeps parity deterministic across environments. |
| Local disk storage | Matches existing `storage/app/parity/` convention from the route-parity harness. |
| Session (`web`+`auth`) middleware | Reuses dashboard login cookie; no new auth scheme. Throttle `60,1` caps payload size abuse. CSRF tokens are validated — `api/capture/p5` is exempted in `bootstrap/app.php` (`validateCsrfTokens`), so browser clients posting JSON without `X-XSRF-TOKEN` are accepted; no session cookie means CSRF isn't enforced anyway. |

## API contract

**Endpoint:** `POST /api/capture/p5` (registered by implementation layer)
**Auth:** session auth via `web` + `auth` middleware
**Rate limit:** `throttle:60,1`
**Content-Type:** `application/json`

### Request payload schema

| Field | Type | Required | Constraints |
|---|---|---|---|
| sketch_id | string | yes | slug `^[a-z0-9][a-z0-9_-]{0,63}$` |
| frame_index | integer | yes | 0 ≤ frame_index < frame_total |
| frame_total | integer | yes | ≥ 1 |
| width | integer | yes | 1–4096 |
| height | integer | yes | 1–4096 |
| pixels | string | yes | base64(raw RGBA), length == width×height×4 |
| sha256 | string | no | lowercase hex, 64 chars |
| reference_id | string | no | reference set id — echoed back in the parity response for traceability; selection stays keyed by sketch_id + frame_index |
| tolerance | object | no | any of max_channel_delta (int 0-255), mae (float >= 0), min_match_ratio (float 0–1) |

### Storage format

```
storage/app/private/parity/p5/
  <sketch_id>/
    references/<frame_index>.json   # golden frame (seeded out of band)
    captures/<run_id>/<frame_index>.json   # one file per captured frame
```

Frame JSON shape:

```json
{
  "sketch_id": "shipment-summary-chart",
  "frame_index": 3,
  "width": 800,
  "height": 600,
  "pixels": "<base64 raw RGBA>",
  "sha256": "<hex|null>",
  "captured_at": 1795214400,
  "source": "browser"
}
```

Run manifest written once per run_id:
`captures/<run_id>/run.json` — `{ sketch_id, run_id, frame_count, started_at, finished_at }`.

### Comparison tolerance (default, overridable per call)

- max_channel_delta: 4 (8-bit channels)
- mae: 1.0 (mean absolute error per channel)
- min_match_ratio: 0.999 (share of pixels within delta)

A frame passes when all three conditions hold. Frame is skipped (not counted
as failure) when no reference exists for that sketch_id + frame_index.
`parity.overall` is true only when every compared frame passes and at least
one frame was compared.

### Response shape (success)

```json
{
  "ok": true,
  "run_id": "c5a2f...-run-uuid",
  "stored": {
    "disk": "local",
    "path": "parity/p5/shipment-summary-chart/captures/c5a2f.../",
    "frames": 1,
    "bytes": 1920000
  },
  "parity": {
    "reference_id": "v2026-09",
    "overall": true,
    "frames": [
      {
        "index": 3,
        "pass": true,
        "max_channel_delta": 2.0,
        "mae": 0.4,
        "match_ratio": 1.0
      }
    ]
  }
}
```

When no reference exists: `"parity": { "reference_id": null, "overall": null, "frames": [], "skipped": [3] }`.

### Error shapes

| Status | Body | Meaning |
|---|---|---|
| 422 | `{ "ok": false, "error": "validation", "message": "<detail>", "fields": [...] }` | Bad payload |
| 401 | `{ "ok": false, "error": "unauthorized" }` | Not authenticated |
| 429 | `{ "ok": false, "error": "rate_limited" }` | Throttle exceeded |
| 404 | `{ "ok": false, "error": "disabled" }` | `CAPTURE_HARNESS_ENABLED=0` |
| 500 | `{ "ok": false, "error": "storage", "message": "<detail>" }` | Frame not stored |

## Directory structure under app/CaptureHarness

```
app/CaptureHarness/
  Contracts/
    CaptureHarnessContract.php    # capture() interface
    FrameStoreContract.php        # store/get reference & capture frames
    ParityComparatorContract.php  # pure-PHP pixel compare interface
  DTOs/
    CapturePayload.php            # validated request value object
  Exceptions/
    CaptureHarnessException.php   # base harness exception
  Storage/                        # concrete store implementations (todo)
  Comparison/                     # concrete comparator implementations (todo)
config/capture-harness.php        # endpoint, middleware, tolerance defaults
```

`config/capture-harness.php` is read via `config('capture-harness.*')`; no
service provider registration is required for config alone.

## Out of scope for this contract

- Route registration (`routes/api.php` does not exist yet; implementation layer adds it).
- Reference-frame generation/seeding tooling.
- Frontend p5 capture snippet (sketch-side `getImageData` + fetch).
- Queued/async storage or Redis-backed dedup — add when run volume demands it.

## Acceptance

- [x] Written contract exists (this document + code interfaces + config)
- [x] Directory skeleton under `app/CaptureHarness`
- [ ] No implementation code yet (interfaces/DTOs/config only — bodies belong to t_fd32ce33)
