<?php

namespace Procorad\ProcostatReporting\Excel\Ooxml\Builders;

use Procorad\ProcostatReporting\Excel\Ooxml\Definitions\LineDefinition;
use Procorad\ProcostatReporting\Excel\Ooxml\XPathHelper;

/**
 * Builds the <c:spPr> DOM subtree controlling a series line from a LineDefinition.
 *
 * No line (discrete points):
 *   <c:spPr><a:ln><a:noFill/></a:ln></c:spPr>
 *
 * Solid colored line:
 *   <c:spPr>
 *     <a:ln w="19050">
 *       <a:solidFill><a:srgbClr val="FF0000"/></a:solidFill>
 *     </a:ln>
 *   </c:spPr>
 */
final class LineBuilder
{
    public function build(\DOMDocument $dom, LineDefinition $def): \DOMElement
    {
        $spPr = XPathHelper::createElement($dom, 'spPr');
        $ln   = XPathHelper::createDrawingElement($dom, 'ln');

        if (! $def->noFill) {
            $ln->setAttribute('w', (string) $def->width);
        }

        if ($def->noFill) {
            $ln->appendChild(XPathHelper::createDrawingElement($dom, 'noFill'));
        } elseif ($def->color !== null) {
            $solidFill = XPathHelper::createDrawingElement($dom, 'solidFill');
            $srgbClr   = XPathHelper::createDrawingElement($dom, 'srgbClr');
            $srgbClr->setAttribute('val', $def->color);
            $solidFill->appendChild($srgbClr);
            $ln->appendChild($solidFill);
        }

        $spPr->appendChild($ln);

        return $spPr;
    }
}
