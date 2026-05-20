<?php

declare(strict_types=1);

namespace Procorad\ProcostatReporting\DTO;

/**
 * Central DTO for document rendering.
 *
 * Deliberately decoupled from ProcostatResult so that:
 *  - reporting can evolve independently from the statistics engine
 *  - labels/translations are resolved before entering the rendering layer
 *  - the DTO can be serialised to JSON for Node.js renderers or API responses
 *
 * Populated by ReportDataFactory::fromProcostatResult().
 */
final readonly class ProcostatReportData
{
    /**
     * @param  string                 $intercomparison  IC identifier, e.g. "CARBON 14"
     * @param  string                 $sample           Sample code, e.g. "25CB"
     * @param  string                 $isotope          Isotope label, e.g. "14C"
     * @param  int                    $year             Campaign year
     * @param  string                 $unit             Measurement unit, e.g. "Bq/l"
     * @param  StatisticsData         $statistics       Descriptive statistics for this sample/isotope
     * @param  ZscoreLimits           $zscoreLimits     Warning/action thresholds
     * @param  LabResult[]            $labResults       One entry per participating laboratory
     * @param  ChartConfig[]          $chartConfigs     Declarative chart definitions (not rendered images)
     * @param  array<string, mixed>   $metadata         Arbitrary key/value pairs (locale, version, …)
     */
    public function __construct(
        public string $intercomparison,
        public string $sample,
        public string $isotope,
        public int $year,
        public string $unit,
        public StatisticsData $statistics,
        public ZscoreLimits $zscoreLimits,
        public array $labResults,
        public array $chartConfigs,
        public array $metadata = [],
    ) {}

    /**
     * Serialise to a plain array suitable for json_encode() or Node.js payload.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'intercomparison' => $this->intercomparison,
            'sample'          => $this->sample,
            'isotope'         => $this->isotope,
            'year'            => $this->year,
            'unit'            => $this->unit,
            'statistics'      => $this->statistics->toArray(),
            'zscoreLimits'    => $this->zscoreLimits->toArray(),
            'labResults'      => array_map(fn (LabResult $r) => $r->toArray(), $this->labResults),
            'chartConfigs'    => array_map(fn (ChartConfig $c) => $c->toArray(), $this->chartConfigs),
            'metadata'        => $this->metadata,
        ];
    }
}
