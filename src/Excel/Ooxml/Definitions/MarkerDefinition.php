<?php

namespace Procorad\ProcostatReporting\Excel\Ooxml\Definitions;

/**
 * Declarative definition of a chart series marker.
 * Maps to <c:marker> in OOXML.
 */
final readonly class MarkerDefinition
{
    /**
     * @param string      $symbol  'circle'|'square'|'diamond'|'triangle'|'star'|'dot'|'dash'|'x'|'auto'|'none'
     * @param int         $size    Marker size in points (1–72)
     * @param string|null $fillColor  Hex RGB fill color, e.g. '4472C4'. Null = use series color.
     * @param bool        $noLine  Whether to suppress the marker border line
     */
    public function __construct(
        public string  $symbol    = 'circle',
        public int     $size      = 7,
        public ?string $fillColor = '4472C4',
        public bool    $noLine    = true,
    ) {}

    public static function none(): self
    {
        return new self(symbol: 'none', size: 1, fillColor: null, noLine: true);
    }

    public static function circle(string $color = '4472C4', int $size = 7): self
    {
        return new self(symbol: 'circle', size: $size, fillColor: $color);
    }
}
