<?php

namespace Procorad\ProcostatReporting\Excel\Builders;

use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Procorad\ProcostatReporting\Data\IntercomparisonReportData;
use Procorad\ProcostatReporting\Data\LabResultData;
use Procorad\ProcostatReporting\Data\SampleAnalysisData;
use Procorad\ProcostatReporting\Excel\Styles\CellStyles;
use Procorad\ProcostatReporting\Excel\Support\ExcelColors;
use Procorad\ProcostatReporting\Excel\Support\ExcelLayout;
use Procorad\ProcostatReporting\Support\FormatHelper;
use Procorad\ProcostatReporting\Support\PackagePaths;

/**
 * Builds the "procostat data" summary sheet.
 *
 * Layout:
 *   Row 1      — logo
 *   Row 3      — "Informations générales" section header (A:B merged)
 *   Rows 4–15  — meta key/value pairs: label in A (merged A:B), value in B
 *                (B is kept narrow so text is readable; long values wrap)
 *   Row 17     — lab results table header, starting at column A
 *   Row 18+    — ALL lab results (evaluated + excluded), with Exclusion column
 */
final class ProcostatDataSheetBuilder
{
    private const HEADER_BG   = '4472C4';
    private const HEADER_TEXT = 'FFFFFF';

    public function build(
        Worksheet                $ws,
        SampleAnalysisData       $analysis,
        IntercomparisonReportData $data,
    ): void {
        $this->insertLogo($ws);
        $this->buildMetaSection($ws, $analysis, $data);
        $this->buildLabTable($ws, $analysis);
    }

    // ── Logo ──────────────────────────────────────────────────────────────────

    private function insertLogo(Worksheet $ws): void
    {
        $logoPath = PackagePaths::asset('logo.png');
        if (file_exists($logoPath)) {
            $drawing = new Drawing();
            $drawing->setName('Procorad')
                    ->setPath($logoPath)
                    ->setWidth(160)
                    ->setCoordinates('A1')
                    ->setWorksheet($ws);
        }
        $ws->getRowDimension(1)->setRowHeight(50);
        $ws->getRowDimension(2)->setRowHeight(8);
        // A = label (wide), B = value
        $ws->getColumnDimension('A')->setWidth(26);
        $ws->getColumnDimension('B')->setWidth(10);
        $ws->getColumnDimension('C')->setWidth(18);
    }

    // ── Meta section ──────────────────────────────────────────────────────────

    private function buildMetaSection(
        Worksheet                $ws,
        SampleAnalysisData       $analysis,
        IntercomparisonReportData $data,
    ): void {
        $metaStart = 3;
        $this->sectionHeader($ws, "A{$metaStart}:C{$metaStart}", 'Informations générales');

        // Count participants vs evaluated
        $nbParticipants = count($analysis->labResults);
        $nbEvalues      = count(array_filter(
            $analysis->labResults,
            fn (LabResultData $l) => $l->isIncluded && !$l->isTruncated && !$l->isBelowLod,
        ));
        $useRobust = $nbEvalues > ExcelLayout::ZPRIME_MIN_POPULATION;

        // Each row: [label, raw_value, is_scientific]
        $rows = [
            ['Année',                         $data->year,                                    false],
            ['IC',                            $data->icTitle,                                 false],
            ['Echantillon',                   $analysis->sampleCode,                          false],
            ['Isotope',                       $analysis->isotope,                             false],
            ['Unité',                         $analysis->unit,                                false],
            ['Valeur assignée',               $analysis->assignedValue,                       true],
            ['Incertitude (95%)',             $analysis->assignedUncertainty,                 true],
            ['Nb laboratoires participants',  $nbParticipants,                                false],
            ['Nb laboratoires évalués',       $nbEvalues,                                     false],
            [$useRobust ? 'Moyenne robuste'    : 'Médiane',
             $useRobust ? $analysis->robustMean    : $analysis->median,                       true],
            [$useRobust ? 'Ecart-type robuste' : 'MADe',
             $useRobust ? $analysis->robustStdDev  : $analysis->madeScale,                    true],
        ];

        foreach ($rows as $i => [$label, $value, $isSci]) {
            $row = $metaStart + 1 + $i;
            // Merge A:B for the label so text is not truncated
            $ws->mergeCells("A{$row}:B{$row}");
            $ws->getCell("A{$row}")->setValue($label);
            $ws->getStyle("A{$row}:B{$row}")->applyFromArray(CellStyles::metaLabel());
            if ($isSci && $value !== null) {
                $ws->getCell("C{$row}")->setValue((float) $value);
                $ws->getStyle("C{$row}")->getNumberFormat()->setFormatCode('0.00E+00');
            } else {
                $ws->getCell("C{$row}")->setValue($value ?? '—');
            }
            $ws->getStyle("C{$row}")->applyFromArray(CellStyles::metaValue());
            $ws->getRowDimension($row)->setRowHeight(18);
        }
    }

