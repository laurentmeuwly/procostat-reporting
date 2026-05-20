<?php

namespace Procorad\ProcostatReporting\Renderer;

use Illuminate\View\Factory as ViewFactory;
use Knp\Snappy\Pdf as SnappyPdf;
use Procorad\ProcostatReporting\Model\LaboratoryReportModel;

final class SnappyLaboratoryReportRenderer implements PdfRendererInterface
{
    public function __construct(
        private readonly SnappyPdf    $snappy,
        private readonly ViewFactory  $view,
    ) {}

    public function render(LaboratoryReportModel $model): string
    {
        $html = $this->view
            ->make('procostat-reporting::lab-report.layout', ['model' => $model])
            ->render();

        return $this->snappy->getOutputFromHtml($html, [
            'page-size'                => 'A4',
            'margin-top'               => '15mm',
            'margin-bottom'            => '15mm',
            'margin-left'              => '12mm',
            'margin-right'             => '12mm',
            'encoding'                 => 'UTF-8',
            'enable-local-file-access' => true,
        ]);
    }
}
