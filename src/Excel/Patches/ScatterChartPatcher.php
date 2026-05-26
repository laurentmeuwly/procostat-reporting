<?php

namespace Procorad\ProcostatReporting\Excel\Patches;

use Procorad\ProcostatReporting\Excel\Ooxml\ChartDocument;
use Procorad\ProcostatReporting\Excel\Ooxml\Definitions\AxisScaleDefinition;
use Procorad\ProcostatReporting\Excel\Ooxml\Definitions\LineDefinition;
use Procorad\ProcostatReporting\Excel\Ooxml\Definitions\MarkerDefinition;
use Procorad\ProcostatReporting\Excel\Support\ExcelColors;
use Procorad\ProcostatReporting\Excel\Support\ExcelLayout;

/**
 * Post-generation OOXML patch for the z' vs zeta scatter chart.
 *
 * Three independent operations applied in sequence:
 *
 * 1. ChartDocument API: series styles + axis scales
 *    - Series 0 (lab points):  circle blue marker, no line
 *    - Series 1-4 (vertical):  orange/red dashed lines, no marker
 *    - Series 5-8 (horizontal):orange/red dashed lines, no marker
 *    - Both axes: symmetric ±SCATTER_AXIS_MAX
 *
 * 2. ZIP/XML: axes cross at origin
 *    Adds <c:crossesAt val="0"/> after <c:crosses val="autoZero"/>
 *
 * 3. ZIP/XML: strRef → numRef inside <c:xVal>
 *    PhpSpreadsheet emits <c:strRef>/<c:strCache> for xVal when the
 *    DataSeriesValues type is STRING. Excel rejects this for scatter charts
 *    and removes the entire drawing part on open (PhpSpreadsheet issue #2817).
 *    This patch renames the nodes to numRef/numCache using DOMDocument.
 */
final class ScatterChartPatcher
{
    public function patch(ChartDocument $doc, int $chartIndex): void
    {
        $axisMax = ExcelLayout::SCATTER_AXIS_MAX;

        try {
            $ctx = $doc->chart($chartIndex);

            // Series 0 — lab points
            $ctx->series(0)
                ->setMarker(MarkerDefinition::circle('4472C4', 7))
                ->setLine(LineDefinition::none());

            // Series 1/2 → orange ±2, 3/4 → red ±3 (vertical)
            // Series 5/6 → orange ±2, 7/8 → red ±3 (horizontal)
            $palette = [
                1 => ExcelColors::ORANGE, 2 => ExcelColors::ORANGE,
                3 => ExcelColors::RED,    4 => ExcelColors::RED,
                5 => ExcelColors::ORANGE, 6 => ExcelColors::ORANGE,
                7 => ExcelColors::RED,    8 => ExcelColors::RED,
            ];
            foreach ($palette as $si => $color) {
                $ctx->series($si)
                    ->setLine(LineDefinition::dashed($color, 'dash', 12700))
                    ->setMarker(MarkerDefinition::none());
            }

            $scale = new AxisScaleDefinition(min: -$axisMax, max: $axisMax);
            $ctx->yAxis(0)->setScale($scale); // X axis (first valAx in OOXML for scatterChart)
            $ctx->yAxis(1)->setScale($scale); // Y axis

            $ctx->save();
        } catch (\InvalidArgumentException) {
            // Chart absent — skip
            return;
        }

        $this->patchXml($doc->getXlsxPath(), $chartIndex);
    }

    // ── ZIP/XML patches ───────────────────────────────────────────────────────

    private function patchXml(string $xlsxPath, int $chartIndex): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($xlsxPath) !== true) return;

        $file = $this->chartFile($zip, $chartIndex);
        if ($file === null) { $zip->close(); return; }

        $xml = $zip->getFromName($file);

        // 1. Axes cross at origin
        $xml = str_replace(
            '<c:crosses val="autoZero"/>',
            '<c:crosses val="autoZero"/><c:crossesAt val="0"/>',
            $xml,
        );

        // 2. strRef → numRef inside <c:xVal> (Excel compatibility — issue #2817)
        $xml = $this->fixXValStrRef($xml);

        $zip->addFromString($file, $xml);
        $zip->close();
    }

    /**
     * Rename <c:strRef>/<c:strCache> to <c:numRef>/<c:numCache> inside every <c:xVal>.
     * Excel requires numeric references for scatter X values; LibreOffice accepts both.
     */
    private function fixXValStrRef(string $xml): string
    {
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        if (! @$dom->loadXML($xml)) return $xml;

        $ns = 'http://schemas.openxmlformats.org/drawingml/2006/chart';
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('c', $ns);

        foreach ($xp->query('//c:xVal/c:strRef') as $node) {
            $replacement = $dom->createElementNS($ns, 'c:numRef');
            while ($node->firstChild) { $replacement->appendChild($node->firstChild); }
            $node->parentNode->replaceChild($replacement, $node);
        }

        foreach ($xp->query('//c:xVal//c:strCache') as $node) {
            $replacement = $dom->createElementNS($ns, 'c:numCache');
            while ($node->firstChild) { $replacement->appendChild($node->firstChild); }
            $node->parentNode->replaceChild($replacement, $node);
        }

        return (string) $dom->saveXML();
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function chartFile(\ZipArchive $zip, int $index): ?string
    {
        $keys = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^xl/charts/chart\d+\.xml$#', $name)) {
                $keys[] = $name;
            }
        }
        sort($keys);
        return $keys[$index] ?? null;
    }
}
