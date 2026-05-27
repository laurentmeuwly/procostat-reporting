<?php

namespace Procorad\ProcostatReporting\Excel\Patches;

use Procorad\ProcostatReporting\Excel\Ooxml\ChartDocument;
use Procorad\ProcostatReporting\Excel\Ooxml\Definitions\LineDefinition;
use Procorad\ProcostatReporting\Excel\Ooxml\Definitions\MarkerDefinition;
use Procorad\ProcostatReporting\Excel\Support\ExcelColors;
use Procorad\ProcostatReporting\Excel\Support\ExcelLayout;

/**
 * Post-generation OOXML patch for bar chart score sheets.
 *
 * 1. Bar coloring via <c:dPt> injection.
 *    |v| > thresholdHigh or < thresholdLow → red
 *    |v| > 2 (symmetric charts only) → orange
 *    otherwise → default blue (no override)
 *
 * 2. Category labels at bar tips (showCatName=1 on series dLbls, dLblPos=outEnd).
 *
 * 3. Y axis scaling to always show threshold lines.
 *
 * 4. Reference line overlay (lineChart in same plotArea) using the barChart's
 *    own string category cache so lines align with bars.
 */
final class BarChartPatcher
{
    /**
     * @param float[] $barValues      Score/bias values sorted in chart order
     * @param float   $thresholdLow   Lower action threshold (default -3 for scores, -25 for bias)
     * @param float   $thresholdHigh  Upper action threshold (default +3 for scores, +50 for bias)
     */
    public function patch(
        ChartDocument $doc,
        int           $chartIndex,
        string        $sheetName,
        bool          $withThresholds = false,
        array         $barValues      = [],
        float         $thresholdLow   = -3.0,
        float         $thresholdHigh  = 3.0,
    ): void {
        // All XML work is done directly on the ChartDocument's in-memory XML,
        // then flushed via doc->save() once. This avoids the overwrite race where
        // a direct ZipArchive write would be stomped by a subsequent doc->save().
        try {
            $chartContext = $doc->chart($chartIndex);
        } catch (\InvalidArgumentException) {
            return;
        }

        // Step 1: use the fluent API to set line/marker (modifies in-memory XML only)
        $chartContext
            ->series(0)
                ->setLine(LineDefinition::solid('4472C4', 0))
                ->setMarker(MarkerDefinition::none())
            ->flush(); // push DOM → in-memory XML without writing to disk

        // Step 2: apply all regex-based patches directly to the in-memory XML
        $xml = $doc->getRawXml($chartIndex);
        $xml = $this->injectBarColors($xml, $barValues, $thresholdLow, $thresholdHigh);
        $xml = $this->injectCatLabels($xml);
        $xml = $this->injectYAxisScale($xml, $barValues, $thresholdLow, $thresholdHigh);
        $xml = $this->injectThresholdLines($xml, $withThresholds, $barValues, $thresholdLow, $thresholdHigh);
        $doc->setRawXml($chartIndex, $xml);

        // Note: do NOT call doc->save() here — the caller (ExcelDocumentGenerator)
        // is responsible for calling $doc->save() once after all patches are applied.
    }

    /**
     * Compute Y axis bounds that always encompass the thresholds plus headroom.
     * For bias (-25/+50): minimum range is [-30, +60].
     */
    private function yBounds(array $barValues, float $thresholdLow, float $thresholdHigh): array
    {
        $dataMin = empty($barValues) ? 0.0 : min(array_map('floatval', $barValues));
        $dataMax = empty($barValues) ? 0.0 : max(array_map('floatval', $barValues));
        $yMin = min($dataMin * 1.1, $thresholdLow  * 1.2);
        $yMax = max($dataMax * 1.1, $thresholdHigh * 1.2);
        $yMin = (float) (floor($yMin / 5) * 5);
        $yMax = (float) (ceil($yMax  / 5) * 5);
        return [$yMin, $yMax];
    }

    // ── Bar coloring ──────────────────────────────────────────────────────────

