<?php

namespace Procorad\ProcostatReporting\Application;

use Procorad\ProcostatReporting\Contract\StorageInterface;
use Procorad\ProcostatReporting\Model\IntercomparisonPageModel;
use Procorad\ProcostatReporting\Model\LaboratoryReportModel;
use Procorad\ProcostatReporting\Renderer\PdfRendererInterface;

final class GenerateLaboratoryReport
{
    public function __construct(
        private readonly PdfRendererInterface $renderer,
        private readonly StorageInterface     $storage,
    ) {}

    /**
     * @param array<string> $icTitles  Ordered list of intercomparison titles for this lab/year.
     * @return string                  Storage path of the generated PDF.
     */
    public function execute(
        int   $labNumber,
        int   $year,
        array $icTitles,
    ): string {
        $pages = array_map(
            fn(string $title) => new IntercomparisonPageModel($title),
            $icTitles,
        );

        $model = new LaboratoryReportModel($labNumber, $year, $pages);

        $pdf = $this->renderer->render($model);

        $path = "lab-{$labNumber}-{$year}.pdf";
        $this->storage->save($path, $pdf);

        return $path;
    }
}
