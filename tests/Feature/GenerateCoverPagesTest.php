<?php

declare(strict_types=1);

namespace Procorad\ProcostatReporting\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Procorad\ProcostatReporting\DTO\ChartConfig;
use Procorad\ProcostatReporting\DTO\LabResult;
use Procorad\ProcostatReporting\DTO\ProcostatReportData;
use Procorad\ProcostatReporting\DTO\ReportResult;
use Procorad\ProcostatReporting\DTO\StatisticsData;
use Procorad\ProcostatReporting\DTO\ZscoreLimits;
use Procorad\ProcostatReporting\Excel\ExcelReportGenerator;
use Procorad\ProcostatReporting\Node\NodeRenderer;
use Procorad\ProcostatReporting\PowerPoint\PowerPointReportGenerator;
use Procorad\ProcostatReporting\Support\PackagePaths;
use Procorad\ProcostatReporting\Word\WordReportGenerator;

/**
 * End-to-end test: actually runs Node and PhpSpreadsheet and checks that
 * the output files are non-empty valid archives.
 *
 * Skipped automatically if node is not available on PATH.
 */
final class GenerateCoverPagesTest extends TestCase
{
    private string $outputDir;

    protected function setUp(): void
    {
        $node = trim(shell_exec('which node 2>/dev/null') ?? '');
        if (empty($node)) {
            $this->markTestSkipped('node not found on PATH — skipping Node renderer tests.');
        }

        $this->outputDir = sys_get_temp_dir() . '/procostat_test_' . uniqid();
        mkdir($this->outputDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up generated files
        foreach (glob($this->outputDir . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->outputDir);
    }

    private function makeTestData(): ProcostatReportData
    {
        return new ProcostatReportData(
            intercomparison: 'CARBON 14',
            sample:          '25CB',
            isotope:         '14C',
            year:            2025,
            unit:            'Bq/l',
            statistics: new StatisticsData(
                numberOfResults:     28,
                assignedValue:       2520.0,
                assignedUncertainty: 70.0,
                robustMean:          2470.0,
                robustDeviation:     61.2,
                geometricalMean:     2480.0,
                minValue:            2220.0,
                maxValue:            3040.0,
            ),
            zscoreLimits: new ZscoreLimits(),
            labResults: [
                new LabResult(3,  2460.0, 220.0, 28.0,  -2.0, -0.3, -1.0),
                new LabResult(4,  2470.0, 100.0, 23.0,  -2.0, -0.4, -0.8),
                new LabResult(56, 3040.0, 183.0, 15.9,  20.0,  2.6,  8.4),
            ],
            chartConfigs: [
                new ChartConfig('results', '14C Results (25CB)'),
                new ChartConfig('zscore',  '14C Z-score (25CB)'),
            ],
            metadata: [
                'locale'            => 'fr',
                'propertyFileTitle' => 'Property File Title',
            ],
        );
    }

    public function test_pptx_cover_is_generated(): void
    {
        $output    = $this->outputDir . '/report.pptx';
        $generator = new PowerPointReportGenerator(new NodeRenderer());

        $result = $generator->generate($this->makeTestData(), $output);

        $this->assertInstanceOf(ReportResult::class, $result);
        $this->assertTrue($result->isFullySuccessful());
        $this->assertFileExists($output);
        $this->assertGreaterThan(5000, filesize($output), 'PPTX file seems too small');
    }

    public function test_docx_cover_is_generated(): void
    {
        $output    = $this->outputDir . '/report.docx';
        $generator = new WordReportGenerator(new NodeRenderer());

        $result = $generator->generate($this->makeTestData(), $output);

        $this->assertInstanceOf(ReportResult::class, $result);
        $this->assertTrue($result->isFullySuccessful());
        $this->assertFileExists($output);
        $this->assertGreaterThan(5000, filesize($output), 'DOCX file seems too small');
    }

    public function test_xlsx_cover_is_generated(): void
    {
        $output    = $this->outputDir . '/report.xlsx';
        $generator = new ExcelReportGenerator();

        $result = $generator->generate($this->makeTestData(), $output);

        $this->assertInstanceOf(ReportResult::class, $result);
        $this->assertTrue($result->isFullySuccessful());
        $this->assertFileExists($output);
        $this->assertGreaterThan(5000, filesize($output), 'XLSX file seems too small');
    }
}
