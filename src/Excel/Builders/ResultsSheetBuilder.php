<?php

namespace Procorad\ProcostatReporting\Excel\Builders;

use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Procorad\ProcostatReporting\Data\SampleAnalysisData;
use Procorad\ProcostatReporting\Excel\Charts\ResultsChartBuilder;
use Procorad\ProcostatReporting\Excel\Styles\CellStyles;
use Procorad\ProcostatReporting\Excel\Support\ExcelLayout;

/**
 * Builds a "results" sheet (sorted by lab number or by activity value).
 *
 * Column layout (all starting at TABLE_START_ROW):
 *   A — lab number  (label axis)
 *   B — activity    (series 0 data)
 *   C — expanded uncertainty k=2  (error bar reference)
 *   D — assigned value            (series 1 — solid red line)
 *   E — assigned value + uncert   (series 2 — dashed red)
 *   F — assigned value - uncert   (series 3 — dashed red)
 */
final class ResultsSheetBuilder
{
    public function __construct(private readonly ResultsChartBuilder $chartBuilder) {}

    /**
     * @param string $sortBy  Property name on LabResultData: 'labNumber' | 'activity'
     */
    public function build(Worksheet $ws, SampleAnalysisData $analysis, string $sortBy = 'labNumber'): void
    {
        $labs = collect($analysis->labResults)->filter(fn ($l) => $l->isIncluded && !$l->isTruncated && !$l->isBelowLod)->sortBy($sortBy)->values()->all();
        $n    = count($labs);

        $headerRow = ExcelLayout::TABLE_START_ROW;
        $firstData = $headerRow + 1;

        $headers   = ['LAB N°', "ACTIVITÉ ({$analysis->unit})", "INCERTITUDE k=2 ({$analysis->unit})", "VALEUR ASSIGNÉE ({$analysis->unit})", "VA + INCERT.", "VA - INCERT."];
        $cols      = ['A', 'B', 'C', 'D', 'E', 'F'];
        $widths    = [10,  22,   26,   24,   14,   14];

        foreach ($headers as $i => $header) {
            $ws->getCell("{$cols[$i]}{$headerRow}")->setValue($header);
            $ws->getStyle("{$cols[$i]}{$headerRow}")->applyFromArray(CellStyles::tableHeader());
            $ws->getColumnDimension($cols[$i])->setWidth($widths[$i]);
        }
        // Col G is the chart right edge — fix its width so chart size is consistent
        $ws->getColumnDimension('G')->setWidth(ExcelLayout::CHART_COL_WIDTH_RESULTS);
        $ws->getRowDimension($headerRow)->setRowHeight(32);

        $assigned = $analysis->assignedValue;
        $upper    = ($assigned !== null && $analysis->assignedUncertainty !== null)
            ? $assigned + $analysis->assignedUncertainty : null;
        $lower    = ($assigned !== null && $analysis->assignedUncertainty !== null)
            ? $assigned - $analysis->assignedUncertainty : null;

        foreach ($labs as $idx => $lab) {
            $row    = $firstData + $idx;
            $bg     = CellStyles::rowBg($idx);
            $values = [(string) $lab->labNumber, $lab->activity, $lab->expandedUncertainty, $assigned, $upper, $lower];

            foreach ($values as $ci => $value) {
                $cellRef = "{$cols[$ci]}{$row}";
                if ($value !== null) {
                    $ws->getCell($cellRef)->setValue($value);
                }
                $ws->getStyle($cellRef)->applyFromArray(
                    CellStyles::dataCell($bg, centerAlign: $ci === 0)
                );
                // Columns B–F are numeric values → scientific notation
                if ($ci > 0 && $value !== null) {
                    $ws->getStyle($cellRef)->getNumberFormat()->setFormatCode('0.00E+00');
                }
            }
        }

        $ws->addChart($this->chartBuilder->build($ws->getTitle(), $analysis, $n));
    }
}
