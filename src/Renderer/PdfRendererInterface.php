<?php

namespace Procorad\ProcostatReporting\Renderer;

use Procorad\ProcostatReporting\Model\LaboratoryReportModel;

interface PdfRendererInterface
{
    /**
     * Renders a LaboratoryReportModel to a PDF binary string.
     */
    public function render(LaboratoryReportModel $model): string;
}
