"""Pytest configuration for CMT Dashboard.

Provides a FastAPI TestClient bound to a throwaway DB. The fixture runs for the
whole test session, so we get one app startup (lifespan) and many cheap HTTP calls.
"""
from __future__ import annotations

import os
import sqlite3
import sys
import tempfile
from pathlib import Path

# Force English locale for these tests (app default is 'id')
os.environ["CMT_LANG"] = "en"

import pytest

# The parity-baseline harness (tests/parity/) is a STANDALONE run:
#     pytest tests/parity -q
# It seeds its own dataset via import-time env vars that conflict with this
# conftest's, so in a combined `pytest tests/` run its test modules are
# excluded from collection here (its conftest mirrors this argv check and
# deactivates its env/fixture setup outside parity mode).
# (see tests/parity/conftest.py + docs/parity-checklist.yaml)
_ARGSTR = " ".join(sys.argv[1:]).replace("\\", "/")
_PARITY_MODE = "tests/parity" in _ARGSTR or _ARGSTR.strip().rstrip("/") == "parity"
if not _PARITY_MODE:
    collect_ignore_glob = ["parity/*"]

# Test env MUST be set BEFORE importing app modules so they see the temp paths.
# CMT_USERS is now REQUIRED for startup; test conftests must explicitly set it.
_REPO_ROOT = Path(__file__).resolve().parent.parent
_TMP = Path(tempfile.mkdtemp(prefix="cmt_test_"))
_TEST_DB = _TMP / "cmt.db"
# Use generated credentials (not user-validating further for test simplicity)
os.environ["CMT_USERS"] = "test_admin:test_pass:admin,test_cust1:cust1_pass:customer:TEST1"
os.environ["DATABASE_URL"] = str(_TEST_DB)
os.environ["DBF_DIR"] = str(_REPO_ROOT / "DBF")

# Ensure `src/` is on sys.path so `from app...` works regardless of where pytest runs.
_SRC = _REPO_ROOT / "src"
sys.path.insert(0, str(_SRC))


