<?php

namespace Procorad\ProcostatReporting\Assembler;

/**
 * Simulation d'une structure minimale pour à MVP
 * Sera remplacé par le retour de Procostat
 */
final class LabPerformance
{
    public function __construct(
        public readonly string $labCode,
        public readonly float $zScore,
    ) {}
}
