<?php

declare(strict_types=1);

namespace Procorad\ProcostatReporting\Excel;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Procorad\ProcostatReporting\Contracts\ReportGenerator;
use Procorad\ProcostatReporting\DTO\LabResult;
use Procorad\ProcostatReporting\DTO\ProcostatReportData;
use Procorad\ProcostatReporting\DTO\ReportResult;
use Procorad\ProcostatReporting\Exceptions\ReportGenerationException;
use Procorad\ProcostatReporting\Support\FormatHelper;
use Procorad\ProcostatReporting\Support\PackagePaths;

final class ExcelReportGenerator implements ReportGenerator
{
    // ── Palette (matching Procorad blue theme) ──────────────────────────────
    private const BLUE_DARK   = '1F497D';
    private const BLUE_LIGHT  = 'DCE6F1';
    private const WHITE       = 'FFFFFF';
    private const GREY_ROW    = 'F2F2F2';

    public function format(): string
    {
        return 'xlsx';
    }

    public function generate(ProcostatReportData $data, string $outputPath): ReportResult
    {
        $start = hrtime(true);

        try {
            $spreadsheet = new Spreadsheet();
            $spreadsheet->getProperties()
                ->setTitle("{$data->intercomparison} — {$data->sample} ({$data->isotope})")
                ->setCreator('Procorad / procostat_reporting');

            $this->buildInfoSheet($spreadsheet->getActiveSheet(), $data);
            $this->buildDataSheet($spreadsheet->createSheet(), $data);
            $this->buildChartsSheet($spreadsheet->createSheet(), $data);

            // Make Info tab active on open
            $spreadsheet->setActiveSheetIndex(0);

            $writer = new Xlsx($spreadsheet);
            $writer->save($outputPath);

        } catch (\Throwable $e) {
            throw ReportGenerationException::fromThrowable($this->format(), $e);
        }

        $ms = (hrtime(true) - $start) / 1_000_000;

        return new ReportResult(
            files: [$this->format() => $outputPath],
            errors: [],
            durationMs: $ms,
        );
    }

    // ── Sheet 1 : Informations ───────────────────────────────────────────────

    private function buildInfoSheet(Worksheet $ws, ProcostatReportData $data): void
    {
        $ws->setTitle('Informations');

        // Logo
        $logoPath = PackagePaths::asset('logo.png');
        if (file_exists($logoPath)) {
            $drawing = new Drawing();
            $drawing->setName('Procorad');
            $drawing->setPath($logoPath);
            $drawing->setWidth(160);
            $drawing->setCoordinates('A1');
            $drawing->setWorksheet($ws);
        }

        $ws->getRowDimension(1)->setRowHeight(50);
        $ws->getRowDimension(2)->setRowHeight(10);
        $ws->getColumnDimension('A')->setWidth(22);
        $ws->getColumnDimension('B')->setWidth(22);
        $ws->getColumnDimension('C')->setWidth(4);
        $ws->getColumnDimension('D')->setWidth(20);
        $ws->getColumnDimension('E')->setWidth(14);

        // Section header: Informations générales (A4:B4)
        $row = 4;
        $ws->mergeCells("A{$row}:B{$row}");
        $this->sectionHeader($ws, "A{$row}", 'Informations générales');

        $stats = $data->statistics;
        $infoRows = [
            ['Année',              (string) $data->year],
            ['IC',                 $data->intercomparison],
            ['Echantillon',        $data->sample],
            ['Isotope',            $data->isotope],
            ['Unité',              $data->unit],
            ['Valeur assignée',    FormatHelper::scientific($stats->assignedValue)],
            ['Incertitude (95%)',  FormatHelper::scientific($stats->assignedUncertainty)],
            ['NB labos',          (string) $stats->numberOfResults],
            ['Moyenne robuste',    FormatHelper::scientific($stats->robustMean)],
            ['Ecart-type robuste', FormatHelper::scientific($stats->robustDeviation)],
        ];

        foreach ($infoRows as $i => [$label, $value]) {
            $r = $row + 1 + $i;
            $this->metaRow($ws, $r, 'A', 'B', $label, $value);
        }

        // Section header: Limites z-score (D4:E4)
        $ws->mergeCells("D{$row}:E{$row}");
        $this->sectionHeader($ws, "D{$row}", 'Limites z-score');

        $limits = $data->zscoreLimits;
        $limitRows = [
            ['Avertissement (bas)', $limits->warningLow],
            ['Avertissement (haut)', $limits->warningHigh],
            ['Action (bas)',         $limits->actionLow],
            ['Action (haut)',        $limits->actionHigh],
        ];
        foreach ($limitRows as $i => [$label, $value]) {
            $r = $row + 1 + $i;
            $this->metaRow($ws, $r, 'D', 'E', $label, (string) $value);
        }
    }

    // ── Sheet 2 : Données ────────────────────────────────────────────────────

    private function buildDataSheet(Worksheet $ws, ProcostatReportData $data): void
    {
        $ws->setTitle('Données');

        $headers = [
            'LAB N°',
            "ACTIVITY\n{$data->unit}",
            "EXPANDED UNCERTAINTY\n(k=2) {$data->unit}",
            "LD\n{$data->unit}",
            'BIAS %',
            'En',
            'Z-SCORE',
        ];

        $cols = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
        $colWidths = [10, 16, 22, 12, 10, 10, 12];

        // Header row
        foreach ($headers as $i => $header) {
            $cell = $ws->getCell("{$cols[$i]}1");
            $cell->setValue($header);
            $ws->getStyle("{$cols[$i]}1")->applyFromArray([
                'font'      => ['bold' => true, 'color' => ['argb' => 'FF' . self::WHITE], 'name' => 'Calibri', 'size' => 10],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . self::BLUE_DARK]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFB8CCE4']]],
            ]);
            $ws->getColumnDimension($cols[$i])->setWidth($colWidths[$i]);
        }
        $ws->getRowDimension(1)->setRowHeight(32);

