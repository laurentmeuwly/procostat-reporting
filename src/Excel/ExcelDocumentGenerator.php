<?php

namespace Procorad\ProcostatReporting\Excel;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Procorad\ProcostatReporting\Contracts\DocumentGenerator;
use Procorad\ProcostatReporting\Data\IntercomparisonReportData;
use Procorad\ProcostatReporting\Data\ReportResult;
use Procorad\ProcostatReporting\Data\SampleAnalysisData;
use Procorad\ProcostatReporting\Exceptions\ReportGenerationException;
use Procorad\ProcostatReporting\Excel\Builders\BarChartSheetBuilder;
use Procorad\ProcostatReporting\Excel\Builders\ProcostatDataSheetBuilder;
use Procorad\ProcostatReporting\Excel\Builders\ResultsSheetBuilder;
use Procorad\ProcostatReporting\Excel\Builders\ZPrimeVsZetaSheetBuilder;
use Procorad\ProcostatReporting\Excel\Charts\BarChartBuilder;
use Procorad\ProcostatReporting\Excel\Charts\ResultsChartBuilder;
use Procorad\ProcostatReporting\Excel\Charts\ScatterChartBuilder;
use Procorad\ProcostatReporting\Excel\Ooxml\ChartDocument;
use Procorad\ProcostatReporting\Excel\Patches\BarChartPatcher;
use Procorad\ProcostatReporting\Excel\Patches\DrawingRelationshipFixer;
use Procorad\ProcostatReporting\Excel\Patches\ResultsChartPatcher;
use Procorad\ProcostatReporting\Excel\Patches\ScatterChartPatcher;
use Procorad\ProcostatReporting\Excel\Support\ExcelLayout;
use Procorad\ProcostatReporting\Excel\Support\YAxisCalculator;

/**
 * Orchestrates the generation of one .xlsx file per SampleAnalysisData.
 *
 * Responsibilities:
 *   1. Create the Spreadsheet and named sheets
 *   2. Delegate sheet content to specialised Builders
 *   3. Save the base file via PhpSpreadsheet
 *   4. Apply OOXML patches (styles, threshold lines, drawings repair)
 *
 * Sheet structure:
 *   0  procostat data     — summary + lab results table          (always)
 *   1  results lab asc    — activity chart, sorted by lab number (always)
 *   2  results val asc    — activity chart, sorted by value      (always)
 *   3  bias               — bar chart, bias %                    (always)
 *   4  zeta_score         — bar chart, zeta score                (always)
 *   5  zprime_score       — bar chart, z'-score                  (n > 12 only)
 *   6  zprime vs zeta     — scatter plot                         (n > 12 only)
 */
final class ExcelDocumentGenerator implements DocumentGenerator
{
    private const SHEET_DATA        = 'procostat data';
    private const SHEET_RESULTS_LAB = 'results lab asc';
    private const SHEET_RESULTS_VAL = 'results val asc';
    private const SHEET_BIAS        = 'bias';
    private const SHEET_ZETA        = 'zeta_score';
    private const SHEET_ZPRIME      = 'zprime_score';
    private const SHEET_SCATTER     = 'zprime vs zeta';

    // Chart indices within the generated xlsx (0-based, matches sheet creation order)
    private const CHART_RESULTS_LAB = 0;
    private const CHART_RESULTS_VAL = 1;
    private const CHART_BIAS        = 2;
    private const CHART_ZETA        = 3;
    private const CHART_ZPRIME      = 4;
    private const CHART_SCATTER     = 5;

    public function format(): string { return 'xlsx'; }

    public function generate(IntercomparisonReportData $data, string $outputPath): ReportResult
    {
        $start    = hrtime(true);
        $analysis = $data->analyses[0]
            ?? throw new ReportGenerationException($this->format(), 'No analysis provided.');

        try {
            $this->buildSpreadsheet($outputPath, $analysis, $data);
            $this->applyPatches($outputPath, $analysis);
        } catch (\Throwable $e) {
            throw ReportGenerationException::fromThrowable($this->format(), $e);
        }

        return new ReportResult(
            files:      ["xlsx:{$analysis->sampleCode}:{$analysis->isotope}" => $outputPath],
            errors:     [],
            durationMs: (hrtime(true) - $start) / 1_000_000,
        );
    }

