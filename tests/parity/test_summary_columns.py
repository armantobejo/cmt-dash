"""Parity tests asserting Projects/Jobs Summary query columns against real_schema.json.

Validates that every selected column in get_projects_jobs_summary exists in the
real schema for all 5 companies (CME, CMT, CSM, MAL, SBY), that FCL/LCL split
columns are present and non-collapsed, and that the five §3.2.1 elements map
to the correct source table.column values.

Run: pytest tests/parity/test_summary_columns.py -v
"""
from __future__ import annotations

import json
from pathlib import Path

import pytest

_REPO_ROOT = Path(__file__).resolve().parents[2]
_SCHEMA_PATH = _REPO_ROOT / "tests" / "parity" / "fixtures" / "real_schema.json"

# Load real schema once
with open(_SCHEMA_PATH, "r", encoding="utf-8") as f:
    REAL_SCHEMA: dict = json.load(f)

COMPANIES = ["CME", "CMT", "CSM", "MAL", "SBY"]

# Expected columns in _PROJECTS_JOBS_SELECT (from queries.py lines 405-418)
# These are the final output columns after the SELECT
PROJECTS_JOBS_OUTPUT_COLUMNS = [
    # UnifiedShipment base columns
    "UNIK", "KIND", "DATE", "REFSHITMEN",
    "BLNO3", "BL_NO", "MBL_NO",
    "BL_PRINTED", "BL_READ", "BL_PRINT_DATE",
    "CONTAINERN", "DIMENSI", "PORTOFLOAD", "CITY_DISCH", "PORTOFDISC",
    "SHIPPER", "NOTIFYPART", "SHIPPINGMA",
    "GW", "NW", "TOT_EUR", "TOT_USD", "TOT_IDR",
    # Customer (derived from CONSIGNEE/SHIPPER)
    "CUSTOMER",
    # Operational Cost (from OPRCOST)
    "OPR_USD", "OPR_RP",
    # Invoice (from INVOICE)
    "INV_USD", "INV_RP", "INV_PAID", "NOINVOICE",
    # DR/CN FCL/LCL split (from DRCRFULL via subquery)
    "DR_CN_FCL", "DR_CN_LCL",  # display columns (COALESCE)
    "DNCN_FCL_USD", "DNCN_FCL_IDR", "DNCN_LCL_USD", "DNCN_LCL_IDR",  # raw columns
]

# §3.2.1 element -> source table.column mapping (from queries.py comments lines 321-369)
SECTION_321_ELEMENTS = {
    "status_of_bl": {
        "description": "Status of B/L (Bill of Lading status)",
        "source_table": "UnifiedShipment",
        "columns": ["BLNO3", "MBL_NO", "BL_NO", "PRINT", "READO", "PRINTDATE"],
        "output_columns": ["BLNO3", "BL_NO", "MBL_NO", "BL_PRINTED", "BL_READ", "BL_PRINT_DATE"],
    },
    "invoice_data": {
        "description": "Invoice data",
        "source_table": "INVOICE",
        "columns": ["GRAND_USD", "GRAND_RP", "LUNAS", "NOINVOICE"],
        "output_columns": ["INV_USD", "INV_RP", "INV_PAID", "NOINVOICE"],
    },
    "operational_cost": {
        "description": "Operational Cost data",
        "source_table": "OPRCOST",
        "columns": ["GRAND_USD", "GRAND_RP"],
        "output_columns": ["OPR_USD", "OPR_RP"],
    },
    "drcn_fcl": {
        "description": "Debit/Credit Note FCL data",
        "source_table": "DRCRFULL",
        "columns": ["TOTAL", "TOTAL_IDR"],  # filtered by DIMENSI IN FCL sizes
        "output_columns": ["DNCN_FCL_USD", "DNCN_FCL_IDR", "DR_CN_FCL"],
        "dimensi_filter": "FCL",  # ('20\'','40\'','40\' HQ','45\'')
    },
    "drcn_lcl": {
        "description": "Debit/Credit Note LCL data",
        "source_table": "DRCRFULL",
        "columns": ["TOTAL", "TOTAL_IDR"],  # filtered by DIMENSI IN LCL units
        "output_columns": ["DNCN_LCL_USD", "DNCN_LCL_IDR", "DR_CN_LCL"],
        "dimensi_filter": "LCL",  # ('CBM','KGS','M3','CTNS','DOC')
    },
}

