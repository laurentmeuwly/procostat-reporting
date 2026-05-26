<?php

namespace Procorad\ProcostatReporting\Shared\Ooxml;

use Procorad\ProcostatReporting\Excel\Ooxml\Definitions\GraphDefinition;

/**
 * Builds a complete OOXML <c:chartSpace> XML with inline data.
 *
 * Supports two chart styles:
 *   'results' — lineChart (categorical X, markers, error bars) + scatterChart overlay
 *               for assigned value / upper / lower dashed lines
 *   'bar'     — barChart (vertical bars, one per lab, coloured by score threshold)
 *               with horizontal reference lines at ±warning and ±action
 */
final class ChartXmlBuilder
{
    public function build(GraphDefinition $graph): string
    {
        return match ($graph->chartStyle) {
            'bar'     => $this->buildBarChart($graph),
            default   => $this->buildResultsChart($graph),
        };
    }

    // ── Results chart (line + scatter overlay) ────────────────────────────────

    private function buildResultsChart(GraphDefinition $graph): string
    {
        $fmt       = $this->floatFmt();
        $n         = count($graph->categories);
        $catCache  = $this->strCache($graph->categories);
        $valCache  = $this->numCache($graph->values, $fmt);
        $errCache  = $this->numCache($graph->errorBars, $fmt);
        $title     = htmlspecialchars($graph->title, ENT_XML1);
        $yLbl      = htmlspecialchars($graph->yAxisLabel, ENT_XML1);
        $yMax      = $fmt($graph->yMax);
        $av        = $fmt($graph->assignedValue);
        $avUp      = $fmt($graph->assignedUpper);
        $avLo      = $fmt($graph->assignedLower);

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<c:chartSpace
    xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart"
    xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"
    xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <c:date1904 val="0"/><c:lang val="fr-FR"/><c:roundedCorners val="0"/>
  <c:chart>
    <c:title><c:tx><c:rich><a:bodyPr/><a:lstStyle/>
      <a:p><a:pPr algn="ctr"><a:defRPr/></a:pPr>
        <a:r><a:rPr lang="fr-FR" sz="1400" b="0"/><a:t>{$title}</a:t></a:r>
      </a:p></c:rich></c:tx><c:layout/><c:overlay val="0"/></c:title>
    <c:autoTitleDeleted val="0"/>
    <c:plotArea><c:layout/>
      <c:lineChart>
        <c:grouping val="standard"/><c:varyColors val="0"/>
        <c:ser>
          <c:idx val="0"/><c:order val="0"/>
          <c:tx><c:strRef><c:f/><c:strCache><c:ptCount val="1"/>
            <c:pt idx="0"><c:v>{$title}</c:v></c:pt>
          </c:strCache></c:strRef></c:tx>
          <c:spPr><a:ln><a:noFill/></a:ln></c:spPr>
          <c:marker>
            <c:symbol val="circle"/><c:size val="7"/>
            <c:spPr><a:solidFill><a:srgbClr val="4472C4"/></a:solidFill>
              <a:ln><a:noFill/></a:ln></c:spPr>
          </c:marker>
          <c:errBars>
            <c:errBarType val="both"/><c:errValType val="cust"/><c:noEndCap val="0"/>
            <c:plus><c:numRef><c:f/>{$errCache}</c:numRef></c:plus>
            <c:minus><c:numRef><c:f/>{$errCache}</c:numRef></c:minus>
          </c:errBars>
          <c:cat><c:strRef><c:f/>{$catCache}</c:strRef></c:cat>
          <c:val><c:numRef><c:f/>{$valCache}</c:numRef></c:val>
          <c:smooth val="0"/>
        </c:ser>
        <c:dLbls><c:showLegendKey val="0"/><c:showVal val="0"/>
          <c:showCatName val="0"/><c:showSerName val="0"/>
          <c:showPercent val="0"/><c:showBubbleSize val="0"/></c:dLbls>
        <c:marker val="1"/><c:smooth val="0"/>
        <c:axId val="1001"/><c:axId val="1002"/>
      </c:lineChart>
      <c:scatterChart>
        <c:scatterStyle val="lineMarker"/><c:varyColors val="0"/>
        {$this->scatterLine(1, $av, 'FF0000', null)}
        {$this->scatterLine(2, $avUp, 'FF0000', 'dash')}
        {$this->scatterLine(3, $avLo, 'FF0000', 'dash')}
        <c:dLbls><c:showLegendKey val="0"/><c:showVal val="0"/>
          <c:showCatName val="0"/><c:showSerName val="0"/>
          <c:showPercent val="0"/><c:showBubbleSize val="0"/></c:dLbls>
        <c:axId val="1003"/><c:axId val="1004"/>
      </c:scatterChart>
      {$this->catAxis('1001', '1002')}
      {$this->valAxis('1002', '1001', '0', $yMax, $yLbl)}
      {$this->hiddenScatterAxes()}
    </c:plotArea>
    <c:plotVisOnly val="1"/><c:dispBlanksAs val="gap"/>
  </c:chart>
  <c:spPr><a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill></c:spPr>
  <c:externalData r:id="rId1"><c:autoUpdate val="0"/></c:externalData>
</c:chartSpace>
XML;
    }

