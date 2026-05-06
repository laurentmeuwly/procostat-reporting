<?php

namespace Procorad\ProcostatReporting\Adapter;

use Procorad\Procostat\DTO\ProcostatResult;
use Procorad\Procostat\Domain\Performance\IndicatorType;
use Procorad\ProcostatReporting\Model\ReportingResult;
use Procorad\ProcostatReporting\Assembler\LabResult;

final class ProcostatResultAdapter
{
    public function fromProcostatResult(ProcostatResult $result): ReportingResult
    {
        $labs = [];

        $useZPrime = $result->primaryIndicator === IndicatorType::Z_PRIME;

        foreach ($result->labEvaluations() as $labCode => $evaluation) {

            $score = $useZPrime
                ? $evaluation->zPrimeScore
                : $evaluation->zScore;

            // On ignore les labos non évaluables (score null)
            if ($score === null) {
                continue;
            }

            $labs[$labCode] = new LabResult(
                zScore: $score,
                zetaScore: $evaluation->zetaScore,
                fitnessStatus: $evaluation->fitnessStatus->value
            );
        }

        return new ReportingResult(
            $labs,
            primaryIndicator: $useZPrime ? 'z_prime' : 'z'
        );
    }
}