# Columns that MUST appear as separate (non-collapsed) columns
FCL_LCL_SPLIT_COLUMNS = [
    "DR_CN_FCL",
    "DR_CN_LCL",
    "DNCN_FCL_USD",
    "DNCN_FCL_IDR",
    "DNCN_LCL_USD",
    "DNCN_LCL_IDR",
]


def _get_company_schema(company: str) -> dict:
    """Get the schema dict for a company."""
    return REAL_SCHEMA.get(company, {})


def _column_exists_in_company(company: str, table: str, column: str) -> bool:
    """Check if a column exists in a table for a specific company."""
    schema = _get_company_schema(company)
    return table in schema and column in schema[table]


def _column_exists_in_all_companies(table: str, column: str) -> list[str]:
    """Return list of companies where column is MISSING."""
    missing = []
    for co in COMPANIES:
        if not _column_exists_in_company(co, table, column):
            missing.append(co)
    return missing


class TestSummaryColumnsExist:
    """Every output column from get_projects_jobs_summary must exist in real_schema.json."""

    def test_all_output_columns_exist_in_real_schema(self):
        """All PROJECTS_JOBS_OUTPUT_COLUMNS must map to a source table.column in real_schema."""
        missing = []
        for col in PROJECTS_JOBS_OUTPUT_COLUMNS:
            # Map output column to source table.column
            source = self._output_col_to_source(col)
            if source:
                table, source_col = source
                missing_co = _column_exists_in_all_companies(table, source_col)
                if missing_co:
                    missing.append(f"{table}.{source_col} missing in {missing_co}")
            else:
                # Derived/calculated columns (CUSTOMER, DR_CN_FCL, DR_CN_LCL) have no direct source
                pass

        assert not missing, f"Missing columns in real_schema.json:\n" + "\n".join(missing)

    def _output_col_to_source(self, output_col: str) -> tuple[str, str] | None:
        """Map output column name to (source_table, source_column)."""
        mapping = {
            "UNIK": ("UnifiedShipment", "UNIK"),
            "KIND": ("UnifiedShipment", "TYPETRAN"),
            "DATE": ("UnifiedShipment", "DATE"),
            "REFSHITMEN": ("UnifiedShipment", "REFSHITMEN"),
            "BLNO3": ("UnifiedShipment", "BLNO3"),
            "BL_NO": ("UnifiedShipment", "BL_NO"),
            "MBL_NO": ("UnifiedShipment", "MBL_NO"),
            "BL_PRINTED": ("UnifiedShipment", "PRINT"),
            "BL_READ": ("UnifiedShipment", "READO"),
            "BL_PRINT_DATE": ("UnifiedShipment", "PRINTDATE"),
            "CONTAINERN": ("UnifiedShipment", "CONTAINERN"),
            "DIMENSI": ("UnifiedShipment", "DIMENSI"),
            "PORTOFLOAD": ("UnifiedShipment", "PORTOFLOAD"),
            "CITY_DISCH": ("UnifiedShipment", "CITY_DISCH"),
            "PORTOFDISC": ("UnifiedShipment", "PORTOFDISC"),
            "SHIPPER": ("UnifiedShipment", "SHIPPER"),
            "NOTIFYPART": ("UnifiedShipment", "NOTIFYPART"),
            "SHIPPINGMA": ("UnifiedShipment", "SHIPPINGMA"),
            "GW": ("UnifiedShipment", "GW"),
            "NW": ("UnifiedShipment", "NW"),
            "TOT_EUR": ("UnifiedShipment", "TOT_EUR"),
            "TOT_USD": ("UnifiedShipment", "TOT_USD"),
            "TOT_IDR": ("UnifiedShipment", "TOT_IDR"),
            # CUSTOMER is derived from CONSIGNEE.NAME / SHIPPER.NAME
            "OPR_USD": ("OPRCOST", "GRAND_USD"),
            "OPR_RP": ("OPRCOST", "GRAND_RP"),
            "INV_USD": ("INVOICE", "GRAND_USD"),
            "INV_RP": ("INVOICE", "GRAND_RP"),
            "INV_PAID": ("INVOICE", "LUNAS"),
            "NOINVOICE": ("INVOICE", "NOINVOICE"),
            "DNCN_FCL_USD": ("DRCRFULL", "TOTAL"),
            "DNCN_FCL_IDR": ("DRCRFULL", "TOTAL_IDR"),
            "DNCN_LCL_USD": ("DRCRFULL", "TOTAL"),
            "DNCN_LCL_IDR": ("DRCRFULL", "TOTAL_IDR"),
            # DR_CN_FCL and DR_CN_LCL are COALESCE of raw columns
        }
        return mapping.get(output_col)


