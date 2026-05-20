<?php

namespace Procorad\ProcostatReporting\Data;

/**
 * Immutable result returned by every generator and by ReportManager::generateAll().
 *
 * Gives the orchestrating app file paths, per-format errors, and timing
 * without coupling it to exceptions or to generator internals.
 */
final class ReportResult
{
    /**
     * @param array<string, string> $files   Format → absolute path  e.g. ['xlsx' => '/…/report.xlsx']
     * @param array<string, string> $errors  Format → error message for formats that failed
     * @param float                 $durationMs Total wall-clock time in milliseconds
     */
    public function __construct(
        public readonly array $files,
        public readonly array $errors,
        public readonly float $durationMs,
    ) {}

    public function isFullySuccessful(): bool
    {
        return empty($this->errors);
    }

    public function hasFile(string $format): bool
    {
        return isset($this->files[$format]);
    }

    public function getFile(string $format): ?string
    {
        return $this->files[$format] ?? null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'files'      => $this->files,
            'errors'     => $this->errors,
            'durationMs' => $this->durationMs,
            'success'    => $this->isFullySuccessful(),
        ];
    }
}
