<?php

namespace Procorad\ProcostatReporting\Exceptions;

use RuntimeException;
use Throwable;

final class ReportGenerationException extends RuntimeException
{
    public function __construct(
        private readonly string $format,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct("[procostat-reporting:{$format}] {$message}", previous: $previous);
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    public static function fromThrowable(string $format, Throwable $e): self
    {
        return new self($format, $e->getMessage(), $e);
    }

    public static function nodeFailure(string $format, string $stderr, int $exitCode): self
    {
        return new self($format, "Node renderer exited {$exitCode}. STDERR: {$stderr}");
    }
}
