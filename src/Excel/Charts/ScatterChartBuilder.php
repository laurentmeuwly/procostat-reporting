<?php

namespace Procorad\ProcostatReporting\Excel\Charts;

use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Layout;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use Procorad\ProcostatReporting\Data\SampleAnalysisData;
use Procorad\ProcostatReporting\Excel\Support\ExcelLayout;

/**
 * Builds the PhpSpreadsheet Chart skeleton for the z' vs zeta scatter plot.
 *
 * Series layout:
 *   0       — lab data points  (B=zprime X, C=zeta Y)
 *   1-4     — vertical threshold lines  (cols D/E, F/G, H/I, J/K, rows t1/t2)
 *   5-8     — horizontal threshold lines (same cols, rows t3/t4)
 *
 * Marker styles, line styles, axis scales and crosses are applied post-generation
 * by ScatterChartPatcher.
 */
final class ScatterChartBuilder
{
    public function build(string $sheetName, SampleAnalysisData $analysis, int $n): Chart
    {
        $r1 = ExcelLayout::TABLE_START_ROW + 1;
        $r2 = ExcelLayout::TABLE_START_ROW + $n;

        // Threshold rows — two-point lines written by ZPrimeVsZetaSheetBuilder
        $t1 = $r1;
        $t2 = $r1 + 1;
        $t3 = $r1 + 2;
        $t4 = $r1 + 3;

        $labels  = [];
        $xSeries = [];
        $ySeries = [];
        $orders  = [];

        // Series 0 — lab data
        $labels[]  = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING,
            null, null, 1, ["{$analysis->isotope} ({$analysis->sampleCode})"]);
        $xSeries[] = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER,
            "'{$sheetName}'!\$B\${$r1}:\$B\${$r2}", null, $n);
        $ySeries[] = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER,
            "'{$sheetName}'!\$C\${$r1}:\$C\${$r2}", null, $n);
        $orders[]  = 0;

        // Series 1-4 — vertical lines (t1/t2 rows)
        // Series 5-8 — horizontal lines (t3/t4 rows)
        $colPairs = [['D', 'E'], ['F', 'G'], ['H', 'I'], ['J', 'K']];

        foreach ($colPairs as $si => [$xCol, $yCol]) {
            $labels[]  = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, null, null, 1, ['']);
            $xSeries[] = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER,
                "'{$sheetName}'!\${$xCol}\${$t1}:'{$sheetName}'!\${$xCol}\${$t2}", null, 2);
            $ySeries[] = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER,
                "'{$sheetName}'!\${$yCol}\${$t1}:'{$sheetName}'!\${$yCol}\${$t2}", null, 2);
            $orders[]  = $si + 1;
        }

        foreach ($colPairs as $si => [$xCol, $yCol]) {
            $labels[]  = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, null, null, 1, ['']);
            $xSeries[] = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER,
                "'{$sheetName}'!\${$xCol}\${$t3}:'{$sheetName}'!\${$xCol}\${$t4}", null, 2);
            $ySeries[] = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER,
                "'{$sheetName}'!\${$yCol}\${$t3}:'{$sheetName}'!\${$yCol}\${$t4}", null, 2);
            $orders[]  = $si + 5;
        }

        $series = new DataSeries(
            DataSeries::TYPE_SCATTERCHART,
            DataSeries::GROUPING_STANDARD,
            $orders,
            $labels, $xSeries, $ySeries,
        );
        $series->setPlotStyle(DataSeries::STYLE_MARKER);

        $chart = new Chart(
            'zprime_vs_zeta',
            new Title("{$analysis->isotope} Z'-score vs Zeta-score ({$analysis->sampleCode})"),
            null,
            new PlotArea(new Layout(), [$series]),
            true,
            DataSeries::EMPTY_AS_GAP,
            new Title("Z'-score"),
            new Title('Zeta-score'),
        );

        $chart->setTopLeftPosition(ExcelLayout::CHART_TOP_LEFT);
        $chart->setBottomRightPosition(ExcelLayout::CHART_BOTTOM_RIGHT_SCORE);

        return $chart;
    }
}
