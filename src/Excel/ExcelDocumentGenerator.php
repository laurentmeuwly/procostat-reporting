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
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Layout;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use Procorad\ProcostatReporting\Contracts\DocumentGenerator;
use Procorad\ProcostatReporting\Data\IntercomparisonReportData;
use Procorad\ProcostatReporting\Data\LabResultData;
use Procorad\ProcostatReporting\Data\ReportResult;
use Procorad\ProcostatReporting\Data\SampleAnalysisData;
use Procorad\ProcostatReporting\Exceptions\ReportGenerationException;
use Procorad\ProcostatReporting\Excel\Ooxml\ChartDocument;
use Procorad\ProcostatReporting\Excel\Ooxml\Definitions\AxisScaleDefinition;
use Procorad\ProcostatReporting\Excel\Ooxml\Definitions\ErrorBarDefinition;
use Procorad\ProcostatReporting\Excel\Ooxml\Definitions\LineDefinition;
use Procorad\ProcostatReporting\Excel\Ooxml\Definitions\MarkerDefinition;
use Procorad\ProcostatReporting\Support\FormatHelper;
use Procorad\ProcostatReporting\Support\PackagePaths;

/**
 * Generates one .xlsx per SampleAnalysisData (sample × isotope).
 *
 * PhpSpreadsheet generates the base file and chart structure.
 * ChartDocument (OOXML layer) patches the chart XML post-generation via
 * DOMDocument + XPath for features PhpSpreadsheet doesn't support:
 *   - error bars
 *   - per-series marker / line styles
 *   - Y-axis explicit scaling
 */
final class ExcelDocumentGenerator implements DocumentGenerator
{
    private const BLUE_DARK  = '1F497D';
    private const BLUE_LIGHT = 'DCE6F1';
    private const WHITE      = 'FFFFFF';
    private const GREY_ROW   = 'F2F2F2';

    // Column C on 'results lab asc' holds uncertainty k=2 — referenced by error bars
    private const UNCERTAINTY_COL = 'C';

    private const SHEETS = [
        'procostat data',
        'results lab asc',
        'results val asc',
        'bias',
        'zprime_score',
        'zeta_score',
    ];

    public function format(): string { return 'xlsx'; }

    public function generate(IntercomparisonReportData $data, string $outputPath): ReportResult
    {
        $start    = hrtime(true);
        $analysis = $data->analyses[0]
            ?? throw new ReportGenerationException($this->format(), 'No analysis provided.');

        try {
            $n    = count($analysis->labResults);
            $yMax = $this->computeYMax($analysis);

            // Step 1 — PhpSpreadsheet generates the base xlsx + chart skeleton
            $this->buildFile($outputPath, $analysis, $data);

            // Step 2 — OOXML layer patches the chart XML
            $sheet   = self::SHEETS[1]; // 'results lab asc'
            $dataRef = fn(string $col) => "'{$sheet}'!\${$col}\$2:\${$col}\$" . ($n + 1);

            $patchChart = function (ChartDocument $doc, int $chartIndex, string $sheetName) use ($n, $yMax): void {
                $dataRef = fn(string $col) => "'{$sheetName}'!\${$col}\$2:\${$col}\$" . ($n + 1);
                $doc->chart($chartIndex)
                    ->series(0)
                        ->addErrorBars(ErrorBarDefinition::symmetric($dataRef(self::UNCERTAINTY_COL)))
                        ->setMarker(MarkerDefinition::circle('4472C4'))
                        ->setLine(LineDefinition::none())
                    ->series(1)
                        ->setLine(LineDefinition::solid('FF0000'))
                        ->setMarker(MarkerDefinition::none())
                    ->yAxis()
                        ->setScale(AxisScaleDefinition::fromZero($yMax))
                    ->save();
            };

            $doc = ChartDocument::open($outputPath);
            $patchChart($doc, 0, self::SHEETS[1]); // results lab asc
            $patchChart($doc, 1, self::SHEETS[2]); // results val asc

        } catch (\Throwable $e) {
            throw ReportGenerationException::fromThrowable($this->format(), $e);
        }

        return new ReportResult(
            files: ["xlsx:{$analysis->sampleCode}:{$analysis->isotope}" => $outputPath],
            errors: [],
            durationMs: (hrtime(true) - $start) / 1_000_000,
        );
    }

    // ── File builder ──────────────────────────────────────────────────────────

    private function buildFile(string $filePath, SampleAnalysisData $analysis, IntercomparisonReportData $data): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setTitle("{$data->year}_{$data->icCode}_{$analysis->sampleCode}_{$analysis->isotope}")
            ->setCreator('procostat-reporting');

        $ws = $spreadsheet->getActiveSheet();
        $ws->setTitle(self::SHEETS[0]);
        $this->buildProcostatDataSheet($ws, $analysis, $data);

