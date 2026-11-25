# Volume / Lifting report — column spec and source (F-26/F-29, F-32)

Status: `unverified-locally` — column set cannot be read from this tree.

## Artifact needed
- `TEMPLATE\VOLUME.DOC` (MODULWORD.VOLUME_R, "REPORT VOLUME")
- `TEMPLATE\LIFTING.DOC` (MODULWORD.LIFTING_R, "REPORT LIFTING")

Neither `.DOC` template nor any `.FRX`/`.FRT` layout is shipped. Report programs are packed binaries.

## Absence proof (re-runnable)
```
python tools/legacy_report_evidence.py --dbf-dir DBF
```
Result (2026-11-18): `report layouts (.FRX/.FRT): 0 | executables: 28 (packed)`. Measurements in `docs/report-dbf-coverage/legacy-evidence.json`.

## Parameter shape (known, from MODULWORD rows)
Both reports take a start date `XTGLAWAL` plus a value `XNILAIC` — period reports. Page filter surface: start date + value. (No end date; divergence from a range picker is recorded here.)

## Inputs available in the 18-table ingest
- `CARGO` / `NA`: `CONTAINERN` (655/1 052 and 6 054/9 542 non-blank in CMT), `MEAST` (605/1 052 and 7 098/9 542), `GW`.

A container/measurement/weight report is arithmetically possible, but which fields the legacy template prints is unknown.

## Column set
Not specified — no artifact obtained. No columns invented, no aggregation assumed. Volume vs Lifting difference (same aggregation different presentation vs different underlying set) cannot be stated without the template.

## Verdict
`unverified-locally`. Owner decision required: supply `TEMPLATE\VOLUME.DOC` / `TEMPLATE\LIFTING.DOC` or a screenshot of the printed report to close the column set; otherwise build from a stated reading or defer. Until then both rows stay at enumeration level (PRD §3.2.9).
