<?php

namespace Procorad\ProcostatReporting\PowerPoint;

use Procorad\ProcostatReporting\Contracts\DocumentGenerator;
use Procorad\ProcostatReporting\Data\IntercomparisonReportData;
use Procorad\ProcostatReporting\Data\ReportResult;
use Procorad\ProcostatReporting\Node\NodeRenderer;
use Procorad\ProcostatReporting\Support\PackagePaths;

final class PowerPointDocumentGenerator implements DocumentGenerator
{
    public function __construct(
        private readonly NodeRenderer $nodeRenderer,
    ) {}

    public function format(): string
    {
        return 'pptx';
    }

    public function generate(IntercomparisonReportData $data, string $outputPath): ReportResult
    {
        $start = hrtime(true);

        $this->nodeRenderer->render(
            script:     PackagePaths::nodeRenderer('render-pptx.js'),
            payload:    $this->buildPayload($data),
            outputPath: $outputPath,
            format:     $this->format(),
        );

        return new ReportResult(
            files: [$this->format() => $outputPath],
            errors: [],
            durationMs: (hrtime(true) - $start) / 1_000_000,
        );
    }

    /** @return array<string,mixed> */
    private function buildPayload(IntercomparisonReportData $data): array
    {
        return array_merge($data->toArray(), [
            'logoPath' => PackagePaths::asset('logo.png'),
            'locale'   => $data->metadata['locale'] ?? 'fr',
        ]);
    }
}
