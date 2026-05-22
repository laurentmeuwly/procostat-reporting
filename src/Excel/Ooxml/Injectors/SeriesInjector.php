<?php

namespace Procorad\ProcostatReporting\Excel\Ooxml\Injectors;

use Procorad\ProcostatReporting\Excel\Ooxml\Builders\ErrorBarBuilder;
use Procorad\ProcostatReporting\Excel\Ooxml\Builders\LineBuilder;
use Procorad\ProcostatReporting\Excel\Ooxml\Builders\MarkerBuilder;
use Procorad\ProcostatReporting\Excel\Ooxml\Definitions\ErrorBarDefinition;
use Procorad\ProcostatReporting\Excel\Ooxml\Definitions\LineDefinition;
use Procorad\ProcostatReporting\Excel\Ooxml\Definitions\MarkerDefinition;
use Procorad\ProcostatReporting\Excel\Ooxml\XPathHelper;

/**
 * Injects properties into a specific <c:ser> element identified by its index.
 *
 * OOXML series index = <c:idx val="N"/> — 0-based.
 *
 * Usage:
 *   $injector = new SeriesInjector($dom, $xpath, seriesIndex: 0);
 *   $injector->addErrorBars(ErrorBarDefinition::symmetric($ref));
 *   $injector->setMarker(MarkerDefinition::circle());
 *   $injector->setLine(LineDefinition::none());
 */
final class SeriesInjector
{
    private readonly \DOMElement $serEl;

    public function __construct(
        private readonly \DOMDocument $dom,
        private readonly \DOMXPath    $xpath,
        int                           $seriesIndex,
    ) {
        // XPath: find the <c:ser> whose <c:idx val="N"/>
        $nodes = $xpath->query("//c:ser[c:idx[@val='{$seriesIndex}']]");

        if ($nodes === false || $nodes->length === 0) {
            throw new \InvalidArgumentException(
                "Series with index {$seriesIndex} not found in chart XML."
            );
        }

        $this->serEl = $nodes->item(0);
    }

    // ── Public API ────────────────────────────────────────────────────────────

    public function addErrorBars(ErrorBarDefinition $def): self
    {
        // Remove any existing errBars first (idempotent)
        XPathHelper::removeChildren($this->serEl, 'errBars');

        $node = (new ErrorBarBuilder())->build($this->dom, $def);

        // OOXML: scatter uses <c:yVal>, line chart uses <c:val>
        // Try yVal first (scatter), fall back to val (line/bar)
        $afterTag = $this->hasChild('yVal') ? 'yVal' : 'val';
        XPathHelper::insertAfter($this->serEl, $node, $afterTag);

        return $this;
    }

    public function setMarker(MarkerDefinition $def): self
    {
        XPathHelper::removeChildren($this->serEl, 'marker');

        $node = (new MarkerBuilder())->build($this->dom, $def);

        // scatter: <c:xVal> / line: <c:cat> — marker goes before the X reference element
        $beforeTag = $this->hasChild('xVal') ? 'xVal' : 'cat';
        XPathHelper::insertBefore($this->serEl, $node, $beforeTag);

        return $this;
    }

    public function setLine(LineDefinition $def): self
    {
        XPathHelper::removeChildren($this->serEl, 'spPr');

        $node = (new LineBuilder())->build($this->dom, $def);

        // spPr comes before marker (or before cat/xVal if no marker yet)
        if ($this->hasChild('marker')) {
            XPathHelper::insertBefore($this->serEl, $node, 'marker');
        } else {
            $beforeTag = $this->hasChild('xVal') ? 'xVal' : 'cat';
            XPathHelper::insertBefore($this->serEl, $node, $beforeTag);
        }

        return $this;
    }
    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Check whether the series element has a direct child with the given local name
     * in the chart namespace — used to detect scatter vs line chart structure.
     */
    private function hasChild(string $localName): bool
    {
        foreach ($this->serEl->childNodes as $child) {
            if ($child instanceof \DOMElement
                && $child->localName === $localName
                && $child->namespaceURI === XPathHelper::NS_C) {
                return true;
            }
        }
        return false;
    }
}
