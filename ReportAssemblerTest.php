<?php

namespace Procorad\ProcostatReporting\Tests\Application;

use PHPUnit\Framework\TestCase;
use Procorad\ProcostatReporting\Assembler\ReportAssembler;
use Procorad\ProcostatReporting\ValueObject\ReportingContext;
use Procorad\ProcostatReporting\Model\ComparisonReportModel;
use Procorad\Procostat\Domain\AssignedValue\AssignedValueSpecification;
use Procorad\Procostat\Domain\AssignedValue\AssignedValueType;
use Procorad\Procostat\DTO\AnalysisDataset;
use Procorad\Procostat\DTO\ProcostatResult;
use Procorad\Procostat\Domain\Measurements\Measurement;
use Procorad\Procostat\Domain\Measurements\Uncertainty;
use Procorad\Procostat\Domain\Measurements\LaboratoryCode;
use Procorad\Procostat\Domain\Decision\FitnessStatus;
use Procorad\Procostat\Domain\AssignedValue\AssignedValue;
use Procorad\Procostat\Domain\Statistics\RobustStatistics;
use Procorad\Procostat\Domain\Population\PopulationSummary;
use Procorad\Procostat\Domain\Decision\IndicatorType;
use Procorad\Procostat\Domain\Audit\AuditTrail;

final class ReportAssemblerTest extends TestCase
{
    public function test_it_builds_a_minimal_comparison_report(): void
    {
        // ------------------------------------------------------------------
        // Given
        // ------------------------------------------------------------------

        $dataset = new AnalysisDataset(
            measurements: [
                new Measurement('LAB01', 10.0, new Uncertainty(0.5)),
                new Measurement('LAB02', 11.0, new Uncertainty(0.5)),
                new Measurement('LAB03', 12.0, new Uncertainty(0.5)),
                new Measurement('LAB04', 13.0, new Uncertainty(0.5)),
            ],
            assignedValueSpec: new AssignedValueSpecification(
                AssignedValueType::ROBUST_MEAN,
                null,
                null
            ),
            campaign: '2025',
            sampleCode: 'XGA',
            radionuclide: 'Cs-137',
            unit: 'Bq/kg'
        );

        $labEvaluations = [
            'LAB01' => (object) [
                'biasPercent'   => -10.0,
                'zScore'        => -1.5,
                'zetaScore'     => -1.2,
                'fitnessStatus' => FitnessStatus::CONFORME,
            ],
            'LAB02' => (object) [
                'biasPercent'   => -3.0,
                'zScore'        => -0.5,
                'zetaScore'     => -0.4,
                'fitnessStatus' => FitnessStatus::CONFORME,
            ],
            'LAB03' => (object) [
                'biasPercent'   => 5.0,
                'zScore'        => 1.1,
                'zetaScore'     => 1.0,
                'fitnessStatus' => FitnessStatus::CONFORME,
            ],
            'LAB04' => (object) [
                'biasPercent'   => 12.0,
                'zScore'        => 2.6,
                'zetaScore'     => 2.4,
                'fitnessStatus' => FitnessStatus::DISCUTABLE,
            ],
        ];

        $result = new ProcostatResult(
            assignedValue: AssignedValue::undefined(),
            robustStatistics: RobustStatistics::empty(),
            populationSummary: PopulationSummary::empty(),
            primaryIndicator: IndicatorType::Z_SCORE,
            labEvaluations: $labEvaluations,
            auditTrail: AuditTrail::empty(),
            engineVersion: '1.0.0-test'
        );

        $context = new ReportingContext(
            campaignId: '2026',
            comparisonCode: 'IC-URINE',
            sampleLabel: 'Urine',
            isotope: 'H-3',
            unit: 'Bq/L',
            referenceDate: null,
        );

        $assembler = new ReportAssembler();

        // ------------------------------------------------------------------
        // When
        // ------------------------------------------------------------------

        $report = $assembler->assembleComparisonReport(
            dataset: $dataset,
            result: $result,
            context: $context,
        );

        // ------------------------------------------------------------------
        // Then
        // ------------------------------------------------------------------

        $this->assertInstanceOf(ComparisonReportModel::class, $report);

        // ---- Table --------------------------------------------------------

        $table = $report->summaryTable;

        $this->assertCount(7, $table->columns);
        $this->assertCount(2, $table->rows);

        $this->assertSame(
            ['lab', 'value', 'uncertainty', 'bias', 'z', 'zeta', 'status'],
            array_map(fn ($c) => $c->key, $table->columns)
        );

        $this->assertSame('LAB01', $table->rows[0]['lab']);
        $this->assertSame(-5.0, $table->rows[0]['bias']);
        $this->assertSame(FitnessStatus::CONFORME, $table->rows[0]['status']);

        // ---- Series -------------------------------------------------------

        $this->assertArrayHasKey('z_score', $report->plots);
        $this->assertArrayHasKey('zeta_vs_z', $report->plots);

        $zSeries = $report->plots['z_score'];

        $this->assertSame(['LAB01', 'LAB02'], $zSeries->labels);
        $this->assertSame([-1.2, 2.4], $zSeries->values);

        // ---- Scatter ------------------------------------------------------

        $scatter = $report->plots['zeta_vs_z'];

        $this->assertCount(2, $scatter->points);

        $this->assertSame(
            ['x' => -0.8, 'y' => -1.2, 'label' => 'LAB01'],
            $scatter->points[0]
        );
    }
}
