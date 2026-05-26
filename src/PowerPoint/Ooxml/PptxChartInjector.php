<?php

namespace Procorad\ProcostatReporting\PowerPoint\Ooxml;

use Procorad\ProcostatReporting\Excel\Ooxml\Definitions\GraphDefinition;
use Procorad\ProcostatReporting\Shared\Ooxml\ChartXmlBuilder;

/**
 * Injects OOXML charts into a PPTX archive — one new slide per chart.
 *
 * For each GraphDefinition:
 *   1. Builds chartN.xml (inline data)
 *   2. Adds chartN.xml.rels (external xlsx link)
 *   3. Creates a new slideN.xml referencing the chart
 *   4. Adds slideN.xml.rels referencing the chart
 *   5. Updates ppt/presentation.xml sldIdLst
 *   6. Updates [Content_Types].xml
 *   7. Updates ppt/_rels/presentation.xml.rels
 */
final class PptxChartInjector
{
    public function __construct(
        private readonly ChartXmlBuilder $chartBuilder = new ChartXmlBuilder(),
    ) {}

    /**
     * @param GraphDefinition[] $graphs
     * @param string            $pptxPath  Absolute path to pptx (modified in-place)
     * @param string            $xlsxPath  Absolute path to xlsx (for external link)
     */
    public function inject(array $graphs, string $pptxPath, string $xlsxPath): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($pptxPath) !== true) {
            throw new \RuntimeException("Cannot open PPTX: {$pptxPath}");
        }

        $presentationXml  = $zip->getFromName('ppt/presentation.xml');
        $presentationRels = $zip->getFromName('ppt/_rels/presentation.xml.rels');
        $contentTypes     = $zip->getFromName('[Content_Types].xml');

        // Find next slide index
        $existingSlides = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#ppt/slides/slide(\d+)\.xml$#', $name, $m)) {
                $existingSlides[] = (int) $m[1];
            }
        }
        $nextSlide = empty($existingSlides) ? 2 : max($existingSlides) + 1;

        // Find next chart index
        $existingCharts = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#ppt/charts/chart(\d+)\.xml$#', $name, $m)) {
                $existingCharts[] = (int) $m[1];
            }
        }
        $nextChart = empty($existingCharts) ? 1 : max($existingCharts) + 1;

        // Find next slide relationship rId in presentation.xml.rels
        preg_match_all('/Id="rId(\d+)"/', $presentationRels, $m);
        $nextRid = empty($m[1]) ? 10 : max(array_map('intval', $m[1])) + 1;

        // Find next sldId value in presentation.xml
        preg_match_all('/id="(\d+)"/', $presentationXml, $m2);
        $nextSldId = empty($m2[1]) ? 256 : max(array_map('intval', $m2[1])) + 1;

        $newSlideRels     = '';  // additions to presentation.xml.rels
        $newSldIdEntries  = '';  // additions to sldIdLst in presentation.xml
        $newContentTypes  = '';

        foreach ($graphs as $graph) {
            $slideIndex = $nextSlide++;
            $chartIndex = $nextChart++;
            $slideRid   = 'rId' . $nextRid++;
            $sldId      = $nextSldId++;

            $slideFile     = "ppt/slides/slide{$slideIndex}.xml";
            $slideRelsFile = "ppt/slides/_rels/slide{$slideIndex}.xml.rels";
            $chartFile     = "ppt/charts/chart{$chartIndex}.xml";
            $chartRelsFile = "ppt/charts/_rels/chart{$chartIndex}.xml.rels";

            // Chart XML
            $zip->addFromString($chartFile, $this->chartBuilder->build($graph));

            // Chart rels — external xlsx link
            $xlsxPathEscaped = str_replace('\\', '/', $xlsxPath);
            $zip->addFromString($chartRelsFile, $this->buildChartRels($xlsxPathEscaped));

            // Slide XML — chart is rId2 (rId1 is slideLayout)
            $zip->addFromString($slideFile, $this->buildSlide($graph->title, 'rId2'));

            // Slide rels — reference chart + slide layout
            $zip->addFromString($slideRelsFile, $this->buildSlideRels($chartIndex));

            // Accumulate presentation.xml.rels entry
            $newSlideRels .= sprintf(
                '<Relationship Id="%s" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide%d.xml"/>',
                $slideRid,
                $slideIndex,
            );

            // Accumulate sldIdLst entry
            $newSldIdEntries .= sprintf(
                '<p:sldId id="%d" r:id="%s"/>',
                $sldId,
                $slideRid,
            );

            // Content types
            $newContentTypes .= sprintf(
                '<Override PartName="/ppt/slides/slide%d.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>',
                $slideIndex,
            );
            $newContentTypes .= sprintf(
                '<Override PartName="/ppt/charts/chart%d.xml" ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml"/>',
                $chartIndex,
            );
        }

        // Patch presentation.xml — append slides to sldIdLst
        $presentationXml = str_replace('</p:sldIdLst>', $newSldIdEntries . '</p:sldIdLst>', $presentationXml);

        // Patch presentation.xml.rels
        $presentationRels = str_replace('</Relationships>', $newSlideRels . '</Relationships>', $presentationRels);

        // Patch content types
        $contentTypes = str_replace('</Types>', $newContentTypes . '</Types>', $contentTypes);

        $zip->addFromString('ppt/presentation.xml', $presentationXml);
        $zip->addFromString('ppt/_rels/presentation.xml.rels', $presentationRels);
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
     * A 4:3 slide with the chart filling the content area below the header.
     * Slide dimensions: 9144000 × 6858000 EMU (standard 4:3 PowerPoint).
     * Chart frame matches reference PPTX (25CB-14C.pptx):
     *   off x=72000 y=836711 — tight to left edge, below the logo/title header
     *   cx=8999999 cy=5760000 — full width, fills remaining height
     * The header (logo + title) is provided by slideLayout2, not drawn here.
     *
     * Note: chart is referenced as rId2 (rId1 is reserved for the slideLayout).
     */
    private function buildSlide(string $title, string $chartRid): string
    {
        $titleEsc = htmlspecialchars($title, ENT_XML1);
        // Chart frame — matches reference PPTX exactly
        $offX = 72000;    // tight to left edge
        $offY = 836711;   // below header banner (logo + title in slideLayout2)
        $cx   = 8999999;  // full slide width minus tiny left margin
        $cy   = 5760000;  // fills remaining height to bottom

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"
       xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"
       xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <p:cSld name="{$titleEsc}">
    <p:spTree>
      <p:nvGrpSpPr>
        <p:cNvPr id="1" name=""/>
        <p:cNvGrpSpPr/>
        <p:nvPr/>
      </p:nvGrpSpPr>
      <p:grpSpPr>
        <a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/>
          <a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/>
        </a:xfrm>
      </p:grpSpPr>
      <p:graphicFrame>
        <p:nvGraphicFramePr>
          <p:cNvPr id="2" name="{$titleEsc}"/>
          <p:cNvGraphicFramePr>
            <a:graphicFrameLocks noGrp="1"/>
          </p:cNvGraphicFramePr>
          <p:nvPr/>
        </p:nvGraphicFramePr>
        <p:xfrm>
          <a:off x="{$offX}" y="{$offY}"/>
          <a:ext cx="{$cx}" cy="{$cy}"/>
        </p:xfrm>
        <a:graphic>
          <a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/chart">
            <c:chart xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart"
                     r:id="{$chartRid}"/>
          </a:graphicData>
        </a:graphic>
      </p:graphicFrame>
    </p:spTree>
  </p:cSld>
  <p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr>
</p:sld>
XML;
    }

    /**
     * Slide relationships — order matches reference PPTX (25CB-14C.pptx slide2.xml.rels):
     *   rId1 → slideLayout2 (provides logo + title placeholder header)
     *   rId2 → chart XML
     *
     * slideLayout2 ("Titre et contenu") carries the Procorad logo and title zone.
     * buildSlide() references the chart as rId2 to match.
     */
    private function buildSlideRels(int $chartIndex): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1"
    Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout"
    Target="../slideLayouts/slideLayout2.xml"/>
  <Relationship Id="rId2"
    Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart"
    Target="../charts/chart{$chartIndex}.xml"/>
</Relationships>
XML;
    }
}