    // ── Step 1: Build spreadsheet ─────────────────────────────────────────────

    private function buildSpreadsheet(
        string                    $outputPath,
        SampleAnalysisData        $analysis,
        IntercomparisonReportData $data,
    ): void {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setTitle("{$data->year}_{$data->icCode}_{$analysis->sampleCode}_{$analysis->isotope}")
            ->setCreator('procostat-reporting');

        $n         = count($analysis->labResults);
        $hasZprime = $n > ExcelLayout::ZPRIME_MIN_POPULATION;

        // Sheet 0 — summary
        $ws = $spreadsheet->getActiveSheet();
        $ws->setTitle(self::SHEET_DATA);
        (new ProcostatDataSheetBuilder())->build($ws, $analysis, $data);

        // Sheets 1-2 — results (by lab, by value)
        $resultsBuilder = new ResultsSheetBuilder(new ResultsChartBuilder());
        $resultsBuilder->build($spreadsheet->createSheet()->setTitle(self::SHEET_RESULTS_LAB), $analysis, sortBy: 'labNumber');
        $resultsBuilder->build($spreadsheet->createSheet()->setTitle(self::SHEET_RESULTS_VAL), $analysis, sortBy: 'activity');

        // Sheets 3-4 — bar charts (always)
        $barBuilder = new BarChartSheetBuilder(new BarChartBuilder());
        $barBuilder->build($spreadsheet->createSheet()->setTitle(self::SHEET_BIAS),  $analysis, field: 'biasPercent', yLabel: '%',    withThresholds: false);
        $barBuilder->build($spreadsheet->createSheet()->setTitle(self::SHEET_ZETA),  $analysis, field: 'zetaScore',   yLabel: 'Zeta', withThresholds: true);

        // Sheets 5-6 — zprime charts (population > 12 only)
        if ($hasZprime) {
            $barBuilder->build($spreadsheet->createSheet()->setTitle(self::SHEET_ZPRIME), $analysis, field: 'zPrimeScore', yLabel: "Z'", withThresholds: true);

            /*(new ZPrimeVsZetaSheetBuilder(new ScatterChartBuilder()))->build(
                $spreadsheet->createSheet()->setTitle(self::SHEET_SCATTER),
                $analysis,
            );*/
        }

        $spreadsheet->setActiveSheetIndex(0);
        $writer = new Xlsx($spreadsheet);
        $writer->setIncludeCharts(true);
        $writer->save($outputPath);
    }

    // ── Step 2: OOXML patches ─────────────────────────────────────────────────

    private function applyPatches(string $outputPath, SampleAnalysisData $analysis): void
    {
        $n         = count($analysis->labResults);
        $hasZprime = $n > ExcelLayout::ZPRIME_MIN_POPULATION;
        $yMax      = (new YAxisCalculator())->compute($analysis);

        $doc            = ChartDocument::open($outputPath);
        $resultsPatcher = new ResultsChartPatcher();
        $barPatcher     = new BarChartPatcher();

        // Results charts
        $resultsPatcher->patch($doc, self::CHART_RESULTS_LAB, self::SHEET_RESULTS_LAB, $n, $yMax);
        $resultsPatcher->patch($doc, self::CHART_RESULTS_VAL, self::SHEET_RESULTS_VAL, $n, $yMax);

        // Bar charts
        $barPatcher->patch($doc, self::CHART_BIAS, self::SHEET_BIAS, withThresholds: false, barValues: []);

        $zetaValues = collect($analysis->labResults)->sortBy('zetaScore')->pluck('zetaScore')->toArray();
        $barPatcher->patch($doc, self::CHART_ZETA, self::SHEET_ZETA, withThresholds: true, barValues: $zetaValues);

        if ($hasZprime) {
            $zprimeValues = collect($analysis->labResults)->sortBy('zPrimeScore')->pluck('zPrimeScore')->toArray();
            $barPatcher->patch($doc, self::CHART_ZPRIME, self::SHEET_ZPRIME, withThresholds: true, barValues: $zprimeValues);
            //(new ScatterChartPatcher())->patch($doc, self::CHART_SCATTER);
        }

        // Drawing XML repair — must run last, after all chart patches are written
        (new DrawingRelationshipFixer())->fix($outputPath);
    }
}
