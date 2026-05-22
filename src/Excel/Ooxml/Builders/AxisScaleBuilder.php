<?php

namespace Procorad\ProcostatReporting\Excel\Ooxml\Builders;

use Procorad\ProcostatReporting\Excel\Ooxml\Definitions\AxisScaleDefinition;
use Procorad\ProcostatReporting\Excel\Ooxml\XPathHelper;

/**
 * Builds / patches the <c:scaling> DOM subtree from an AxisScaleDefinition.
 *
 * Replaces the existing <c:scaling> element rather than appending,
 * because PhpSpreadsheet always writes one with just <c:orientation>.
 *
 *   <c:scaling>
 *     <c:orientation val="minMax"/>
 *     <c:max val="0.035"/>
 *     <c:min val="0"/>
 *   </c:scaling>
 */
final class AxisScaleBuilder
{
    public function build(\DOMDocument $dom, AxisScaleDefinition $def): \DOMElement
    {
        $scaling = XPathHelper::createElement($dom, 'scaling');

        $scaling->appendChild(
            XPathHelper::attr(XPathHelper::createElement($dom, 'orientation'), 'val', $def->orientation)
        );

        // OOXML requires max before min
        if ($def->max !== null) {
            $scaling->appendChild(
                XPathHelper::attr(XPathHelper::createElement($dom, 'max'), 'val', $this->format($def->max))
            );
        }

        if ($def->min !== null) {
            $scaling->appendChild(
                XPathHelper::attr(XPathHelper::createElement($dom, 'min'), 'val', $this->format($def->min))
            );
        }

        return $scaling;
    }

    /**
     * Format float for OOXML: no unnecessary trailing zeros, full precision.
     * "0.035" not "0.0350000000000000014" and not "3.5E-2".
     */
    private function format(float $value): string
    {
        // Use enough precision then strip trailing zeros
        $str = rtrim(sprintf('%.10f', $value), '0');
        return rtrim($str, '.') ?: '0';
    }
}
