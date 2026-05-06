<?php

namespace Procorad\ProcostatReporting\Model;

final class ReportingResult
{
    /**
     * @param array<string, LabResult> $labs
     */
    public function __construct(
        public readonly array $labs,
        public readonly string $primaryIndicator // 'z' ou 'z_prime'
    ) {}
}
