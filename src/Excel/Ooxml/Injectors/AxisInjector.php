<?php

namespace Procorad\ProcostatReporting\Excel\Ooxml\Injectors;

use Procorad\ProcostatReporting\Excel\Ooxml\Builders\AxisScaleBuilder;
use Procorad\ProcostatReporting\Excel\Ooxml\Definitions\AxisScaleDefinition;
use Procorad\ProcostatReporting\Excel\Ooxml\XPathHelper;

/**
 * Patches <c:scaling> on a specific axis element.
 *
 * OOXML axis types:
 *   c:valAx  — value axis (Y in a scatter chart)
 *   c:catAx  — category axis (X in a scatter chart when using text labels)
 *
 * XPath query targets the Nth axis of the given type.
 */
final class AxisInjector
{
    private readonly \DOMElement $axEl;

    /**
     * @param string $axisType  'valAx' | 'catAx'
     * @param int    $index     0-based index when multiple axes of the same type exist
     */
    public function __construct(
        private readonly \DOMDocument $dom,
        private readonly \DOMXPath    $xpath,
        string                        $axisType = 'valAx',
        int                           $index    = 0,
    ) {
        $nodes = $xpath->query("//c:{$axisType}");

        if ($nodes === false || $nodes->length === 0) {
            throw new \InvalidArgumentException("Axis type '{$axisType}' not found in chart XML.");
        }

        if ($index >= $nodes->length) {
            throw new \InvalidArgumentException(
                "Axis index {$index} out of range (found {$nodes->length} '{$axisType}' elements)."
            );
        }

        $this->axEl = $nodes->item($index);
    }

    // ── Public API ────────────────────────────────────────────────────────────

    public function setScale(AxisScaleDefinition $def): self
    {
        // Remove existing <c:scaling>
        XPathHelper::removeChildren($this->axEl, 'scaling');

        $node = (new AxisScaleBuilder())->build($this->dom, $def);

        // <c:scaling> is the first child of <c:*Ax> in standard OOXML
        if ($this->axEl->firstChild) {
            $this->axEl->insertBefore($node, $this->axEl->firstChild);
        } else {
            $this->axEl->appendChild($node);
        }

        return $this;
    }
}
