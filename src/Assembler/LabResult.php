<?php

namespace Procorad\ProcostatReporting\Assembler;

/**
 * Simulation d'une structure minimale pour à MVP
 * Sera remplacé par le retour de Procostat
 */
final class LabResult
{
    public function __construct(
        //public readonly float $value,
        //public readonly float $uncertainty,
        public readonly float $zScore,
        public readonly float $zetaScore,
        //public readonly string $status,
        public readonly string $fitnessStatus
    ) {}
}
