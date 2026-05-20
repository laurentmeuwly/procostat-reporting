<?php

declare(strict_types=1);

namespace Procorad\ProcostatReporting\Tests\Unit\DTO;

use PHPUnit\Framework\TestCase;
use Procorad\ProcostatReporting\DTO\ChartConfig;
use Procorad\ProcostatReporting\DTO\LabResult;
use Procorad\ProcostatReporting\DTO\ProcostatReportData;
use Procorad\ProcostatReporting\DTO\StatisticsData;
use Procorad\ProcostatReporting\DTO\ZscoreLimits;

final class ProcostatReportDataTest extends TestCase
{
    private function makeData(): ProcostatReportData
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
                new LabResult(3, 2460.0, 220.0, 28.0, -2.0, -0.3, -1.0),
            ],
            chartConfigs: [
                new ChartConfig('results', '14C Results (25CB)'),
            ],
            metadata: ['locale' => 'fr'],
        );
    }

    public function test_to_array_contains_all_keys(): void
    {
        $arr = $this->makeData()->toArray();

        $this->assertArrayHasKey('intercomparison', $arr);
        $this->assertArrayHasKey('statistics', $arr);
        $this->assertArrayHasKey('labResults', $arr);
        $this->assertArrayHasKey('chartConfigs', $arr);
        $this->assertArrayHasKey('zscoreLimits', $arr);
    }

    public function test_lab_results_are_serialised(): void
    {
        $arr = $this->makeData()->toArray();

        $this->assertCount(1, $arr['labResults']);
        $this->assertEquals(3, $arr['labResults'][0]['labNumber']);
    }

    public function test_is_json_serialisable(): void
    {
        $json = json_encode($this->makeData()->toArray(), JSON_THROW_ON_ERROR);
        $this->assertJson($json);
    }
}
