<?php

namespace App\Support\Dbf;

use XBase\Column\XBaseColumn;
use XBase\Enum\FieldType;
use XBase\TableReader;

/**
 * Facade over hisamu/php-xbase — the ONLY file that knows the library
 * (docs/decisions/dbf-reading.md §8; bus-factor mitigation = one file to fork
 * or rewrite). Ports Python sync_db.py's reading layer:
 *
 *   - encoding fallback chain latin-1 → cp437 → utf-8 (R4b). Python passes
 *     those names to dbfread; iconv (used by php-xbase) rejects 'latin-1' as
 *     an alias on some platforms, so the chain is expressed with iconv-safe
 *     names that denote the same byte mappings.
 *   - per-field tolerance → NULL (R4): Python's _RobustParser catches
 *     field-level parse errors; here every field read is wrapped and a failed
 *     read yields NULL for that cell instead of aborting the table.
 *   - duplicate field-name dedup, first wins (R4c) — the DBF spec forbids
 *     duplicate names but legacy FoxPro files contain them; php-xbase keeps
 *     them all, so dedup happens here.
 *
 * Names are re-uppercased: DBF headers store names upper-case and Python's
 * dbfread surfaces them as-is, but php-xbase lower-cases them on read. The
 * SQLite schema (and every query in both apps) depends on upper-case names.
 */
class DbfReader
{
    /** iconv-safe spellings of Python's ('latin-1', 'cp437', 'utf-8') chain. */
    public const ENCODINGS = ['ISO-8859-1', 'CP437', 'UTF-8'];

    /**
     * Read a DBF file. Returns null when no encoding could read it (Python:
     * "FAILED to load ... with any encoding").
     *
     * @return array{fields: list<array{name:string, type:string, length:int, dec:int}>, rows: list<array<string,?string>>}|null
     */
    public function read(string $path): ?array
    {
        foreach (self::ENCODINGS as $encoding) {
            try {
                $table = new TableReader($path, ['encoding' => $encoding]);
            } catch (\Throwable) {
                continue; // header/structure unreadable under this encoding
            }

            $fields = [];
            $seen = [];
            /** @var XBaseColumn $col */
            foreach ($table->getColumns() as $col) {
                $name = strtoupper($col->getName());
                if (isset($seen[$name])) {
                    continue; // duplicate field name: first field wins (R4c)
                }
                $seen[$name] = true;
                $fields[] = [
                    'name' => $name,
                    'type' => $col->getType(),
                    'length' => $col->getLength(),
                    'dec' => (int) ($col->getDecimalCount() ?? 0),
                ];
            }

            $names = array_map(fn (array $f) => $f['name'], $fields);
            $types = array_map(fn (array $f) => $f['type'], $fields);
            $rows = [];
            try {
                while ($rec = $table->nextRecord()) {
                    if ($rec->isDeleted()) {
                        continue; // dbfread skips deleted records; so do we
                    }
                    $row = [];
                    foreach (array_keys($names) as $i) {
                        $row[$names[$i]] = $this->readField($rec, $names[$i], $types[$i]);
                    }
                    $rows[] = $row;
                }
            } catch (\Throwable $e) {
                error_log('[dbf] record iteration failed for '.$path.': '.$e->getMessage());

                return null; // mid-file corruption → treat like unreadable
            } finally {
                try {
                    $table->close();
                } catch (\Throwable) {
                }
            }

            return ['fields' => $fields, 'rows' => $rows];
        }

        return null;
    }

    /**
     * Read one field with Python _RobustParser semantics: any parse failure
     * becomes NULL instead of an exception (R4). Memo fields read via the
     * reader's memo object (FPT support); missing memos become NULL (Python:
     * ignore_missing_memofile=True).
     */
    protected function readField($rec, string $name, string $type): ?string
    {
        try {
            $value = $type === FieldType::MEMO ? $rec->getMemoObject($name) : $rec->get($name);
        } catch (\Throwable) {
            return null;
        }

        if (is_object($value)) {
            try {
                $value = (string) $value;
            } catch (\Throwable) {
                return null;
            }
        }

        return $value === null ? null : (string) $value;
    }
}