        // Data rows
        /** @var LabResult $lab */
        foreach ($data->labResults as $rowIndex => $lab) {
            $row     = $rowIndex + 2;
            $isEven  = ($rowIndex % 2 === 0);
            $bgColor = $isEven ? self::GREY_ROW : self::WHITE;

            $values = [
                $lab->labNumber,
                FormatHelper::scientific($lab->activity),
                FormatHelper::scientific($lab->expandedUncertainty),
                FormatHelper::scientific($lab->detectionLimit),
                $lab->bias,
                $lab->enScore,
                $lab->zscore,
            ];

            foreach ($values as $ci => $value) {
                $cell = $ws->getCell("{$cols[$ci]}{$row}");
                $cell->setValue($value);

                $ws->getStyle("{$cols[$ci]}{$row}")->applyFromArray([
                    'font'      => ['name' => 'Calibri', 'size' => 10, 'bold' => $ci === 0],
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . $bgColor]],
                    'alignment' => ['horizontal' => $ci === 0 ? Alignment::HORIZONTAL_CENTER : Alignment::HORIZONTAL_RIGHT,
                                    'vertical'   => Alignment::VERTICAL_CENTER],
                    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFD9D9D9']]],
                ]);
            }

            // Conditional background for Z-SCORE column (G)
            $zColor = FormatHelper::zscoreColor(
                $lab->zscore,
                $data->zscoreLimits->warningLow,
                $data->zscoreLimits->warningHigh,
                $data->zscoreLimits->actionLow,
                $data->zscoreLimits->actionHigh,
            );
            if ($zColor !== '4472C4') {
                $ws->getStyle("G{$row}")->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . $zColor]],
                    'font' => ['color' => ['argb' => 'FFFFFFFF'], 'bold' => true],
                ]);
            }
        }

        // Statistics summary block below data
        $summaryStart = count($data->labResults) + 4;
        $stats = $data->statistics;
        $summaryRows = [
            ['NUMBER OF RESULTS', $stats->numberOfResults],
            ['UNIT',              $data->unit],
            ['ASSIGNED VALUE',    FormatHelper::scientific($stats->assignedValue)],
            ['UNCERTAINTY (95%)', FormatHelper::scientific($stats->assignedUncertainty)],
            ['ROBUST MEAN',       FormatHelper::scientific($stats->robustMean)],
            ['ROBUST DEVIATION',  FormatHelper::scientific($stats->robustDeviation)],
            ['GEOMETRICAL MEAN',  FormatHelper::scientific($stats->geometricalMean)],
            ['MINIMAL VALUE',     FormatHelper::scientific($stats->minValue)],
            ['MAXIMUM VALUE',     FormatHelper::scientific($stats->maxValue)],
        ];

        foreach ($summaryRows as $i => [$label, $value]) {
            $r = $summaryStart + $i;
            $ws->getCell("A{$r}")->setValue($label);
            $ws->getCell("B{$r}")->setValue($value);
            $ws->getStyle("A{$r}")->applyFromArray([
                'font' => ['bold' => true, 'name' => 'Calibri', 'size' => 10],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . self::BLUE_LIGHT]],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFB8CCE4']]],
            ]);
            $ws->getStyle("B{$r}")->applyFromArray([
                'font'    => ['name' => 'Calibri', 'size' => 10],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFB8CCE4']]],
            ]);
        }
    }

    // ── Sheet 3 : Graphiques (placeholder — charts generated by Node/Excel) ──

    private function buildChartsSheet(Worksheet $ws, ProcostatReportData $data): void
    {
        $ws->setTitle('Graphiques');
        $ws->getCell('A1')->setValue('Les graphiques seront générés ici.');
        $ws->getStyle('A1')->getFont()->setItalic(true)->setColor(new Color('FF888888'));
        // TODO: native PhpSpreadsheet chart generation per ChartConfig
    }

    // ── Style helpers ────────────────────────────────────────────────────────

    private function sectionHeader(Worksheet $ws, string $cell, string $title): void
    {
        $ws->getCell($cell)->setValue($title);
        $ws->getStyle($cell)->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['argb' => 'FF' . self::WHITE], 'name' => 'Calibri', 'size' => 11],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . self::BLUE_DARK]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $ws->getRowDimension((int) preg_replace('/\D/', '', $cell))->setRowHeight(22);
    }

    private function metaRow(Worksheet $ws, int $row, string $colLabel, string $colValue, string $label, string $value): void
    {
        $ws->getCell("{$colLabel}{$row}")->setValue($label);
        $ws->getStyle("{$colLabel}{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['argb' => 'FF' . self::BLUE_DARK], 'name' => 'Calibri', 'size' => 11],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . self::BLUE_LIGHT]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFB8CCE4']]],
        ]);

        $ws->getCell("{$colValue}{$row}")->setValue($value);
        $ws->getStyle("{$colValue}{$row}")->applyFromArray([
            'font'      => ['name' => 'Calibri', 'size' => 11],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFB8CCE4']]],
        ]);

        $ws->getRowDimension($row)->setRowHeight(18);
    }
}
