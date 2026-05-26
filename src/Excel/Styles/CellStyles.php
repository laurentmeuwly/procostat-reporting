<?php

namespace Procorad\ProcostatReporting\Excel\Styles;

use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Procorad\ProcostatReporting\Excel\Support\ExcelColors;

/**
 * Centralised style arrays for PhpSpreadsheet's applyFromArray().
 *
 * All methods return plain arrays — callers pass them to:
 *   $ws->getStyle($ref)->applyFromArray(CellStyles::tableHeader())
 *
 * Or use the shorthand apply*() helpers that also call setWidth / setRowHeight.
 */
final class CellStyles
{
    // ── Style arrays ──────────────────────────────────────────────────────────

    public static function tableHeader(): array
    {
        return [
            'font'      => [
                'bold'  => true,
                'name'  => 'Calibri',
                'size'  => 10,
                'color' => ['argb' => 'FF' . ExcelColors::WHITE],
            ],
            'fill'      => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF' . ExcelColors::BLUE_DARK],
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

    public static function dataCell(string $bg, bool $centerAlign = false): array
    {
        return [
            'font'      => ['name' => 'Calibri', 'size' => 10],
            'fill'      => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF' . $bg],
            ],
            'alignment' => [
                'horizontal' => $centerAlign
                    ? Alignment::HORIZONTAL_CENTER
                    : Alignment::HORIZONTAL_RIGHT,
                'vertical'   => Alignment::VERTICAL_CENTER,
            ],
            'borders'   => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color'       => ['argb' => 'FF' . ExcelColors::BORDER],
                ],
            ],
        ];
    }

    public static function metaLabel(): array
    {
        return [
            'font'      => [
                'bold'  => true,
                'name'  => 'Calibri',
                'size'  => 11,
                'color' => ['argb' => 'FF' . ExcelColors::BLUE_DARK],
            ],
            'fill'      => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF' . ExcelColors::BLUE_LIGHT],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical'   => Alignment::VERTICAL_CENTER,
                'indent'     => 1,
            ],
            'borders'   => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color'       => ['argb' => 'FFB8CCE4'],
                ],
            ],
        ];
    }

    public static function metaValue(): array
    {
        return [
            'font'      => ['name' => 'Calibri', 'size' => 11],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical'   => Alignment::VERTICAL_CENTER,
                'indent'     => 1,
            ],
            'borders'   => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color'       => ['argb' => 'FFB8CCE4'],
                ],
            ],
        ];
    }

    public static function sectionHeader(): array
    {
        return [
            'font'      => [
                'bold'  => true,
                'name'  => 'Calibri',
                'size'  => 11,
                'color' => ['argb' => 'FF' . ExcelColors::WHITE],
            ],
            'fill'      => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF' . ExcelColors::BLUE_DARK],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER,
            ],
        ];
    }

    public static function scoreHighlight(string $hexColor): array
    {
        return [
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF' . $hexColor],
            ],
            'font' => [
                'color' => ['argb' => 'FFFFFFFF'],
                'bold'  => true,
            ],
        ];
    }

    // ── Convenience helpers ───────────────────────────────────────────────────

    /**
     * Apply a table header style and set column width in one call.
     */
    public static function applyHeader(Worksheet $ws, string $cell, string $value, ?int $width = null): void
    {
        $ws->getCell($cell)->setValue($value);
        $ws->getStyle($cell)->applyFromArray(self::tableHeader());
        if ($width !== null) {
            $col = preg_replace('/\d/', '', $cell);
            $ws->getColumnDimension($col)->setWidth($width);
        }
    }

    /**
     * Zebra-stripe row background: grey on even index, white on odd.
     */
    public static function rowBg(int $idx): string
    {
        return ($idx % 2 === 0) ? ExcelColors::GREY_ROW : ExcelColors::WHITE;
    }
}
