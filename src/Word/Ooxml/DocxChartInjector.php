<?php

namespace Procorad\ProcostatReporting\Word\Ooxml;

use Procorad\ProcostatReporting\Excel\Ooxml\Definitions\GraphDefinition;
use Procorad\ProcostatReporting\Shared\Ooxml\ChartXmlBuilder;

/**
 * Injects OOXML charts into a DOCX archive, grouped 2 per page.
 *
 * Page layout per analysis (matching PPTX content):
 *   Page 1 — Results lab-order (top) + Results value-order (bottom)
 *   Page 2 — Bias (top) + Z'-score (bottom)  [Z'-score omitted if absent]
 *   Page 3 — Zeta (top, full height if alone)
 *
 * Each chart drawing is sized to the full content width of A4 (margins 1cm each side).
 * A4 usable width ≈ 6,120,000 EMU; all charts use the same height (~4,100,000 EMU)
 * so the two charts on a shared page sit at equal size and the lone zeta chart
 * matches them visually (consistent look, easy to add a 4th chart later).
 */
final class DocxChartInjector
{
    // A4 content area in EMU (margins 1134 DXA each side, 1 DXA = 914400/1440 EMU)
    // Content width:  (11906 - 2×1134) DXA × 635 = 9638 × 635 ≈ 6,120,130 EMU
    // Content height: (16838 - 2×1134 - ~850 header) DXA × 635 ≈ 8,712,200 EMU total
    // Two charts per page: each gets ~4,200,000 EMU height (leaves ~300,000 for gap)
    private const CHART_W      = 6120000; // full content width
    private const CHART_H_HALF = 4100000; // half-page chart (2 per page)
    private const CHART_H_FULL = 4100000; // single chart — same size as half (consistent look)

    public function __construct(
        private readonly ChartXmlBuilder $chartBuilder = new ChartXmlBuilder(),
    ) {}

