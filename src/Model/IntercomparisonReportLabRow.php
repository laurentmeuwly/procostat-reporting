<?php

namespace Procorad\ProcostatReporting\Model;

final class IntercomparisonReportLabRow
{
    public function __construct(
        public readonly int     $labNumber,
        public readonly ?float  $result,
        public readonly ?float  $uncertainty,
        public readonly ?float  $detectionLimit,
        public readonly ?float  $biasPercent,
        public readonly ?float  $enScore,
        public readonly ?float  $zScore,
        public readonly string  $specialStatus = '',
    ) {}

    public function zScoreStatus(): string
    {
        if ($this->zScore === null) return 'none';
        $abs = abs($this->zScore);
        if ($abs <= 2.0) return 'ok';
        if ($abs <= 3.0) return 'questionable';
        return 'discrepant';
    }

    public function enScoreStatus(): string
    {
        if ($this->enScore === null) return 'none';
        return abs($this->enScore) <= 1.0 ? 'ok' : 'discrepant';
    }
}
