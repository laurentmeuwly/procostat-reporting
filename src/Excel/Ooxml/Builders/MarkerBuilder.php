<?php

namespace Procorad\ProcostatReporting\Excel\Ooxml\Builders;

use Procorad\ProcostatReporting\Excel\Ooxml\Definitions\MarkerDefinition;
use Procorad\ProcostatReporting\Excel\Ooxml\XPathHelper;

/**
 * Builds the <c:marker> DOM subtree from a MarkerDefinition.
 *
 * Output (filled circle, no border line):
 *   <c:marker>
 *     <c:symbol val="circle"/>
 *     <c:size val="7"/>
 *     <c:spPr>
 *       <a:solidFill><a:srgbClr val="4472C4"/></a:solidFill>
 *       <a:ln><a:noFill/></a:ln>
 *     </c:spPr>
 *   </c:marker>
 */
final class MarkerBuilder
{
    public function build(\DOMDocument $dom, MarkerDefinition $def): \DOMElement
    {
        $marker = XPathHelper::createElement($dom, 'marker');

        $marker->appendChild(
            XPathHelper::attr(XPathHelper::createElement($dom, 'symbol'), 'val', $def->symbol)
        );

        if ($def->symbol !== 'none') {
            $marker->appendChild(
                XPathHelper::attr(XPathHelper::createElement($dom, 'size'), 'val', (string) $def->size)
            );

            if ($def->fillColor !== null || $def->noLine) {
                $spPr = XPathHelper::createElement($dom, 'spPr');

                if ($def->fillColor !== null) {
                    $solidFill = XPathHelper::createDrawingElement($dom, 'solidFill');
                    $srgbClr   = XPathHelper::createDrawingElement($dom, 'srgbClr');
                    $srgbClr->setAttribute('val', $def->fillColor);
                    $solidFill->appendChild($srgbClr);
                    $spPr->appendChild($solidFill);
                }

                if ($def->noLine) {
                    $ln = XPathHelper::createDrawingElement($dom, 'ln');
                    $ln->appendChild(XPathHelper::createDrawingElement($dom, 'noFill'));
                    $spPr->appendChild($ln);
                }

                $marker->appendChild($spPr);
            }
        }

        return $marker;
    }
}
