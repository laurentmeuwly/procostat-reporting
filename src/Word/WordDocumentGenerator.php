<?php

namespace Procorad\ProcostatReporting\Word;

use Procorad\ProcostatReporting\Contracts\DocumentGenerator;
use Procorad\ProcostatReporting\Data\IntercomparisonReportData;
use Procorad\ProcostatReporting\Data\ReportResult;
use Procorad\ProcostatReporting\Excel\Ooxml\Definitions\GraphDefinitionFactory;
use Procorad\ProcostatReporting\Node\NodeRenderer;
use Procorad\ProcostatReporting\Support\PackagePaths;
use Procorad\ProcostatReporting\Word\Ooxml\DocxChartInjector;

final class WordDocumentGenerator implements DocumentGenerator
{
    public function __construct(
        private readonly NodeRenderer         $nodeRenderer,
        private readonly DocxChartInjector    $chartInjector    = new DocxChartInjector(),
        private readonly GraphDefinitionFactory $graphFactory    = new GraphDefinitionFactory(),
    ) {}

    public function format(): string { return 'docx'; }

    public function generate(IntercomparisonReportData $data, string $outputPath): ReportResult
    {
        $start = hrtime(true);

        // Step 1 — Node.js renders the cover page
        $this->nodeRenderer->render(
            script:     PackagePaths::nodeRenderer('render-docx.js'),
            payload:    $this->buildPayload($data),
            outputPath: $outputPath,
            format:     $this->format(),
        );

        // Step 2 — PHP injects one chart page per analysis
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
            'logoPath'          => PackagePaths::asset('logo.png'),
            'propertyFileTitle' => $data->metadata['propertyFileTitle'] ?? 'Property File Title',
            'locale'            => $data->metadata['locale'] ?? 'fr',
            'generatedAt'       => now()->toIso8601String(),
        ]);
    }

    /**
     * Derive the xlsx path from the docx path — they share the same directory
     * and naming convention: 2026_25CB.docx → 2026_25CB_25CB_14C.xlsx
     */
    private function resolveXlsxPath(
        string $docxPath,
        IntercomparisonReportData $data,
        string $sampleCode,
        string $isotope,
    ): string {
        $dir = dirname($docxPath);
        return $dir . '/' . sprintf('%d_%s_%s_%s.xlsx', $data->year, $data->icCode, $sampleCode, $isotope);
    }
}
