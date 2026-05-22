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
        return new self(min: 0.0, max: self::niceMax($max));
    }

    /**
     * Round $value up to a visually clean axis ceiling.
     *
     * Examples:
     *   0.0280 → 0.030   (magnitude 0.01, step 3.0)
     *   0.0312 → 0.035
     *   2840   → 3000
     *   18.7   → 20
     *
     * Algorithm: normalise to [1, 10), pick the next "nice" step,
     * scale back to the original magnitude.
     */
    private static function niceMax(float $value): float
    {
        if ($value <= 0.0) {
            return 1.0;
        }

        $magnitude  = 10 ** floor(log10($value));
        $normalized = $value / $magnitude;

        foreach ([1.5, 2.0, 2.5, 3.0, 4.0, 5.0, 6.0, 8.0, 10.0] as $step) {
            if ($normalized <= $step) {
                return $step * $magnitude;
            }
        }

        return 10.0 * $magnitude;
    }
}
