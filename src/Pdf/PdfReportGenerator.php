<?php

declare(strict_types=1);

namespace Procorad\ProcostatReporting\Pdf;

use Procorad\ProcostatReporting\Contracts\ReportGenerator;
use Procorad\ProcostatReporting\DTO\ProcostatReportData;
use Procorad\ProcostatReporting\DTO\ReportResult;
use Procorad\ProcostatReporting\Exceptions\ReportGenerationException;
use Procorad\ProcostatReporting\Word\WordReportGenerator;
use Symfony\Component\Process\Process;

/**
 * Generates a PDF by:
 *   1. Producing a temporary DOCX via WordReportGenerator
 *   2. Converting it to PDF with LibreOffice headless
 *
 * This guarantees the PDF is visually identical to the DOCX and keeps
 * charts editable in the Word document while the PDF is print-ready.
 */
final class PdfReportGenerator implements ReportGenerator
{
    public function __construct(
        private readonly WordReportGenerator $wordGenerator,
        private readonly string $libreofficeBinary = 'libreoffice',
        private readonly int $timeout = 120,
    ) {}

    public function format(): string
    {
        return 'pdf';
    }

    public function generate(ProcostatReportData $data, string $outputPath): ReportResult
    {
        $start   = hrtime(true);
        $tmpDocx = tempnam(sys_get_temp_dir(), 'procostat_') . '.docx';

        try {
            // Step 1 — generate intermediate DOCX
            $this->wordGenerator->generate($data, $tmpDocx);

            // Step 2 — convert to PDF
            $outputDir = dirname($outputPath);
            $process   = new Process(
                command: [
                    $this->libreofficeBinary,
                    '--headless',
                    '--convert-to', 'pdf',
                    '--outdir', $outputDir,
                    $tmpDocx,
                ],
                timeout: $this->timeout,
            );

            $process->run();

            if (! $process->isSuccessful()) {
                throw ReportGenerationException::nodeFailure(
                    $this->format(),
                    $process->getErrorOutput(),
                    $process->getExitCode() ?? -1,
                );
            }

            // LibreOffice names the PDF after the source file — rename it
            $generatedPdf = $outputDir . '/' . pathinfo($tmpDocx, PATHINFO_FILENAME) . '.pdf';
            if (file_exists($generatedPdf)) {
                rename($generatedPdf, $outputPath);
            }

        } catch (ReportGenerationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ReportGenerationException::fromThrowable($this->format(), $e);
        } finally {
            if (file_exists($tmpDocx)) {
                unlink($tmpDocx);
            }
        }

        $ms = (hrtime(true) - $start) / 1_000_000;

        return new ReportResult(
            files: [$this->format() => $outputPath],
            errors: [],
            durationMs: $ms,
        );
    }
}