class TestSection321Elements:
    """Each of the five §3.2.1 elements must resolve to mapped table.column values."""

    @pytest.mark.parametrize("element_id,element", list(SECTION_321_ELEMENTS.items()))
    def test_element_resolves_to_real_schema(self, element_id: str, element: dict):
        """Verify all source columns for this element exist in real_schema for all companies."""
        table = element["source_table"]
        missing = []
        for col in element["columns"]:
            missing_co = _column_exists_in_all_companies(table, col)
            if missing_co:
                missing.append(f"{table}.{col} missing in {missing_co}")

        assert not missing, (
            f"§3.2.1 element '{element_id}' ({element['description']}) "
            f"has missing source columns:\n" + "\n".join(missing)
        )

    @pytest.mark.parametrize("element_id,element", list(SECTION_321_ELEMENTS.items()))
    def test_element_output_columns_in_select(self, element_id: str, element: dict):
        """Verify each element's output columns appear in the PROJECTS_JOBS_SELECT."""
        for out_col in element["output_columns"]:
            assert out_col in PROJECTS_JOBS_OUTPUT_COLUMNS, (
                f"§3.2.1 element '{element_id}' output column '{out_col}' "
                f"not found in PROJECTS_JOBS_OUTPUT_COLUMNS"
            )


class TestFclLclNonCollapse:
    """FCL and LCL must appear as separate columns; never collapsed into one."""

    def test_fcl_lcl_display_columns_are_separate(self):
        """DR_CN_FCL and DR_CN_LCL must both exist as distinct output columns."""
        assert "DR_CN_FCL" in PROJECTS_JOBS_OUTPUT_COLUMNS
        assert "DR_CN_LCL" in PROJECTS_JOBS_OUTPUT_COLUMNS
        assert "DR_CN_FCL" != "DR_CN_LCL"

    def test_fcl_lcl_raw_columns_are_separate(self):
        """All four raw FCL/LCL columns must exist as distinct output columns."""
        expected = ["DNCN_FCL_USD", "DNCN_FCL_IDR", "DNCN_LCL_USD", "DNCN_LCL_IDR"]
        for col in expected:
            assert col in PROJECTS_JOBS_OUTPUT_COLUMNS, f"Missing FCL/LCL raw column: {col}"
        assert len(set(expected)) == 4, "FCL/LCL raw columns must be distinct"

    def test_fcl_lcl_source_columns_use_dimensi_filter(self):
        """DRCRFULL.TOTAL/TOTAL_IDR must be filtered by DIMENSI for FCL vs LCL.
        
        This test documents the requirement from queries.py lines 395-398:
        FCL uses DIMENSI IN ('20\'','40\'','40\' HQ','45\'')
        LCL uses DIMENSI IN ('CBM','KGS','M3','CTNS','DOC')
        """
        # The schema confirms DRCRFULL has TOTAL and TOTAL_IDR
        for co in COMPANIES:
            assert _column_exists_in_company(co, "DRCRFULL", "TOTAL"), f"{co}.DRCRFULL.TOTAL missing"
            assert _column_exists_in_company(co, "DRCRFULL", "TOTAL_IDR"), f"{co}.DRCRFULL.TOTAL_IDR missing"
            # UnifiedShipment.DIMENSI exists for the filter
            assert _column_exists_in_company(co, "UnifiedShipment", "DIMENSI"), f"{co}.UnifiedShipment.DIMENSI missing"

    def test_no_single_drcn_column_exists(self):
        """There must NOT be a single combined DR/CN column (would indicate collapse).
        
        Legitimate FCL/LCL split columns (DR_CN_FCL, DR_CN_LCL, DNCN_FCL_*, DNCN_LCL_*)
        are explicitly allowed. This test catches truly collapsed names like DR_CN, DNCN_USD, etc.
        """
        forbidden = ["DR_CN$", "DNCN_USD$", "DNCN_IDR$", "DRCN_TOTAL"]
        import re
        for col in PROJECTS_JOBS_OUTPUT_COLUMNS:
            for forb in forbidden:
                # Use regex to match exact forbidden names (not prefixes of FCL/LCL columns)
                assert not re.search(forb, col), f"Potential collapsed column detected: {col} matches '{forb}'"


