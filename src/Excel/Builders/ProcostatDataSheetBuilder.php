<?php

namespace Procorad\ProcostatReporting\Excel\Builders;

use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Procorad\ProcostatReporting\Data\IntercomparisonReportData;
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
 *   Row 1      — logo (PNG drawing)
 *   Row 3      — "Informations générales" section header
 *   Rows 4–13  — meta key/value pairs
 *   Row 16+    — lab results table (A–H or A–I depending on population)
 */
final class ProcostatDataSheetBuilder
{
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
        $ws->getColumnDimension('A')->setWidth(22);
        $ws->getColumnDimension('B')->setWidth(20);
    }

    // ── Meta section ──────────────────────────────────────────────────────────

    private function buildMetaSection(
        Worksheet                $ws,
        SampleAnalysisData       $analysis,
        IntercomparisonReportData $data,
    ): void {
        $metaStart = 3;
        $this->sectionHeader($ws, "A{$metaStart}:B{$metaStart}", 'Informations générales');

        $rows = [
            ['Année',              (string) $data->year],
            ['IC',                 $data->icTitle],
            ['Echantillon',        $analysis->sampleCode],
            ['Isotope',            $analysis->isotope],
            ['Unité',              $analysis->unit],
            ['Valeur assignée',    FormatHelper::scientific($analysis->assignedValue)],
            ['Incertitude (95%)',  FormatHelper::scientific($analysis->assignedUncertainty)],
            ['NB labos',           (string) count($analysis->labResults)],
            ['Moyenne robuste',    FormatHelper::scientific($analysis->robustMean)],
            ['Ecart-type robuste', FormatHelper::scientific($analysis->robustStdDev)],
        ];

        foreach ($rows as $i => [$label, $value]) {
            $this->metaRow($ws, $metaStart + 1 + $i, 'A', 'B', $label, $value);
        }
    }

    // ── Lab results table ─────────────────────────────────────────────────────

    private function buildLabTable(Worksheet $ws, SampleAnalysisData $analysis): void
    {
        $metaRowCount = 10; // must match rows array length above
        $tableStart   = 3 + $metaRowCount + 3; // section header + rows + gap
        $hasZprime    = count($analysis->labResults) > ExcelLayout::ZPRIME_MIN_POPULATION;

        [$headers, $cols, $colWidths] = $this->tableColumns($analysis->unit, $hasZprime);

        // Header row
        foreach ($headers as $i => $header) {
            $ws->getCell("{$cols[$i]}{$tableStart}")->setValue($header);
            $ws->getStyle("{$cols[$i]}{$tableStart}")->applyFromArray(CellStyles::tableHeader());
            $ws->getColumnDimension($cols[$i])->setWidth($colWidths[$i]);
        }
        $ws->getRowDimension($tableStart)->setRowHeight(32);

        // Column index bookmarks for score colouring
        $zscoreColIdx = 5;
        $zprimeColIdx = $hasZprime ? 6 : null;
        $zetaColIdx   = $hasZprime ? 7 : 6;

        // Data rows
        foreach ($analysis->labResults as $idx => $lab) {
            $row        = $tableStart + 1 + $idx;
            $isExcluded = ! $lab->isIncluded || $lab->isTruncated;
            $bg         = $isExcluded
                ? 'FFFDE7'
                : CellStyles::rowBg($idx);

            $values = [
                $lab->labNumber,
                $this->sciOrEmpty($lab->activity),
                $this->sciOrEmpty($lab->expandedUncertainty),
                $this->sciOrEmpty($lab->detectionLimit),
                $lab->biasPercent !== null ? round($lab->biasPercent)    : '',
                $lab->zScore      !== null ? round($lab->zScore, 2)      : '',
            ];
            if ($hasZprime) {
                $values[] = $lab->zPrimeScore !== null ? round($lab->zPrimeScore, 2) : '';
            }
            $values[] = $lab->zetaScore !== null ? round($lab->zetaScore, 2) : '';
            $values[] = $lab->exclusionLabel() ?? '';

            $lastColIdx = count($values) - 1;

            foreach ($values as $ci => $value) {
                $isExclCol = ($ci === $lastColIdx);
                $ws->getCell("{$cols[$ci]}{$row}")->setValue($value);
                $ws->getStyle("{$cols[$ci]}{$row}")->applyFromArray([
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
            }

            // Score colour highlights (included labs only)
            if ($lab->isIncluded) {
                $this->applyScoreColor($ws, $cols[$zscoreColIdx] . $row, $lab->zScore);
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
        $colWidths = [10,  18,   20,   14,  10,  12];

        if ($hasZprime) {
            $headers[]   = "Z'-SCORE";
            $cols[]      = 'G';
            $colWidths[] = 12;
        }

        $headers[]   = "ZETA\nSCORE";
        $cols[]      = $hasZprime ? 'H' : 'G';
        $colWidths[] = 12;

        $headers[]   = "EXCLU\nDES STATS";
        $cols[]      = $hasZprime ? 'I' : 'H';
        $colWidths[] = 22;

        return [$headers, $cols, $colWidths];
    }

    // ── Style helpers ─────────────────────────────────────────────────────────

    private function sectionHeader(Worksheet $ws, string $range, string $title): void
    {
        $ws->mergeCells($range);
        $firstCell = explode(':', $range)[0];
        $ws->getCell($firstCell)->setValue($title);
        $ws->getStyle($range)->applyFromArray(CellStyles::sectionHeader());
        $ws->getRowDimension((int) preg_replace('/\D/', '', $firstCell))->setRowHeight(22);
    }

    private function metaRow(Worksheet $ws, int $row, string $colL, string $colV, string $label, string $value): void
    {
        $ws->getCell("{$colL}{$row}")->setValue($label);
        $ws->getStyle("{$colL}{$row}")->applyFromArray(CellStyles::metaLabel());
        $ws->getCell("{$colV}{$row}")->setValue($value);
        $ws->getStyle("{$colV}{$row}")->applyFromArray(CellStyles::metaValue());
        $ws->getRowDimension($row)->setRowHeight(18);
    }

    private function applyScoreColor(Worksheet $ws, string $cellRef, ?float $score): void
    {
        if ($score === null) return;
        $color = FormatHelper::zscoreColor($score);
        if ($color !== ExcelColors::BLUE_DARK) {
            $ws->getStyle($cellRef)->applyFromArray(CellStyles::scoreHighlight($color));
        }
    }

    private function sciOrEmpty(?float $value): string
    {
        return $value === null ? '' : FormatHelper::scientific($value);
    }
}
