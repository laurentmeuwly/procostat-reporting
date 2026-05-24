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

    /** Row at which data tables start on chart sheets (chart occupies A1:P26). */
    private const TABLE_START_ROW = 28;

    // Column C on 'results lab asc' holds uncertainty k=2 — referenced by error bars
    private const UNCERTAINTY_COL = 'C';

    private const SHEETS = [
        'procostat data',    // 0
        'results lab asc',   // 1
        'results val asc',   // 2
        'bias',              // 3
        'zeta_score',        // 4  — always present
        'zprime_score',      // 5  — only when n > 12
        'zprime vs zeta',    // 6  — only when n > 12
    ];

    /** Threshold above which z'-score and z'_score vs zeta charts are added. */
    private const ZPRIME_MIN_POPULATION = 12;

    public function format(): string { return 'xlsx'; }

    public function generate(IntercomparisonReportData $data, string $outputPath): ReportResult
    {
        $start    = hrtime(true);
        $analysis = $data->analyses[0]
            ?? throw new ReportGenerationException($this->format(), 'No analysis provided.');

        try {
            $n    = count($analysis->labResults);
            $yMax = $this->computeYMax($analysis);
            $hasZprime = $n > self::ZPRIME_MIN_POPULATION;

            // Step 1 — PhpSpreadsheet generates the base xlsx + chart skeleton
            $this->buildFile($outputPath, $analysis, $data);

            // Step 2 — OOXML layer patches the chart XML
            $sheet   = self::SHEETS[1]; // 'results lab asc'
            $dataRef = fn(string $col) => "'{$sheet}'!\${$col}\$2:\${$col}\$" . ($n + 1);

            $patchChart = function (ChartDocument $doc, int $chartIndex, string $sheetName) use ($n, $yMax): void {
                $r1 = self::TABLE_START_ROW + 1;
                $r2 = self::TABLE_START_ROW + $n;
                $errRef = fn(string $col) => "'{$sheetName}'!\${$col}\${$r1}:\${$col}\${$r2}";
                $doc->chart($chartIndex)
                    ->series(0)  // lab activity — points, error bars, no line
                        ->addErrorBars(ErrorBarDefinition::symmetric($errRef(self::UNCERTAINTY_COL)))
                        ->setMarker(MarkerDefinition::circle('4472C4'))
                        ->setLine(LineDefinition::none())
                    ->series(1)  // assigned value — solid red line, no marker
                        ->setLine(LineDefinition::solid('FF0000', 12700))
                        ->setMarker(MarkerDefinition::none())
                    ->series(2)  // upper uncertainty bound — dashed red, no marker
                        ->setLine(LineDefinition::dashed('FF0000', 'dash', 12700))
                        ->setMarker(MarkerDefinition::none())
                    ->series(3)  // lower uncertainty bound — dashed red, no marker
                        ->setLine(LineDefinition::dashed('FF0000', 'dash', 12700))
                        ->setMarker(MarkerDefinition::none())
                    ->yAxis()
                        ->setScale(AxisScaleDefinition::fromZero($yMax))
                    ->save();
            };

            $doc = ChartDocument::open($outputPath);
            $patchChart($doc, 0, self::SHEETS[1]); // results lab asc
            $patchChart($doc, 1, self::SHEETS[2]); // results val asc
            $this->patchBarChart($doc, 2, self::SHEETS[3], showThresholds: false); // bias
            $this->patchBarChart($doc, 3, self::SHEETS[4], showThresholds: true);  // zeta_score

            if ($hasZprime) {
                $this->patchBarChart($doc, 4, self::SHEETS[5], showThresholds: true); // zprime_score
                $this->patchZprimeVsZetaChart($doc, 5, axisMax: 4.0);               // zprime vs zeta
            }

            // Repair drawing XML for Excel compatibility (PhpSpreadsheet sometimes
            // generates incomplete xdr:twoCellAnchor entries that Excel rejects)
            $this->repairExcelDrawings($outputPath);

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

        $n = count($analysis->labResults);
        $hasZprime = $n > self::ZPRIME_MIN_POPULATION;

        $ws = $spreadsheet->getActiveSheet();
        $ws->setTitle(self::SHEETS[0]);
        $this->buildProcostatDataSheet($ws, $analysis, $data);

        $wsResults = $spreadsheet->createSheet();
        $wsResults->setTitle(self::SHEETS[1]);
        $this->buildResultsSheet($wsResults, $analysis, sortBy: 'labNumber');

        $wsResultsVal = $spreadsheet->createSheet();
        $wsResultsVal->setTitle(self::SHEETS[2]);
        $this->buildResultsSheet($wsResultsVal, $analysis, sortBy: 'activity');

        $wsBias = $spreadsheet->createSheet();
        $wsBias->setTitle(self::SHEETS[3]);
        $this->buildBarSheet($wsBias, $analysis, field: 'biasPercent', yLabel: '%', showThresholdLines: false);

        $wsZeta = $spreadsheet->createSheet();
        $wsZeta->setTitle(self::SHEETS[4]);
        $this->buildBarSheet($wsZeta, $analysis, field: 'zetaScore', yLabel: 'Zeta', showThresholdLines: true);

        if ($hasZprime) {
            $wsZprime = $spreadsheet->createSheet();
            $wsZprime->setTitle(self::SHEETS[5]);
            $this->buildBarSheet($wsZprime, $analysis, field: 'zPrimeScore', yLabel: "Z'", showThresholdLines: true);

            $wsZpZeta = $spreadsheet->createSheet();
            $wsZpZeta->setTitle(self::SHEETS[6]);
            $this->buildZprimeVsZetaSheet($wsZpZeta, $analysis);
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

        // Columns: fixed + optional z' + zeta + exclusion (always last)
        $headers   = ['LAB N°', "ACTIVITÉ\n{$analysis->unit}", "INCERTITUDE\n(k=2) {$analysis->unit}", "LD\n{$analysis->unit}", "BIAIS\n%", "Z-SCORE"];
        $cols      = ['A', 'B', 'C', 'D', 'E', 'F'];
        $colWidths = [10,  18,   20,   14,  10,  12];

        if ($hasZprime) { $headers[] = "Z'-SCORE"; $cols[] = 'G'; $colWidths[] = 12; }
        $headers[] = "ZETA\nSCORE"; $cols[] = $hasZprime ? 'H' : 'G'; $colWidths[] = 12;

        // "Exclu des stats" — always the last column
        $headers[]      = "EXCLU\nDES STATS";
        $exclCol        = $hasZprime ? 'I' : 'H';
        $cols[]         = $exclCol;
        $colWidths[]    = 22;

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
            $row       = $tableStart + 1 + $idx;
            $isExcluded = ! $lab->isIncluded || $lab->isTruncated;

            // Excluded/truncated rows get a light yellow background to stand out
            $bg = $isExcluded
                ? 'FFFDE7'   // light amber — excluded
                : (($idx % 2 === 0) ? self::GREY_ROW : self::WHITE);

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
            $values[] = $lab->exclusionLabel() ?? ''; // exclusion column

            foreach ($values as $ci => $value) {
                $isExclCol = ($ci === count($values) - 1);
                $ws->getCell("{$cols[$ci]}{$row}")->setValue($value);
                $ws->getStyle("{$cols[$ci]}{$row}")->applyFromArray([
                    'font'      => [
                        'name'   => 'Calibri',
                        'size'   => 10,
                        'bold'   => ($ci === 0),
                        // Truncated value cells in italic (activity col = index 1)
                        'italic' => ($ci === 1 && $lab->isTruncated),
                        'color'  => ['argb' => $isExclCol && $isExcluded ? 'FFC0392B' : 'FF000000'],
                    ],
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.$bg]],
                    'alignment' => [
                        'horizontal' => ($ci === 0 || $isExclCol) ? Alignment::HORIZONTAL_CENTER : Alignment::HORIZONTAL_RIGHT,
                        'vertical'   => Alignment::VERTICAL_CENTER,
                    ],
                    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFD9D9D9']]],
                ]);
            }

            // Score colour highlights (only for included labs — excluded have no scores)
            if ($lab->isIncluded) {
                $this->applyScoreColor($ws, $cols[$zscoreColIdx].$row, $lab->zScore);
                if ($zprimeColIdx !== null) { $this->applyScoreColor($ws, $cols[$zprimeColIdx].$row, $lab->zPrimeScore); }
                $this->applyScoreColor($ws, $cols[$zetaColIdx].$row, $lab->zetaScore);
            }
        }
    }

    // ── Sheet 2: results lab asc ──────────────────────────────────────────────

    private function buildResultsSheet(Worksheet $ws, SampleAnalysisData $analysis, string $sortBy = 'labNumber'): void
    {
        $labs = collect($analysis->labResults)->sortBy($sortBy)->values()->all();
        $n    = count($labs);

        // Chart sits in A1:P26 — table starts at row TABLE_START_ROW
        $headerRow = self::TABLE_START_ROW;

        // A=label, B=activity, C=uncertainty k2, D=assigned, E=upper, F=lower
        $headers     = ['LAB N°', "ACTIVITÉ ({$analysis->unit})", "INCERTITUDE k=2 ({$analysis->unit})", "VALEUR ASSIGNÉE ({$analysis->unit})", "VA + INCERT.", "VA - INCERT."];
        $tableCols   = ['A', 'B', 'C', 'D', 'E', 'F'];
        $tableWidths = [10,  22,   26,   24,   14,   14];

        foreach ($headers as $i => $header) {
            $ws->getCell("{$tableCols[$i]}{$headerRow}")->setValue($header);
            $ws->getStyle("{$tableCols[$i]}{$headerRow}")->applyFromArray([
                'font'      => ['bold' => true, 'color' => ['argb' => 'FF'.self::WHITE], 'name' => 'Calibri', 'size' => 10],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.self::BLUE_DARK]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFB8CCE4']]],
            ]);
            $ws->getColumnDimension($tableCols[$i])->setWidth($tableWidths[$i]);
        }
        $ws->getRowDimension($headerRow)->setRowHeight(32);

        $assigned = $analysis->assignedValue;
        $upper    = $assigned !== null && $analysis->assignedUncertainty !== null
            ? $assigned + $analysis->assignedUncertainty : null;
        $lower    = $assigned !== null && $analysis->assignedUncertainty !== null
            ? $assigned - $analysis->assignedUncertainty : null;

        foreach ($labs as $idx => $lab) {
            /** @var LabResultData $lab */
            $row    = $headerRow + 1 + $idx;
            $bg     = ($idx % 2 === 0) ? self::GREY_ROW : self::WHITE;
            $values = [(string) $lab->labNumber, $lab->activity, $lab->expandedUncertainty, $assigned, $upper, $lower];

            foreach ($values as $ci => $value) {
                if ($value !== null) {
                    $ws->getCell("{$tableCols[$ci]}{$row}")->setValue($value);
                }
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
        // Data is now at TABLE_START_ROW+1 .. TABLE_START_ROW+n
        $dataRow = self::TABLE_START_ROW + 1;
        $lastRow = self::TABLE_START_ROW + $n;

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

        $label3  = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING,
            null, null, 1, ['VA + incertitude']);
        $values3 = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER,
            "'{$sheet}'!\$E\${$dataRow}:\$E\${$lastRow}", null, $n);

        $label4  = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING,
            null, null, 1, ['VA - incertitude']);
        $values4 = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER,
            "'{$sheet}'!\$F\${$dataRow}:\$F\${$lastRow}", null, $n);

        $series = new DataSeries(
            DataSeries::TYPE_LINECHART, DataSeries::GROUPING_STANDARD,
            range(0, 3),
            [$label1, $label2, $label3, $label4],
            [$xLabels, $xLabels, $xLabels, $xLabels],
            [$values1, $values2, $values3, $values4],
        );
        $series->setPlotStyle(DataSeries::STYLE_MARKER);

        $chart = new Chart(
            'results_lab_asc',
            new Title("{$analysis->isotope} Results ({$analysis->sampleCode})"),
            null, // no legend — removed per client request
            new PlotArea(new Layout(), [$series]),
            true, DataSeries::EMPTY_AS_GAP,
            new Title('Laboratoire'), new Title($analysis->unit),
        );

        // Chart in A1:P26 — table below from row TABLE_START_ROW
        $chart->setTopLeftPosition('A1');
        $chart->setBottomRightPosition('P26');

        return $chart;
    }

    // ── Y-axis ceiling ────────────────────────────────────────────────────────

    private function computeYMax(SampleAnalysisData $analysis): float
    {
        $max = 0.0;
        foreach ($analysis->labResults as $lab) {
            if ($lab->activity !== null && $lab->expandedUncertainty !== null) {
                // Include the full error bar extent (activity + k=2 uncertainty)
                $max = max($max, $lab->activity + $lab->expandedUncertainty);
            } elseif ($lab->activity !== null) {
                $max = max($max, $lab->activity);
            }
        }
        // 10% breathing room above the highest error bar tip
        return $max > 0.0 ? $max * 1.10 : $max;
    }

    // ── Bar chart sheets (bias, zeta_score) ──────────────────────────────────

    /**
     * Build a data sheet + bar chart skeleton for a score field.
     *
     * Chart sits in A1:P26 — data table starts at TABLE_START_ROW.
     * When showThresholdLines=true, reference line data is stored in cols C-F
     * (rows TABLE_START_ROW+1 to TABLE_START_ROW+2) for OOXML patching.
     *
     * @param string $field  Property name on LabResultData: 'biasPercent' | 'zetaScore' | 'zPrimeScore'
     */
    private function buildBarSheet(
        Worksheet          $ws,
        SampleAnalysisData $analysis,
        string             $field,
        string             $yLabel,
        bool               $showThresholdLines = false,
    ): void {
        $labs = collect($analysis->labResults)->sortBy($field)->values()->all();
        $n    = count($labs);

        $headerRow = self::TABLE_START_ROW;
        $firstData = $headerRow + 1;
        $lastData  = $headerRow + $n;

        // ── Column widths ─────────────────────────────────────────────────────
        $ws->getColumnDimension('A')->setWidth(10);
        $ws->getColumnDimension('B')->setWidth(16);
        // Extra cols for threshold line data (hidden chart series, score sheets only)
        if ($showThresholdLines) {
            foreach (['C','D','E','F'] as $col) {
                $ws->getColumnDimension($col)->setWidth(8);
            }
        }

        // ── Table header ──────────────────────────────────────────────────────
        foreach (['LAB N°', $yLabel] as $ci => $header) {
            $col = ['A', 'B'][$ci];
            $ws->getCell("{$col}{$headerRow}")->setValue($header);
            $ws->getStyle("{$col}{$headerRow}")->applyFromArray([
                'font'      => ['bold' => true, 'color' => ['argb' => 'FF'.self::WHITE], 'name' => 'Calibri', 'size' => 10],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.self::BLUE_DARK]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFB8CCE4']]],
            ]);
        }
        $ws->getRowDimension($headerRow)->setRowHeight(28);

        // ── Data rows ─────────────────────────────────────────────────────────
        foreach ($labs as $idx => $lab) {
            /** @var LabResultData $lab */
            $row   = $firstData + $idx;
            $bg    = ($idx % 2 === 0) ? self::GREY_ROW : self::WHITE;
            $value = $lab->{$field} ?? null;

            $ws->getCell("A{$row}")->setValue((string) $lab->labNumber);
            $ws->getCell("B{$row}")->setValue($value);

            foreach (['A', 'B'] as $col) {
                $ws->getStyle("{$col}{$row}")->applyFromArray([
                    'font'      => ['name' => 'Calibri', 'size' => 10],
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.$bg]],
                    'alignment' => ['horizontal' => $col === 'A' ? Alignment::HORIZONTAL_CENTER : Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER],
                    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFD9D9D9']]],
                ]);
            }
        }

        // ── Threshold reference line data (score charts only) ─────────────────
        // We add 4 horizontal line series as two-point scatter overlays:
        //   C = X coordinates (always first and last lab, so 1..n)
        //   D = Y = 0         (centre / reference line — solid red)
        //   E = Y = +2        (warning upper — dashed red)
        //   F = Y = -2        (warning lower — dashed red)
        // Rows firstData and lastData hold the two endpoints for each line.
        // The bar chart will be extended in OOXML with these series.
        if ($showThresholdLines) {
            // Two-point rows: first point = row firstData, second = row lastData
            $ws->getCell("C{$firstData}")->setValue(1);
            $ws->getCell("C{$lastData}")->setValue($n);
            $ws->getCell("D{$firstData}")->setValue(0.0);
            $ws->getCell("D{$lastData}")->setValue(0.0);
            $ws->getCell("E{$firstData}")->setValue(2.0);
            $ws->getCell("E{$lastData}")->setValue(2.0);
            $ws->getCell("F{$firstData}")->setValue(-2.0);
            $ws->getCell("F{$lastData}")->setValue(-2.0);
        }

        // ── Bar chart skeleton ────────────────────────────────────────────────
        $sheet   = $ws->getTitle();

        $xLabels = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING,
            "'{$sheet}'!\$A\${$firstData}:\$A\${$lastData}", null, $n);
        $label   = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING,
            null, null, 1, [$yLabel]);
        $values  = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER,
            "'{$sheet}'!\$B\${$firstData}:\$B\${$lastData}", null, $n);

        $series = new DataSeries(
            DataSeries::TYPE_BARCHART,
            DataSeries::GROUPING_CLUSTERED,
            [0],
            [$label], [$xLabels], [$values],
        );

        $chart = new Chart(
            'bar_' . $field,
            new Title("{$analysis->isotope} {$yLabel} ({$analysis->sampleCode})"),
            null,
            new PlotArea(new Layout(), [$series]),
            true,
            DataSeries::EMPTY_AS_GAP,
            new Title('Laboratoire'),
            new Title($yLabel),
        );

        // Chart in A1:P26 — table below from TABLE_START_ROW
        $chart->setTopLeftPosition('A1');
        $chart->setBottomRightPosition('P26');

        $ws->addChart($chart);
    }

    // ── zprime vs zeta scatter (n ≥ 12 only) ─────────────────────────────────

    /**
     * Scatter plot: z'-score (X) vs zeta-score (Y), one point per lab.
     *
     * Threshold lines at ±2 (warning, dashed orange) and ±3 (action, dashed red)
     * on both axes — implemented as 8 two-point scatter series so they span
     * the full axis range and remain native/editable.
     *
     * Data table:
     *   A = lab number (label)
     *   B = zprime score (X)
     *   C = zeta score  (Y)
     */
    private function buildZprimeVsZetaSheet(Worksheet $ws, SampleAnalysisData $analysis): void
    {
        $labs    = collect($analysis->labResults)->sortBy('zPrimeScore')->values()->all();
        $n       = count($labs);
        $axisMax = 4.0;
        $sheet   = $ws->getTitle();

        // ── Column widths ─────────────────────────────────────────────────────
        $ws->getColumnDimension('A')->setWidth(10);
        $ws->getColumnDimension('B')->setWidth(14);
        $ws->getColumnDimension('C')->setWidth(14);
        // Threshold data columns (hidden — chart data source)
        foreach (['D','E','F','G','H','I','J','K'] as $col) {
            $ws->getColumnDimension($col)->setWidth(8);
        }

        // ── Table header (TABLE_START_ROW) ────────────────────────────────────
        $headerRow = self::TABLE_START_ROW;
        foreach (['LAB N°', "Z'-SCORE", 'ZETA-SCORE'] as $ci => $header) {
            $col = ['A', 'B', 'C'][$ci];
            $ws->getCell("{$col}{$headerRow}")->setValue($header);
            $ws->getStyle("{$col}{$headerRow}")->applyFromArray([
                'font'      => ['bold' => true, 'color' => ['argb' => 'FF'.self::WHITE], 'name' => 'Calibri', 'size' => 10],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.self::BLUE_DARK]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFB8CCE4']]],
            ]);
        }
        $ws->getRowDimension($headerRow)->setRowHeight(28);

        // ── Lab data rows ─────────────────────────────────────────────────────
        $firstData = $headerRow + 1;
        foreach ($labs as $idx => $lab) {
            /** @var LabResultData $lab */
            $row = $firstData + $idx;
            $bg  = ($idx % 2 === 0) ? self::GREY_ROW : self::WHITE;
            $ws->getCell("A{$row}")->setValue((string) $lab->labNumber);
            $ws->getCell("B{$row}")->setValue($lab->zPrimeScore);
            $ws->getCell("C{$row}")->setValue($lab->zetaScore);
            foreach (['A', 'B', 'C'] as $col) {
                $ws->getStyle("{$col}{$row}")->applyFromArray([
                    'font'      => ['name' => 'Calibri', 'size' => 10],
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.$bg]],
                    'alignment' => ['horizontal' => $col === 'A' ? Alignment::HORIZONTAL_CENTER : Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER],
                    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFD9D9D9']]],
                ]);
            }
        }

        // ── Threshold line data in cells (after data, cols D..K) ─────────────
        // Each vertical line  = (fixed_x, fixed_x) with Y = (-axisMax, +axisMax)
        // Each horizontal line = (fixed_y, fixed_y) with X = (-axisMax, +axisMax)
        // Two rows: t1 = firstData row, t2 = firstData+1 row (chart data source)
        // These are in hidden cols D..K, below the chart area (row >= TABLE_START_ROW)
        //
        // Col pairs:   D/E = x=+2   F/G = x=-2   H/I = x=+3   J/K = x=-3 (vertical)
        //              D/E = y=+2   F/G = y=-2   H/I = y=+3   J/K = y=-3 (horizontal, +2 rows)
        $t1 = $firstData;
        $t2 = $firstData + 1;
        $t3 = $firstData + 2;
        $t4 = $firstData + 3;

        $threshData = [
            // vertical lines — rows t1, t2
            ['D', 'E',  2,      2,     -$axisMax, $axisMax],  // x=+2
            ['F', 'G', -2,     -2,     -$axisMax, $axisMax],  // x=-2
            ['H', 'I',  3,      3,     -$axisMax, $axisMax],  // x=+3
            ['J', 'K', -3,     -3,     -$axisMax, $axisMax],  // x=-3
        ];
        foreach ($threshData as [$xCol, $yCol, $x1, $x2, $y1, $y2]) {
            $ws->getCell("{$xCol}{$t1}")->setValue($x1);
            $ws->getCell("{$xCol}{$t2}")->setValue($x2);
            $ws->getCell("{$yCol}{$t1}")->setValue($y1);
            $ws->getCell("{$yCol}{$t2}")->setValue($y2);
        }

        // Horizontal lines — rows t3, t4
        $horizData = [
            ['D', 'E', -$axisMax, $axisMax,  2,  2],   // y=+2
            ['F', 'G', -$axisMax, $axisMax, -2, -2],   // y=-2
            ['H', 'I', -$axisMax, $axisMax,  3,  3],   // y=+3
            ['J', 'K', -$axisMax, $axisMax, -3, -3],   // y=-3
        ];
        foreach ($horizData as [$xCol, $yCol, $x1, $x2, $y1, $y2]) {
            $ws->getCell("{$xCol}{$t3}")->setValue($x1);
            $ws->getCell("{$xCol}{$t4}")->setValue($x2);
            $ws->getCell("{$yCol}{$t3}")->setValue($y1);
            $ws->getCell("{$yCol}{$t4}")->setValue($y2);
        }

        // ── Chart series ──────────────────────────────────────────────────────
        $lastData  = self::TABLE_START_ROW + $n;

        $labels  = [];
        $xSeries = [];
        $ySeries = [];
        $orders  = [];

        // Series 0 — lab points (zprime X, zeta Y) — STYLE_MARKER
        $labels[]  = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING,
            null, null, 1, ["{$analysis->isotope} ({$analysis->sampleCode})"]);
        $xSeries[] = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER,
            "'{$sheet}'!\$B\${$firstData}:\$B\${$lastData}", null, $n);
        $ySeries[] = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER,
            "'{$sheet}'!\$C\${$firstData}:\$C\${$lastData}", null, $n);
        $orders[]  = 0;

        // Series 1-4 — vertical threshold lines (cell-referenced, rows t1/t2)
        $vertCols = [['D','E'], ['F','G'], ['H','I'], ['J','K']];
        foreach ($vertCols as $si => [$xCol, $yCol]) {
            $labels[]  = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, null, null, 1, ['']);
            $xSeries[] = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER,
                "'{$sheet}'!\${$xCol}\${$t1}:'{$sheet}'!\${$xCol}\${$t2}", null, 2);
            $ySeries[] = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER,
                "'{$sheet}'!\${$yCol}\${$t1}:'{$sheet}'!\${$yCol}\${$t2}", null, 2);
            $orders[]  = $si + 1;
        }

        // Series 5-8 — horizontal threshold lines (rows t3/t4)
        foreach ($vertCols as $si => [$xCol, $yCol]) {
            $labels[]  = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, null, null, 1, ['']);
            $xSeries[] = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER,
                "'{$sheet}'!\${$xCol}\${$t3}:'{$sheet}'!\${$xCol}\${$t4}", null, 2);
            $ySeries[] = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER,
                "'{$sheet}'!\${$yCol}\${$t3}:'{$sheet}'!\${$yCol}\${$t4}", null, 2);
            $orders[]  = $si + 5;
        }

        // One DataSeries per plot style group — PhpSpreadsheet requires separate
        // DataSeries objects if we mix STYLE_MARKER and STYLE_LINE.
        // We use STYLE_SMOOTHMARKER for the whole chart (markers + line capable)
        // and suppress lines on series 0 via OOXML patch.
        $series = new DataSeries(
            DataSeries::TYPE_SCATTERCHART,
            DataSeries::GROUPING_STANDARD,
            $orders,
            $labels, $xSeries, $ySeries,
        );
        $series->setPlotStyle(DataSeries::STYLE_MARKER);

        $title = "{$analysis->isotope} Z'-score vs Zeta-score ({$analysis->sampleCode})";
        $chart = new Chart(
            'zprime_vs_zeta',
            new Title($title),
            null,
            new PlotArea(new Layout(), [$series]),
            true,
            DataSeries::EMPTY_AS_GAP,
            new Title("Z'-score"),
            new Title('Zeta-score'),
        );
        $chart->setTopLeftPosition('A1');
        $chart->setBottomRightPosition('P26');
        $ws->addChart($chart);
    }

    /**
     * Post-generation OOXML patch for the zprime vs zeta scatter chart.
     * Applies: lab series = circle markers, no line
     *          threshold series 1-4 = vertical lines (orange/red dashed)
     *          threshold series 5-8 = horizontal lines (orange/red dashed)
     */
    private function patchZprimeVsZetaChart(ChartDocument $doc, int $chartIndex, float $axisMax): void
    {
        try {
            $ctx = $doc->chart($chartIndex);

            // Series 0 — lab points: circle markers, NO connecting line
            $ctx->series(0)
                ->setMarker(MarkerDefinition::circle('4472C4', 7))
                ->setLine(LineDefinition::none());

            // Series 1-4 — vertical threshold lines
            // Series 5-8 — horizontal threshold lines
            // Color: orange for ±2 (series 1,2,5,6), red for ±3 (series 3,4,7,8)
            $thresholdStyles = [
                1 => 'FFA500', 2 => 'FFA500', // x=+2, x=-2 — orange
                3 => 'FF0000', 4 => 'FF0000', // x=+3, x=-3 — red
                5 => 'FFA500', 6 => 'FFA500', // y=+2, y=-2 — orange
                7 => 'FF0000', 8 => 'FF0000', // y=+3, y=-3 — red
            ];

            foreach ($thresholdStyles as $serIdx => $color) {
                $ctx->series($serIdx)
                    ->setLine(LineDefinition::dashed($color, 'dash', 12700))
                    ->setMarker(MarkerDefinition::none());
            }

            // Both axes: symmetric ±axisMax, centered on 0
            // On a scatter chart PhpSpreadsheet writes two valAx — index 0 = X, index 1 = Y
            $scale = new AxisScaleDefinition(min: -$axisMax, max: $axisMax);
            $ctx->yAxis(0)->setScale($scale); // X axis (first valAx in OOXML)
            $ctx->yAxis(1)->setScale($scale); // Y axis (second valAx)

            $ctx->save();

            // Additional OOXML patch: center axes at zero (crosses at 0,0)
            // ChartDocument doesn't have a crosses() API yet — patch directly
            $this->patchScatterAxesCrossAtZero($doc->getXlsxPath(), $chartIndex);

        } catch (\InvalidArgumentException) {
            // Chart not present (n < 12) — skip silently
        }
    }

    /**
     * Patch <c:crosses val="autoZero"> → <c:crosses val="autoZero"> is already correct,
     * but we also need <c:crossesAt val="0"/> to ensure both axes cross at origin.
     * Direct ZipArchive patch on the chart XML after ChartDocument::save().
     */
    private function patchScatterAxesCrossAtZero(string $xlsxPath, int $chartIndex): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($xlsxPath) !== true) return;

        $keys = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^xl/charts/chart(\d+)\.xml$#', $name)) {
                $keys[] = $name;
            }
        }
        sort($keys);

        if (! isset($keys[$chartIndex])) {
            $zip->close();
            return;
        }

        $xml = $zip->getFromName($keys[$chartIndex]);

        // Replace <c:crosses val="autoZero"/> with crossesAt=0 on all axes
        // so both X and Y axes cross at the origin
        $xml = str_replace(
            '<c:crosses val="autoZero"/>',
            '<c:crosses val="autoZero"/><c:crossesAt val="0"/>',
            $xml
        );

        $zip->addFromString($keys[$chartIndex], $xml);
        $zip->close();
    }

    /**
     * Fix Excel compatibility issue with drawings.
     *
     * PhpSpreadsheet may generate xl/drawings/drawingN.xml files that contain
     * chart anchors without a proper <xdr:graphicFrame> wrapper, or that reference
     * charts via relationships that Excel cannot validate. Excel silently removes
     * the offending drawing part on open.
     *
     * This method iterates all drawing files in the xlsx and ensures that each
     * <xdr:twoCellAnchor> containing a chart reference is a valid graphicFrame.
     * If PhpSpreadsheet generated a bare <xdr:sp> or an empty anchor instead of
     * <xdr:graphicFrame>, we rebuild the anchor from the chart relationship.
     *
     * Additionally ensures [Content_Types].xml has the correct Override entries
     * for all chart drawing parts, which Excel requires but LibreOffice ignores.
     */
    private function repairExcelDrawings(string $xlsxPath): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($xlsxPath) !== true) return;

        $modified = false;

        // Find all drawing XML files
        $drawingFiles = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^xl/drawings/drawing\d+\.xml$#', $name)) {
                $drawingFiles[] = $name;
            }
        }

        foreach ($drawingFiles as $drawingFile) {
            $xml = $zip->getFromName($drawingFile);
            if ($xml === false) continue;

            // Find the relationship file for this drawing
            $drawingName = basename($drawingFile); // e.g. drawing6.xml
            $relFile = 'xl/drawings/_rels/' . $drawingName . '.rels';
            $relXml  = $zip->getFromName($relFile);

            if ($relXml === false) continue;

            // Parse relationships to find chart references
            $relDom = new \DOMDocument();
            if (! @$relDom->loadXML($relXml)) continue;

            $relXpath = new \DOMXPath($relDom);
            $relXpath->registerNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');

            $chartRels = $relXpath->query('//r:Relationship[@Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart"]');
            if ($chartRels === false || $chartRels->length === 0) continue;

            // Parse the drawing XML
            $dom = new \DOMDocument();
            $dom->preserveWhiteSpace = false;
            $dom->formatOutput = false;
            if (! @$dom->loadXML($xml)) continue;

            $xp = new \DOMXPath($dom);
            $xp->registerNamespace('xdr', 'http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing');
            $xp->registerNamespace('a',   'http://schemas.openxmlformats.org/drawingml/2006/main');
            $xp->registerNamespace('r',   'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $xp->registerNamespace('c',   'http://schemas.openxmlformats.org/drawingml/2006/chart');

            // Check each anchor: if it contains a graphicFrame with a chart reference, it's valid.
            // If it's missing or malformed (no xdr:graphicFrame), we flag it.
            $anchors = $xp->query('//xdr:twoCellAnchor');
            if ($anchors === false) continue;

            $needsRebuild = false;
            foreach ($anchors as $anchor) {
                $graphicFrames = $xp->query('xdr:graphicFrame', $anchor);
                if ($graphicFrames === false || $graphicFrames->length === 0) {
                    $needsRebuild = true;
                    break;
                }
            }

            if (! $needsRebuild) continue;

            // Rebuild drawing XML: for each chart relationship, create a proper
            // twoCellAnchor with graphicFrame. Use generic positions (A1:P26).
            $ns  = 'http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing';
            $ans = 'http://schemas.openxmlformats.org/drawingml/2006/main';
            $rns = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

            $newDom = new \DOMDocument('1.0', 'UTF-8');
            $root   = $newDom->createElementNS($ns, 'xdr:wsDr');
            $root->setAttribute('xmlns:a',   $ans);
            $root->setAttribute('xmlns:r',   $rns);
            $root->setAttribute('xmlns:xdr', $ns);
            $newDom->appendChild($root);

            foreach ($chartRels as $idx => $rel) {
                $rId    = $rel->getAttribute('Id');
                $anchor = $newDom->createElementNS($ns, 'xdr:twoCellAnchor');
                $anchor->setAttribute('editAs', 'oneCell');

                // from: A1 (col=0, row=0)
                $from = $newDom->createElementNS($ns, 'xdr:from');
                foreach (['xdr:col' => '0', 'xdr:colOff' => '0', 'xdr:row' => '0', 'xdr:rowOff' => '0'] as $tag => $val) {
                    $el = $newDom->createElementNS($ns, $tag, $val);
                    $from->appendChild($el);
                }

                // to: P26 (col=15, row=25)
                $to = $newDom->createElementNS($ns, 'xdr:to');
                foreach (['xdr:col' => '15', 'xdr:colOff' => '0', 'xdr:row' => '25', 'xdr:rowOff' => '0'] as $tag => $val) {
                    $el = $newDom->createElementNS($ns, $tag, $val);
                    $to->appendChild($el);
                }

                // graphicFrame
                $gf  = $newDom->createElementNS($ns, 'xdr:graphicFrame');
                $gf->setAttribute('macro', '');
                $nvGf = $newDom->createElementNS($ns, 'xdr:nvGraphicFramePr');
                $cNvPr = $newDom->createElementNS($ns, 'xdr:cNvPr');
                $cNvPr->setAttribute('id',   (string)(2 + $idx));
                $cNvPr->setAttribute('name', 'Chart ' . $idx);
                $cNvGfPr = $newDom->createElementNS($ns, 'xdr:cNvGraphicFramePr');
                $nvGf->appendChild($cNvPr);
                $nvGf->appendChild($cNvGfPr);

                $xfrm = $newDom->createElementNS($ns, 'xdr:xfrm');
                $off  = $newDom->createElementNS($ans, 'a:off');
                $off->setAttribute('x', '0'); $off->setAttribute('y', '0');
                $ext  = $newDom->createElementNS($ans, 'a:ext');
                $ext->setAttribute('cx', '0'); $ext->setAttribute('cy', '0');
                $xfrm->appendChild($off); $xfrm->appendChild($ext);

                $graphic = $newDom->createElementNS($ans, 'a:graphic');
                $gData   = $newDom->createElementNS($ans, 'a:graphicData');
                $gData->setAttribute('uri', 'http://schemas.openxmlformats.org/drawingml/2006/chart');
                $cChart  = $newDom->createElementNS('http://schemas.openxmlformats.org/drawingml/2006/chart', 'c:chart');
                $cChart->setAttributeNS($rns, 'r:id', $rId);
                $gData->appendChild($cChart);
                $graphic->appendChild($gData);

                $gf->appendChild($nvGf);
                $gf->appendChild($xfrm);
                $gf->appendChild($graphic);

                $clientData = $newDom->createElementNS($ns, 'xdr:clientData');

                $anchor->appendChild($from);
                $anchor->appendChild($to);
                $anchor->appendChild($gf);
                $anchor->appendChild($clientData);
                $root->appendChild($anchor);
            }

            $zip->addFromString($drawingFile, $newDom->saveXML());
            $modified = true;
        }

        $zip->close();
    }

    /**
     * OOXML patch for bar charts.
     *
     * When showThresholds=true (zeta_score, zprime_score), injects reference lines
     * by patching the chart XML directly:
     *   - solid red line at Y=0   (assigned value reference)
     *   - dashed red line at Y=+2 (warning upper)
     *   - dashed red line at Y=-2 (warning lower)
     *
     * These are added as <c:ser> line series appended to the barChart via a
     * secondary lineChart plotted on the same axes — the only reliable OOXML
     * approach for overlay reference lines on bar charts.
     */
    private function patchBarChart(ChartDocument $doc, int $chartIndex, string $sheetName, bool $showThresholds = false): void
    {
        try {
            $doc->chart($chartIndex)
                ->series(0)
                    ->setLine(LineDefinition::solid('4472C4', 0))
                    ->setMarker(MarkerDefinition::none())
                ->save();

            if ($showThresholds) {
                $this->injectBarChartReferenceLines($doc->getXlsxPath(), $chartIndex, $sheetName);
            }
        } catch (\InvalidArgumentException) {
            // Chart index out of range — skip silently
        }
    }

    /**
     * Inject reference lines into a bar chart by appending a lineChart block
     * to the chart XML alongside the existing barChart.
     *
     * Uses cols C (x-coord), D (y=0), E (y=+2), F (y=-2) at TABLE_START_ROW+1
     * and TABLE_START_ROW+n (the two-point row pair written by buildBarSheet).
     *
     * Line series:
     *   - y=0  : solid red       (assigned/centre reference)
     *   - y=+2 : dashed red      (warning upper, e.g. |z|=2)
     *   - y=-2 : dashed red      (warning lower)
     */
    private function injectBarChartReferenceLines(string $xlsxPath, int $chartIndex, string $sheetName): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($xlsxPath) !== true) return;

        $keys = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^xl/charts/chart\d+\.xml$#', $name)) {
                $keys[] = $name;
            }
        }
        sort($keys);

        if (! isset($keys[$chartIndex])) { $zip->close(); return; }

        $xml = $zip->getFromName($keys[$chartIndex]);

        // Row references: buildBarSheet writes two-point data at firstData and lastData.
        // We use a wide range (TABLE_START_ROW+1 .. TABLE_START_ROW+100) and rely on
        // EMPTY_AS_GAP — only the two populated rows will contribute to the line.
        $r1Str = (string)(self::TABLE_START_ROW + 1);
        $r2Str = (string)(self::TABLE_START_ROW + 100);
        $esc_  = $sheetName;

        $makeLineSer = function (int $idx, string $col, string $color, string $dash) use ($r1Str, $r2Str, $esc_): string {
            $dashXml = $dash === 'solid' ? '' : "<a:prstDash val=\"{$dash}\"/>";
            return
                "<c:ser>" .
                "<c:idx val=\"{$idx}\"/><c:order val=\"{$idx}\"/>" .
                "<c:spPr><a:ln w=\"19050\"><a:solidFill><a:srgbClr val=\"{$color}\"/></a:solidFill>{$dashXml}</a:ln></c:spPr>" .
                "<c:marker><c:symbol val=\"none\"/></c:marker>" .
                "<c:val><c:numRef><c:f>'{$esc_}'!\${$col}\${$r1Str}:'{$esc_}'!\${$col}\${$r2Str}</c:f></c:numRef></c:val>" .
                "</c:ser>";
        };

        // Build a lineChart block to overlay on the barChart
        // Axis IDs (200/201) must differ from the barChart axes to avoid conflicts.
        $lineChartXml =
            "<c:lineChart>" .
            "<c:grouping val=\"standard\"/>" .
            $makeLineSer(1, 'D', 'FF0000', 'solid') .  // y=0 solid red
            $makeLineSer(2, 'E', 'FF0000', 'dash')  .  // y=+2 dashed
            $makeLineSer(3, 'F', 'FF0000', 'dash')  .  // y=-2 dashed
            "<c:axId val=\"200\"/><c:axId val=\"201\"/>" .
            "</c:lineChart>";

        // Inject after the closing </c:barChart> tag, before </c:plotArea>
        // Also add two axis definitions for the line overlay (share same plot area)
        $axesXml =
            "<c:valAx>" .
            "<c:axId val=\"201\"/>" .
            "<c:scaling><c:orientation val=\"minMax\"/></c:scaling>" .
            "<c:delete val=\"1\"/>" .  // hidden axis
            "<c:axPos val=\"l\"/>" .
            "<c:crossAx val=\"200\"/>" .
            "</c:valAx>" .
            "<c:catAx>" .
            "<c:axId val=\"200\"/>" .
            "<c:scaling><c:orientation val=\"minMax\"/></c:scaling>" .
            "<c:delete val=\"1\"/>" .
            "<c:axPos val=\"b\"/>" .
            "<c:crossAx val=\"201\"/>" .
            "</c:catAx>";

        // Insert lineChart + hidden axes before </c:plotArea>
        $xml = str_replace(
            '</c:plotArea>',
            $lineChartXml . $axesXml . '</c:plotArea>',
            $xml
        );

        $zip->addFromString($keys[$chartIndex], $xml);
        $zip->close();
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
