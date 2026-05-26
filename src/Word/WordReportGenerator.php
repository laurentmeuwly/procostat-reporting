<?php

declare(strict_types=1);

namespace Procorad\ProcostatReporting\Word;

use Procorad\ProcostatReporting\Contracts\ReportGenerator;
use Procorad\ProcostatReporting\DTO\ProcostatReportData;
use Procorad\ProcostatReporting\DTO\ReportResult;
use Procorad\ProcostatReporting\Exceptions\ReportGenerationException;
use Procorad\ProcostatReporting\Node\NodeRenderer;
use Procorad\ProcostatReporting\Support\PackagePaths;

final class WordReportGenerator implements ReportGenerator
{
    public function __construct(
        private readonly NodeRenderer $nodeRenderer,
    ) {}

    public function format(): string
    {
        return 'docx';
    }

    public function generate(ProcostatReportData $data, string $outputPath): ReportResult
    {
        $start = hrtime(true);

        $this->nodeRenderer->render(
            script: PackagePaths::nodeRenderer('render-docx.js'),
            payload: $this->buildPayload($data),
            outputPath: $outputPath,
            format: $this->format(),
        );

        $ms = (hrtime(true) - $start) / 1_000_000;

        return new ReportResult(
            files: [$this->format() => $outputPath],
            errors: [],
            durationMs: $ms,
        );
    }

    /** @return array<string, mixed> */
    private function buildPayload(ProcostatReportData $data): array
    {
        $payload = array_merge($data->toArray(), [
            'logoPath'    => PackagePaths::asset('logo.png'),
            'locale'      => $data->metadata['locale'] ?? 'fr',
            'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);

        \Log::debug('docx_payload_check', [
            'keys'               => array_keys($payload),
            'unexpectedIsotopes' => count($payload['unexpectedIsotopes'] ?? []),
            'analyses'           => count($payload['analyses'] ?? []),
        ]);

        return $payload;
    }
}