        $wsResults = $spreadsheet->createSheet();
        $wsResults->setTitle(self::SHEETS[1]);
        $this->buildResultsSheet($wsResults, $analysis, sortBy: 'labNumber');

        $wsResultsVal = $spreadsheet->createSheet();
        $wsResultsVal->setTitle(self::SHEETS[2]);
        $this->buildResultsSheet($wsResultsVal, $analysis, sortBy: 'activity');

        foreach (array_slice(self::SHEETS, 3) as $sheetName) {
            $stub = $spreadsheet->createSheet();
            $stub->setTitle($sheetName);
            $this->buildStubSheet($stub, $sheetName);
        }

        $spreadsheet->setActiveSheetIndex(0);

        $writer = new Xlsx($spreadsheet);
        $writer->setIncludeCharts(true);
        $writer->save($filePath);
    }

    // ── Sheet 1: procostat data ───────────────────────────────────────────────

    private function buildProcostatDataSheet(
        Worksheet $ws,
        SampleAnalysisData $analysis,
        IntercomparisonReportData $data,
    ): void {
        $logoPath = PackagePaths::asset('logo.png');
        if (file_exists($logoPath)) {
            $drawing = new Drawing();
            $drawing->setName('Procorad')->setPath($logoPath)
                    ->setWidth(160)->setCoordinates('A1')->setWorksheet($ws);
        }
        $ws->getRowDimension(1)->setRowHeight(50);
        $ws->getRowDimension(2)->setRowHeight(8);
        $ws->getColumnDimension('A')->setWidth(22);
        $ws->getColumnDimension('B')->setWidth(20);

        $metaStart = 3;
        $this->sectionHeader($ws, "A{$metaStart}:B{$metaStart}", 'Informations générales');

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

        $tableStart = $metaStart + count($metaRows) + 3;
        $hasZprime  = count($analysis->labResults) >= 12;

        $headers   = ['LAB N°', "ACTIVITÉ\n{$analysis->unit}", "INCERTITUDE\n(k=2) {$analysis->unit}", "LD\n{$analysis->unit}", "BIAIS\n%", "Z-SCORE"];
        $cols      = ['A', 'B', 'C', 'D', 'E', 'F'];
        $colWidths = [10,  18,   20,   14,  10,  12];

        if ($hasZprime) { $headers[] = "Z'-SCORE"; $cols[] = 'G'; $colWidths[] = 12; }
        $headers[] = "ZETA\nSCORE"; $cols[] = $hasZprime ? 'H' : 'G'; $colWidths[] = 12;

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

        $zscoreColIdx = 5;
        $zprimeColIdx = $hasZprime ? 6 : null;
        $zetaColIdx   = $hasZprime ? 7 : 6;

        foreach ($analysis->labResults as $idx => $lab) {
            $row    = $tableStart + 1 + $idx;
            $bg     = ($idx % 2 === 0) ? self::GREY_ROW : self::WHITE;
            $values = [
                $lab->labNumber,
                self::sciOrEmpty($lab->activity),
                self::sciOrEmpty($lab->expandedUncertainty),
                self::sciOrEmpty($lab->detectionLimit),
                $lab->biasPercent !== null ? round($lab->biasPercent) : '',
                $lab->zScore      !== null ? round($lab->zScore, 2)   : '',
            ];
            if ($hasZprime) { $values[] = $lab->zPrimeScore !== null ? round($lab->zPrimeScore, 2) : ''; }
            $values[] = $lab->zetaScore !== null ? round($lab->zetaScore, 2) : '';

            foreach ($values as $ci => $value) {
                $ws->getCell("{$cols[$ci]}{$row}")->setValue($value);
                $ws->getStyle("{$cols[$ci]}{$row}")->applyFromArray([
                    'font'      => ['name' => 'Calibri', 'size' => 10, 'bold' => ($ci === 0)],
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.$bg]],
                    'alignment' => ['horizontal' => $ci === 0 ? Alignment::HORIZONTAL_CENTER : Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER],
                    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFD9D9D9']]],
                ]);
            }

            $this->applyScoreColor($ws, $cols[$zscoreColIdx].$row, $lab->zScore);
            if ($zprimeColIdx !== null) { $this->applyScoreColor($ws, $cols[$zprimeColIdx].$row, $lab->zPrimeScore); }
            $this->applyScoreColor($ws, $cols[$zetaColIdx].$row, $lab->zetaScore);
        }
    }

    // ── Sheet 2: results lab asc ──────────────────────────────────────────────

    private function buildResultsSheet(Worksheet $ws, SampleAnalysisData $analysis, string $sortBy = 'labNumber'): void
    {
        $labs = collect($analysis->labResults)->sortBy($sortBy)->values()->all();
        $n    = count($labs);

        // A=lab number, B=activity, C=uncertainty k2, D=assigned value
        // Column letters must match UNCERTAINTY_COL and ChartDocument references
        $headers     = ['LAB N°', "ACTIVITÉ ({$analysis->unit})", "INCERTITUDE k=2 ({$analysis->unit})", "VALEUR ASSIGNÉE ({$analysis->unit})"];
        $tableCols   = ['A', 'B', 'C', 'D'];
        $tableWidths = [10,  22,   26,   24];

        foreach ($headers as $i => $header) {
            $ws->getCell("{$tableCols[$i]}1")->setValue($header);
            $ws->getStyle("{$tableCols[$i]}1")->applyFromArray([
                'font'      => ['bold' => true, 'color' => ['argb' => 'FF'.self::WHITE], 'name' => 'Calibri', 'size' => 10],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.self::BLUE_DARK]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFB8CCE4']]],
            ]);
            $ws->getColumnDimension($tableCols[$i])->setWidth($tableWidths[$i]);
        }
        $ws->getRowDimension(1)->setRowHeight(32);

        foreach ($labs as $idx => $lab) {
            /** @var LabResultData $lab */
            $row    = $idx + 2;
            $bg     = ($idx % 2 === 0) ? self::GREY_ROW : self::WHITE;
            $values = [$lab->labNumber, $lab->activity, $lab->expandedUncertainty, $analysis->assignedValue];

            foreach ($values as $ci => $value) {
                $ws->getCell("{$tableCols[$ci]}{$row}")->setValue($value);
                $ws->getStyle("{$tableCols[$ci]}{$row}")->applyFromArray([
                    'font'      => ['name' => 'Calibri', 'size' => 10],
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.$bg]],
                    'alignment' => ['horizontal' => $ci === 0 ? Alignment::HORIZONTAL_CENTER : Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER],
                    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFD9D9D9']]],
                ]);
            }
        }

        // Chart skeleton — PhpSpreadsheet writes the XML structure
        // ChartDocument patches it post-generation (error bars, styles, scale)
        $ws->addChart($this->buildChartSkeleton($ws->getTitle(), $analysis, $n));
    }

    private function buildChartSkeleton(string $sheet, SampleAnalysisData $analysis, int $n): Chart
    {
        $dataRow = 2;
        $lastRow = $n + 1;

        $xLabels = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING,
            "'{$sheet}'!\$A\${$dataRow}:\$A\${$lastRow}", null, $n);

        $label1  = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING,
            null, null, 1, ["{$analysis->isotope} Results ({$analysis->sampleCode})"]);
        $values1 = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER,
            "'{$sheet}'!\$B\${$dataRow}:\$B\${$lastRow}", null, $n);

        $label2  = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING,
            null, null, 1, ['Valeur assignée']);
        $values2 = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER,
            "'{$sheet}'!\$D\${$dataRow}:\$D\${$lastRow}", null, $n);

        $series = new DataSeries(
            DataSeries::TYPE_SCATTERCHART, DataSeries::GROUPING_STANDARD,
            range(0, 1),
            [$label1, $label2], [$xLabels, $xLabels], [$values1, $values2],
        );
        $series->setPlotStyle(DataSeries::STYLE_MARKER);

        $chart = new Chart(
            'results_lab_asc',
            new Title("{$analysis->isotope} Results ({$analysis->sampleCode})"),
            new Legend(Legend::POSITION_RIGHT, null, false),
            new PlotArea(new Layout(), [$series]),
            true, DataSeries::EMPTY_AS_GAP,
            new Title('Laboratoire'), new Title($analysis->unit),
        );

        $chart->setTopLeftPosition('F1');
        $chart->setBottomRightPosition('T26');

        return $chart;
    }

    // ── Y-axis ceiling ────────────────────────────────────────────────────────

    private function computeYMax(SampleAnalysisData $analysis): float
    {
        $max = 0.0;
        foreach ($analysis->labResults as $lab) {
            if ($lab->activity !== null && $lab->expandedUncertainty !== null) {
                $max = max($max, $lab->activity + $lab->expandedUncertainty);
            } elseif ($lab->activity !== null) {
                $max = max($max, $lab->activity);
            }
        }
        return $max;
    }

    // ── Stubs ─────────────────────────────────────────────────────────────────

    private function buildStubSheet(Worksheet $ws, string $sheetName): void
    {
        $ws->getCell('A1')->setValue($sheetName);
        $ws->getStyle('A1')->applyFromArray(['font' => ['bold' => true, 'color' => ['argb' => 'FF'.self::BLUE_DARK], 'name' => 'Calibri', 'size' => 12]]);
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
