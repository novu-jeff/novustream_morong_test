<?php

namespace App\Helper;

class NumberHelper
{
    public static function convertToWords($amount)
{
    $f = new \NumberFormatter("en", \NumberFormatter::SPELLOUT);

    // Split amount into whole pesos and centavos
    $pesos = floor($amount);
    $centavos = round(($amount - $pesos) * 100);

    // Convert pesos to words and capitalize each word
    $words = ucwords($f->format($pesos));

    // Format centavos as 2-digit string (e.g., 5 → 05)
    $centavosFormatted = str_pad($centavos, 2, '0', STR_PAD_LEFT);

    return $words . ' Pesos & ' . $centavosFormatted . '/100';
}


}
