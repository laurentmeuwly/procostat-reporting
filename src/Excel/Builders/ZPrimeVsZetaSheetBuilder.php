<?php

namespace Procorad\ProcostatReporting\Excel\Builders;

use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Procorad\ProcostatReporting\Data\SampleAnalysisData;
use Procorad\ProcostatReporting\Excel\Charts\ScatterChartBuilder;
use Procorad\ProcostatReporting\Excel\Styles\CellStyles;
use Procorad\ProcostatReporting\Excel\Support\ExcelLayout;

/**
 * Builds the "zprime vs zeta" scatter plot sheet.
 *
 * Column layout (from TABLE_START_ROW):
 *   A — lab number  (label)
 *   B — z'-score    (X axis)
 *   C — zeta-score  (Y axis)
 *
 * Threshold line cell data (two endpoints per line, for use as chart series):
 *   D/E — vertical   x=+2  rows t1/t2  (warning)
 *   F/G — vertical   x=-2  rows t1/t2  (warning)
 *   H/I — vertical   x=+3  rows t1/t2  (action)
 *   J/K — vertical   x=-3  rows t1/t2  (action)
 *   D/E — horizontal y=+2  rows t3/t4  (warning)
 *   F/G — horizontal y=-2  rows t3/t4  (warning)
 *   H/I — horizontal y=+3  rows t3/t4  (action)
 *   J/K — horizontal y=-3  rows t3/t4  (action)
 */
final class ZPrimeVsZetaSheetBuilder
{
    public function __construct(private readonly ScatterChartBuilder $chartBuilder) {}

    public function build(Worksheet $ws, SampleAnalysisData $analysis): void
    {
        $labs = collect($analysis->labResults)->filter(fn ($l) => $l->isIncluded && !$l->isTruncated && !$l->isBelowLod)->sortBy('zPrimeScore')->values()->all();
        $n       = count($labs);
        $axisMax = ExcelLayout::SCATTER_AXIS_MAX;

        $headerRow = ExcelLayout::TABLE_START_ROW;
        $firstData = $headerRow + 1;

        // Fix columns A-M so the chart (A1:M26) always has a consistent physical size
        foreach (range('A', 'M') as $col) {
            $ws->getColumnDimension($col)->setWidth(ExcelLayout::CHART_COL_WIDTH_SCORE);
        }
        // Data and threshold columns get explicit widths (override above)
        foreach (['A' => 10, 'B' => 14, 'C' => 14] as $col => $w) {
            $ws->getColumnDimension($col)->setWidth($w);
        }
        foreach (['D','E','F','G','H','I','J','K'] as $col) {
            $ws->getColumnDimension($col)->setWidth(8);
        }

        // Header
        foreach (['LAB N°', "Z'-SCORE", 'ZETA-SCORE'] as $ci => $header) {
            $col = ['A', 'B', 'C'][$ci];
            $ws->getCell("{$col}{$headerRow}")->setValue($header);
            $ws->getStyle("{$col}{$headerRow}")->applyFromArray(CellStyles::tableHeader());
        }
        $ws->getRowDimension($headerRow)->setRowHeight(28);

        // Lab data rows
        foreach ($labs as $idx => $lab) {
            $row = $firstData + $idx;
            $bg  = CellStyles::rowBg($idx);
            $ws->getCell("A{$row}")->setValue((string) $lab->labNumber);
            $ws->getCell("B{$row}")->setValue($lab->zPrimeScore);
            $ws->getCell("C{$row}")->setValue($lab->zetaScore);
            $ws->getStyle("A{$row}")->applyFromArray(CellStyles::dataCell($bg, centerAlign: true));
            $ws->getStyle("B{$row}")->applyFromArray(CellStyles::dataCell($bg));
            $ws->getStyle("C{$row}")->applyFromArray(CellStyles::dataCell($bg));
        }

        // Threshold line endpoints — vertical lines use rows t1/t2, horizontal t3/t4
        $t1 = $firstData;
        $t2 = $firstData + 1;
        $t3 = $firstData + 2;
        $t4 = $firstData + 3;

        // [xCol, yCol, x1, x2, y1, y2, row_a, row_b]
        foreach ([
            // Vertical lines (constant X, Y spans ±axisMax)
            ['D', 'E',  2,        2,       -$axisMax, $axisMax,  $t1, $t2],
            ['F', 'G', -2,       -2,       -$axisMax, $axisMax,  $t1, $t2],
            ['H', 'I',  3,        3,       -$axisMax, $axisMax,  $t1, $t2],
            ['J', 'K', -3,       -3,       -$axisMax, $axisMax,  $t1, $t2],
            // Horizontal lines (constant Y, X spans ±axisMax)
            ['D', 'E', -$axisMax, $axisMax,  2,        2,        $t3, $t4],
            ['F', 'G', -$axisMax, $axisMax, -2,       -2,        $t3, $t4],
            ['H', 'I', -$axisMax, $axisMax,  3,        3,        $t3, $t4],
            ['J', 'K', -$axisMax, $axisMax, -3,       -3,        $t3, $t4],
        ] as [$xCol, $yCol, $x1, $x2, $y1, $y2, $ra, $rb]) {
            $ws->getCell("{$xCol}{$ra}")->setValue($x1);
            $ws->getCell("{$xCol}{$rb}")->setValue($x2);
            $ws->getCell("{$yCol}{$ra}")->setValue($y1);
            $ws->getCell("{$yCol}{$rb}")->setValue($y2);
        }

        $ws->addChart($this->chartBuilder->build($ws->getTitle(), $analysis, $n));
    }
}
