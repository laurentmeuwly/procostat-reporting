<?php

namespace Procorad\ProcostatReporting\Contracts;

use Procorad\ProcostatReporting\Data\IntercomparisonReportData;
use Procorad\ProcostatReporting\Data\ReportResult;

/**
 * Contract implemented by every document generator (xlsx, docx, pptx, pdf).
 *
 * Deliberately named DocumentGenerator (not ReportGenerator) to avoid
 * confusion with the existing PDF-only GenerateIntercomparisonReport use-case.
 */
interface DocumentGenerator
{
    /**
     * Generate a document and write it to $outputPath.
     *
     * @throws \Procorad\ProcostatReporting\Exceptions\ReportGenerationException
     */
    public function generate(IntercomparisonReportData $data, string $outputPath): ReportResult;

    /**
     * Format identifier this generator handles: 'xlsx', 'docx', 'pptx', 'pdf'.
     */
    public function format(): string;
}
