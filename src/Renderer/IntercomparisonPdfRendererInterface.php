<?php

namespace Procorad\ProcostatReporting\Renderer;

use Procorad\ProcostatReporting\Model\IntercomparisonReportModel;

interface IntercomparisonPdfRendererInterface
{
    public function render(IntercomparisonReportModel $model): string;
}