    // ── Bar chart (scores: bias, zprime, zeta) ────────────────────────────────

    /**
     * Builds a barChart + optional lineChart overlay.
     *
     * PowerPoint compatibility rules (learned from reference 25CB-14C.pptx):
     *
     *   1. barChart and lineChart MUST use SEPARATE axis pairs, not shared ones.
     *      - barChart  → axId 2001 (catAx bottom) + 2002 (valAx left)
     *      - lineChart → axId 2003 (catAx top, hidden) + 2004 (valAx right, deleted)
     *
     *   2. The lineChart catAx must be a REAL catAx (not valAx), positioned at "t",
     *      with tickLblPos="none" so labels are invisible.
     *
     *   3. The lineChart valAx must be deleted (delete val="1") so it doesn't
     *      render a second Y axis — but it MUST share the same scaling as the
     *      barChart valAx so the threshold lines land at the right Y positions.
     *
     *   4. Threshold line series use <c:cat> pointing to the lineChart's catAx
     *      (same categories as barChart) and <c:val> with the constant threshold.
     *      They do NOT need numeric cat indices — string categories work fine.
     *
     *   5. bias chart: no thresholds, all bars plain blue (no per-bar colour).
     *      score charts (zprime, zeta): coloured bars + ±2/±3 threshold lines.
     */
    private function buildBarChart(GraphDefinition $graph): string
    {
        $fmt   = $this->floatFmt();
        $title = htmlspecialchars($graph->title, ENT_XML1);
        $yLbl  = htmlspecialchars($graph->yAxisLabel, ENT_XML1);
        $yMax  = $fmt($graph->yMax);
        $yMin  = $fmt($graph->yMin);

        if ($graph->showThresholds) {
            $bars = $this->buildColoredBars($graph->values, $graph->categories, $fmt);
        } else {
            // bias: all bars uniform blue, no per-bar colour override
            $bars = $this->buildUniformBars($graph->values, $graph->categories, $fmt);
        }

        if ($graph->showThresholds) {
            $warn = 2.0;
            $act  = 3.0;
            // Threshold series: use the same string categories as the barChart.
            // They reference the lineChart's own catAx (2003) so PowerPoint
            // keeps them visually aligned with the bars.
            $cats = $graph->categories;
            $thresholdSeries =
                $this->lineThresholdCat(1, $cats, $fmt($warn),  'FFA500', 'dash') .
                $this->lineThresholdCat(2, $cats, $fmt(-$warn), 'FFA500', 'dash') .
                $this->lineThresholdCat(3, $cats, $fmt($act),   'FF0000', 'lgDash') .
                $this->lineThresholdCat(4, $cats, $fmt(-$act),  'FF0000', 'lgDash');

            $lineChartBlock = <<<XML

      <c:lineChart>
        <c:grouping val="standard"/><c:varyColors val="0"/>
        {$thresholdSeries}
        <c:dLbls><c:showLegendKey val="0"/><c:showVal val="0"/>
          <c:showCatName val="0"/><c:showSerName val="0"/>
          <c:showPercent val="0"/><c:showBubbleSize val="0"/></c:dLbls>
        <c:marker val="0"/><c:smooth val="0"/>
        <c:axId val="2003"/><c:axId val="2004"/>
      </c:lineChart>
XML;
            // Hidden top catAx for lineChart (same categories, labels invisible)
            $lineCatAxis = <<<XML

      <c:catAx>
        <c:axId val="2003"/>
        <c:scaling><c:orientation val="minMax"/></c:scaling>
        <c:delete val="0"/><c:axPos val="t"/>
        <c:numFmt formatCode="General" sourceLinked="1"/>
        <c:majorTickMark val="none"/><c:minorTickMark val="none"/>
        <c:tickLblPos val="none"/>
        <c:crossAx val="2004"/><c:crosses val="max"/>
        <c:auto val="0"/><c:lblAlgn val="ctr"/><c:lblOffset val="100"/>
        <c:noMultiLvlLbl val="0"/>
      </c:catAx>
XML;
            // Hidden right valAx for lineChart — same scale as barChart valAx
            // so threshold values land at the correct Y positions
            $lineValAxis = <<<XML

      <c:valAx>
        <c:axId val="2004"/>
        <c:scaling>
          <c:orientation val="minMax"/>
          <c:max val="{$yMax}"/>
          <c:min val="{$yMin}"/>
        </c:scaling>
        <c:delete val="1"/><c:axPos val="r"/>
        <c:numFmt formatCode="General" sourceLinked="0"/>
        <c:majorTickMark val="none"/><c:minorTickMark val="none"/>
        <c:tickLblPos val="none"/>
        <c:crossAx val="2003"/><c:crosses val="max"/>
        <c:crossBetween val="between"/>
      </c:valAx>
XML;
        } else {
            $lineChartBlock = '';
            $lineCatAxis    = '';
            $lineValAxis    = '';
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<c:chartSpace
    xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart"
    xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"
    xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <c:date1904 val="0"/><c:lang val="fr-FR"/><c:roundedCorners val="0"/>
  <c:chart>
    <c:title><c:tx><c:rich><a:bodyPr/><a:lstStyle/>
      <a:p><a:pPr algn="ctr"><a:defRPr/></a:pPr>
        <a:r><a:rPr lang="fr-FR" sz="1400" b="1"/><a:t>{$title}</a:t></a:r>
      </a:p></c:rich></c:tx><c:layout/><c:overlay val="0"/></c:title>
    <c:autoTitleDeleted val="0"/>
    <c:plotArea><c:layout/>
      <c:barChart>
        <c:barDir val="col"/>
        <c:grouping val="clustered"/>
        <c:varyColors val="0"/>
        {$bars}
        <c:dLbls><c:showLegendKey val="0"/><c:showVal val="0"/>
          <c:showCatName val="0"/><c:showSerName val="0"/>
          <c:showPercent val="0"/><c:showBubbleSize val="0"/></c:dLbls>
        <c:gapWidth val="100"/>
        <c:axId val="2001"/><c:axId val="2002"/>
      </c:barChart>{$lineChartBlock}
      {$this->catAxis('2001', '2002', true)}
      {$this->valAxis('2002', '2001', $yMin, $yMax, $yLbl)}{$lineCatAxis}{$lineValAxis}
    </c:plotArea>
    <c:plotVisOnly val="1"/><c:dispBlanksAs val="gap"/>
  </c:chart>
  <c:spPr><a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill></c:spPr>
  <c:externalData r:id="rId1"><c:autoUpdate val="0"/></c:externalData>
</c:chartSpace>
XML;
    }

    /**
     * Bar series with per-bar colour based on threshold (|v| ≥ 3 red, ≥ 2 orange, else blue).
     * Used for z-prime and zeta score charts.
     * - invertIfNegative val="0" keeps negative bars solid (same colour, not inverted/hollow)
     * - showCatName val="1" on series dLbls displays lab numbers above/below each bar
     */
    private function buildColoredBars(array $values, array $categories, callable $fmt): string
    {
        $catCache = $this->strCache($categories);
        $valCache = $this->numCache($values, $fmt);

        $dPts = '';
        foreach ($values as $i => $v) {
            $abs   = abs($v);
            $color = $abs >= 3.0 ? 'FF0000' : ($abs >= 2.0 ? 'FFA500' : '4472C4');
            $dPts .= <<<XML
<c:dPt>
  <c:idx val="{$i}"/>
  <c:invertIfNegative val="0"/>
  <c:spPr><a:solidFill><a:srgbClr val="{$color}"/></a:solidFill>
    <a:ln><a:solidFill><a:srgbClr val="{$color}"/></a:solidFill></a:ln>
  </c:spPr>
</c:dPt>
XML;
        }

        return <<<XML
<c:ser>
  <c:idx val="0"/><c:order val="0"/>
  <c:invertIfNegative val="0"/>
  {$dPts}
  <c:dLbls>
    <c:txPr><a:bodyPr rot="0" vert="horz"/><a:lstStyle/>
      <a:p><a:pPr algn="ctr"><a:defRPr lang="fr-FR" sz="1000" b="1"/></a:pPr><a:endParaRPr lang="fr-FR"/></a:p>
    </c:txPr>
    <c:showLegendKey val="0"/><c:showVal val="0"/>
    <c:showCatName val="1"/><c:showSerName val="0"/>
    <c:showPercent val="0"/><c:showBubbleSize val="0"/>
    <c:showLeaderLines val="0"/>
  </c:dLbls>
  <c:cat><c:strRef><c:f/>{$catCache}</c:strRef></c:cat>
  <c:val><c:numRef><c:f/>{$valCache}</c:numRef></c:val>
</c:ser>
XML;
    }

    /**
     * Bar series with uniform blue — used for bias chart (no threshold colouring).
     * - invertIfNegative val="0" keeps negative bars solid blue (not inverted/hollow)
     * - showCatName val="1" on series dLbls displays lab numbers above/below each bar
     */
    private function buildUniformBars(array $values, array $categories, callable $fmt): string
    {
        $catCache = $this->strCache($categories);
        $valCache = $this->numCache($values, $fmt);

        return <<<XML
<c:ser>
  <c:idx val="0"/><c:order val="0"/>
  <c:spPr>
    <a:solidFill><a:srgbClr val="4472C4"/></a:solidFill>
    <a:ln><a:solidFill><a:srgbClr val="4472C4"/></a:solidFill></a:ln>
  </c:spPr>
  <c:invertIfNegative val="0"/>
  <c:dLbls>
    <c:txPr><a:bodyPr rot="0" vert="horz"/><a:lstStyle/>
      <a:p><a:pPr algn="ctr"><a:defRPr lang="fr-FR" sz="1000" b="1"/></a:pPr><a:endParaRPr lang="fr-FR"/></a:p>
    </c:txPr>
    <c:showLegendKey val="0"/><c:showVal val="0"/>
    <c:showCatName val="1"/><c:showSerName val="0"/>
    <c:showPercent val="0"/><c:showBubbleSize val="0"/>
    <c:showLeaderLines val="0"/>
  </c:dLbls>
  <c:cat><c:strRef><c:f/>{$catCache}</c:strRef></c:cat>
  <c:val><c:numRef><c:f/>{$valCache}</c:numRef></c:val>
</c:ser>
XML;
    }

    // ── Shared axis / line fragments ──────────────────────────────────────────

    /**
     * A lineChart threshold series using the same string categories as the barChart.
     * References the lineChart's own separate catAx (2003/2004) so PowerPoint accepts
     * the barChart + lineChart combo without rejecting the file.
     *
     * Pattern taken directly from reference PPTX (25CB-14C.pptx chart4.xml):
     *   - <c:cat> uses string categories matching the barChart
     *   - <c:val> is a constant repeated for every category
     *   - Axes 2003 (catAx top hidden) + 2004 (valAx right deleted) are separate from
     *     barChart's 2001/2002 — this is the key to PowerPoint compatibility.
     */
    private function lineThresholdCat(int $idx, array $categories, string $yVal, string $color, string $dash): string
    {
        $n        = count($categories);
        $catCache = $this->strCache($categories);
        $valPts   = '';
        for ($i = 0; $i < $n; $i++) {
            $valPts .= "<c:pt idx=\"{$i}\"><c:v>{$yVal}</c:v></c:pt>";
        }
        $valCache = "<c:numCache><c:formatCode>General</c:formatCode><c:ptCount val=\"{$n}\"/>{$valPts}</c:numCache>";

        return <<<XML
<c:ser>
  <c:idx val="{$idx}"/><c:order val="{$idx}"/>
  <c:spPr><a:ln w="19050" cap="rnd" cmpd="sng">
    <a:solidFill><a:srgbClr val="{$color}"/></a:solidFill>
    <a:prstDash val="{$dash}"/>
    <a:round/>
  </a:ln></c:spPr>
  <c:marker><c:symbol val="none"/></c:marker>
  <c:cat><c:strRef><c:f/>{$catCache}</c:strRef></c:cat>
  <c:val><c:numRef><c:f/>{$valCache}</c:numRef></c:val>
  <c:smooth val="0"/>
</c:ser>
XML;
    }

    private function catAxis(string $axId, string $crossAx, bool $hideLabels = false): string
    {
        $lblPos = $hideLabels ? 'none' : 'low';
        return <<<XML
<c:catAx>
  <c:axId val="{$axId}"/>
  <c:scaling><c:orientation val="minMax"/></c:scaling>
  <c:delete val="0"/><c:axPos val="b"/>
  <c:numFmt formatCode="General" sourceLinked="1"/>
  <c:majorTickMark val="none"/><c:minorTickMark val="none"/>
  <c:tickLblPos val="{$lblPos}"/>
  <c:txPr><a:bodyPr/><a:lstStyle/>
    <a:p><a:pPr><a:defRPr lang="fr-FR" sz="1000" b="1">
      <a:latin typeface="Calibri"/>
    </a:defRPr></a:pPr></a:p>
  </c:txPr>
  <c:crossAx val="{$crossAx}"/>
  <c:crosses val="autoZero"/>
  <c:auto val="0"/><c:lblAlgn val="ctr"/><c:lblOffset val="100"/>
  <c:noMultiLvlLbl val="0"/>
</c:catAx>
XML;
    }

    private function valAxis(string $axId, string $crossAx, string $yMin, string $yMax, string $label): string
    {
        $labelXml = $label !== '' ? <<<LBLXML
<c:title><c:tx><c:rich><a:bodyPr rot="-5400000"/><a:lstStyle/>
  <a:p><a:r><a:rPr lang="fr-FR" sz="1000" b="1"/><a:t>{$label}</a:t></a:r></a:p>
</c:rich></c:tx><c:layout/><c:overlay val="0"/></c:title>
LBLXML : '';

        return <<<XML
<c:valAx>
  <c:axId val="{$axId}"/>
  <c:scaling>
    <c:orientation val="minMax"/>
    <c:max val="{$yMax}"/>
    <c:min val="{$yMin}"/>
  </c:scaling>
  <c:delete val="0"/><c:axPos val="l"/>
  <c:majorGridlines/>
  {$labelXml}
  <c:numFmt formatCode="General" sourceLinked="0"/>
  <c:majorTickMark val="out"/><c:minorTickMark val="none"/>
  <c:tickLblPos val="nextTo"/>
  <c:txPr><a:bodyPr/><a:lstStyle/>
    <a:p><a:pPr><a:defRPr lang="fr-FR" sz="1000" b="1">
      <a:latin typeface="Calibri"/>
    </a:defRPr></a:pPr></a:p>
  </c:txPr>
  <c:crossAx val="{$crossAx}"/>
  <c:crosses val="autoZero"/>
  <c:crossBetween val="between"/>
</c:valAx>
XML;
    }

    /**
     * A scatterChart series for the results chart (lineChart primary axis).
     * Spans x=0 to x=1 at a constant y value — used for assigned value lines.
     * Only valid when the primary chart is lineChart (not barChart).
     */
    private function scatterLine(int $idx, string $yVal, string $color, ?string $dash): string
    {
        $dashEl = $dash ? "<a:prstDash val=\"{$dash}\"/>" : '';
        return <<<XML
<c:ser>
  <c:idx val="{$idx}"/><c:order val="{$idx}"/>
  <c:spPr><a:ln w="12700" cmpd="sng">
    <a:solidFill><a:srgbClr val="{$color}"/></a:solidFill>{$dashEl}
  </a:ln></c:spPr>
  <c:marker><c:symbol val="none"/></c:marker>
  <c:xVal><c:numRef><c:f/><c:numCache>
    <c:formatCode>General</c:formatCode><c:ptCount val="2"/>
    <c:pt idx="0"><c:v>0</c:v></c:pt><c:pt idx="1"><c:v>1</c:v></c:pt>
  </c:numCache></c:numRef></c:xVal>
  <c:yVal><c:numRef><c:f/><c:numCache>
    <c:formatCode>General</c:formatCode><c:ptCount val="2"/>
    <c:pt idx="0"><c:v>{$yVal}</c:v></c:pt><c:pt idx="1"><c:v>{$yVal}</c:v></c:pt>
  </c:numCache></c:numRef></c:yVal>
  <c:smooth val="0"/>
</c:ser>
XML;
    }

    private function hiddenScatterAxes(string $xId = '1003', string $yId = '1004'): string
    {
        return <<<XML
<c:valAx>
  <c:axId val="{$xId}"/>
  <c:scaling><c:orientation val="minMax"/><c:max val="1"/><c:min val="0"/></c:scaling>
  <c:delete val="1"/><c:axPos val="t"/>
  <c:numFmt formatCode="General" sourceLinked="1"/>
  <c:majorTickMark val="none"/><c:minorTickMark val="none"/>
  <c:tickLblPos val="none"/>
  <c:crossAx val="{$yId}"/><c:crosses val="max"/><c:crossBetween val="midCat"/>
</c:valAx>
<c:valAx>
  <c:axId val="{$yId}"/>
  <c:scaling><c:orientation val="minMax"/></c:scaling>
  <c:delete val="1"/><c:axPos val="r"/>
  <c:numFmt formatCode="General" sourceLinked="1"/>
  <c:majorTickMark val="none"/><c:minorTickMark val="none"/>
  <c:tickLblPos val="none"/>
  <c:crossAx val="{$xId}"/><c:crosses val="max"/><c:crossBetween val="midCat"/>
</c:valAx>
XML;
    }

    // ── Cache builders ────────────────────────────────────────────────────────

    private function strCache(array $items): string
    {
        $n   = count($items);
        $pts = '';
        foreach ($items as $i => $v) {
            $pts .= '<c:pt idx="' . $i . '"><c:v>' . htmlspecialchars((string)$v, ENT_XML1) . '</c:v></c:pt>';
        }
        return "<c:strCache><c:ptCount val=\"{$n}\"/>{$pts}</c:strCache>";
    }

    private function numCache(array $items, callable $fmt): string
    {
        $n   = count($items);
        $pts = '';
        foreach ($items as $i => $v) {
            $pts .= '<c:pt idx="' . $i . '"><c:v>' . $fmt((float)$v) . '</c:v></c:pt>';
        }
        return "<c:numCache><c:formatCode>General</c:formatCode><c:ptCount val=\"{$n}\"/>{$pts}</c:numCache>";
    }

    private function floatFmt(): \Closure
    {
        return fn(float $v): string => rtrim(rtrim(sprintf('%.10f', $v), '0'), '.') ?: '0';
    }
}
