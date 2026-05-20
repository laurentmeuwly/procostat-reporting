<?php

namespace Procorad\ProcostatReporting\Renderer;

use Illuminate\View\Factory as ViewFactory;
use Knp\Snappy\Pdf as SnappyPdf;
use Procorad\ProcostatReporting\Model\IntercomparisonReportModel;

final class SnappyIntercomparisonReportRenderer implements IntercomparisonPdfRendererInterface
{
    public function __construct(
        private readonly SnappyPdf   $snappy,   // injecté par le provider via 'snappy.pdf'
        private readonly ViewFactory $view,
    ) {}

    public function render(IntercomparisonReportModel $model): string
    {
        $html = $this->view
            ->make('procostat-reporting::intercomparison-report.layout', ['model' => $model])
            ->render();

        return $this->snappy->getOutputFromHtml($html, [
            'page-size'                => 'A4',
            //'orientation'              => 'Landscape',
            'margin-top'               => '15mm',
            'margin-bottom'            => '15mm',
            'margin-left'              => '12mm',
            'margin-right'             => '12mm',
            'encoding'                 => 'UTF-8',
            'enable-local-file-access' => true,
        ]);
    }
}
