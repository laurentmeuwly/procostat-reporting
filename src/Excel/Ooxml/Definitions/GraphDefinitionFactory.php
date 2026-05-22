<?php

namespace Procorad\ProcostatReporting\Excel\Ooxml\Definitions;

use Procorad\ProcostatReporting\Data\SampleAnalysisData;

/**
 * Builds all GraphDefinition objects from a SampleAnalysisData.
 *
 * Chart inventory:
 *   0. results_lab_asc  — line+markers, sorted by lab number
 *   1. results_val_asc  — line+markers, sorted by activity
 *   2. bias             — bar chart, biasPercent, sorted by lab number
 *   3. zprime_score     — bar chart, zPrimeScore (only if n >= 12)
 *   4. zeta_score       — bar chart, zetaScore
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
        $graphs = [
            $this->resultsLabAsc($analysis),
            $this->resultsValAsc($analysis),
            $this->bias($analysis),
        ];

        // zprime only when population warrants it
        if (count($analysis->labResults) >= self::ZPRIME_MIN_POPULATION) {
            $graphs[] = $this->zprimeScore($analysis);
        }

        $graphs[] = $this->zetaScore($analysis);

        return $graphs;
    }

    // ── Results charts ────────────────────────────────────────────────────────

    public function resultsLabAsc(SampleAnalysisData $analysis): GraphDefinition
    {
        $labs = collect($analysis->labResults)->sortBy('labNumber')->values()->all();
        return $this->buildResultsGraph($analysis, $labs, 'results_lab_asc',
            "{$analysis->isotope} Results ({$analysis->sampleCode})");
    }

    public function resultsValAsc(SampleAnalysisData $analysis): GraphDefinition
    {
        $labs = collect($analysis->labResults)->sortBy('activity')->values()->all();
        return $this->buildResultsGraph($analysis, $labs, 'results_val_asc',
            "{$analysis->isotope} Results ({$analysis->sampleCode}) — sorted");
    }

    // ── Score / bar charts ────────────────────────────────────────────────────

    public function bias(SampleAnalysisData $analysis): GraphDefinition
    {
        $labs   = collect($analysis->labResults)->sortBy('labNumber')->values()->all();
        $values = array_map(fn ($l) => (float) ($l->biasPercent ?? 0.0), $labs);

        return $this->buildBarGraph(
            analysis:  $analysis,
            labs:      $labs,
            values:    $values,
            type:      'bias',
            title:     "{$analysis->isotope} Bias ({$analysis->sampleCode})",
            yLabel:    '%',
            // Bias: symmetric around 0, thresholds ±warning/action
            yMin:      $this->niceMin(min($values) * 1.10),
            yMax:      $this->niceMax(max($values) * 1.10),
        );
    }

    public function zprimeScore(SampleAnalysisData $analysis): GraphDefinition
    {
        $labs   = collect($analysis->labResults)->sortBy('labNumber')->values()->all();
        $values = array_map(fn ($l) => (float) ($l->zPrimeScore ?? 0.0), $labs);

        return $this->buildBarGraph(
            analysis: $analysis,
            labs:     $labs,
            values:   $values,
            type:     'zprime_score',
            title:    "{$analysis->isotope} Z'-score ({$analysis->sampleCode})",
            yLabel:   "Z'",
            yMin:     $this->scoreAxisMin($values),
            yMax:     $this->scoreAxisMax($values),
        );
    }

    public function zetaScore(SampleAnalysisData $analysis): GraphDefinition
    {
        $labs   = collect($analysis->labResults)->sortBy('labNumber')->values()->all();
        $values = array_map(fn ($l) => (float) ($l->zetaScore ?? 0.0), $labs);

        return $this->buildBarGraph(
            analysis: $analysis,
            labs:     $labs,
            values:   $values,
            type:     'zeta_score',
            title:    "{$analysis->isotope} Zeta-score ({$analysis->sampleCode})",
            yLabel:   'Zeta',
            yMin:     $this->scoreAxisMin($values),
            yMax:     $this->scoreAxisMax($values),
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
    ): GraphDefinition {
        $categories = array_map(fn ($l) => (string) $l->labNumber, $labs);

        return new GraphDefinition(
            type:          $type,
            title:         $title,
            xAxisLabel:    'Laboratoire',
            yAxisLabel:    $yLabel,
            categories:    $categories,
            values:        $values,
            errorBars:     [],
            assignedValue: 0.0,
            assignedUpper: 0.0,
            assignedLower: 0.0,
            yMin:          $yMin,
            yMax:          $yMax,
            isotope:       $analysis->isotope,
            sampleCode:    $analysis->sampleCode,
            chartStyle:    'bar',
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