@pytest.fixture(scope="session", autouse=True)
def _ensure_db_file() -> None:
    """Touch an empty SQLite file so app lifespan doesn't warn at startup."""
    if _PARITY_MODE:
        return  # parity harness owns the DB via tests/parity/conftest.py
    _TEST_DB.parent.mkdir(parents=True, exist_ok=True)
    _TEST_DB.touch()
    conn = sqlite3.connect(str(_TEST_DB))
    # Minimal business tables so authenticated dashboard routes return 200
    # (queries reference UnifiedShipment, INVOICE, VESSEL).
    conn.executescript(
        """
        CREATE TABLE IF NOT EXISTS NA (
            UNIK TEXT, SALES TEXT, THN TEXT, BLNO3 TEXT, DATE TEXT, REFSHITMEN TEXT,
            AGENT TEXT, CODE TEXT, KD_CONSIG TEXT, CONSIGNEE TEXT, CONTAINERN TEXT,
            DIMENSI TEXT, GODOWN TEXT, PORTOFLOAD TEXT, CITY_DISCH TEXT,
            PORTOFDISC TEXT, KD_SHIPPER TEXT, SHIPPER TEXT, KD_NOTIFY TEXT,
            NOTIFYPART TEXT, SHIPPINGMA TEXT, GW TEXT, NW TEXT, MEAST TEXT,
            MEAST_SAT TEXT, SAIDTOCONT TEXT, KD_SATUAN TEXT, PREPAID TEXT,
            OPR TEXT, PRINT TEXT, PRINTDATE TEXT, READO TEXT, TYPETRAN TEXT,
            BL_NO TEXT, MBL_NO TEXT, KD_EXVESSE TEXT, EXVOYAGE TEXT, ETA TEXT,
            ENTRY TEXT, PBM TEXT, CONS_NOTI TEXT, CITY_FROM TEXT, DESCRIPTIO TEXT,
            TOT_EUR TEXT, TOT_USD TEXT, TOT_IDR TEXT
        );
        CREATE TABLE IF NOT EXISTS CARGO (
            UNIK TEXT, SALES TEXT, THN TEXT, BLNO3 TEXT, DATE TEXT, REFSHITMEN TEXT,
            AGENT TEXT, CODE TEXT, KD_CONSIG TEXT, CONSIGNEE TEXT, CONTAINERN TEXT,
            DIMENSI TEXT, GODOWN TEXT, PORTOFLOAD TEXT, CITY_DISCH TEXT,
            PORTOFDISC TEXT, KD_SHIPPER TEXT, SHIPPER TEXT, KD_NOTIFY TEXT,
            NOTIFYPART TEXT, SHIPPINGMA TEXT, GW TEXT, NW TEXT, MEAST TEXT,
            MEAST_SAT TEXT, SAIDTOCONT TEXT, KD_SATUAN TEXT, PREPAID TEXT,
            OPR TEXT, PRINT TEXT, PRINTDATE TEXT, READO TEXT, TYPETRAN TEXT,
            ETDSIN TEXT, ETADES TEXT, ARRIVAL_TI TEXT
        );
        CREATE TABLE IF NOT EXISTS VESSEL (UNIK TEXT, NAME TEXT);
                CREATE TABLE IF NOT EXISTS CONSIGNEE (
                    KD_CONSIG TEXT, NAME TEXT, ADDRESS1 TEXT, ADDRESS2 TEXT, ADDRESS3 TEXT,
                    TELP TEXT, FAX TEXT
                );
                CREATE TABLE IF NOT EXISTS SHIPPER (
                    KD_SHIPPER TEXT, NAME TEXT, KD_BU TEXT, TELP TEXT, FAX TEXT,
                    KD_JNSCUST TEXT, TERM TEXT, KD_ACCOUNT TEXT, NPWP TEXT
                );
                CREATE TABLE IF NOT EXISTS AGENT (
                    CODE TEXT, NAME TEXT, AGENT TEXT, AGENTINV TEXT, RATE TEXT,
                    KD_ACCOUNT TEXT, ACCT_CR TEXT, TELP TEXT, FAX TEXT, PIC TEXT
                );
                CREATE TABLE IF NOT EXISTS ACCOUNT (
                    KD_ACCOUNT TEXT, NM_ACCOUNT TEXT, SLD_NORMAL TEXT, TYPE TEXT,
                    "GROUP" TEXT, ENTRY TEXT, ACTIVE TEXT, NOACTIVE TEXT
                );
                CREATE TABLE IF NOT EXISTS OPRCOST (
                    UOPRCOST TEXT, UNIK TEXT, REFSHITMEN TEXT, NOMOR TEXT, TANGGAL TEXT,
                    EXRATE REAL, GRAND_USD REAL, GRAND_RP REAL
                );
                CREATE TABLE IF NOT EXISTS OPRCOST_R (
                    UOPRCOST TEXT, JENISCOST TEXT, DESCRIPTIO TEXT, CURRENCY TEXT,
                    QTY REAL, TSRATE REAL, JUMLAH REAL, BAYAR REAL, KD_SHIPLIN TEXT, INVOICE_NO TEXT, INV_TGL TEXT, KETERANGAN TEXT
                );
                CREATE TABLE IF NOT EXISTS DRCRFULL_R (
                    UNIK TEXT, JENISCOST TEXT, DESCRIPTIO TEXT, CURRENCY TEXT,
                    QTY REAL, UNITPRICE REAL, JUMLAH REAL
                );
        CREATE TABLE IF NOT EXISTS INVOICE (
            UNIK TEXT, K_INVOICE TEXT, SHIPPER TEXT, SHIPP_PIUT TEXT, DATEOFISSU TEXT,
            REFSHITMEN TEXT, NOINVOICE TEXT, GRAND_USD REAL, GRAND_RP REAL, READO INTEGER,
            LUNAS INTEGER, BYR_USD REAL, DIRPKAN REAL, BYR_RP REAL, DIUSDKAN REAL
        );
        CREATE TABLE IF NOT EXISTS DRCRFULL (
            UNIK TEXT, REFSHITMEN TEXT, NO_REF TEXT, TANGGAL TEXT, BLNO TEXT,
            EXRATE REAL, TOTAL REAL, TOTAL_IDR REAL, LUNAS INTEGER,
            BAYAR REAL, DIRPKAN REAL, BAYAR_IDR REAL, DIUSDKAN REAL
        );
        """
    )
    # UnifiedShipment — use a VIEW in tests so inserts into NA/CARGO
    # are immediately visible (production uses a TABLE for performance).
    sys.path.insert(0, str(_REPO_ROOT / "tools"))
    from schema import _unified_select_sql
    cur = conn.cursor()
    cur.execute("DROP VIEW IF EXISTS UnifiedShipment")
    cur.execute("DROP TABLE IF EXISTS UnifiedShipment")
    cur.execute(f"CREATE VIEW UnifiedShipment AS {_unified_select_sql()}")
    # Columns the invoice-detail page needs beyond the minimal INVOICE table
    # (added idempotently; production sync creates the full table).
    # NOTE: deliberately NO CONTAINERN — real company DBs have no such column
    # (the sync builds INVOICE from FoxPro field metadata), and granting it in
    # tests hid a production 500. See tests/test_schema_gate.py and
    # docs/decisions/invoice-container-schema-drift.md.
    for col in ("EI", "BLNO", "BLNO3", "PORTOFLOAD", "PORTOFDISC",
                "VESSELNAME", "ETDDATE", "ETADATE", "NOTEINV", "REMARK"):
        try:
            cur.execute(f"ALTER TABLE INVOICE ADD COLUMN {col} TEXT")
        except sqlite3.OperationalError:
            pass  # column already exists (rerun within same session)
    for col in ("SELISIH_US", "SELISIH_ID", "EXRATE"):
        try:
            cur.execute(f"ALTER TABLE INVOICE ADD COLUMN {col} REAL")
        except sqlite3.OperationalError:
            pass
    cur.execute("CREATE TABLE IF NOT EXISTS SATUAN (KD_SATUAN TEXT, SATUAN TEXT)")
    cur.execute(
        "CREATE TABLE IF NOT EXISTS QUOTATION ("
        "UNIK TEXT, KD_QUOTA TEXT, TANGGAL TEXT, EXPIRED TEXT, KD_SHIPPER TEXT"
        ")"
    )
    cur.execute(
        "CREATE TABLE IF NOT EXISTS QUOTATION_R ("
        "UNIK TEXT, CODE_AGENT TEXT, KD_CITY TEXT, DESC_CITY TEXT,"
        "LCL REAL, MIN REAL, D20 REAL, D40 REAL, D40HQ REAL, D45 REAL,"
        "PRINT TEXT"
        ")"
    )
    cur.execute(
        "CREATE TABLE IF NOT EXISTS INVOICE_R ("
        "NOINVOICE TEXT, K_INVOICE TEXT, JENISCOST TEXT, DESCRIPTIO TEXT, "
        "CURRENCY TEXT, QTY REAL, SELLING REAL, JUMLAH REAL, BAYAR REAL, NOTE TEXT, "
        "NO REAL, KD_SATUAN TEXT)"
    )
    # ARS / ARS_R for Statement of Account
    cur.execute(
        "CREATE TABLE IF NOT EXISTS ARS ("
        "UARS TEXT, KD_SHIPPER TEXT, TANGGAL TEXT, CURRENCY TEXT, EXRATE REAL,"
        "NO_REF TEXT, TOT_BAYAR REAL, TOT_DIRPKA REAL)"
    )
    cur.execute(
        "CREATE TABLE IF NOT EXISTS ARS_R ("
        "UARS TEXT, UARS_R TEXT, K_INVOICE TEXT, BAYAR REAL, DIRPKAN REAL)"
    )
    conn.commit()
    conn.close()


