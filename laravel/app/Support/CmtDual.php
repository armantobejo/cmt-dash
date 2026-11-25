<?php

namespace App\Support;

/**
 * Port of the Jinja `dual` macro used by index.html/shipments.html money
 * cells: '-' when BOTH are null; USD when present else IDR; a muted IDR line
 * under the USD when both exist. Returns raw HTML segments for Blade.
 */
class CmtDual
{
    /** Blade-facing convenience: the HTML segment only (macro's cell output). */
    public static function cell(mixed $usd, mixed $idr): string
    {
        return self::render($usd, $idr)[0];
    }

    /** @return array{0:string,1:bool} [html, hasContent] */
    public static function render(mixed $usd, mixed $idr): array
    {
        if ($usd === null && $idr === null) {
            return ['-', false];
        }

        $html = $usd !== null
            ? \App\Support\CmtMoney::format($usd)
            : \App\Support\CmtMoney::format($idr, 'idr');

        if ($usd !== null && $idr !== null) {
            $html .= '<br/><span class="text-muted-custom">'.\App\Support\CmtMoney::format($idr, 'idr').'</span>';
        }

        return [$html, true];
    }
}
