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

        // OOXML order: errBars must come after yVal / val
        XPathHelper::insertAfter($this->serEl, $node, 'yVal');

        return $this;
    }

    public function setMarker(MarkerDefinition $def): self
    {
        XPathHelper::removeChildren($this->serEl, 'marker');

        $node = (new MarkerBuilder())->build($this->dom, $def);

        // marker comes before xVal in OOXML scatter series
        XPathHelper::insertBefore($this->serEl, $node, 'xVal');

        return $this;
    }

    public function setLine(LineDefinition $def): self
    {
        XPathHelper::removeChildren($this->serEl, 'spPr');

        $node = (new LineBuilder())->build($this->dom, $def);

        // spPr comes right after tx (series label) in standard OOXML order
        XPathHelper::insertBefore($this->serEl, $node, 'marker');

        return $this;
    }
}
