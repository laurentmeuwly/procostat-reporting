<?php

namespace Procorad\ProcostatReporting\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Procorad\ProcostatReporting\Assembler\ReportAssembler;
use Procorad\ProcostatReporting\ValueObject\PlotType;
use Procorad\ProcostatReporting\ValueObject\ReportingContext;

use Procorad\ProcostatReporting\Assembler\LabResult;
use Procorad\ProcostatReporting\Model\ReportingResult;
use Procorad\ProcostatReporting\Infrastructure\JsonDebugRenderer;

class MvpPipelineTest extends TestCase
{
    public function test_mvp_pipeline_produces_chart()
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

        $context = new ReportingContext(
            campaignId: '2026',
            comparisonCode: '26XGA',
            sampleLabel: 'Sample A',
            isotope: '40K',
            unit: 'Bq/L',
            referenceDate: null
        );

        $assembler = new ReportAssembler();
        $plot = $assembler->buildZScorePlot($result, $context);

        $renderer = new JsonDebugRenderer();
        $chart = $renderer->render($plot);

        $this->assertEquals('application/json', $chart->mimeType);
        $this->assertStringContainsString('z\'-score', $chart->binaryContent);
    }
}
