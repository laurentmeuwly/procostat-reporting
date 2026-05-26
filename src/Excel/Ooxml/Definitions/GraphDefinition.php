<?php

namespace Procorad\ProcostatReporting\Excel\Ooxml\Definitions;

/**
 * Canonical chart definition — format-agnostic.
 *
 * @param string $chartStyle  'results' (line+markers+errorbars) | 'bar' (vertical bar chart)
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
        public array   $errorBars,       // empty for bar charts
        public float   $assignedValue,   // 0.0 for bar charts
        public float   $assignedUpper,   // 0.0 for bar charts
        public float   $assignedLower,   // 0.0 for bar charts
        public float   $yMin,            // 0.0 for results, negative for scores
        public float   $yMax,
        public string  $isotope,
        public string  $sampleCode,
        public string  $chartStyle = 'results', // 'results' | 'bar'
        public bool    $showThresholds = false,  // bar only: draw ±2/±3 lines
    ) {}
}