    // ── Lab results table ─────────────────────────────────────────────────────

    private function buildLabTable(Worksheet $ws, SampleAnalysisData $analysis): void
    {
        $metaRowCount = 11; // must match rows array length above
        $tableStart   = 3 + $metaRowCount + 3;

        // Evaluated labs: used to determine zprime threshold
        $nbEvalues = count(array_filter(
            $analysis->labResults,
            fn (LabResultData $l) => $l->isIncluded && !$l->isTruncated && !$l->isBelowLod,
        ));
        $hasZprime = $nbEvalues > ExcelLayout::ZPRIME_MIN_POPULATION;

        [$headers, $cols, $colWidths] = $this->tableColumns($analysis->unit, $hasZprime);

        // Header row
        foreach ($headers as $i => $header) {
            $ws->getCell("{$cols[$i]}{$tableStart}")->setValue($header);
            $ws->getStyle("{$cols[$i]}{$tableStart}")->applyFromArray($this->tableHeaderStyle());
            $ws->getColumnDimension($cols[$i])->setWidth($colWidths[$i]);
        }
        $ws->getRowDimension($tableStart)->setRowHeight(32);

        // Column index bookmarks for score colouring
        $zprimeColIdx = $hasZprime ? 6 : null;
        $zetaColIdx   = $hasZprime ? 7 : 6;
        $exclColIdx   = $hasZprime ? 8 : 7;

        // ALL labs (including excluded/belowLod) for full picture
        foreach ($analysis->labResults as $idx => $lab) {
            $row        = $tableStart + 1 + $idx;
            $isExcluded = !$lab->isIncluded || $lab->isTruncated || $lab->isBelowLod;
            $bg         = $isExcluded ? 'FFFDE7' : CellStyles::rowBg($idx);

            $values = [
                $lab->labNumber,
                $lab->activity,                // sci format applied below
                $lab->expandedUncertainty,     // sci format applied below
                $lab->detectionLimit,          // sci format applied below
                $lab->biasPercent !== null ? round($lab->biasPercent) : '',
                $lab->zScore      !== null ? round($lab->zScore, 2)   : '',
            ];
            if ($hasZprime) {
                $values[] = $lab->zPrimeScore !== null ? round($lab->zPrimeScore, 2) : '';
            }
            $values[] = $lab->zetaScore !== null ? round($lab->zetaScore, 2) : '';
            $values[] = $lab->exclusionLabel() ?? '';

            foreach ($values as $ci => $value) {
                $isExclCol = ($ci === $exclColIdx);
                $cellRef   = "{$cols[$ci]}{$row}";
                $ws->getCell($cellRef)->setValue($value ?? '');
                $ws->getStyle($cellRef)->applyFromArray([
                    'font'      => [
                        'name'   => 'Calibri',
                        'size'   => 10,
                        'bold'   => ($ci === 0),
                        'italic' => ($ci === 1 && $lab->isTruncated),
                        'color'  => ['argb' => ($isExclCol && $isExcluded) ? 'FFC0392B' : 'FF000000'],
                    ],
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . $bg]],
                    'alignment' => [
                        'horizontal' => ($ci === 0 || $isExclCol)
                            ? Alignment::HORIZONTAL_CENTER
                            : Alignment::HORIZONTAL_RIGHT,
                        'vertical'   => Alignment::VERTICAL_CENTER,
                    ],
                    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFD9D9D9']]],
                ]);
                // Apply scientific format AFTER applyFromArray to avoid reset
                if (in_array($ci, [1, 2, 3], true) && $value !== null && $value !== '') {
                    $ws->getStyle($cellRef)->getNumberFormat()->setFormatCode('0.00E+00');
                }
            }

