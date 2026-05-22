<?php

namespace Procorad\ProcostatReporting\Word\Ooxml;

use Procorad\ProcostatReporting\Excel\Ooxml\Definitions\GraphDefinition;
use Procorad\ProcostatReporting\Shared\Ooxml\ChartXmlBuilder;

/**
 * Injects OOXML charts into a DOCX archive.
 *
 * For each GraphDefinition:
 *   1. Builds chartN.xml (via ChartXmlBuilder, inline data cache)
 *   2. Adds chartN.xml.rels with external link to xlsx
 *   3. Adds a new page to document.xml (page break + drawing element)
 *   4. Updates word/_rels/document.xml.rels (rId for drawing)
 *   5. Updates [Content_Types].xml
 *
 * The chart drawing is anchored as an inline image-sized drawing that references
 * the chart via a relationship — same mechanism as the model files.
 */
final class DocxChartInjector
{
    public function __construct(
        private readonly ChartXmlBuilder $chartBuilder = new ChartXmlBuilder(),
    ) {}

    /**
     * @param GraphDefinition[] $graphs
     * @param string            $docxPath    Absolute path to the docx (modified in-place)
     * @param string            $xlsxPath    Absolute path to the xlsx (for external link)
     */
    public function inject(array $graphs, string $docxPath, string $xlsxPath): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($docxPath) !== true) {
            throw new \RuntimeException("Cannot open DOCX: {$docxPath}");
        }

        // Read existing files we need to patch
        $documentXml     = $zip->getFromName('word/document.xml');
        $documentRels    = $zip->getFromName('word/_rels/document.xml.rels');
        $contentTypes    = $zip->getFromName('[Content_Types].xml');

        // Find the highest existing rId and chartN index
        preg_match_all('/Id="rId(\d+)"/', $documentRels, $m);
        $nextRid = empty($m[1]) ? 1 : max(array_map('intval', $m[1])) + 1;

        // Find existing chart files to avoid collision
        $existingCharts = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#word/charts/chart(\d+)\.xml#', $name, $mc)) {
                $existingCharts[] = (int) $mc[1];
            }
        }
        $nextChart = empty($existingCharts) ? 1 : max($existingCharts) + 1;

        // Drawing rId accumulator — pairs (chartFile → rId in document.xml.rels)
        $newDocRels    = [];
        $newChartPages = '';

        foreach ($graphs as $graph) {
            $chartIndex = $nextChart++;
            $chartFile  = "word/charts/chart{$chartIndex}.xml";
            $chartRels  = "word/charts/_rels/chart{$chartIndex}.xml.rels";
            $drawingRid = 'rId' . $nextRid++;
            $xlsxRid    = 'rId1'; // inside the chart .rels file, always rId1

            // 1. Build and write chartN.xml
            $chartXml = $this->chartBuilder->build($graph);
            $zip->addFromString($chartFile, $chartXml);

            // 2. Chart relationship → external xlsx
            $xlsxPathEscaped = str_replace('\\', '/', $xlsxPath);
            $zip->addFromString($chartRels, $this->buildChartRels($xlsxPathEscaped));

            // 3. Drawing rId in document.xml.rels
            $newDocRels[] = [
                'id'     => $drawingRid,
                'type'   => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart',
                'target' => "charts/chart{$chartIndex}.xml",
            ];

            // 4. Page content — page break + drawing
            $newChartPages .= $this->buildChartPage($graph->title, $drawingRid);
        }

        // Patch document.xml — append chart pages before </w:body>
        $documentXml = str_replace(
            '</w:body>',
            $newChartPages . '</w:body>',
            $documentXml,
        );

        // Patch document.xml.rels — add new relationships
        $relsToAdd = '';
        foreach ($newDocRels as $rel) {
            $relsToAdd .= sprintf(
                '<Relationship Id="%s" Type="%s" Target="%s"/>',
                $rel['id'], $rel['type'], $rel['target'],
            );
        }
        $documentRels = str_replace('</Relationships>', $relsToAdd . '</Relationships>', $documentRels);

        // Patch [Content_Types].xml — add chart content type if missing
        if (! str_contains($contentTypes, 'application/vnd.openxmlformats-officedocument.drawingml.chart+xml')) {
            $contentTypes = str_replace(
                '</Types>',
                '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/word/charts/chart1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml"/>'
                . '</Types>',
                $contentTypes,
            );
        }
        // Add each new chart override
        foreach ($graphs as $i => $graph) {
            $idx          = (count($existingCharts) ? max($existingCharts) : 0) + $i + 1;
            $contentTypes = str_replace(
                '</Types>',
                "<Override PartName=\"/word/charts/chart{$idx}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.drawingml.chart+xml\"/></Types>",
                $contentTypes,
            );
        }

        $zip->addFromString('word/document.xml', $documentXml);
        $zip->addFromString('word/_rels/document.xml.rels', $documentRels);
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->close();
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

    /**
     * Builds one page of document.xml content: page break + full-page chart drawing.
     * EMU dimensions: A4 width minus margins ≈ 16200000 × 10800000 (portrait)
     */
    private function buildChartPage(string $title, string $rId): string
    {
        $titleEsc = htmlspecialchars($title, ENT_XML1);
        // cx/cy in EMU — A4 usable area minus 2cm margins each side ≈ 16837000 × 11906000
        $cx = 14400000;
        $cy = 9800000;

        return <<<XML
<w:p><w:r><w:br w:type="page"/></w:r></w:p>
<w:p>
  <w:pPr><w:jc w:val="center"/></w:pPr>
  <w:r>
    <w:drawing>
      <wp:inline xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"
                 distT="0" distB="0" distL="0" distR="0">
        <wp:extent cx="{$cx}" cy="{$cy}"/>
        <wp:effectExtent l="0" t="0" r="0" b="0"/>
        <wp:docPr id="1" name="{$titleEsc}"/>
        <wp:cNvGraphicFramePr>
          <a:graphicFrameLocks xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" noChangeAspect="1"/>
        </wp:cNvGraphicFramePr>
        <a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">
          <a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/chart">
            <c:chart xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart"
                     xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"
                     r:id="{$rId}"/>
          </a:graphicData>
        </a:graphic>
      </wp:inline>
    </w:drawing>
  </w:r>
</w:p>
XML;
    }
}
