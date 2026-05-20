<?php

namespace Procorad\ProcostatReporting\Excel;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Procorad\ProcostatReporting\Contracts\DocumentGenerator;
use Procorad\ProcostatReporting\Data\IntercomparisonReportData;
use Procorad\ProcostatReporting\Data\LabResultData;
use Procorad\ProcostatReporting\Data\ReportResult;
use Procorad\ProcostatReporting\Data\SampleAnalysisData;
use Procorad\ProcostatReporting\Exceptions\ReportGenerationException;
use Procorad\ProcostatReporting\Support\FormatHelper;
use Procorad\ProcostatReporting\Support\PackagePaths;

final class ExcelDocumentGenerator implements DocumentGenerator
{
    private const BLUE_DARK  = '1F497D';
    private const BLUE_LIGHT = 'DCE6F1';
    private const WHITE      = 'FFFFFF';
    private const GREY_ROW   = 'F2F2F2';

    public function format(): string
    {
        return 'xlsx';
    }

    public function generate(IntercomparisonReportData $data, string $outputPath): ReportResult
    {
        $start = hrtime(true);

        try {
            $spreadsheet = new Spreadsheet();
            $spreadsheet->getProperties()
                ->setTitle("{$data->icCode} {$data->year}")
                ->setCreator('procostat-reporting');

            $first = true;
            foreach ($data->analyses as $analysis) {
                $ws = $first
                    ? $spreadsheet->getActiveSheet()
                    : $spreadsheet->createSheet();
                $first = false;

                $sheetTitle = substr("{$analysis->sampleCode}_{$analysis->isotope}", 0, 31);
                $ws->setTitle($sheetTitle);

                $this->buildAnalysisSheet($ws, $analysis, $data);
            }

            $spreadsheet->setActiveSheetIndex(0);

            (new Xlsx($spreadsheet))->save($outputPath);

        } catch (\Throwable $e) {
            throw ReportGenerationException::fromThrowable($this->format(), $e);
        }

        return new ReportResult(
            files: [$this->format() => $outputPath],
            errors: [],
            durationMs: (hrtime(true) - $start) / 1_000_000,
        );
    }

    // ── Sheet builder ────────────────────────────────────────────────────────