            // Score highlights only for evaluated (included, not truncated, not belowLod) labs
            if ($lab->isIncluded && !$lab->isTruncated && !$lab->isBelowLod) {
                if ($zprimeColIdx !== null) {
                    $this->applyScoreColor($ws, $cols[$zprimeColIdx] . $row, $lab->zPrimeScore);
                }
                $this->applyScoreColor($ws, $cols[$zetaColIdx] . $row, $lab->zetaScore);
            }
        }
    }

    // ── Column definition ─────────────────────────────────────────────────────

    /** @return array{0: string[], 1: string[], 2: int[]} */
    private function tableColumns(string $unit, bool $hasZprime): array
    {
        $headers   = ['LAB N°', "ACTIVITÉ\n{$unit}", "INCERTITUDE\n(k=2) {$unit}", "LD\n{$unit}", "BIAIS\n%", "Z-SCORE"];
        $cols      = ['A', 'B', 'C', 'D', 'E', 'F'];
        $colWidths = [10,   18,  20,   14,  10,  12];

        if ($hasZprime) {
            $headers[]   = "Z'-SCORE";
            $cols[]      = 'G';
            $colWidths[] = 12;
        }

        $headers[]   = "ZETA\nSCORE";
        $cols[]      = $hasZprime ? 'H' : 'G';
        $colWidths[] = 12;

        $headers[]   = "EXCLUSION";
        $cols[]      = $hasZprime ? 'I' : 'H';
        $colWidths[] = 22;

        return [$headers, $cols, $colWidths];
    }

    // ── Style helpers ─────────────────────────────────────────────────────────

    private function tableHeaderStyle(): array
    {
        return [
            'font'      => [
                'bold'  => true,
                'name'  => 'Calibri',
                'size'  => 10,
                'color' => ['argb' => 'FF' . self::HEADER_TEXT],
            ],
            'fill'      => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF' . self::HEADER_BG],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER,
                'wrapText'   => true,
            ],
            'borders'   => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color'       => ['argb' => 'FFB8CCE4'],
                ],
            ],
        ];
    }

    private function sectionHeader(Worksheet $ws, string $range, string $title): void
    {
        $ws->mergeCells($range);
        $firstCell = explode(':', $range)[0];
        $ws->getCell($firstCell)->setValue($title);
        $ws->getStyle($range)->applyFromArray([
            'font'      => [
                'bold'  => true,
                'name'  => 'Calibri',
                'size'  => 11,
                'color' => ['argb' => 'FF' . self::HEADER_TEXT],
            ],
            'fill'      => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF' . self::HEADER_BG],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $ws->getRowDimension((int) preg_replace('/\D/', '', $firstCell))->setRowHeight(22);
    }

    /**
     * Apply score background ONLY when triggered (orange/red).
     * Blue OK values must not override the zebra background.
     */
    private function applyScoreColor(Worksheet $ws, string $cellRef, ?float $score): void
    {
        if ($score === null) return;
        $color = FormatHelper::zscoreColor($score);
        // Only colour the cell when outside the OK zone (i.e. not the satisfactory blue)
        if ($color !== ExcelColors::BLUE_DARK && $color !== '4472C4') {
            $ws->getStyle($cellRef)->applyFromArray(CellStyles::scoreHighlight($color));
        }
    }

    private function sciOrEmpty(?float $value): string
    {
        return $value === null ? '' : FormatHelper::scientific($value);
    }
}
