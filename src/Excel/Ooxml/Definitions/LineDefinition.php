<?php

namespace Procorad\ProcostatReporting\Excel\Ooxml\Definitions;

/**
 * Declarative definition of a series line (spPr > ln).
 * Maps to <c:spPr><a:ln> in OOXML.
 */
final readonly class LineDefinition
{
    /**
     * @param bool        $noFill  When true: no line drawn (discrete scatter points)
     * @param string|null $color   Hex RGB color, e.g. 'FF0000'. Null = theme color.
     * @param int         $width   Line width in EMUs (English Metric Units). 19050 ≈ 1.5pt.
     * @param string|null $dash    OOXML preset dash: null=solid, 'dash', 'dashDot', 'dot',
     *                             'lgDash', 'lgDashDot', 'sysDash', 'sysDot'. Null = solid.
     */
    public function __construct(
        public bool    $noFill = false,
        public ?string $color  = null,
        public int     $width  = 19050,
        public ?string $dash   = null,
    ) {}

    public static function none(): self
    {
        return new self(noFill: true);
    }

    public static function solid(string $color, int $widthEmu = 19050): self
    {
        return new self(noFill: false, color: $color, width: $widthEmu);
    }

    public static function dashed(string $color, string $preset = 'dash', int $widthEmu = 19050): self
    {
        return new self(noFill: false, color: $color, width: $widthEmu, dash: $preset);
    }
}