    private function injectBarColors(string $xml, array $barValues, float $thresholdLow, float $thresholdHigh): string
    {
        if (empty($barValues)) return $xml;

        $isSymmetric = (abs($thresholdLow) === abs($thresholdHigh));

        $dPtXml = '';
        foreach ($barValues as $idx => $value) {
            $v = (float) $value;
            if ($v < $thresholdLow || $v > $thresholdHigh) {
                $color = ExcelColors::RED;
            } elseif ($isSymmetric && abs($v) > 2.0) {
                $color = ExcelColors::ORANGE;
            } else {
                continue;
            }

            $dPtXml .=
                "<c:dPt>" .
                "<c:idx val=\"{$idx}\"/>" .
                "<c:invertIfNegative val=\"0\"/>" .
                "<c:spPr>" .
                "<a:solidFill><a:srgbClr val=\"{$color}\"/></a:solidFill>" .
                "</c:spPr>" .
                "</c:dPt>";
        }

        if ($dPtXml === '') return $xml;

        return (string) preg_replace(
            '/(<c:ser>(\s*<c:idx[^\/]*\/>)(\s*<c:order[^\/]*\/>))/s',
            '$1' . $dPtXml,
            $xml,
            1,
        );
    }

    // ── Category labels at bar tips ───────────────────────────────────────────

    private function injectCatLabels(string $xml): string
    {
        $dLbls =
            '<c:dLbls>' .
            '<c:txPr><a:bodyPr rot="0" vert="horz"/><a:lstStyle/>' .
            '<a:p><a:pPr algn="ctr"><a:defRPr lang="fr-FR" sz="900" b="1"/></a:pPr>' .
            '<a:endParaRPr lang="fr-FR"/></a:p></c:txPr>' .
            '<c:showLegendKey val="0"/><c:showVal val="0"/>' .
            '<c:showCatName val="1"/><c:showSerName val="0"/>' .
            '<c:showPercent val="0"/><c:showBubbleSize val="0"/>' .
            '<c:dLblPos val="outEnd"/>' .
            '<c:showLeaderLines val="0"/>' .
            '</c:dLbls>';

        if (str_contains($xml, '<c:dLbls>')) {
            // Replace the first existing dLbls (inside the bar series)
            return (string) preg_replace('/<c:dLbls>.*?<\/c:dLbls>/s', $dLbls, $xml, 1);
        }

        // No dLbls present — insert after </c:val> inside the first <c:ser>
        return (string) preg_replace(
            '/(<\/c:val>)(<\/c:ser>)/s',
            '$1' . $dLbls . '$2',
            $xml,
            1,
        );
    }

    // ── Y axis scale ──────────────────────────────────────────────────────────

    private function injectYAxisScale(string $xml, array $barValues, float $thresholdLow, float $thresholdHigh): string
    {
        if (empty($barValues)) return $xml;

        [$yMin, $yMax] = $this->yBounds($barValues, $thresholdLow, $thresholdHigh);

        $scalingXml =
            "<c:scaling>" .
            "<c:orientation val=\"minMax\"/>" .
            "<c:max val=\"{$yMax}\"/>" .
            "<c:min val=\"{$yMin}\"/>" .
            "</c:scaling>";

        return (string) preg_replace(
            '/(<c:valAx>.*?)<c:scaling>.*?<\/c:scaling>/s',
            '$1' . $scalingXml,
            $xml,
            1,
        );
    }

    // ── Reference line overlay ────────────────────────────────────────────────

