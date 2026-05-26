<?php

namespace Procorad\ProcostatReporting\Excel\Builders;

use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Procorad\ProcostatReporting\Data\SampleAnalysisData;
use Procorad\ProcostatReporting\Excel\Charts\BarChartBuilder;
use Procorad\ProcostatReporting\Excel\Styles\CellStyles;
use Procorad\ProcostatReporting\Excel\Support\ExcelLayout;

/**
 * Builds a score bar chart sheet (bias, zeta_score, zprime_score).
 *
 * Column layout (from TABLE_START_ROW):
 *   A — lab number  (category axis)
 *   B — score value (bar series)
 *
 * When $withThresholds=true, threshold reference data is written to cols D-G.
 * These cells are the data source for reference lines injected by BarChartPatcher:
 *   D — constant +2  (warning upper, orange dashed)
 *   E — constant -2  (warning lower, orange dashed)
 *   F — constant +3  (action upper,  red dashed)
 *   G — constant -3  (action lower,  red dashed)
 *
 * Two rows are written per threshold (firstData and lastData) so that the
 * line spans the full chart width when referenced as a 2-point series.
 */
final class BarChartSheetBuilder
{
    public function __construct(private readonly BarChartBuilder $chartBuilder) {}

    /**
     * @param string $field          Property name on LabResultData: 'biasPercent' | 'zetaScore' | 'zPrimeScore'
     * @param string $yLabel         Y-axis label and series name
     * @param bool   $withThresholds Write threshold reference data (±2/±3 lines)
     */
    public function build(
        Worksheet          $ws,
        SampleAnalysisData $analysis,
        string             $field,
        string             $yLabel,
        bool               $withThresholds = false,
    ): void {
        $labs = collect($analysis->labResults)->filter(fn ($l) => $l->isIncluded && !$l->isTruncated && !$l->isBelowLod)->sortBy($field)->values()->all();
        $n    = count($labs);

        $headerRow = ExcelLayout::TABLE_START_ROW;
        $firstData = $headerRow + 1;
        $lastData  = $headerRow + $n;

        // Fix columns A-M to a consistent width so the chart (A1:M26) always
        // renders at the same physical size regardless of data content.
        foreach (range('A', 'M') as $col) {
            $ws->getColumnDimension($col)->setWidth(ExcelLayout::CHART_COL_WIDTH_SCORE);
        }
        // Data cols get their own widths (override the chart-width defaults)
        $ws->getColumnDimension('A')->setWidth(10);
        $ws->getColumnDimension('B')->setWidth(16);

        // Header
        foreach (['LAB N°', $yLabel] as $ci => $header) {
            $col = ['A', 'B'][$ci];
            $ws->getCell("{$col}{$headerRow}")->setValue($header);
            $ws->getStyle("{$col}{$headerRow}")->applyFromArray(CellStyles::tableHeader());
        }
        $ws->getRowDimension($headerRow)->setRowHeight(28);

        // Data rows
        foreach ($labs as $idx => $lab) {
            $row   = $firstData + $idx;
            $bg    = CellStyles::rowBg($idx);
            $value = $lab->{$field} ?? null;

            $ws->getCell("A{$row}")->setValue((string) $lab->labNumber);
            $ws->getCell("B{$row}")->setValue($value);

            $ws->getStyle("A{$row}")->applyFromArray(CellStyles::dataCell($bg, centerAlign: true));
            $ws->getStyle("B{$row}")->applyFromArray(CellStyles::dataCell($bg));
        }

        // Threshold reference data
        if ($withThresholds) {
            foreach (['D', 'E', 'F', 'G'] as $col) {
                $ws->getColumnDimension($col)->setWidth(8);
            }
            // Write two points per line (first row and last row) — EMPTY_AS_GAP ignores blanks
            $ws->getCell("D{$firstData}")->setValue(2.0);   $ws->getCell("D{$lastData}")->setValue(2.0);
            $ws->getCell("E{$firstData}")->setValue(-2.0);  $ws->getCell("E{$lastData}")->setValue(-2.0);
            $ws->getCell("F{$firstData}")->setValue(3.0);   $ws->getCell("F{$lastData}")->setValue(3.0);
            $ws->getCell("G{$firstData}")->setValue(-3.0);  $ws->getCell("G{$lastData}")->setValue(-3.0);
        }

        $ws->addChart($this->chartBuilder->build($ws->getTitle(), $analysis, $yLabel, $n));
    }
}
