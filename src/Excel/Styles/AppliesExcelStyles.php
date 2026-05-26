<?php

namespace Procorad\ProcostatReporting\Excel\Styles;

use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Layout;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use Procorad\ProcostatReporting\Data\IntercomparisonReportData;
use Procorad\ProcostatReporting\Data\LabResultData;
use Procorad\ProcostatReporting\Data\SampleAnalysisData;
use Procorad\ProcostatReporting\Excel\Ooxml\ChartDocument;
use Procorad\ProcostatReporting\Excel\Ooxml\Definitions\AxisScaleDefinition;
use Procorad\ProcostatReporting\Excel\Ooxml\Definitions\ErrorBarDefinition;
use Procorad\ProcostatReporting\Excel\Ooxml\Definitions\LineDefinition;
use Procorad\ProcostatReporting\Excel\Ooxml\Definitions\MarkerDefinition;
use Procorad\ProcostatReporting\Support\FormatHelper;
use Procorad\ProcostatReporting\Support\PackagePaths;
use Procorad\ProcostatReporting\Excel\Support\ExcelColors;

trait AppliesExcelStyles
{
    private function buildStubSheet(Worksheet $ws, string $sheetName): void
    {
        $ws->getCell('A1')->setValue($sheetName);
        $ws->getStyle('A1')->applyFromArray(['font' => ['bold' => true, 'color' => ['argb' => 'FF'.ExcelColors::BLUE_DARK], 'name' => 'Calibri', 'size' => 12]]);
        $ws->getCell('A2')->setValue('Données à venir.');
        $ws->getStyle('A2')->getFont()->setItalic(true)->setColor(new Color('FF888888'));
        $ws->getColumnDimension('A')->setWidth(30);
    }

    // ── Style helpers ─────────────────────────────────────────────────────────

    private static function sciOrEmpty(?float $value): string
    {
        return $value === null ? '' : FormatHelper::scientific($value);
    }

    private function applyScoreColor(Worksheet $ws, string $cellRef, ?float $score): void
    {
        if ($score === null) return;
        $color = FormatHelper::zscoreColor($score);
        if ($color !== '4472C4') {
            $ws->getStyle($cellRef)->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.$color]],
                'font' => ['color' => ['argb' => 'FFFFFFFF'], 'bold' => true],
            ]);
        }
    }

    private function sectionHeader(Worksheet $ws, string $range, string $title): void
    {
        $ws->mergeCells($range);
        $firstCell = explode(':', $range)[0];
        $ws->getCell($firstCell)->setValue($title);
        $ws->getStyle($range)->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['argb' => 'FF'.self::WHITE], 'name' => 'Calibri', 'size' => 11],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.ExcelColors::BLUE_DARK]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $ws->getRowDimension((int) preg_replace('/\D/', '', $firstCell))->setRowHeight(22);
    }

    private function metaRow(Worksheet $ws, int $row, string $colL, string $colV, string $label, string $value): void
    {
        $ws->getCell("{$colL}{$row}")->setValue($label);
        $ws->getStyle("{$colL}{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['argb' => 'FF'.ExcelColors::BLUE_DARK], 'name' => 'Calibri', 'size' => 11],
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
