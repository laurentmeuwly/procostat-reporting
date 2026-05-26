<?php

namespace Procorad\ProcostatReporting\PowerPoint\Ooxml;

use Procorad\ProcostatReporting\Excel\Ooxml\Definitions\GraphDefinition;
use Procorad\ProcostatReporting\Shared\Ooxml\ChartXmlBuilder;
use Procorad\ProcostatReporting\Support\PackagePaths;

/**
 * Injects OOXML charts into a PPTX archive — one new slide per chart.
 *
 * For each GraphDefinition:
 *   1. Ensures the Procorad logo PNG is present in ppt/media/logo.png
 *   2. Builds chartN.xml (inline data)
 *   3. Adds chartN.xml.rels (external xlsx link)
 *   4. Creates a new slideN.xml with background, logo, and chart
 *   5. Adds slideN.xml.rels referencing chart + slideLayout1
 *   6. Updates ppt/presentation.xml sldIdLst
 *   7. Updates [Content_Types].xml
 *   8. Updates ppt/_rels/presentation.xml.rels
 *
 * Design notes:
 *   - pptxgenjs only generates slideLayout1, so we cannot rely on slideLayout2
 *     for the logo — it is embedded directly in each slide XML instead.
 *   - Background colour (E8E8E8) is set explicitly on every slide so it matches
 *     the cover slide produced by render-pptx.js.
 *   - The logo is copied into ppt/media/logo.png on the first call and reused
 *     on subsequent slides via the same media path.
 */
final class PptxChartInjector
{
    /** Logo position/size in EMU — matches slideLayout2 from reference PPTX */
    private const LOGO_X  = 107504;
    private const LOGO_Y  = 69730;
    private const LOGO_CX = 1615948;
    private const LOGO_CY = 603940;

    /** Chart area in EMU — fills slide below the logo header */
    private const CHART_X  = 72000;
    private const CHART_Y  = 836711;
    private const CHART_CX = 8999999;
    private const CHART_CY = 5760000;

    /** Slide background colour (same as cover) */
    private const BG_COLOR = 'E8E8E8';

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

        // ── Ensure logo is present in the archive ────────────────────────────
        $logoMediaPath = 'ppt/media/logo.png';
        $logoAlreadyEmbedded = ($zip->locateName($logoMediaPath) !== false);
        if (!$logoAlreadyEmbedded) {
            $logoAsset = PackagePaths::asset('logo.png');
            if (file_exists($logoAsset)) {
                $zip->addFile($logoAsset, $logoMediaPath);
                // Register PNG content type if not already present
                if (strpos($contentTypes, 'image/png') === false) {
                    $contentTypes = str_replace(
                        '</Types>',
                        '<Default Extension="png" ContentType="image/png"/></Types>',
                        $contentTypes,
                    );
                }
            }
        }

