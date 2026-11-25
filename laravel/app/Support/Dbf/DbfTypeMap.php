<?php

namespace App\Support\Dbf;

/**
 * Port of tools/sync_db.py::dbf_to_sqlite_type + convert_val — same mapping
 * table, same NULL-on-failure semantics (R4).
 *
 * PHP-specific care: Python's `if not value` treats '' as empty but '0' as a
 * real value; PHP's falsy check would also drop '0'. The emptiness test here
 * is therefore explicit (`$value === null || $value === ''`).
 */
class DbfTypeMap
{
    /** @return array{sql:string, conv:string} */
    public static function sqliteType(string $type, int $dec = 0): array
    {
        return match ($type) {
            'C', 'M' => ['sql' => 'TEXT', 'conv' => 'text'],
            'L' => ['sql' => 'INTEGER', 'conv' => 'bool'],
            'D' => ['sql' => 'TEXT', 'conv' => 'date'],
            'N' => $dec === 0
                ? ['sql' => 'INTEGER', 'conv' => 'int']
                : ['sql' => 'REAL', 'conv' => 'float'],
            default => ['sql' => 'TEXT', 'conv' => 'text'], // B, F, I, 0, ...
        };
    }

    /**
     * Clean and convert one raw DBF field value. Any conversion failure
     * returns NULL — a corrupt cell never aborts the sync (R4).
     */
    public static function convert(?string $value, string $conv)
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') { // Python `if not value` — but ONLY '' here, not '0'
            return null;
        }

        try {
            switch ($conv) {
                case 'text':
                    return (string) $value;
                case 'bool':
                    // dbfread/xbase hand L fields back as real booleans; Python's
                    // `if not value` turns False into NULL (stored, not 0).
                    // readField stringifies: false -> '' (caught above),
                    // true -> '1' (-> 1). A raw 'T'/'F' string keeps Python's
                    // string-path semantics ('F' is truthy -> 0).
                    return in_array(strtoupper($value), ['T', 'Y', '1', 'TRUE'], true) ? 1 : 0;
                case 'date':
                    return self::convertDate($value);
                case 'int':
                case 'float':
                    // Python: dbfread parses N fields to int/float BEFORE
                    // convert_val, so a ZERO numeric hits `if not value` and
                    // becomes NULL — a 0/0.0 cell is never stored as 0.
                    // int(float(v)) then truncates toward zero ('0.5' -> 0).
                    if (! is_numeric($value) || (float) $value == 0.0) {
                        return null;
                    }

                    return $conv === 'int' ? (int) (float) $value : (float) $value;
            }
        } catch (\Throwable) {
            return null;
        }

        return (string) $value;
    }

    /**
     * dbfread parses D fields into datetime.date objects (raising on invalid
     * calendar dates, which _RobustParser swallows to NULL) and convert_val
     * then renders date.isoformat(). This mirrors the observable outcome:
     * 'YYYYMMDD' → 'YYYY-MM-DD' when the calendar date is valid, NULL
     * otherwise ('00000000', '20260230', garbage).
     */
    protected static function convertDate(string $value): ?string
    {
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m) !== 1) {
            return null; // dbfread parseD would raise → NULL
        }
        [, $y, $mo, $d] = $m;
        if (! checkdate((int) $mo, (int) $d, (int) $y)) {
            return null;
        }

        return "{$y}-{$mo}-{$d}";
    }
}
