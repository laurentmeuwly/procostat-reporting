<?php

namespace Procorad\ProcostatReporting\Tests\Assembler;

use PHPUnit\Framework\TestCase;
use Procorad\ProcostatReporting\Assembler\ReportAssembler;
use Procorad\ProcostatReporting\ValueObject\PlotType;
use Procorad\ProcostatReporting\ValueObject\ReportingContext;

use Procorad\ProcostatReporting\Assembler\LabResult;
use Procorad\ProcostatReporting\Model\ReportingResult;

final class ReportAssemblerTest extends TestCase
{
    public function test_build_zscore_plot()
    {
        $result = new ReportingResult(
            labs: [
                'Lab01' => new LabResult(
                    zScore: 0.5,
                    zetaScore: 0.0,
                    fitnessStatus: 'conforme'
                ),
                'Lab02' => new LabResult(
                    zScore: -1.2,
                    zetaScore: 0.0,
                    fitnessStatus: 'conforme'
                ),
                'Lab03' => new LabResult(
                    zScore: 2.4,
                    zetaScore: 0.0,
                    fitnessStatus: 'conforme'
                ),
            ],
            primaryIndicator: 'z' // ou 'z_prime'
        );

        $assembler = new ReportAssembler();

        $context = new ReportingContext(
            campaignId: '2026',
            comparisonCode: '26XGA',
            sampleLabel: 'Sample A',
            isotope: '40K',
            unit: 'Bq/L',
            referenceDate: null,
        );

        $plot = $assembler->buildZScorePlot(
            $result,
            $context
        );

        $this->assertEquals(PlotType::BAR, $plot->type);
        $this->assertCount(1, $plot->series);

        $this->assertEquals(
            ['Lab01','Lab02','Lab03'],
            $plot->series[0]->labels
        );

        $this->assertEquals(
            [0.5,-1.2,2.4],
            $plot->series[0]->values
        );

        $this->assertCount(2, $plot->thresholds);
        $this->assertEquals(-2.0, $plot->thresholds[0]->min);
        $this->assertEquals(2.0, $plot->thresholds[0]->max);

        $this->assertStringContainsString('26XGA', $plot->title);
        $this->assertStringContainsString('40K', $plot->title);
    }
}