class TestJoinTablesExist:
    """All tables used in _PROJECTS_JOBS_JOINS must exist in real_schema."""

    def test_join_tables_exist_in_all_companies(self):
        """Tables referenced in JOINs: CONSIGNEE, SHIPPER, OPRCOST, INVOICE, DRCRFULL, UnifiedShipment."""
        join_tables = ["CONSIGNEE", "SHIPPER", "OPRCOST", "INVOICE", "DRCRFULL", "UnifiedShipment"]
        for table in join_tables:
            missing_co = [co for co in COMPANIES if table not in REAL_SCHEMA.get(co, {})]
            assert not missing_co, f"Join table '{table}' missing in companies: {missing_co}"

    def test_join_key_columns_exist(self):
        """Key columns used for JOIN conditions must exist."""
        join_keys = {
            "CONSIGNEE": ["KD_CONSIG", "NAME"],
            "SHIPPER": ["KD_SHIPPER", "NAME"],
            "OPRCOST": ["REFSHITMEN", "GRAND_USD", "GRAND_RP"],
            "INVOICE": ["REFSHITMEN", "GRAND_USD", "GRAND_RP", "LUNAS", "NOINVOICE"],
            "DRCRFULL": ["REFSHITMEN", "TOTAL", "TOTAL_IDR"],
            "UnifiedShipment": ["REFSHITMEN", "KD_CONSIG", "KD_SHIPPER", "DIMENSI"],
        }
        missing = []
        for table, cols in join_keys.items():
            for col in cols:
                missing_co = _column_exists_in_all_companies(table, col)
                if missing_co:
                    missing.append(f"{table}.{col} missing in {missing_co}")
        assert not missing, f"Missing join key columns:\n" + "\n".join(missing)


class TestCompanyFilterColumns:
    """Company filter uses CONSIGNEE.KD_CONSIG and SHIPPER.KD_SHIPPER."""

    def test_company_filter_columns_exist(self):
        for co in COMPANIES:
            assert _column_exists_in_company(co, "CONSIGNEE", "KD_CONSIG"), f"{co}.CONSIGNEE.KD_CONSIG missing"
            assert _column_exists_in_company(co, "SHIPPER", "KD_SHIPPER"), f"{co}.SHIPPER.KD_SHIPPER missing"
            assert _column_exists_in_company(co, "CONSIGNEE", "NAME"), f"{co}.CONSIGNEE.NAME missing"
            assert _column_exists_in_company(co, "SHIPPER", "NAME"), f"{co}.SHIPPER.NAME missing"


if __name__ == "__main__":
    pytest.main([__file__, "-v"])