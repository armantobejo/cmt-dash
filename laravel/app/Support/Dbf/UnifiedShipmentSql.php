<?php

namespace App\Support\Dbf;

/**
 * Port of tools/schema.py — single source of truth for the UnifiedShipment
 * build SQL (PRD §3: "ported verbatim"). The Python file stays the reference;
 * this port must be updated in lockstep (byte-comparison gate enforces it).
 *
 * Merges NA (import) + CARGO (export/other) into one row per shipment with
 * window-function dedup (R3): first UNIK per REFSHITMEN wins, GW/NW/MEAST are
 * partition sums across all duplicates.
 */
class UnifiedShipmentSql
{
    /** NA (import) branch of the unified select, in column order. */
    public const NA_SELECT_PARTS = [
        'UNIK', 'SALES', 'THN', 'BLNO3', 'DATE', 'REFSHITMEN', 'AGENT', 'CODE',
        'KD_CONSIG', 'CONSIGNEE', 'CONTAINERN', 'DIMENSI', 'GODOWN', 'PORTOFLOAD',
        'CITY_DISCH', 'PORTOFDISC', 'KD_SHIPPER', 'SHIPPER', 'KD_NOTIFY',
        'NOTIFYPART', 'SHIPPINGMA', 'GW', 'NW', 'MEAST', 'MEAST_SAT', 'SAIDTOCONT',
        'KD_SATUAN', 'PREPAID', 'OPR', 'PRINT', 'PRINTDATE', 'READO',
        // NA-only fields
        "'I' AS TYPETRAN", 'BL_NO', 'MBL_NO', 'KD_EXVESSE', 'EXVOYAGE', 'ETA',
        'NULL AS ETD', 'NULL AS ATA',
        'ENTRY', 'PBM', 'CONS_NOTI', 'CITY_FROM', 'DESCRIPTIO', 'TOT_EUR',
        'TOT_USD', 'TOT_IDR',
    ];

    /** CARGO (export/other) branch, in column order. */
    public const CARGO_SELECT_PARTS = [
        'UNIK', 'SALES', 'THN', 'BLNO3', 'DATE', 'REFSHITMEN', 'AGENT', 'CODE',
        'KD_CONSIG', 'CONSIGNEE', 'CONTAINERN', 'DIMENSI', 'GODOWN', 'PORTOFLOAD',
        'CITY_DISCH', 'PORTOFDISC', 'KD_SHIPPER', 'SHIPPER', 'KD_NOTIFY',
        'NOTIFYPART', 'SHIPPINGMA', 'GW', 'NW', 'MEAST', 'MEAST_SAT', 'SAIDTOCONT',
        'KD_SATUAN', 'PREPAID', 'OPR', 'PRINT', 'PRINTDATE', 'READO',
        // CARGO-specific equivalent of TYPETRAN
        'TYPETRAN',
        // NA-only fields set to NULL for CARGO
        'NULL AS BL_NO', 'NULL AS MBL_NO', 'NULL AS KD_EXVESSE', 'NULL AS EXVOYAGE',
        'NULL AS ETA', 'ETDSIN AS ETD', 'ARRIVAL_TI AS ATA',
        'NULL AS ENTRY', 'NULL AS PBM', 'NULL AS CONS_NOTI',
        'NULL AS CITY_FROM', 'NULL AS DESCRIPTIO', 'NULL AS TOT_EUR',
        'NULL AS TOT_USD', 'NULL AS TOT_IDR',
    ];

    /**
     * Inner SELECT that merges NA + CARGO into one row per shipment
     * (tools/schema.py::_unified_select_sql). *$tables* is the set of
     * available source tables; defaults to both (backward-compatible).
     */
    public static function unifiedSelectSql(?array $tables = null): string
    {
        $tables = $tables ?? ['NA', 'CARGO'];

        $parts = [];
        if (in_array('NA', $tables, true)) {
            $parts[] = 'SELECT '.implode(', ', self::NA_SELECT_PARTS).' FROM NA';
        }
        if (in_array('CARGO', $tables, true)) {
            $parts[] = 'SELECT '.implode(', ', self::CARGO_SELECT_PARTS).' FROM CARGO';
        }
        if ($parts === []) {
            return 'SELECT 1 WHERE 0'; // empty result, should never be used
        }
        $union = implode("\nUNION ALL\n", $parts);

        return "SELECT UNIK, SALES, THN, BLNO3, DATE, REFSHITMEN, AGENT, CODE, KD_CONSIG, "
            .'CONSIGNEE, CONTAINERN, DIMENSI, GODOWN, PORTOFLOAD, CITY_DISCH, PORTOFDISC, '
            .'KD_SHIPPER, SHIPPER, KD_NOTIFY, NOTIFYPART, SHIPPINGMA, '
            // Bare _GW/_NW/_MEAST aliases, exactly as schema.py writes them.
            .'_GW AS GW, _NW AS NW, _MEAST AS MEAST, '
            .'MEAST_SAT, SAIDTOCONT, KD_SATUAN, PREPAID, OPR, PRINT, PRINTDATE, READO, '
            .'TYPETRAN, BL_NO, MBL_NO, KD_EXVESSE, EXVOYAGE, ETA, ETD, ATA, ENTRY, PBM, CONS_NOTI, '
            ."CITY_FROM, DESCRIPTIO, TOT_EUR, TOT_USD, TOT_IDR\n"
            ."FROM (\n"
            .'  SELECT *, '
            ."ROW_NUMBER() OVER (PARTITION BY COALESCE(NULLIF(REFSHITMEN, ''), UNIK) "
            .'ORDER BY UNIK) AS _rn, '
            ."SUM(GW) OVER (PARTITION BY COALESCE(NULLIF(REFSHITMEN, ''), UNIK)) AS _GW, "
            ."SUM(NW) OVER (PARTITION BY COALESCE(NULLIF(REFSHITMEN, ''), UNIK)) AS _NW, "
            ."SUM(MEAST) OVER (PARTITION BY COALESCE(NULLIF(REFSHITMEN, ''), UNIK)) AS _MEAST\n"
            ."  FROM (\n"
            ."    {$union}\n"
            ."  )\n"
            .') WHERE _rn = 1';
    }

    /**
     * Drop + recreate UnifiedShipment as a real TABLE (R3) on *$pdo*.
     * Skips gracefully when neither NA nor CARGO exists.
     */
    public static function createTable(\PDO $pdo): void
    {
        $existing = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name IN ('NA','CARGO')"
        )->fetchAll(\PDO::FETCH_COLUMN);
        if ($existing === [] || $existing === false) {
            echo "  [schema] Skipping UnifiedShipment: neither NA nor CARGO table found\n";

            return;
        }

        $type = $pdo->query(
            "SELECT type FROM sqlite_master WHERE name='UnifiedShipment'"
        )->fetchColumn();
        if ($type !== false && $type !== null && $type !== '') {
            $pdo->exec('DROP '.strtoupper((string) $type).' UnifiedShipment');
        }

        $pdo->exec('CREATE TABLE UnifiedShipment AS '.self::unifiedSelectSql($existing));
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_us_ref ON UnifiedShipment(REFSHITMEN)');
    }
}
