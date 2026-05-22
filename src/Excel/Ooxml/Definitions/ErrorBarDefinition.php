<?php

namespace Procorad\ProcostatReporting\Excel\Ooxml\Definitions;

/**
 * Declarative definition of OOXML error bars for a chart series.
 *
 * Maps to the <c:errBars> element in OOXML chart XML.
 *
 * @see https://docs.microsoft.com/en-us/openspecs/office_standards/ms-ooxml
 */
final readonly class ErrorBarDefinition
{
    /**
     * @param string $type      'both' | 'plus' | 'minus'
     * @param string $valType   'cust' | 'fixedVal' | 'percentage' | 'stdDev' | 'stdErr'
     * @param string $plusRef   Spreadsheet formula reference for + values, e.g. "'Sheet'!$C$2:$C$10"
     * @param string $minusRef  Spreadsheet formula reference for − values (same as plus for symmetric)
     * @param bool   $noEndCap  Whether to hide the end cap on error bar lines
     */
    public function __construct(
        public string $plusRef,
        public string $minusRef,
        public string $type     = 'both',
        public string $valType  = 'cust',
        public bool   $noEndCap = false,
    ) {}

    /**
     * Symmetric error bars using a single column reference for both ± directions.
     */
    public static function symmetric(string $ref, bool $noEndCap = false): self
    {
        return new self(
            plusRef:  $ref,
            minusRef: $ref,
            noEndCap: $noEndCap,
        );
    }
}
