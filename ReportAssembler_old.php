<?php

namespace Procorad\ProcostatReporting\Assembler;

use Procorad\Procostat\DTO\AnalysisDataset;
use Procorad\Procostat\DTO\ProcostatResult;
use Procorad\ProcostatReporting\Model\ComparisonReportModel;
use Procorad\ProcostatReporting\ValueObject\ScatterSeries;
use Procorad\ProcostatReporting\ValueObject\Series;
use Procorad\ProcostatReporting\ValueObject\Table;
use Procorad\ProcostatReporting\ValueObject\TableColumn;
use Procorad\ProcostatReporting\ValueObject\ThresholdBands;
use Procorad\ProcostatReporting\ValueObject\ReportingContext;

final class ReportAssembler_old
{
    public function assembleComparisonReport(
        AnalysisDataset $dataset,
        ProcostatResult $result,
        ReportingContext $context,
    ): ComparisonReportModel {
        return new ComparisonReportModel(
            summaryTable: $this->buildSummaryTable($dataset, $result, $context),
            plots: [
                'z_score' => $this->buildZScoreSeries($dataset, $result, $context),
                'zeta_vs_z' => $this->buildZetaVsZSeries($dataset, $result, $context),
            ],
        );
    }

    private function buildSummaryTable(
        AnalysisDataset $dataset,
        ProcostatResult $result,
        ReportingContext $context,
    ): Table {
        return new Table(
            columns: [
                new TableColumn('lab', 'Laboratoire', 'string'),
                new TableColumn('value', 'Résultat', 'float', $context->unit),
                new TableColumn('uncertainty', 'Incertitude (k=2)', 'float', $context->unit),
                new TableColumn('bias', 'Biais (%)', 'float'),
                new TableColumn('z', 'z′', 'float'),
                new TableColumn('zeta', 'ζ', 'float'),
                new TableColumn('status', 'Statut', 'status'),
            ],
            rows: $this->buildSummaryRows($dataset, $result),
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function buildSummaryRows(
        AnalysisDataset $dataset,
        ProcostatResult $result,
    ): array {
        $rows = [];

        foreach ($dataset->measurements() as $measurement) {
            $labCode = $measurement->laboratoryCode();

            $evaluation = $result->labEvaluationFor($labCode);

            if ($evaluation === null) {
                continue;
            }

            $rows[] = [
                'lab' => $labCode,
                'value' => $measurement->value(),
                'uncertainty' => $measurement->uncertainty(),
                'bias' => $evaluation->biasPercent,
                'z' => $evaluation->zScore,
                'zeta' => $evaluation->zetaScore,
                'status' => $evaluation->fitnessStatus,
            ];
        }

        return $rows;
    }

    private function buildZScoreSeries(
        AnalysisDataset $dataset,
        ProcostatResult $result,
        ReportingContext $context,
    ): Series {
        $labels = [];
        $values = [];

        foreach ($dataset->measurements() as $measurement) {
            $labCode = $measurement->laboratoryCode();
            $evaluation = $result->labEvaluationFor($labCode);

            if ($evaluation === null) {
                continue;
            }

            $labels[] = $labCode;
            $values[] = $evaluation->zScore;
        }

        return new Series(
            id: 'z_score',
            label: 'z\'-score',
            labels: $labels,
            values: $values,
        );
    }

    private function buildZetaVsZSeries(
        AnalysisDataset $dataset,
        ProcostatResult $result,
        ReportingContext $context,
    ): ScatterSeries {
        $points = [];

        foreach ($dataset->measurements() as $measurement) {
            $labCode = $measurement->laboratoryCode();
            $evaluation = $result->labEvaluationFor($labCode);

            if ($evaluation === null) {
                continue;
            }

            $points[] = [
                'x' => $evaluation->zetaScore,
                'y' => $evaluation->zScore,
                'label' => $labCode,
            ];
        }

        return new ScatterSeries(
            id: 'zeta_vs_z',
            label: 'z\' = f(ζ)',
            points: $points,
        );
    }

    public function zScoreThresholds(): ThresholdBands
    {
        return ThresholdBands::zScore();
    }
}
