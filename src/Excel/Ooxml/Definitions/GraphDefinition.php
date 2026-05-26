<?php

namespace Procorad\ProcostatReporting\Excel\Ooxml\Definitions;

/**
 * Canonical chart definition — format-agnostic.
 *
 * @param string $chartStyle      'results' (line+markers+errorbars) | 'bar' (vertical bar chart)
 * @param bool   $showThresholds  bar only: draw threshold reference lines
 * @param float  $thresholdLow    lower action threshold (red, e.g. -3.0 for z-scores, -25.0 for bias)
 * @param float  $thresholdHigh   upper action threshold (red, e.g. +3.0 for z-scores, +50.0 for bias)
 * @param float|null $warningLow  lower warning threshold (orange, e.g. -2.0 for z-scores; null = no warning lines)
 * @param float|null $warningHigh upper warning threshold (orange, e.g. +2.0 for z-scores; null = no warning lines)
 */
final readonly class GraphDefinition
{
    public function __construct(
        public string  $type,
        public string  $title,
        public string  $xAxisLabel,
        public string  $yAxisLabel,
        public array   $categories,
        public array   $values,
        public array   $errorBars,
        public float   $assignedValue,
        public float   $assignedUpper,
        public float   $assignedLower,
        public float   $yMin,
        public float   $yMax,
        public string  $isotope,
        public string  $sampleCode,
        public string  $chartStyle     = 'results',
        public bool    $showThresholds = false,
        public float   $thresholdLow   = -3.0,
        public float   $thresholdHigh  = 3.0,
        public ?float  $warningLow     = null,   // orange warning line (lower); null = none
        public ?float  $warningHigh    = null,   // orange warning line (upper); null = none
    ) {}
}
