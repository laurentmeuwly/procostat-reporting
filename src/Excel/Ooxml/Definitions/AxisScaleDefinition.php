<?php

namespace Procorad\ProcostatReporting\Excel\Ooxml\Definitions;

/**
 * Declarative definition of axis scaling.
 * Maps to <c:scaling> inside <c:valAx> or <c:catAx>.
 */
final readonly class AxisScaleDefinition
{
    /**
     * @param float|null $min  Explicit minimum. Null = Excel auto.
     * @param float|null $max  Explicit maximum. Null = Excel auto.
     * @param string     $orientation 'minMax' | 'maxMin' (reversed axis)
     */
    public function __construct(
        public ?float $min         = null,
        public ?float $max         = null,
        public string $orientation = 'minMax',
    ) {}

    public static function fromZero(float $max): self
    {
        return new self(min: 0.0, max: $max);
    }
}
