<?php

declare(strict_types=1);

namespace Procorad\ProcostatReporting\Tests\Unit\Services;

use Mockery;
use PHPUnit\Framework\TestCase;
use Procorad\ProcostatReporting\Contracts\ReportGenerator;
use Procorad\ProcostatReporting\DTO\ProcostatReportData;
use Procorad\ProcostatReporting\DTO\ReportResult;
use Procorad\ProcostatReporting\DTO\StatisticsData;
use Procorad\ProcostatReporting\DTO\ZscoreLimits;
use Procorad\ProcostatReporting\Exceptions\ReportGenerationException;
use Procorad\ProcostatReporting\Services\ReportManager;

final class ReportManagerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    private function makeData(): ProcostatReportData
    {
        return new ProcostatReportData(
            intercomparison: 'CARBON 14',
            sample:          '25CB',
            isotope:         '14C',
            year:            2025,
            unit:            'Bq/l',
            statistics:      new StatisticsData(28, 2520, 70, 2470, 61.2, 2480, 2220, 3040),
            zscoreLimits:    new ZscoreLimits(),
            labResults:      [],
            chartConfigs:    [],
        );
    }

    private function mockGenerator(string $format, bool $succeeds = true): ReportGenerator
    {
        $mock = Mockery::mock(ReportGenerator::class);
        $mock->shouldReceive('format')->andReturn($format);

        if ($succeeds) {
            $mock->shouldReceive('generate')
                ->andReturn(new ReportResult(
                    files: [$format => "/tmp/report.{$format}"],
                    errors: [],
                    durationMs: 50.0,
                ));
        } else {
            $mock->shouldReceive('generate')
                ->andThrow(new ReportGenerationException($format, 'Simulated failure'));
        }

        return $mock;
    }

    public function test_generates_all_registered_formats(): void
    {
        $manager = new ReportManager();
        $manager->register($this->mockGenerator('xlsx'));
        $manager->register($this->mockGenerator('pptx'));

        $result = $manager->generateAll($this->makeData(), '/tmp');

        $this->assertTrue($result->isFullySuccessful());
        $this->assertTrue($result->hasFile('xlsx'));
        $this->assertTrue($result->hasFile('pptx'));
    }

    public function test_continues_on_partial_failure_by_default(): void
    {
        $manager = new ReportManager();
        $manager->register($this->mockGenerator('xlsx', succeeds: true));
        $manager->register($this->mockGenerator('pptx', succeeds: false));

        $result = $manager->generateAll($this->makeData(), '/tmp');

        $this->assertFalse($result->isFullySuccessful());
        $this->assertTrue($result->hasFile('xlsx'));
        $this->assertArrayHasKey('pptx', $result->errors);
    }

    public function test_stop_on_first_error_throws(): void
    {
        $this->expectException(ReportGenerationException::class);

        $manager = new ReportManager(stopOnFirstError: true);
        $manager->register($this->mockGenerator('xlsx', succeeds: false));

        $manager->generateAll($this->makeData(), '/tmp');
    }

    public function test_registered_formats(): void
    {
        $manager = new ReportManager();
        $manager->register($this->mockGenerator('xlsx'));
        $manager->register($this->mockGenerator('docx'));

        $this->assertSame(['xlsx', 'docx'], $manager->registeredFormats());
    }

    public function test_generate_single_unknown_format_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $manager = new ReportManager();
        $manager->generate('pdf', $this->makeData(), '/tmp/out.pdf');
    }
}
