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

/**
 * Generates one .xlsx file per SampleAnalysisData (sample × isotope).
 *
 * Each file has 6 sheets:
 *   1. procostat data   — metadata block + full lab results table
 *   2. results lab asc  — placeholder (future: results sorted by lab number)
 *   3. results val asc  — placeholder (future: results sorted by value)
 *   4. bias             — placeholder (future: bias chart data)
 *   5. zprime_score     — placeholder (future: z'-score chart data)
 *   6. zeta_score       — placeholder (future: zeta-score chart data)
 *
 * ReportManager calls generate() once per analysis via GenerateReportsAction,
 * which loops over data->analyses and builds one output path per file.
 */
final class ExcelDocumentGenerator implements DocumentGenerator
{
    private const BLUE_DARK  = '1F497D';
    private const BLUE_LIGHT = 'DCE6F1';
    private const WHITE      = 'FFFFFF';
    private const GREY_ROW   = 'F2F2F2';

    // Sheet names (6 tabs)
    private const SHEETS = [
        'procostat data',
        'results lab asc',
        'results val asc',
        'bias',
        'zprime_score',
        'zeta_score',
    ];

    public function format(): string
    {
        return 'xlsx';
    }

    /**
     * Generate exactly ONE xlsx for the first (and expected only) analysis in $data.
     *
     * GenerateReportsAction is responsible for the loop over analyses and calls
     * this method once per analysis with a dedicated single-analysis $data and
     * a fully-resolved $outputPath (e.g. /…/2026_25CB_25CB_14C.xlsx).
     *
     * ReportResult::files key is "xlsx:{sampleCode}:{isotope}" so that the
     * Action can merge multiple results without key collision.
     */
    public function generate(IntercomparisonReportData $data, string $outputPath): ReportResult
    {
        $start    = hrtime(true);
        $analysis = $data->analyses[0]
            ?? throw new ReportGenerationException($this->format(), 'No analysis provided.');

        try {
            $this->buildFile($outputPath, $analysis, $data);
        } catch (\Throwable $e) {
            throw ReportGenerationException::fromThrowable($this->format(), $e);
        }

        return new ReportResult(
            files: ["xlsx:{$analysis->sampleCode}:{$analysis->isotope}" => $outputPath],
            errors: [],
            durationMs: (hrtime(true) - $start) / 1_000_000,
        );
    }

    // ── File builder ─────────────────────────────────────────────────────────

    private function buildFile(
        string $filePath,
        SampleAnalysisData $analysis,
        IntercomparisonReportData $data,
    ): void {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setTitle("{$data->year}_{$data->icCode}_{$analysis->sampleCode}_{$analysis->isotope}")
            ->setCreator('procostat-reporting');

        // Sheet 1: procostat data (populated)
        $ws = $spreadsheet->getActiveSheet();
        $ws->setTitle(self::SHEETS[0]);
        $this->buildProcostatDataSheet($ws, $analysis, $data);

        // Sheets 2–6: stubs (data + charts in next iteration)
        foreach (array_slice(self::SHEETS, 1) as $sheetName) {
            $stub = $spreadsheet->createSheet();
            $stub->setTitle($sheetName);
            $this->buildStubSheet($stub, $sheetName);
        }

        $spreadsheet->setActiveSheetIndex(0);
        (new Xlsx($spreadsheet))->save($filePath);
    }

    // ── Sheet 1: procostat data ──────────────────────────────────────────────

    private function buildProcostatDataSheet(
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

        // Column widths (A–G)
        foreach (['A' => 22, 'B' => 20, 'C' => 4, 'D' => 22, 'E' => 14] as $col => $w) {
            $ws->getColumnDimension($col)->setWidth($w);
        }

        // ── Metadata block ───────────────────────────────────────────────────
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
            $this->metaRow($ws, $metaStart + 1 + $i, 'A', 'B', $label, $value);
        }

        $limitRows = [
            ['Avertissement (bas)',  '-2'],
            ['Avertissement (haut)', '+2'],
            ['Action (bas)',         '-3'],
            ['Action (haut)',        '+3'],
        ];
        foreach ($limitRows as $i => [$label, $value]) {
            $this->metaRow($ws, $metaStart + 1 + $i, 'D', 'E', $label, $value);
        }

        // ── Lab results table ────────────────────────────────────────────────
        $tableStart = $metaStart + count($metaRows) + 3;

        $headers   = ['LAB N°', "ACTIVITÉ\n{$analysis->unit}", "INCERTITUDE\n(k=2)", "LD", 'BIAIS %', 'En', 'Z-SCORE'];
        $cols      = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
        $colWidths = [10, 16, 18, 12, 10, 10, 12];

        foreach ($headers as $i => $header) {
            $ws->getCell("{$cols[$i]}{$tableStart}")->setValue($header);
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
            $bg     = ($idx % 2 === 0) ? self::GREY_ROW : self::WHITE;

            $values = [
                $lab->labNumber,
                FormatHelper::scientific($lab->activity),
                FormatHelper::scientific($lab->expandedUncertainty),
                FormatHelper::scientific($lab->detectionLimit),
                $lab->biasPercent !== null ? round($lab->biasPercent) : '',
                $lab->enScore     !== null ? round($lab->enScore, 1)  : '',
                $lab->zScore      !== null ? round($lab->zScore, 1)   : '',
            ];

            foreach ($values as $ci => $value) {
                $ws->getCell("{$cols[$ci]}{$row}")->setValue($value);
                $ws->getStyle("{$cols[$ci]}{$row}")->applyFromArray([
                    'font'      => ['name' => 'Calibri', 'size' => 10, 'bold' => ($ci === 0)],
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.$bg]],
                    'alignment' => ['horizontal' => $ci === 0
                        ? Alignment::HORIZONTAL_CENTER
                        : Alignment::HORIZONTAL_RIGHT,
                        'vertical' => Alignment::VERTICAL_CENTER],
                    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFD9D9D9']]],
                ]);
            }

            // Z-score colour
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
    }

    // ── Stub sheets 2–6 ─────────────────────────────────────────────────────

    private function buildStubSheet(Worksheet $ws, string $sheetName): void
    {
        $ws->getCell('A1')->setValue($sheetName);
        $ws->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FF'.self::BLUE_DARK], 'name' => 'Calibri', 'size' => 12],
        ]);
        $ws->getCell('A2')->setValue('Données à venir.');
        $ws->getStyle('A2')->getFont()->setItalic(true)->setColor(new Color('FF888888'));
        $ws->getColumnDimension('A')->setWidth(30);
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
        $ws->getRowDimension((int) preg_replace('/\D/', '', $firstCell))->setRowHeight(22);
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
