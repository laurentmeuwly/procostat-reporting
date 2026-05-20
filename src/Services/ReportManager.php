<?php

namespace Procorad\ProcostatReporting\Services;

use Procorad\ProcostatReporting\Contracts\DocumentGenerator;
use Procorad\ProcostatReporting\Data\IntercomparisonReportData;
use Procorad\ProcostatReporting\Data\ReportResult;
use Procorad\ProcostatReporting\Exceptions\ReportGenerationException;

/**
 * Orchestrates all registered DocumentGenerators.
 *
 * By default continues on partial failure — one broken generator does not
 * abort the others. Pass $stopOnFirstError = true for fail-fast behaviour.
 */
final class ReportManager
{
    /** @var DocumentGenerator[] */
    private array $generators = [];

    public function __construct(
        private readonly bool $stopOnFirstError = false,
    ) {}

    public function register(DocumentGenerator $generator): self
    {
        $this->generators[$generator->format()] = $generator;

        return $this;
    }

    /**
     * Generate all registered formats into $directory.
     *
     * Filenames: {icCode}_{year}_{sampleCode}_{isotope}.{ext}
     * One file per format per analysis (sample × isotope).
     * For multi-analysis ICs the first analysis drives the file name.
     *
     * @throws ReportGenerationException  only when $stopOnFirstError is true
     */
    public function generateAll(
        IntercomparisonReportData $data,
        string $directory,
    ): ReportResult {
        $globalStart = hrtime(true);

        // For file naming we take the first analysis as representative.
        // Each generator receives the full $data and decides how to render
        // multiple analyses (e.g. one sheet per analysis in Excel).
        $firstAnalysis = $data->analyses[0] ?? null;
        $baseName = implode('_', array_filter([
            $data->icCode,
            (string) $data->year,
            $firstAnalysis?->sampleCode,
            $firstAnalysis?->isotope,
        ]));
        $baseName = preg_replace('/\W+/', '_', strtolower($baseName));

        $files  = [];
        $errors = [];

        foreach ($this->generators as $format => $generator) {
            $outputPath = rtrim($directory, '/') . "/{$baseName}.{$format}";

            try {
                $result = $generator->generate($data, $outputPath);
                $files  = array_merge($files, $result->files);
            } catch (ReportGenerationException $e) {
                if ($this->stopOnFirstError) {
                    throw $e;
                }
                $errors[$format] = $e->getMessage();
            }
        }

        return new ReportResult(
            files: $files,
            errors: $errors,
            durationMs: (hrtime(true) - $globalStart) / 1_000_000,
        );
    }

    /**
     * Generate a single format to an explicit output path.
     *
     * @throws \InvalidArgumentException  if the format is not registered
     * @throws ReportGenerationException
     */
    public function generate(
        string $format,
        IntercomparisonReportData $data,
        string $outputPath,
    ): ReportResult {
        if (! isset($this->generators[$format])) {
            throw new \InvalidArgumentException(
                "No generator for format '{$format}'. Registered: " . implode(', ', array_keys($this->generators))
            );
        }

        return $this->generators[$format]->generate($data, $outputPath);
    }

    /** @return string[] */
    public function registeredFormats(): array
    {
        return array_keys($this->generators);
    }
}
