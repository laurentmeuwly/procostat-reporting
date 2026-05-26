<?php

namespace Procorad\ProcostatReporting\Excel\Ooxml\Definitions;

use Procorad\ProcostatReporting\Data\SampleAnalysisData;

/**
 * Builds all GraphDefinition objects from a SampleAnalysisData.
 *
 * Chart inventory:
 *   0. results_lab_asc  — line+markers, sorted by lab number
 *   1. results_val_asc  — line+markers, sorted by activity
 *   2. bias             — bar chart, biasPercent, sorted by value asc
 *   3. zprime_score     — bar chart, zPrimeScore (only if evaluated n >= 12)
 *   4. zeta_score       — bar chart, zetaScore, sorted by value asc
 *
 * All charts use only "evaluated" labs: isIncluded=true, isTruncated=false, isBelowLod=false.
 */
final class GraphDefinitionFactory
{
    private const ZPRIME_MIN_POPULATION = 12;

    // Threshold lines for score charts (warning/action)
    private const SCORE_WARNING = 2.0;
    private const SCORE_ACTION  = 3.0;

    /**
     * @return GraphDefinition[]
     */
    public function fromAnalysis(SampleAnalysisData $analysis): array
    {
        $evaluated = $this->evaluatedLabs($analysis);

        $graphs = [
            $this->resultsLabAsc($analysis, $evaluated),
            $this->resultsValAsc($analysis, $evaluated),
            $this->bias($analysis, $evaluated),
        ];

        // zprime only when evaluated population warrants it
        if (count($evaluated) >= self::ZPRIME_MIN_POPULATION) {
            $graphs[] = $this->zprimeScore($analysis, $evaluated);
        }

        $graphs[] = $this->zetaScore($analysis, $evaluated);

        return $graphs;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Returns only labs that are fully evaluated:
     *   - included in statistics (isIncluded = true)
     *   - not truncated / z > 5 (isTruncated = false)
     *   - result not below detection limit (isBelowLod = false)
     *
     * @return \Procorad\ProcostatReporting\Data\LabResultData[]
     */
    private function evaluatedLabs(SampleAnalysisData $analysis): array
    {
        return array_values(array_filter(
            $analysis->labResults,
            fn ($l) => $l->isIncluded && !$l->isTruncated && !$l->isBelowLod,
        ));
    }

    // ── Results charts ────────────────────────────────────────────────────────

    public function resultsLabAsc(SampleAnalysisData $analysis, ?array $labs = null): GraphDefinition
    {
        $labs ??= $this->evaluatedLabs($analysis);
        $labs = collect($labs)->sortBy('labNumber')->values()->all();
        return $this->buildResultsGraph($analysis, $labs, 'results_lab_asc',
            "{$analysis->isotope} Results ({$analysis->sampleCode})");
    }

    public function resultsValAsc(SampleAnalysisData $analysis, ?array $labs = null): GraphDefinition
    {
        $labs ??= $this->evaluatedLabs($analysis);
        $labs = collect($labs)->sortBy('activity')->values()->all();
        return $this->buildResultsGraph($analysis, $labs, 'results_val_asc',
            "{$analysis->isotope} Results ({$analysis->sampleCode})");
    }

    // ── Score / bar charts ────────────────────────────────────────────────────

    public function bias(SampleAnalysisData $analysis, ?array $labs = null): GraphDefinition
    {
        $labs   = collect($labs ?? $this->evaluatedLabs($analysis))->sortBy('biasPercent')->values()->all();
        $values = array_map(fn ($l) => (float) ($l->biasPercent ?? 0.0), $labs);

        // Y axis: accommodate the asymmetric thresholds (-25 / +50) plus some headroom
        $yMin = $this->niceMin(min(min($values) * 1.10, -30.0));
        $yMax = $this->niceMax(max(max($values) * 1.10,  55.0));

        return $this->buildBarGraph(
            analysis:       $analysis,
            labs:           $labs,
            values:         $values,
            type:           'bias',
            title:          "{$analysis->isotope} Bias ({$analysis->sampleCode})",
            yLabel:         '%',
            yMin:           $yMin,
            yMax:           $yMax,
            showThresholds: true,
            thresholdLow:   -25.0,
            thresholdHigh:  50.0,
        );
    }

    public function zprimeScore(SampleAnalysisData $analysis, ?array $labs = null): GraphDefinition
    {
        $labs   = collect($labs ?? $this->evaluatedLabs($analysis))->sortBy('zPrimeScore')->values()->all();
        $values = array_map(fn ($l) => (float) ($l->zPrimeScore ?? 0.0), $labs);

        return $this->buildBarGraph(
            analysis:       $analysis,
            labs:           $labs,
            values:         $values,
            type:           'zprime_score',
            title:          "{$analysis->isotope} Z'-score ({$analysis->sampleCode})",
            yLabel:         "Z'",
            yMin:           $this->scoreAxisMin($values),
            yMax:           $this->scoreAxisMax($values),
            showThresholds: true,
            thresholdLow:   -3.0,
            thresholdHigh:  3.0,
            warningLow:     -2.0,
            warningHigh:    2.0,
        );
    }

    public function zetaScore(SampleAnalysisData $analysis, ?array $labs = null): GraphDefinition
    {
        $labs   = collect($labs ?? $this->evaluatedLabs($analysis))->sortBy('zetaScore')->values()->all();
        $values = array_map(fn ($l) => (float) ($l->zetaScore ?? 0.0), $labs);

        return $this->buildBarGraph(
            analysis:       $analysis,
            labs:           $labs,
            values:         $values,
            type:           'zeta_score',
            title:          "{$analysis->isotope} Zeta-score ({$analysis->sampleCode})",
            yLabel:         'Zeta',
            yMin:           $this->scoreAxisMin($values),
            yMax:           $this->scoreAxisMax($values),
            showThresholds: true,
            thresholdLow:   -3.0,
            thresholdHigh:  3.0,
            warningLow:     -2.0,
            warningHigh:    2.0,
        );
    }

    // ── Private builders ──────────────────────────────────────────────────────

    private function buildResultsGraph(
        SampleAnalysisData $analysis,
        array              $labs,
        string             $type,
        string             $title,
    ): GraphDefinition {
        $categories = array_map(fn ($l) => (string) $l->labNumber, $labs);
        $values     = array_map(fn ($l) => (float) ($l->activity ?? 0.0), $labs);
        $errorBars  = array_map(fn ($l) => (float) ($l->expandedUncertainty ?? 0.0), $labs);

        $assigned = (float) ($analysis->assignedValue ?? 0.0);
        $uncert   = (float) ($analysis->assignedUncertainty ?? 0.0);
        $yMax     = $this->computeResultsYMax($values, $errorBars);

        return new GraphDefinition(
            type:          $type,
            title:         $title,
            xAxisLabel:    'Laboratoire',
            yAxisLabel:    $analysis->unit,
            categories:    $categories,
            values:        $values,
            errorBars:     $errorBars,
            assignedValue: $assigned,
            assignedUpper: $assigned + $uncert,
            assignedLower: $assigned - $uncert,
            yMin:          0.0,
            yMax:          $yMax,
            isotope:       $analysis->isotope,
            sampleCode:    $analysis->sampleCode,
            chartStyle:    'results',
        );
    }

    private function buildBarGraph(
        SampleAnalysisData $analysis,
        array              $labs,
        array              $values,
        string             $type,
        string             $title,
        string             $yLabel,
        float              $yMin,
        float              $yMax,
        bool               $showThresholds = false,
        float              $thresholdLow   = -3.0,
        float              $thresholdHigh  = 3.0,
        ?float             $warningLow     = null,
        ?float             $warningHigh    = null,
    ): GraphDefinition {
        $categories = array_map(fn ($l) => (string) $l->labNumber, $labs);

        return new GraphDefinition(
            type:           $type,
            title:          $title,
            xAxisLabel:     'Laboratoire',
            yAxisLabel:     $yLabel,
            categories:     $categories,
            values:         $values,
            errorBars:      [],
            assignedValue:  0.0,
            assignedUpper:  0.0,
            assignedLower:  0.0,
            yMin:           $yMin,
            yMax:           $yMax,
            isotope:        $analysis->isotope,
            sampleCode:     $analysis->sampleCode,
            chartStyle:     'bar',
            showThresholds: $showThresholds,
            thresholdLow:   $thresholdLow,
            thresholdHigh:  $thresholdHigh,
            warningLow:     $warningLow,
            warningHigh:    $warningHigh,
        );
    }

    // ── Axis helpers ──────────────────────────────────────────────────────────

    private function computeResultsYMax(array $values, array $errorBars): float
    {
        $max = 0.0;
        foreach ($values as $i => $val) {
            $max = max($max, $val + ($errorBars[$i] ?? 0.0));
        }
        return $max > 0.0 ? $this->niceMax($max * 1.10) : 1.0;
    }

    /**
     * Score axis: always show ±(action + 1) at minimum, extend if data exceeds it.
     */
    private function scoreAxisMax(array $values): float
    {
        $dataMax = empty($values) ? 0.0 : max($values);
        return $this->niceMax(max(self::SCORE_ACTION + 1.0, abs($dataMax) * 1.15));
    }

    private function scoreAxisMin(array $values): float
    {
        return -$this->scoreAxisMax($values);
    }

    private function niceMax(float $value): float
    {
        if ($value <= 0.0) return 1.0;
        $magnitude  = 10 ** floor(log10(abs($value)));
        $normalized = $value / $magnitude;
        foreach ([1.5, 2.0, 2.5, 3.0, 4.0, 5.0, 6.0, 8.0, 10.0] as $step) {
            if ($normalized <= $step) return $step * $magnitude;
        }
        return 10.0 * $magnitude;
    }

    private function niceMin(float $value): float
    {
        if ($value >= 0.0) return 0.0;
        return -$this->niceMax(abs($value));
    }
}
