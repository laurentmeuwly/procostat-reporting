<?php

namespace Procorad\ProcostatReporting\Application;

use Procorad\ProcostatReporting\Contract\StorageInterface;
use Procorad\ProcostatReporting\Data\IntercomparisonReportData;
use Procorad\ProcostatReporting\Data\LabResultData;
use Procorad\ProcostatReporting\Data\SampleAnalysisData;
use Procorad\ProcostatReporting\Model\IntercomparisonReportLabRow;
use Procorad\ProcostatReporting\Model\IntercomparisonReportModel;
use Procorad\ProcostatReporting\Model\IntercomparisonReportSamplePage;
use Procorad\ProcostatReporting\Renderer\IntercomparisonPdfRendererInterface;

final class GenerateIntercomparisonReport
{
    public function __construct(
        private readonly IntercomparisonPdfRendererInterface $renderer,
        private readonly StorageInterface                    $storage,
    ) {}

    public function execute(IntercomparisonReportData $data): string
    {
        $pages = array_map(
            fn(SampleAnalysisData $analysis) => $this->buildPage($analysis),
            $data->analyses,
        );

        $model = new IntercomparisonReportModel(
            icCode:  $data->icCode,
            icTitle: $data->icTitle,
            year:    $data->year,
            pages:   $pages,
        );

        $pdf  = $this->renderer->render($model);
        $path = "ic-{$data->icCode}-{$data->year}.pdf";

        $this->storage->save($path, $pdf);

        return $path;
    }

    private function buildPage(SampleAnalysisData $analysis): IntercomparisonReportSamplePage
    {
        $rows = array_map(
            fn(LabResultData $r) => new IntercomparisonReportLabRow(
                labNumber:      $r->labNumber,
                result:         null,
                uncertainty:    null,
                detectionLimit: null,
                biasPercent:    $r->biasPercent,
                enScore:        $r->zPrimeScore,
                zScore:         $r->zScore,
                specialStatus:  $r->evaluationValidity === 'valid' ? '' : $r->evaluationValidity,
            ),
            $analysis->labResults,
        );

        return new IntercomparisonReportSamplePage(
            sampleCode:          $analysis->sampleCode,
            isotope:             $analysis->isotope,
            matrix:              $analysis->matrix,
            unit:                $analysis->unit,
            assignedValue:       $analysis->assignedValue,
            assignedUncertainty: $analysis->assignedUncertainty,
            robustMean:          $analysis->robustMean,
            rows:                $rows,
        );
    }
}
