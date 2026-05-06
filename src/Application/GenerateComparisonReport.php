<?php

namespace Procorad\ProcostatReporting\Application;

use Procorad\ProcostatReporting\Assembler\ReportAssembler;
use Procorad\ProcostatReporting\Contract\ChartRenderer;
use Procorad\ProcostatReporting\Model\ComparisonReportModel;
use Procorad\ProcostatReporting\Support\ReportingContext;

final class GenerateComparisonReport
{
    public function __construct(
        private ReportAssembler $assembler,
        private ChartRenderer $chartRenderer,
    ) {
    }

    public function execute(
        AnalysisDataset $dataset,
        ProcostatResult $result,
        ReportingContext $context,
    ): ComparisonReportModel {
        return $this->assembler
            ->assembleComparisonReport($dataset, $result, $context);
    }
}
