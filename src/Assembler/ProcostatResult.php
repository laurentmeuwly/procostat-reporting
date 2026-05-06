<?php

namespace Procorad\ProcostatReporting\Assembler;

/**
 * Simulation d'une structure minimale pour à MVP
 * Sera remplacé par le retour de Procostat
 */
final class ProcostatResult
{
    /**
     * @var array<string, LabResult>
     */
    public array $labs;

    public function __construct(array $labs)
    {
        $this->labs = $labs;
    }
}