        // ── Find next available indices ───────────────────────────────────────
        $existingSlides = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#ppt/slides/slide(\d+)\.xml$#', $name, $m)) {
                $existingSlides[] = (int) $m[1];
            }
        }
        $nextSlide = empty($existingSlides) ? 2 : max($existingSlides) + 1;

        $existingCharts = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#ppt/charts/chart(\d+)\.xml$#', $name, $m)) {
                $existingCharts[] = (int) $m[1];
            }
        }
        $nextChart = empty($existingCharts) ? 1 : max($existingCharts) + 1;

        preg_match_all('/Id="rId(\d+)"/', $presentationRels, $m);
        $nextRid = empty($m[1]) ? 10 : max(array_map('intval', $m[1])) + 1;

        // Extract sldId values only from the <p:sldIdLst> section to avoid
        // matching unrelated high-value id attributes from pptxgenjs output.
        // OOXML spec: sldId must be in range [256, 2147483647].
        $sldIdLst = '';
        if (preg_match('/<p:sldIdLst>(.*?)<\/p:sldIdLst>/s', $presentationXml, $sldm)) {
            $sldIdLst = $sldm[1];
        }
        preg_match_all('/\bid="(\d+)"/', $sldIdLst, $m2);
        $maxExisting = empty($m2[1]) ? 255 : max(array_map('intval', $m2[1]));
        $nextSldId   = max(256, min($maxExisting + 1, 2147483640)); // stay safely below int32 max

        $newSlideRels    = '';
        $newSldIdEntries = '';
        $newContentTypes = '';

        foreach ($graphs as $graph) {
            $slideIndex = $nextSlide++;
            $chartIndex = $nextChart++;
            $slideRid   = 'rId' . $nextRid++;
            $sldId      = $nextSldId++;

            $slideFile     = "ppt/slides/slide{$slideIndex}.xml";
            $slideRelsFile = "ppt/slides/_rels/slide{$slideIndex}.xml.rels";
            $chartFile     = "ppt/charts/chart{$chartIndex}.xml";
            $chartRelsFile = "ppt/charts/_rels/chart{$chartIndex}.xml.rels";

            $zip->addFromString($chartFile, $this->chartBuilder->build($graph));

            $xlsxPathEscaped = str_replace('\\', '/', $xlsxPath);
            $zip->addFromString($chartRelsFile, $this->buildChartRels($xlsxPathEscaped));

            // Slide XML: rId1=slideLayout, rId2=chart, rId3=logo image
            $zip->addFromString($slideFile, $this->buildSlide($graph->title, 'rId2', 'rId3'));

            $zip->addFromString($slideRelsFile, $this->buildSlideRels($chartIndex));

            $newSlideRels .= sprintf(
                '<Relationship Id="%s" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide%d.xml"/>',
                $slideRid,
                $slideIndex,
            );

            $newSldIdEntries .= sprintf(
                '<p:sldId id="%d" r:id="%s"/>',
                $sldId,
                $slideRid,
            );

            $newContentTypes .= sprintf(
                '<Override PartName="/ppt/slides/slide%d.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>',
                $slideIndex,
            );
            $newContentTypes .= sprintf(
                '<Override PartName="/ppt/charts/chart%d.xml" ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml"/>',
                $chartIndex,
            );
        }

        $presentationXml  = str_replace('</p:sldIdLst>', $newSldIdEntries . '</p:sldIdLst>', $presentationXml);
        $presentationRels = str_replace('</Relationships>', $newSlideRels . '</Relationships>', $presentationRels);
        $contentTypes     = str_replace('</Types>', $newContentTypes . '</Types>', $contentTypes);

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
     * Slide XML — 4:3 format (9144000×6858000 EMU).
     *
     * Contains three elements:
     *   1. Explicit background (E8E8E8) matching the cover slide
     *   2. Procorad logo (top-left, position/size from reference PPTX)
     *   3. Chart graphic frame filling the content area below the logo
     *
     * Relationship IDs:
     *   rId1 → slideLayout1 (only layout available from pptxgenjs)
     *   rId2 → chart XML
     *   rId3 → logo PNG (ppt/media/logo.png)
     */
    private function buildSlide(string $title, string $chartRid, string $logoRid): string
    {
        $titleEsc = htmlspecialchars($title, ENT_XML1);
        $offX = self::CHART_X;
        $offY = self::CHART_Y;
        $cx   = self::CHART_CX;
        $cy   = self::CHART_CY;
        $lx   = self::LOGO_X;
        $ly   = self::LOGO_Y;
        $lcx  = self::LOGO_CX;
        $lcy  = self::LOGO_CY;
        $bg   = self::BG_COLOR;

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"
       xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"
       xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <p:cSld name="{$titleEsc}">
    <p:bg>
      <p:bgPr>
        <a:solidFill><a:srgbClr val="{$bg}"/></a:solidFill>
        <a:effectLst/>
      </p:bgPr>
    </p:bg>
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
      <p:pic>
        <p:nvPicPr>
          <p:cNvPr id="3" name="logo"/>
          <p:cNvPicPr><a:picLocks noChangeAspect="1"/></p:cNvPicPr>
          <p:nvPr/>
        </p:nvPicPr>
        <p:blipFill>
          <a:blip r:embed="{$logoRid}"/>
          <a:srcRect/>
          <a:stretch><a:fillRect/></a:stretch>
        </p:blipFill>
        <p:spPr>
          <a:xfrm><a:off x="{$lx}" y="{$ly}"/><a:ext cx="{$lcx}" cy="{$lcy}"/></a:xfrm>
          <a:prstGeom prst="rect"><a:avLst/></a:prstGeom>
        </p:spPr>
      </p:pic>
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
     * Slide relationships:
     *   rId1 → slideLayout1 (the only layout present in a pptxgenjs-generated file)
     *   rId2 → chart XML
     *   rId3 → logo PNG (ppt/media/logo.png)
     */
    private function buildSlideRels(int $chartIndex): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1"
    Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout"
    Target="../slideLayouts/slideLayout1.xml"/>
  <Relationship Id="rId2"
    Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart"
    Target="../charts/chart{$chartIndex}.xml"/>
  <Relationship Id="rId3"
    Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image"
    Target="../media/logo.png"/>
</Relationships>
XML;
    }
}
