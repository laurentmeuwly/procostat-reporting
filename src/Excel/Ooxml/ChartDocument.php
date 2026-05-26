<?php

namespace Procorad\ProcostatReporting\Excel\Ooxml;

use Procorad\ProcostatReporting\Excel\Ooxml\Definitions\AxisScaleDefinition;
use Procorad\ProcostatReporting\Excel\Ooxml\Definitions\ErrorBarDefinition;
use Procorad\ProcostatReporting\Excel\Ooxml\Definitions\LineDefinition;
use Procorad\ProcostatReporting\Excel\Ooxml\Definitions\MarkerDefinition;
use Procorad\ProcostatReporting\Excel\Ooxml\Injectors\AxisInjector;
use Procorad\ProcostatReporting\Excel\Ooxml\Injectors\SeriesInjector;

/**
 * Fluent entry point for post-generation OOXML chart patching.
 *
 * Opens the xlsx as a ZipArchive, provides a chart() → series() / yAxis()
 * fluent API backed by DOMDocument + XPath, and writes everything back
 * on save().
 *
 * Usage:
 *
 *   ChartDocument::open($xlsxPath)
 *       ->chart(0)
 *       ->series(0)
 *           ->addErrorBars(ErrorBarDefinition::symmetric($ref))
 *           ->setMarker(MarkerDefinition::circle())
 *           ->setLine(LineDefinition::none())
 *       ->series(1)
 *           ->setLine(LineDefinition::solid('FF0000'))
 *           ->setMarker(MarkerDefinition::none())
 *       ->yAxis()
 *           ->setScale(AxisScaleDefinition::fromZero($yMax))
 *       ->save();
 */
final class ChartDocument
{
    /** @var array<string, string>  chartFile => raw XML */
    private array $charts = [];

    private function __construct(
        private readonly string $xlsxPath,
    ) {
        $zip = $this->openZip();

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^xl/charts/chart\d+\.xml$#', $name)) {
                $this->charts[$name] = $zip->getFromName($name);
            }
        }

        $zip->close();
    }

    public static function open(string $xlsxPath): self
    {
        return new self($xlsxPath);
    }

    public function getXlsxPath(): string
    {
        return $this->xlsxPath;
    }

    // ── Chart selector ────────────────────────────────────────────────────────

    /**
     * Select a chart by 0-based index (order of xl/charts/chart*.xml filenames).
     * Returns a ChartContext which provides the series() / yAxis() / xAxis() API.
     */
    public function chart(int $index = 0): ChartContext
    {
        $keys = array_keys($this->charts);
        sort($keys); // ensure deterministic order

        if (! isset($keys[$index])) {
            throw new \InvalidArgumentException(
                "Chart index {$index} out of range (found " . count($keys) . " charts)."
            );
        }

        $chartFile = $keys[$index];

        return new ChartContext(
            xml:         $this->charts[$chartFile],
            onSave:      function (string $patchedXml) use ($chartFile): void {
                $this->charts[$chartFile] = $patchedXml;
            },
            document:    $this,
        );
    }

    // ── Persistence ───────────────────────────────────────────────────────────

    /**
     * Write all patched chart XMLs back into the xlsx archive.
     */
    public function save(): void
    {
        $zip = $this->openZip();

        foreach ($this->charts as $chartFile => $xml) {
            $zip->addFromString($chartFile, $xml);
        }

        $zip->close();
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function openZip(): \ZipArchive
    {
        $zip = new \ZipArchive();
        $result = $zip->open($this->xlsxPath);

        if ($result !== true) {
            throw new \RuntimeException(
                "Cannot open xlsx as ZipArchive (code {$result}): {$this->xlsxPath}"
            );
        }

        return $zip;
    }
}

/**
 * Represents one chart XML document — provides the fluent series/axis API.
 *
 * Kept in the same file as ChartDocument (internal collaborator, not public API).
 */
final class ChartContext
{
    private readonly \DOMDocument $dom;
    private readonly \DOMXPath    $xpath;

    /**
     * @param string   $xml      Raw chart XML
     * @param callable $onSave   Callback to push patched XML back to ChartDocument
     * @param ChartDocument $document  Parent — returned by save() for chaining
     */
    public function __construct(
        string                          $xml,
        private readonly \Closure       $onSave,
        private readonly ChartDocument  $document,
    ) {
        $this->dom = new \DOMDocument('1.0', 'UTF-8');
        $this->dom->preserveWhiteSpace = false;
        $this->dom->formatOutput       = false;

        if (! $this->dom->loadXML($xml)) {
            throw new \RuntimeException('Failed to parse chart XML as DOMDocument.');
        }

        $this->xpath = XPathHelper::for($this->dom);
    }

    // ── Series ────────────────────────────────────────────────────────────────

    public function series(int $index): SeriesContext
    {
        return new SeriesContext(
            injector: new SeriesInjector($this->dom, $this->xpath, $index),
            chartContext: $this,
        );
    }

    // ── Axes ──────────────────────────────────────────────────────────────────

    public function yAxis(int $index = 0): AxisContext
    {
        return new AxisContext(
            injector: new AxisInjector($this->dom, $this->xpath, 'valAx', $index),
            chartContext: $this,
        );
    }

    public function xAxis(int $index = 0): AxisContext
    {
        return new AxisContext(
            injector: new AxisInjector($this->dom, $this->xpath, 'catAx', $index),
            chartContext: $this,
        );
    }

    // ── Persistence ───────────────────────────────────────────────────────────

    public function save(): ChartDocument
    {
        $patched = $this->dom->saveXML();
        ($this->onSave)($patched);
        $this->document->save();

        return $this->document;
    }
}

/**
 * Fluent wrapper around SeriesInjector — chains back to ChartContext.
 */
final class SeriesContext
{
    public function __construct(
        private readonly SeriesInjector $injector,
        private readonly ChartContext   $chartContext,
    ) {}

    public function addErrorBars(ErrorBarDefinition $def): self
    {
        $this->injector->addErrorBars($def);
        return $this;
    }

    public function setMarker(MarkerDefinition $def): self
    {
        $this->injector->setMarker($def);
        return $this;
    }

    public function setLine(LineDefinition $def): self
    {
        $this->injector->setLine($def);
        return $this;
    }

    /** Return to ChartContext to select another series or axis. */
    public function series(int $index): SeriesContext
    {
        return $this->chartContext->series($index);
    }

    public function yAxis(int $index = 0): AxisContext
    {
        return $this->chartContext->yAxis($index);
    }

    public function xAxis(int $index = 0): AxisContext
    {
        return $this->chartContext->xAxis($index);
    }

    public function save(): ChartDocument
    {
        return $this->chartContext->save();
    }
}

/**
 * Fluent wrapper around AxisInjector — chains back to ChartContext.
 */
final class AxisContext
{
    public function __construct(
        private readonly AxisInjector $injector,
        private readonly ChartContext $chartContext,
    ) {}

    public function setScale(AxisScaleDefinition $def): self
    {
        $this->injector->setScale($def);
        return $this;
    }

    public function setNumberFormat(string $formatCode): self
    {
        $this->injector->setNumberFormat($formatCode);
        return $this;
    }

    public function series(int $index): SeriesContext
    {
        return $this->chartContext->series($index);
    }

    public function yAxis(int $index = 0): AxisContext
    {
        return $this->chartContext->yAxis($index);
    }

    public function save(): ChartDocument
    {
        return $this->chartContext->save();
    }
}
