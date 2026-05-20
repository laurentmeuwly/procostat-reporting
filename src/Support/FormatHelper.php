<?php

namespace Procorad\ProcostatReporting\Support;

final class FormatHelper
{
    /**
     * Format a float in Procorad scientific notation: 2.52E+03
     */
    public static function scientific(?float $value, int $decimals = 2): string
    {
        if ($value === null || $value == 0.0) {
            return '0.00E+00';
        }
        $exp      = (int) floor(log10(abs($value)));
        $mantissa = $value / (10 ** $exp);

        return sprintf("%.{$decimals}fE%+03d", $mantissa, $exp);
    }

    /**
     * Hex colour (no #) for a z-score cell: blue OK / orange warning / red action.
     */
    public static function zscoreColor(
        float $zscore,
        float $warnLow  = -2.0,
        float $warnHigh = 2.0,
        float $actLow   = -3.0,
        float $actHigh  = 3.0,
    ): string {
        if ($zscore <= $actLow || $zscore >= $actHigh) {
            return 'FF0000'; // action — red
        }
        if ($zscore <= $warnLow || $zscore >= $warnHigh) {
            return 'FFA500'; // warning — orange
        }

        return '4472C4'; // satisfactory — blue
    }
}
