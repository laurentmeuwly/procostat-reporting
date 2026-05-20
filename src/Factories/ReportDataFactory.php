<?php

declare(strict_types=1);

namespace Procorad\ProcostatReporting\Factories;

use Procorad\ProcostatReporting\DTO\ChartConfig;
use Procorad\ProcostatReporting\DTO\LabResult;
use Procorad\ProcostatReporting\DTO\ProcostatReportData;
use Procorad\ProcostatReporting\DTO\StatisticsData;
use Procorad\ProcostatReporting\DTO\ZscoreLimits;

/**
 * Anti-corruption layer between the procostat statistics engine and the reporting layer.
 *
 * The fromProcostatResult() method accepts whatever shape ProcostatResult has
 * at the time, and maps it to our stable DTO. If ProcostatResult changes,
 * only this factory needs updating — not the generators.
 *
 * The $procostatResult parameter is typed as mixed (or object) intentionally:
 * we do not want a hard compile-time dependency on the procostat package here.
 * Type safety is enforced at runtime via the explicit property accesses below.
 *
 * Usage (from the Procorad app):
 *
 *   $reportData = ReportDataFactory::fromProcostatResult(
 *       result: $dataResult,
 *       intercomparison: 'CARBON 14',
 *       sample: '25CB',
 *       isotope: '14C',
 *       year: 2025,
 *       locale: 'fr',
 *   );
 */
final class ReportDataFactory
{
    /**
     * Build a ProcostatReportData from a ProcostatResult object.
     *
     * @param  object              $result         The immutable DataResult from procostat
     * @param  string              $intercomparison Human-readable IC name
     * @param  string              $sample          Sample code
     * @param  string              $isotope         Isotope label
     * @param  int                 $year            Campaign year
     * @param  string              $locale          Locale for labels ('fr', 'en', …)
     * @param  array<string,mixed> $extraMetadata   Optional extra fields passed through to metadata
     */
    public static function fromProcostatResult(
        object $result,
        string $intercomparison,
        string $sample,
        string $isotope,
        int $year,
        string $locale = 'fr',
        array $extraMetadata = [],
    ): ProcostatReportData {
        return new ProcostatReportData(
            intercomparison: $intercomparison,
            sample:          $sample,
            isotope:         $isotope,
            year:            $year,
            unit:            $result->unit,
            statistics:      self::buildStatistics($result),
            zscoreLimits:    self::buildZscoreLimits($result),
            labResults:      self::buildLabResults($result),
            chartConfigs:    self::buildChartConfigs($isotope, $sample),
            metadata:        array_merge([
                'locale'            => $locale,
                'propertyFileTitle' => 'Property File Title',
                'generatedAt'       => now()->toIso8601String(),
                'packageVersion'    => '1.0.0',
            ], $extraMetadata),
        );
    }

    /**
     * Build a ProcostatReportData directly from raw arrays (useful for testing
     * or when the procostat package is not available).
     *
     * @param  array<string, mixed>      $statistics
     * @param  array<int, array<string,mixed>> $labResults
     * @param  array<string, float>      $zscoreLimits
     * @param  array<string, mixed>      $metadata
     */
    public static function fromArrays(
        string $intercomparison,
        string $sample,
        string $isotope,
        int $year,
        string $unit,
        array $statistics,
        array $labResults,
        array $zscoreLimits = [],
        array $metadata = [],
    ): ProcostatReportData {
        return new ProcostatReportData(
            intercomparison: $intercomparison,
            sample:          $sample,
            isotope:         $isotope,
            year:            $year,
            unit:            $unit,
            statistics:      new StatisticsData(
                numberOfResults:     $statistics['numberOfResults']     ?? 0,
                assignedValue:       $statistics['assignedValue']       ?? 0.0,
                assignedUncertainty: $statistics['assignedUncertainty'] ?? 0.0,
                robustMean:          $statistics['robustMean']          ?? 0.0,
                robustDeviation:     $statistics['robustDeviation']     ?? 0.0,
                geometricalMean:     $statistics['geometricalMean']     ?? 0.0,
                minValue:            $statistics['minValue']            ?? 0.0,
                maxValue:            $statistics['maxValue']            ?? 0.0,
            ),
            zscoreLimits: new ZscoreLimits(
                warningLow:  $zscoreLimits['warningLow']  ?? -2.0,
                warningHigh: $zscoreLimits['warningHigh'] ?? 2.0,
                actionLow:   $zscoreLimits['actionLow']   ?? -3.0,
                actionHigh:  $zscoreLimits['actionHigh']  ?? 3.0,
            ),
            labResults:   array_map(
                fn (array $r) => new LabResult(
                    labNumber:           $r['labNumber'],
                    activity:            $r['activity'],
                    expandedUncertainty: $r['expandedUncertainty'],
                    detectionLimit:      $r['detectionLimit'],
                    bias:                $r['bias'],
                    enScore:             $r['enScore'],
                    zscore:              $r['zscore'],
                ),
                $labResults
            ),
            chartConfigs: self::buildChartConfigs($isotope, $sample),
            metadata:     $metadata,
        );
    }

    // ── Private mapping helpers ──────────────────────────────────────────────

    private static function buildStatistics(object $result): StatisticsData
    {
        // Adapt to your actual ProcostatResult property names
        return new StatisticsData(
            numberOfResults:     $result->numberOfResults,
            assignedValue:       $result->assignedValue,
            assignedUncertainty: $result->assignedUncertainty,
            robustMean:          $result->robustMean,
            robustDeviation:     $result->robustDeviation,
            geometricalMean:     $result->geometricalMean,
            minValue:            $result->minValue,
            maxValue:            $result->maxValue,
        );
    }

    private static function buildZscoreLimits(object $result): ZscoreLimits
    {
        return new ZscoreLimits(
            warningLow:  $result->zscoreLimits['warning'][0] ?? -2.0,
            warningHigh: $result->zscoreLimits['warning'][1] ?? 2.0,
            actionLow:   $result->zscoreLimits['action'][0]  ?? -3.0,
            actionHigh:  $result->zscoreLimits['action'][1]  ?? 3.0,
        );
    }

    /** @return LabResult[] */
    private static function buildLabResults(object $result): array
    {
        return array_map(
            fn (object $r) => new LabResult(
                labNumber:           $r->labNumber,
                activity:            $r->activity,
                expandedUncertainty: $r->expandedUncertainty,
                detectionLimit:      $r->detectionLimit,
                bias:                $r->bias,
                enScore:             $r->enScore,
                zscore:              $r->zscore,
            ),
            $result->labResults,
        );
    }

    /** @return ChartConfig[] */
    private static function buildChartConfigs(string $isotope, string $sample): array
    {
        return [
            new ChartConfig('results',        "{$isotope} Results ({$sample})"),
            new ChartConfig('results_sorted',  "{$isotope} Results ({$sample}) — sorted"),
            new ChartConfig('bias',            "{$isotope} Bias ({$sample})"),
            new ChartConfig('zscore',          "{$isotope} Z-score ({$sample})"),
            new ChartConfig('en',              "{$isotope} En ({$sample})"),
        ];
    }
}
