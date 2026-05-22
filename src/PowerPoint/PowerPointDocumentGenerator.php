<?php

namespace Procorad\ProcostatReporting\PowerPoint;

use Procorad\ProcostatReporting\Contracts\DocumentGenerator;
use Procorad\ProcostatReporting\Data\IntercomparisonReportData;
use Procorad\ProcostatReporting\Data\ReportResult;
use Procorad\ProcostatReporting\Excel\Ooxml\Definitions\GraphDefinitionFactory;
use Procorad\ProcostatReporting\Node\NodeRenderer;
use Procorad\ProcostatReporting\PowerPoint\Ooxml\PptxChartInjector;
use Procorad\ProcostatReporting\Support\PackagePaths;

final class PowerPointDocumentGenerator implements DocumentGenerator
{
    public function __construct(
        private readonly NodeRenderer           $nodeRenderer,
        private readonly PptxChartInjector      $chartInjector = new PptxChartInjector(),
        private readonly GraphDefinitionFactory $graphFactory  = new GraphDefinitionFactory(),
    ) {}

    public function format(): string { return 'pptx'; }

    public function generate(IntercomparisonReportData $data, string $outputPath): ReportResult
    {
        $start = hrtime(true);

        // Step 1 — Node.js renders the cover slide
        $this->nodeRenderer->render(
            script:     PackagePaths::nodeRenderer('render-pptx.js'),
            payload:    $this->buildPayload($data),
            outputPath: $outputPath,
            format:     $this->format(),
        );

        // Step 2 — PHP injects one chart slide per analysis
        foreach ($data->analyses as $analysis) {
            $graphs   = $this->graphFactory->fromAnalysis($analysis);
            $xlsxPath = $this->resolveXlsxPath($outputPath, $data, $analysis->sampleCode, $analysis->isotope);

            $this->chartInjector->inject($graphs, $outputPath, $xlsxPath);
        }

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

    private function resolveXlsxPath(
        string $pptxPath,
        IntercomparisonReportData $data,
        string $sampleCode,
        string $isotope,
    ): string {
        $dir = dirname($pptxPath);
        return $dir . '/' . sprintf('%d_%s_%s_%s.xlsx', $data->year, $data->icCode, $sampleCode, $isotope);
    }
}
