<?php

declare(strict_types=1);

namespace Procorad\ProcostatReporting\Contracts;

use Procorad\ProcostatReporting\DTO\ProcostatReportData;
use Procorad\ProcostatReporting\DTO\ReportResult;

interface ReportGenerator
{
    /**
     * Generate a report document from the given data and write it to $outputPath.
     *
     * @param  ProcostatReportData $data       Fully-populated report DTO
     * @param  string              $outputPath Absolute path where the file must be written
     * @return ReportResult
     *
     * @throws \Procorad\ProcostatReporting\Exceptions\ReportGenerationException
     */
    public function generate(ProcostatReportData $data, string $outputPath): ReportResult;

    /**
     * Return the format identifier this generator handles: 'xlsx', 'docx', 'pptx', 'pdf'.
     */
    public function format(): string;
}