    private function buildAnalysisSheet(
        Worksheet $ws,
        SampleAnalysisData $analysis,
        IntercomparisonReportData $data,
    ): void {
        // Logo
        $logoPath = PackagePaths::asset('logo.png');
        if (file_exists($logoPath)) {
            $drawing = new Drawing();
            $drawing->setName('Procorad')->setPath($logoPath)
                    ->setWidth(160)->setCoordinates('A1')->setWorksheet($ws);
        }
        $ws->getRowDimension(1)->setRowHeight(50);
        $ws->getRowDimension(2)->setRowHeight(8);

        // ── Metadata block (row 3–12) ────────────────────────────────────────
        $metaStart = 3;
        $this->sectionHeader($ws, "A{$metaStart}:B{$metaStart}", 'Informations générales');
        $this->sectionHeader($ws, "D{$metaStart}:E{$metaStart}", 'Limites z-score');

        $metaRows = [
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

        foreach ($metaRows as $i => [$label, $value]) {
            $r = $metaStart + 1 + $i;
            $this->metaRow($ws, $r, 'A', 'B', $label, $value);
        }

        $limitRows = [
            ['Avertissement (bas)',  '-2'],
            ['Avertissement (haut)', '2'],
            ['Action (bas)',         '-3'],
            ['Action (haut)',        '3'],
        ];
        foreach ($limitRows as $i => [$label, $value]) {
            $r = $metaStart + 1 + $i;
            $this->metaRow($ws, $r, 'D', 'E', $label, $value);
        }

        // ── Data table ───────────────────────────────────────────────────────
        $tableStart = $metaStart + count($metaRows) + 3;
        $headers    = [
            'LAB N°', "ACTIVITÉ\n{$analysis->unit}", "INCERTITUDE\n(k=2) {$analysis->unit}",
            "LD\n{$analysis->unit}", 'BIAIS %', 'En', 'Z-SCORE',
        ];
        $cols      = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
        $colWidths = [10,  16,   22,  12,  10,  10,  12];

        foreach ($headers as $i => $header) {
            $cell = $ws->getCell("{$cols[$i]}{$tableStart}");
            $cell->setValue($header);
            $ws->getStyle("{$cols[$i]}{$tableStart}")->applyFromArray([
                'font'      => ['bold' => true, 'color' => ['argb' => 'FF'.self::WHITE], 'name' => 'Calibri', 'size' => 10],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.self::BLUE_DARK]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFB8CCE4']]],
            ]);
            $ws->getColumnDimension($cols[$i])->setWidth($colWidths[$i]);
        }
        $ws->getRowDimension($tableStart)->setRowHeight(32);

        /** @var LabResultData $lab */
        foreach ($analysis->labResults as $idx => $lab) {
            $row    = $tableStart + 1 + $idx;
            $isEven = ($idx % 2 === 0);
            $bg     = $isEven ? self::GREY_ROW : self::WHITE;

            $values = [
                $lab->labNumber,
                FormatHelper::scientific($lab->activity),
                FormatHelper::scientific($lab->expandedUncertainty),
                FormatHelper::scientific($lab->detectionLimit),
                $lab->biasPercent !== null ? round($lab->biasPercent) : '',
                $lab->enScore !== null     ? round($lab->enScore, 1) : '',
                $lab->zScore !== null      ? round($lab->zScore, 1)  : '',
            ];

            foreach ($values as $ci => $value) {
                $ws->getCell("{$cols[$ci]}{$row}")->setValue($value);
                $ws->getStyle("{$cols[$ci]}{$row}")->applyFromArray([
                    'font'      => ['name' => 'Calibri', 'size' => 10, 'bold' => $ci === 0],
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.$bg]],
                    'alignment' => ['horizontal' => $ci === 0
                        ? Alignment::HORIZONTAL_CENTER
                        : Alignment::HORIZONTAL_RIGHT,
                        'vertical' => Alignment::VERTICAL_CENTER],
                    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFD9D9D9']]],
                ]);
            }

            // Z-SCORE colour highlight
            if ($lab->zScore !== null) {
                $zColor = FormatHelper::zscoreColor($lab->zScore);
                if ($zColor !== '4472C4') {
                    $ws->getStyle("G{$row}")->applyFromArray([
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.$zColor]],
                        'font' => ['color' => ['argb' => 'FFFFFFFF'], 'bold' => true],
                    ]);
                }
            }
        }

        // Column widths
        foreach ($cols as $i => $col) {
            $ws->getColumnDimension($col)->setWidth($colWidths[$i]);
        }
    }

    // ── Style helpers ────────────────────────────────────────────────────────

    private function sectionHeader(Worksheet $ws, string $range, string $title): void
    {
        $ws->mergeCells($range);
        $firstCell = explode(':', $range)[0];
        $ws->getCell($firstCell)->setValue($title);
        $ws->getStyle($range)->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['argb' => 'FF'.self::WHITE], 'name' => 'Calibri', 'size' => 11],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.self::BLUE_DARK]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $row = (int) preg_replace('/\D/', '', $firstCell);
        $ws->getRowDimension($row)->setRowHeight(22);
    }

    private function metaRow(Worksheet $ws, int $row, string $colL, string $colV, string $label, string $value): void
    {
        $ws->getCell("{$colL}{$row}")->setValue($label);
        $ws->getStyle("{$colL}{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['argb' => 'FF'.self::BLUE_DARK], 'name' => 'Calibri', 'size' => 11],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.self::BLUE_LIGHT]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFB8CCE4']]],
        ]);
        $ws->getCell("{$colV}{$row}")->setValue($value);
        $ws->getStyle("{$colV}{$row}")->applyFromArray([
            'font'      => ['name' => 'Calibri', 'size' => 11],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFB8CCE4']]],
        ]);
        $ws->getRowDimension($row)->setRowHeight(18);
    }
}
