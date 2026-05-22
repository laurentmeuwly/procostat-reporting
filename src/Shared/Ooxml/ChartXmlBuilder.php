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
  <c:spPr><a:solidFill><a:schemeClr val="bg1"/></a:solidFill></c:spPr>
  <c:externalData r:id="rId1"><c:autoUpdate val="0"/></c:externalData>
</c:chartSpace>
XML;
    }

    // ── Bar chart (scores: bias, zprime, zeta) ────────────────────────────────

    private function buildBarChart(GraphDefinition $graph): string
    {
        $fmt      = $this->floatFmt();
        $title    = htmlspecialchars($graph->title, ENT_XML1);
        $yLbl     = htmlspecialchars($graph->yAxisLabel, ENT_XML1);
        $yMax     = $fmt($graph->yMax);
        $yMin     = $fmt($graph->yMin);
        $catCache = $this->strCache($graph->categories);

        // Per-bar colouring: blue OK, orange warning, red action
        $bars = $this->buildColoredBars($graph->values, $graph->categories, $fmt);

        // Warning/action reference lines via scatter overlay
        $warn = 2.0;
        $act  = 3.0;

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
      <c:barChart>
        <c:barDir val="col"/>
        <c:grouping val="clustered"/>
        <c:varyColors val="0"/>
        {$bars}
        <c:dLbls><c:showLegendKey val="0"/><c:showVal val="0"/>
          <c:showCatName val="0"/><c:showSerName val="0"/>
          <c:showPercent val="0"/><c:showBubbleSize val="0"/></c:dLbls>
        <c:axId val="2001"/><c:axId val="2002"/>
      </c:barChart>
      <c:scatterChart>
        <c:scatterStyle val="lineMarker"/><c:varyColors val="0"/>
        {$this->scatterLine(0, $fmt($warn),  'FFA500', null)}
        {$this->scatterLine(1, $fmt(-$warn), 'FFA500', null)}
        {$this->scatterLine(2, $fmt($act),   'FF0000', null)}
        {$this->scatterLine(3, $fmt(-$act),  'FF0000', null)}
        <c:dLbls><c:showLegendKey val="0"/><c:showVal val="0"/>
          <c:showCatName val="0"/><c:showSerName val="0"/>
          <c:showPercent val="0"/><c:showBubbleSize val="0"/></c:dLbls>
        <c:axId val="2003"/><c:axId val="2004"/>
      </c:scatterChart>
      {$this->catAxis('2001', '2002')}
      {$this->valAxis('2002', '2001', $yMin, $yMax, $yLbl)}
      {$this->hiddenScatterAxes('2003', '2004')}
    </c:plotArea>
    <c:plotVisOnly val="1"/><c:dispBlanksAs val="gap"/>
  </c:chart>
  <c:spPr><a:solidFill><a:schemeClr val="bg1"/></a:solidFill></c:spPr>
  <c:externalData r:id="rId1"><c:autoUpdate val="0"/></c:externalData>
</c:chartSpace>
XML;
    }

    /**
     * Build a single bar series with per-bar fill colouring.
     * Blue = OK, Orange = warning (|v| ≥ 2), Red = action (|v| ≥ 3).
     */
    private function buildColoredBars(array $values, array $categories, callable $fmt): string
    {
        $n        = count($values);
        $catCache = $this->strCache($categories);
        $valCache = $this->numCache($values, $fmt);

        // Per-point colour overrides (<c:dPt>)
        $dPts = '';
        foreach ($values as $i => $v) {
            $abs   = abs($v);
            $color = $abs >= 3.0 ? 'FF0000' : ($abs >= 2.0 ? 'FFA500' : '4472C4');
            $dPts .= <<<XML
<c:dPt>
  <c:idx val="{$i}"/>
  <c:spPr><a:solidFill><a:srgbClr val="{$color}"/></a:solidFill>
    <a:ln><a:solidFill><a:srgbClr val="{$color}"/></a:solidFill></a:ln>
  </c:spPr>
</c:dPt>
XML;
        }

        return <<<XML
<c:ser>
  <c:idx val="0"/><c:order val="0"/>
  {$dPts}
  <c:cat><c:strRef><c:f/>{$catCache}</c:strRef></c:cat>
  <c:val><c:numRef><c:f/>{$valCache}</c:numRef></c:val>
</c:ser>
XML;
    }

    // ── Shared axis / line fragments ──────────────────────────────────────────

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

    private function catAxis(string $axId, string $crossAx): string
    {
        return <<<XML
<c:catAx>
  <c:axId val="{$axId}"/>
  <c:scaling><c:orientation val="minMax"/></c:scaling>
  <c:delete val="0"/><c:axPos val="b"/>
  <c:numFmt formatCode="General" sourceLinked="1"/>
  <c:majorTickMark val="none"/><c:minorTickMark val="none"/>
  <c:tickLblPos val="low"/>
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