@pytest.fixture(scope="session")
def client():
    """Session-scoped FastAPI TestClient."""
    from fastapi.testclient import TestClient
    from app.main import app

    with TestClient(app) as c:
        yield c


@pytest.fixture(autouse=True)
def _reset_login_rate_limit():
    """Reset the in-memory login rate limiter between tests. TestClient
    reuses one IP for the whole session, so the sliding window would
    otherwise accumulate across tests and 429 legitimate logins.
    Production deploys are unaffected — this fixture touches test state only."""
    from app.main import _login_attempts

    _login_attempts.clear()

# ---------------------------------------------------------------------------
# Authenticated clients (shared across test modules)
# ---------------------------------------------------------------------------
@pytest.fixture
def admin_client(client: TestClient) -> TestClient:
    """Client logged in as admin (no company filter)."""
    client.post("/login", data={"username": "test_admin", "password": "test_pass"})
    return client


@pytest.fixture
def trisco_client(client: TestClient) -> TestClient:
    """Client logged in as customer user with company filter = Trisco."""
    client.post("/login", data={"username": "test_cust1", "password": "cust1_pass"})
    return client
# ---------------------------------------------------------------------------
# Fixtures: seed the database with two shipments (import + export)
# ---------------------------------------------------------------------------
@pytest.fixture(scope="session", autouse=True)
def _seed_test_data() -> None:
    """Populate SQLite with two shipments + related detail rows.

    Runs once per test session before any tests in this module.
    """
    if _PARITY_MODE:
        return  # parity harness seeds its own dataset via tests/parity/conftest.py
    from app import db as db_mod

    db_mod.init_schema()
    db = db_mod.get_db()

    with db as conn:
        # ---- Reference tables ------------------------------------------------
        conn.execute("DELETE FROM NA WHERE UNIK LIKE 'N%'")
        conn.execute("DELETE FROM CARGO WHERE UNIK LIKE 'C%'")
        conn.execute("DELETE FROM INVOICE WHERE NOINVOICE LIKE 'INV-%'")
        conn.execute("DELETE FROM INVOICE_R WHERE JENISCOST LIKE '%'")
        conn.execute("DELETE FROM OPRCOST WHERE UOPRCOST LIKE 'OP-%'")
        conn.execute("DELETE FROM OPRCOST_R WHERE JENISCOST LIKE '%'")
        conn.execute("DELETE FROM DRCRFULL WHERE UNIK LIKE 'D-%'")
        conn.execute("DELETE FROM DRCRFULL_R WHERE JENISCOST LIKE '%'")
        conn.execute("DELETE FROM VESSEL WHERE UNIK LIKE 'V%'")
        conn.execute("DELETE FROM AGENT WHERE AGENT LIKE 'AG%'")
        conn.execute("DELETE FROM CONSIGNEE WHERE KD_CONSIG LIKE 'CONS%'")
        conn.execute("DELETE FROM SHIPPER WHERE KD_SHIPPER LIKE 'SHIP%'")
        conn.execute("DELETE FROM SATUAN WHERE KD_SATUAN LIKE 'U%'")

        # Consignee (import customer)
        conn.execute(
            "INSERT INTO CONSIGNEE (KD_CONSIG, NAME, ADDRESS1, TELP) VALUES (?, ?, ?, ?)",
            ("CONS001", "PT TRISCO INDONESIA", "Jl. Sudirman", "-"),
        )
        # Shipper (export customer)
        conn.execute(
            "INSERT INTO SHIPPER (KD_SHIPPER, NAME, KD_BU) VALUES (?, ?, ?)",
            ("SHIP001", "ACME EXPORT CO", "-"),
        )
        # Agent
        conn.execute(
            "INSERT INTO AGENT (AGENT, CODE, NAME) VALUES (?, ?, ?)",
            ("AG1", "CODE1", "AGENT ONE"),
        )
        # Vessel (detail header needs NM_VESSEL, KD_VESSEL)
        cols = {r[1] for r in conn.execute("PRAGMA table_info(VESSEL)")}
        if "KD_VESSEL" not in cols:
            conn.execute("ALTER TABLE VESSEL ADD COLUMN KD_VESSEL TEXT")
        if "NM_VESSEL" not in cols:
            conn.execute("ALTER TABLE VESSEL ADD COLUMN NM_VESSEL TEXT")
        conn.execute(
            "INSERT INTO VESSEL (UNIK, NAME, KD_VESSEL, NM_VESSEL) VALUES (?, ?, ?, ?)",
            ("V1", "MV OCEAN STAR", "V1", "MV OCEAN STAR"),
        )
        # Satuan
        conn.execute("INSERT INTO SATUAN (KD_SATUAN, SATUAN) VALUES (?, ?)", ("U1", "PCS"))
        conn.execute("INSERT INTO SATUAN (KD_SATUAN, SATUAN) VALUES (?, ?)", ("U2", "CTN"))

        # ---- Unified shipment rows (NA import, CARGO export) -----------------
        # S-001: FCL import
        conn.execute(
            """INSERT INTO NA (
                UNIK, SALES, THN, BLNO3, DATE, REFSHITMEN, AGENT, CODE,
                KD_CONSIG, CONSIGNEE, CONTAINERN, DIMENSI, GODOWN, PORTOFLOAD,
                CITY_DISCH, PORTOFDISC, KD_SHIPPER, SHIPPER, KD_NOTIFY, NOTIFYPART,
                SHIPPINGMA, GW, NW, MEAST, MEAST_SAT, SAIDTOCONT, KD_SATUAN,
                PREPAID, OPR, PRINT, PRINTDATE, READO,
                BL_NO, MBL_NO, KD_EXVESSE, EXVOYAGE, ETA,
                ENTRY, PBM, CONS_NOTI, CITY_FROM, DESCRIPTIO,
                TOT_EUR, TOT_USD, TOT_IDR
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
            (
                "N1",
                "SLS",
                "2026",
                "BL-S1",
                "2026-05-10",
                "S-001",
                "AG1",
                "CODE1",
                "CONS001",
                "PT TRISCO INDONESIA",
                "MSKU-123456-7",
                "40'",
                "GODOWN-1",
                "IDJKT",
                "JAKARTA",
                "IDTPP",
                "SHIP001",
                "ACME EXPORT CO",
                "NOT1",
                "NOTIFY TRISCO",
                "SHIPPING MARK 1",
                18500,
                16200,
                "10x20x15",
                "U1",
                "Y",
                "U1",
                "N",
                "Y",
                "Y",
                "2026-05-12",
                "Y",
                "HB-S1",
                "MB-S1",
                "V1",
                "VOY-100",
                "2026-06-01",
                "ENT-1",
                "PBM-1",
                "CN-1",
                "JAKARTA",
                "DESC 1",
                0,
                1500,
                24000000,
            ),
        )
        # S-002: LCL export (CARGO)
        conn.execute(
            """INSERT INTO CARGO (
                UNIK, SALES, THN, BLNO3, DATE, REFSHITMEN, AGENT, CODE,
                KD_CONSIG, CONSIGNEE, CONTAINERN, DIMENSI, GODOWN, PORTOFLOAD,
                CITY_DISCH, PORTOFDISC, KD_SHIPPER, SHIPPER, KD_NOTIFY, NOTIFYPART,
                SHIPPINGMA, GW, NW, MEAST, MEAST_SAT, SAIDTOCONT, KD_SATUAN,
                PREPAID, OPR, PRINT, PRINTDATE, READO,
                TYPETRAN, ETDSIN, ARRIVAL_TI
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
            (
                "C1",
                "SLS",
                "2026",
                "BL-S2",
                "2026-05-20",
                "S-002",
                "AG1",
                "CODE1",
                "CONS001",
                "PT TRISCO INDONESIA",
                "",
                "CBM",
                "GODOWN-2",
                "IDSIN",
                "SINGAPORE",
                "SGSIN",
                "SHIP001",
                "ACME EXPORT CO",
                "NOT2",
                "NP-ACME",
                "SM-ACME",
                500,
                480,
                "10x10x10",
                "U2",
                "N",
                "U2",
                "N",
                "",
                "N",
                "",
                "E",
                "2026-05-21",
                    "2026-05-28",
                "2026-06-05",
            ),
        )

        # ---- Invoices ---------------------------------------------------------
        # INV-1 paid USD 1000
        conn.execute(
            """INSERT INTO INVOICE (
                NOINVOICE, K_INVOICE, REFSHITMEN, DATEOFISSU, GRAND_USD, GRAND_RP,
                LUNAS, EI, SHIPPER, EXRATE, BYR_USD, BYR_RP, SELISIH_US, SELISIH_ID, REMARK
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
            (
                "INV-1",
                "KI-1",
                "S-001",
                "2026-05-12",
                1000,
                16000000,
                1,
                "I",
                "SHIP001",
                16000,
                1000,
                16000000,
                0,
                0,
                "PAID INVOICE",
            ),
        )
        # INV-2 unpaid USD 500
        conn.execute(
            """INSERT INTO INVOICE (NOINVOICE, K_INVOICE, REFSHITMEN, DATEOFISSU, GRAND_USD, GRAND_RP, READO, LUNAS, EI, SHIPPER, EXRATE, BYR_USD, BYR_RP, SELISIH_US, SELISIH_ID, REMARK) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
            (
                "INV-2",
                "KI-2",
                "S-001",
                "2026-05-15",
                500,
                8000000,
                0,
                "I",
                "SHIP001",
                16000,
                0,
                0,
                0,
                500,
                8000000,
                "UNPAID INVOICE",
            ),
        )
        # INV-3 export paid USD 800
        conn.execute(
            """INSERT INTO INVOICE (NOINVOICE, K_INVOICE, REFSHITMEN, DATEOFISSU, GRAND_USD, GRAND_RP, READO, LUNAS, EI, SHIPPER, EXRATE, BYR_USD, BYR_RP, SELISIH_US, SELISIH_ID, REMARK) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
            (
                "INV-3",
                "KI-3",
                "S-002",
                "2026-05-22",
                800,
                12800000,
                1,
                "E",
                "SHIP001",
                16000,
                800,
                12800000,
                0,
                0,
                0,
                "EXPORT PAID",
            ),
        )

        # INVOICE_R lines
        conn.execute(
            "INSERT INTO INVOICE_R (K_INVOICE, JENISCOST, DESCRIPTIO, CURRENCY, QTY, SELLING, JUMLAH, NOTE, KD_SATUAN) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            ("KI-1", "FREIGHT", "Ocean freight", "USD", 1, 1000, 1000, "note1", "U1"),
        )
        conn.execute(
            "INSERT INTO INVOICE_R (K_INVOICE, JENISCOST, DESCRIPTIO, CURRENCY, QTY, SELLING, JUMLAH, NOTE, KD_SATUAN) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            ("KI-2", "THC", "Terminal handling", "USD", 1, 500, 500, "note2", "U1"),
        )
        conn.execute(
            "INSERT INTO INVOICE_R (K_INVOICE, JENISCOST, DESCRIPTIO, CURRENCY, QTY, SELLING, JUMLAH, NOTE, KD_SATUAN) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            ("KI-3", "DOOR", "Door delivery", "USD", 1, 800, 800, "note3", "U1"),
        )

        # ---- Operational Costs ------------------------------------------------
        conn.execute(
            """INSERT INTO OPRCOST (
                UOPRCOST, REFSHITMEN, NOMOR, TANGGAL, EXRATE, GRAND_USD, GRAND_RP
            ) VALUES (?, ?, ?, ?, ?, ?, ?)""",
            ("OP-1", "S-001", "OPR-1", "2026-05-11", 16000, 300, 4800000),
        )
        conn.execute(
            """INSERT INTO OPRCOST (UOPRCOST, REFSHITMEN, NOMOR, TANGGAL, EXRATE, GRAND_USD, GRAND_RP) VALUES (?, ?, ?, ?, ?, ?, ?)""",
            ("OP-2", "S-002", "OPR-2", "2026-05-21", 16000, 200, 3200000),
        )

        conn.execute(
            "INSERT INTO OPRCOST_R (UOPRCOST, JENISCOST, DESCRIPTIO, CURRENCY, QTY, TSRATE, JUMLAH, INVOICE_NO, KETERANGAN) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            ("OP-1", "COST-A", "Port charge", "USD", 1, 300, 300, "VI-1", "k1"),
        )
        conn.execute(
            "INSERT INTO OPRCOST_R (UOPRCOST, JENISCOST, DESCRIPTIO, CURRENCY, QTY, TSRATE, JUMLAH, INVOICE_NO, KETERANGAN) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            ("OP-2", "COST-B", "Trucking", "USD", 1, 200, 200, "VI-2", "k2"),
        )

        # ---- DRCRFULL (needs extra columns for detail query) ------------------
        conn.execute("ALTER TABLE DRCRFULL ADD COLUMN PLUS REAL")
        conn.execute("ALTER TABLE DRCRFULL ADD COLUMN TONAME TEXT")
        conn.execute("ALTER TABLE DRCRFULL ADD COLUMN MODE TEXT")
        conn.execute("ALTER TABLE DRCRFULL ADD COLUMN BLNO3 TEXT")
        conn.execute("ALTER TABLE DRCRFULL ADD COLUMN ACCT_DRCR TEXT")

        # FCL debit note for S-001 (TOTAL 500)
        conn.execute(
            """INSERT INTO DRCRFULL (
                UNIK, REFSHITMEN, NO_REF, PLUS, TANGGAL, TONAME, MODE, BLNO,
                BLNO3, TOTAL, TOTAL_IDR, EXRATE, BAYAR, DIRPKAN, LUNAS,
                ACCT_DRCR, BAYAR_IDR, DIUSDKAN
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
            (
                "D-1",
                "S-001",
                "DN-1",
                500,
                "2026-05-13",
                "TRISCO",
                "X",
                "BL-S1",
                "BL-S1",
                500,
                8000000,
                16000,
                0,
                0,
                0,
                "DR",
                0,
                0,
            ),
        )
        # LCL credit note for S-002 (TOTAL -150)
        conn.execute(
            """INSERT INTO DRCRFULL (UNIK, REFSHITMEN, NO_REF, PLUS, TANGGAL, TONAME, MODE, BLNO, BLNO3, TOTAL, TOTAL_IDR, EXRATE, BAYAR, DIRPKAN, LUNAS, ACCT_DRCR, BAYAR_IDR, DIUSDKAN) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
            (
                "D-2",
                "S-002",
                "CN-2",
                -150,
                "2026-05-23",
                "ACME",
                "X",
                "BL-S2",
                "BL-S2",
                -150,
                -2400000,
                16000,
                0,
                0,
                0,
                "CR",
                0,
                0,
            ),
        )

        # DRCRFULL_R lines
        conn.execute(
            "INSERT INTO DRCRFULL_R (JENISCOST, DESCRIPTIO, CURRENCY, QTY, UNITPRICE, JUMLAH) VALUES (?, ?, ?, ?, ?, ?)",
            ("D-R-1", "DN line", "USD", 1, 500, 500),
        )
        conn.execute(
            "INSERT INTO DRCRFULL_R (JENISCOST, DESCRIPTIO, CURRENCY, QTY, UNITPRICE, JUMLAH) VALUES (?, ?, ?, ?, ?, ?)",
            ("D-R-2", "CN line", "USD", 1, -150, -150),
        )


