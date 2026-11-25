<?php

namespace App\Support;

/**
 * Python scalar-rendering parity helpers. Values that Python interpolates
 * with {{ value }} (raw str(), NOT the money filter) must render with
 * Python's spelling: float 1.0 -> "1.0" (PHP gives "1").
 */
class CmtPy
{
    public static function scalar(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_bool($v)) {
            return $v ? 'True' : 'False';
        }
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_float($v)) {
            // Integral floats keep Python's trailing ".0".
            if ($v == (int) $v && abs($v) < 1e15) {
                return ((string) (int) $v).'.0';
            }

            return (string) $v;
        }

        return (string) $v;
    }
}
