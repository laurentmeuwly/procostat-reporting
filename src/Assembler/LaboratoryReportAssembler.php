<?php

namespace Procorad\ProcostatReporting\Assembler;

final class LaboratoryReportAssembler
{
    public function __construct(
        private ChartRendererInterface $chartRenderer,
    ) {}

    public function build(
        int               $labNumber,
        int               $year,
        string            $eventLocation,
        array             $intercomparisonResults, // résultats procostat indexés par IC
    ): LaboratoryReportModel {
        $pages = [];
        foreach ($intercomparisonResults as $icCode => $icData) {
            $rows  = $this->buildRows($labNumber, $icData);
            $chart = $this->buildBiasChart($rows, $icData->context);
            $pages[] = new IntercomparisonPageModel(...);
        }
        return new LaboratoryReportModel($labNumber, $year, $eventLocation, $pages);
    }

    private function buildBiasChart(array $rows, ReportingContext $context): RenderedChart
    {
        // Réutilise le pipeline PlotSpec → ChartJsNodeRenderer existant
        // mais avec le BIAS % au lieu du Z-score
        $plot = new PlotSpec(
            type: PlotType::BAR,
            title: 'BIAS %',
            yLabel: 'BIAS %',
            series: [new Series(
                labels: array_map(fn($r) => "{$r->sampleCode} - {$r->isotope}", $rows),
                values: array_map(fn($r) => $r->biasPercent, $rows),
            )],
            thresholds: [] // pas de seuils fixes sur le bias
        );
        return $this->chartRenderer->render($plot);
    }
}