    private function injectThresholdLines(string $xml, bool $withThresholds, array $barValues, float $thresholdLow, float $thresholdHigh): string
    {
        if (! $withThresholds || empty($barValues)) return $xml;

        // Reuse the barChart's string category cache so the lines align with the bars.
        // This mirrors exactly what ChartXmlBuilder does for the PPTX charts.
        preg_match('/<c:cat>.*?<\/c:cat>/s', $xml, $catMatch);
        $catXml = $catMatch[0] ?? '';
        if ($catXml === '') return $xml;

        $n = count($barValues);

        // Unique axis IDs that don't conflict with existing ones
        preg_match_all('/<c:axId val="(\d+)"/', $xml, $m);
        $usedMax   = empty($m[1]) ? 2000 : max(array_map('intval', $m[1]));
        $lineAxCat = $usedMax + 1;
        $lineAxVal = $usedMax + 2;

        $isSymmetric = (abs($thresholdLow) === abs($thresholdHigh));
        [$yMin, $yMax] = $this->yBounds($barValues, $thresholdLow, $thresholdHigh);

        $makeValCache = function (float $yVal) use ($n): string {
            $pts = '';
            for ($i = 0; $i < $n; $i++) {
                $pts .= "<c:pt idx=\"{$i}\"><c:v>{$yVal}</c:v></c:pt>";
            }
            return
                '<c:numRef><c:f/><c:numCache>' .
                '<c:formatCode>General</c:formatCode>' .
                "<c:ptCount val=\"{$n}\"/>{$pts}" .
                '</c:numCache></c:numRef>';
        };

        $makeLineSer = function (int $idx, float $yVal, string $color, string $dashType) use ($catXml, $makeValCache): string {
            return
                "<c:ser>" .
                "<c:idx val=\"{$idx}\"/><c:order val=\"{$idx}\"/>" .
                "<c:spPr><a:ln w=\"19050\" cap=\"rnd\" cmpd=\"sng\">" .
                "<a:solidFill><a:srgbClr val=\"{$color}\"/></a:solidFill>" .
                "<a:prstDash val=\"{$dashType}\"/><a:round/>" .
                "</a:ln></c:spPr>" .
                "<c:marker><c:symbol val=\"none\"/></c:marker>" .
                $catXml .
                "<c:val>" . $makeValCache($yVal) . "</c:val>" .
                "<c:smooth val=\"0\"/>" .
                "</c:ser>";
        };

        $series = $makeLineSer(1, $thresholdHigh, 'FF0000', 'lgDash') .
                  $makeLineSer(2, $thresholdLow,  'FF0000', 'lgDash');
        if ($isSymmetric) {
            $series .= $makeLineSer(3,  2.0, 'FFA500', 'lgDash') .
                       $makeLineSer(4, -2.0, 'FFA500', 'lgDash');
        } elseif ($thresholdHigh === 50.0) {
            // Bias chart: add warning line at -25% lower only (no symmetric orange)
            // Nothing extra needed for bias — only red lines at -25/+50
        }

        $lineChart =
            "<c:lineChart>" .
            "<c:grouping val=\"standard\"/><c:varyColors val=\"0\"/>" .
            $series .
            "<c:smooth val=\"0\"/>" .
            "<c:axId val=\"{$lineAxCat}\"/><c:axId val=\"{$lineAxVal}\"/>" .
            "</c:lineChart>";

        $axes =
            "<c:catAx>" .
            "<c:axId val=\"{$lineAxCat}\"/>" .
            "<c:scaling><c:orientation val=\"minMax\"/></c:scaling>" .
            "<c:delete val=\"1\"/><c:axPos val=\"b\"/>" .
            "<c:numFmt formatCode=\"General\" sourceLinked=\"1\"/>" .
            "<c:majorTickMark val=\"none\"/><c:minorTickMark val=\"none\"/>" .
            "<c:tickLblPos val=\"none\"/>" .
            "<c:crossAx val=\"{$lineAxVal}\"/><c:crosses val=\"autoZero\"/>" .
            "</c:catAx>" .
            "<c:valAx>" .
            "<c:axId val=\"{$lineAxVal}\"/>" .
            "<c:scaling>" .
            "<c:orientation val=\"minMax\"/>" .
            "<c:max val=\"{$yMax}\"/><c:min val=\"{$yMin}\"/>" .
            "</c:scaling>" .
            "<c:delete val=\"1\"/><c:axPos val=\"r\"/>" .
            "<c:numFmt formatCode=\"General\" sourceLinked=\"0\"/>" .
            "<c:majorTickMark val=\"none\"/><c:minorTickMark val=\"none\"/>" .
            "<c:tickLblPos val=\"none\"/>" .
            "<c:crossAx val=\"{$lineAxCat}\"/><c:crosses val=\"max\"/>" .
            "<c:crossBetween val=\"between\"/>" .
            "</c:valAx>";

        return str_replace('</c:plotArea>', $lineChart . $axes . '</c:plotArea>', $xml);
    }
}
