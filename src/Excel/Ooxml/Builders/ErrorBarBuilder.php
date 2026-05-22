<?php

namespace Procorad\ProcostatReporting\Excel\Ooxml\Builders;

use Procorad\ProcostatReporting\Excel\Ooxml\Definitions\ErrorBarDefinition;
use Procorad\ProcostatReporting\Excel\Ooxml\XPathHelper;

/**
 * Builds the <c:errBars> DOM subtree from an ErrorBarDefinition.
 *
 * Output structure (custom symmetric ±):
 *
 *   <c:errBars>
 *     <c:errBarType val="both"/>
 *     <c:errValType val="cust"/>
 *     <c:noEndCap val="0"/>
 *     <c:plus>
 *       <c:numRef><c:f>Sheet!$C$2:$C$10</c:f></c:numRef>
 *     </c:plus>
 *     <c:minus>
 *       <c:numRef><c:f>Sheet!$C$2:$C$10</c:f></c:numRef>
 *     </c:minus>
 *   </c:errBars>
 */
final class ErrorBarBuilder
{
    public function build(\DOMDocument $dom, ErrorBarDefinition $def): \DOMElement
    {
        $errBars = XPathHelper::createElement($dom, 'errBars');

        $errBars->appendChild(
            XPathHelper::attr(XPathHelper::createElement($dom, 'errBarType'), 'val', $def->type)
        );
        $errBars->appendChild(
            XPathHelper::attr(XPathHelper::createElement($dom, 'errValType'), 'val', $def->valType)
        );
        $errBars->appendChild(
            XPathHelper::attr(XPathHelper::createElement($dom, 'noEndCap'), 'val', $def->noEndCap ? '1' : '0')
        );

        $errBars->appendChild($this->buildNumRef($dom, 'plus',  $def->plusRef));
        $errBars->appendChild($this->buildNumRef($dom, 'minus', $def->minusRef));

        return $errBars;
    }

    private function buildNumRef(\DOMDocument $dom, string $direction, string $ref): \DOMElement
    {
        $dir    = XPathHelper::createElement($dom, $direction);
        $numRef = XPathHelper::createElement($dom, 'numRef');
        $f      = XPathHelper::createElement($dom, 'f');
        $f->appendChild($dom->createTextNode($ref));
        $numRef->appendChild($f);
        $dir->appendChild($numRef);

        return $dir;
    }
}
