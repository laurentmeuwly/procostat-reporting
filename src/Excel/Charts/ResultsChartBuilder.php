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
 * Builds the PhpSpreadsheet Chart skeleton for "results" sheets.
 *
 * The chart is a line+marker chart with 4 series:
 *   0 — lab activities          (markers + error bars via OOXML patch)
 *   1 — assigned value          (solid red line)
 *   2 — assigned value + uncert (dashed red line)
 *   3 — assigned value - uncert (dashed red line)
 *
 * Actual series styles (markers, lines, error bars, Y-axis scale) are applied
 * post-generation by the OOXML patch layer — this builder only sets up the
 * data references that PhpSpreadsheet needs to write chart1.xml.
 */
final class ResultsChartBuilder
{
    /**
     * @param string             $sheetName  Title of the worksheet (used in cell references)
     * @param SampleAnalysisData $analysis
     * @param int                $n          Number of lab rows
     */
    public function build(string $sheetName, SampleAnalysisData $analysis, int $n): Chart
    {
        $r1 = ExcelLayout::TABLE_START_ROW + 1;
        $r2 = ExcelLayout::TABLE_START_ROW + $n;

        $ref = fn(string $col) => "'{$sheetName}'!\${$col}\${$r1}:\${$col}\${$r2}";

        $xLabels = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $ref('A'), null, $n);

        $series = new DataSeries(
            DataSeries::TYPE_LINECHART,
            DataSeries::GROUPING_STANDARD,
            range(0, 3),
            [
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, null, null, 1, ["{$analysis->isotope} Results ({$analysis->sampleCode})"]),
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, null, null, 1, ['Valeur assignée']),
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, null, null, 1, ['VA + incertitude']),
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, null, null, 1, ['VA - incertitude']),
            ],
            [$xLabels, $xLabels, $xLabels, $xLabels],
            [
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, $ref('B'), null, $n),
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, $ref('D'), null, $n),
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, $ref('E'), null, $n),
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, $ref('F'), null, $n),
            ],
        );
        $series->setPlotStyle(DataSeries::STYLE_MARKER);

        $chart = new Chart(
            'results_' . md5($sheetName),
            new Title("{$analysis->isotope} Results ({$analysis->sampleCode})"),
            null,
            new PlotArea(new Layout(), [$series]),
            true,
            DataSeries::EMPTY_AS_GAP,
            new Title('Laboratoire'),
            new Title($analysis->unit),
        );

        $chart->setTopLeftPosition(ExcelLayout::CHART_TOP_LEFT);
        $chart->setBottomRightPosition(ExcelLayout::CHART_BOTTOM_RIGHT_RESULTS);

        return $chart;
    }
}