    /**
     * @param GraphDefinition[] $graphs   All graphs for one analysis (from GraphDefinitionFactory)
     * @param string            $docxPath Absolute path to docx (modified in-place)
     * @param string            $xlsxPath Absolute path to xlsx (for external chart link)
     */
    public function inject(array $graphs, string $docxPath, string $xlsxPath): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($docxPath) !== true) {
            throw new \RuntimeException("Cannot open DOCX: {$docxPath}");
        }

        $documentXml  = $zip->getFromName('word/document.xml');
        $documentRels = $zip->getFromName('word/_rels/document.xml.rels');
        $contentTypes = $zip->getFromName('[Content_Types].xml');

        // Find next available IDs
        preg_match_all('/Id="rId(\d+)"/', $documentRels, $m);
        $nextRid = empty($m[1]) ? 1 : max(array_map('intval', $m[1])) + 1;

        $existingCharts = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#word/charts/chart(\d+)\.xml#', $name, $mc)) {
                $existingCharts[] = (int) $mc[1];
            }
        }
        $nextChart = empty($existingCharts) ? 1 : max($existingCharts) + 1;

        // Find next drawing docPr id (must be unique across document)
        preg_match_all('/docPr id="(\d+)"/', $documentXml, $md);
        $nextDocPr = empty($md[1]) ? 1 : max(array_map('intval', $md[1])) + 1;

        // ── Group graphs into pages ───────────────────────────────────────────
        // GraphDefinitionFactory produces (in order):
        //   0: results_lab_asc   → results charts
        //   1: results_val_asc   ↗
        //   2: bias              → scores charts (page 2 top)
        //   3: zprime_score      → page 2 bottom (optional)
        //   4: zeta_score        → page 3 (alone or with nothing)
        //
        // We build pages as arrays of [GraphDefinition, height_emu]
        $pages = $this->groupIntoPages($graphs);

        $newDocRels  = '';
        $chartPages  = '';
        $newCt       = '';
        $relsToAdd   = [];

        foreach ($pages as $pageGraphs) {
            $chartPages .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';

            foreach ($pageGraphs as [$graph, $heightEmu]) {
                $chartIndex = $nextChart++;
                $drawingRid = 'rId' . $nextRid++;
                $docPrId    = $nextDocPr++;

                $chartFile = "word/charts/chart{$chartIndex}.xml";
                $chartRels = "word/charts/_rels/chart{$chartIndex}.xml.rels";

                $zip->addFromString($chartFile, $this->chartBuilder->build($graph));
                $zip->addFromString($chartRels, $this->buildChartRels(str_replace('\\', '/', $xlsxPath)));

                $relsToAdd[] = sprintf(
                    '<Relationship Id="%s" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart" Target="charts/chart%d.xml"/>',
                    $drawingRid,
                    $chartIndex,
                );

                $newCt .= sprintf(
                    '<Override PartName="/word/charts/chart%d.xml" ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml"/>',
                    $chartIndex,
                );

                $titleEsc = htmlspecialchars($graph->title, ENT_XML1);
                $cx       = self::CHART_W;
                $cy       = $heightEmu;

                $chartPages .= <<<XML
<w:p>
  <w:pPr><w:jc w:val="center"/><w:spacing w:after="80"/></w:pPr>
  <w:r>
    <w:drawing>
      <wp:inline xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"
                 distT="0" distB="0" distL="0" distR="0">
        <wp:extent cx="{$cx}" cy="{$cy}"/>
        <wp:effectExtent l="0" t="0" r="0" b="0"/>
        <wp:docPr id="{$docPrId}" name="{$titleEsc}"/>
        <wp:cNvGraphicFramePr>
          <a:graphicFrameLocks xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" noChangeAspect="1"/>
        </wp:cNvGraphicFramePr>
        <a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">
          <a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/chart">
            <c:chart xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart"
                     xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"
                     r:id="{$drawingRid}"/>
          </a:graphicData>
        </a:graphic>
      </wp:inline>
    </w:drawing>
  </w:r>
</w:p>
XML;
            }
        }

        // Patch document.xml
        $documentXml = str_replace('</w:body>', $chartPages . '</w:body>', $documentXml);

        // Patch document.xml.rels
        $documentRels = str_replace('</Relationships>', implode('', $relsToAdd) . '</Relationships>', $documentRels);

        // Patch content types
        if (!str_contains($contentTypes, 'drawingml.chart+xml')) {
            $contentTypes = str_replace('</Types>',
                '<Override PartName="/word/charts/chart0_placeholder.xml" ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml"/>'.
                '</Types>', $contentTypes);
        }
        $contentTypes = str_replace('</Types>', $newCt . '</Types>', $contentTypes);

        $zip->addFromString('word/document.xml',           $documentXml);
        $zip->addFromString('word/_rels/document.xml.rels', $documentRels);
        $zip->addFromString('[Content_Types].xml',          $contentTypes);
        $zip->close();
    }

    // ── Page grouping ─────────────────────────────────────────────────────────

    /**
     * Groups GraphDefinitions into pages of 1 or 2 charts.
     *
     * Layout:
     *   Page 1: results_lab_asc (half) + results_val_asc (half)
     *   Page 2: bias (half) + zprime_score (half, if present)
     *   Page 3: zeta_score (full, alone)
     *
     * If zprime is absent:
     *   Page 2: bias (full, alone)
     *   Page 3: zeta_score (full, alone)
     *
     * @return array<array<array{0: GraphDefinition, 1: int}>>
     */
    private function groupIntoPages(array $graphs): array
    {
        // Index by type
        $byType = [];
        foreach ($graphs as $g) {
            $byType[$g->type] = $g;
        }

        $pages = [];

        // Page 1: results
        $resultsPage = [];
        if (isset($byType['results_lab_asc'])) {
            $resultsPage[] = [$byType['results_lab_asc'], self::CHART_H_HALF];
        }
        if (isset($byType['results_val_asc'])) {
            $resultsPage[] = [$byType['results_val_asc'], self::CHART_H_HALF];
        }
        // If only one results chart, make it full height
        if (count($resultsPage) === 1) {
            $resultsPage[0][1] = self::CHART_H_FULL;
        }
        if (!empty($resultsPage)) {
            $pages[] = $resultsPage;
        }

        // Page 2: bias + z'prime (or bias alone)
        $scoresPage = [];
        if (isset($byType['bias'])) {
            $scoresPage[] = [$byType['bias'], self::CHART_H_HALF];
        }
        if (isset($byType['zprime_score'])) {
            $scoresPage[] = [$byType['zprime_score'], self::CHART_H_HALF];
        }
        // Bias alone → full height
        if (count($scoresPage) === 1) {
            $scoresPage[0][1] = self::CHART_H_FULL;
        }
        if (!empty($scoresPage)) {
            $pages[] = $scoresPage;
        }

        // Page 3: zeta alone (full height)
        if (isset($byType['zeta_score'])) {
            $pages[] = [[$byType['zeta_score'], self::CHART_H_FULL]];
        }

        return $pages;
    }

    // ── XML builders ─────────────────────────────────────────────────────────

    private function buildChartRels(string $xlsxPath): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1"
    Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/oleObject"
    Target="file:///{$xlsxPath}"
    TargetMode="External"/>
</Relationships>
XML;
    }
}
