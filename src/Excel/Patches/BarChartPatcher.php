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
 * Two independent operations, both optional:
 *
 * 1. Bar coloring (<c:dPt> injection)
 *    Individual bars are colored based on absolute score value:
 *      |v| > 3 → red    (ExcelColors::RED)
 *      |v| > 2 → orange (ExcelColors::ORANGE)
 *      otherwise → default blue (no override)
 *
 * 2. Reference line overlay (secondary <c:lineChart> injected into <c:plotArea>)
 *    Four horizontal lines sourced from cols D-G of the sheet (written by BarChartSheetBuilder):
 *      D  +2  orange dashed  (warning upper)
 *      E  -2  orange dashed  (warning lower)
 *      F  +3  red dashed     (action upper)
 *      G  -3  red dashed     (action lower)
 *    Lines use hidden axes (IDs 200/201) so they don't interfere with the bar chart axes.
 */
final class BarChartPatcher
{
    /**
     * Apply the base series style (uniform blue bars, no markers).
     * Then optionally inject threshold lines and/or color individual bars.
     *
     * @param float[] $barValues  Score values sorted in chart order (same as BarChartSheetBuilder sort)
     */
    public function patch(
        ChartDocument $doc,
        int           $chartIndex,
        string        $sheetName,
        bool          $withThresholds = false,
        array         $barValues      = [],
    ): void {
        try {
            $doc->chart($chartIndex)
                ->series(0)
                    ->setLine(LineDefinition::solid('4472C4', 0))
                    ->setMarker(MarkerDefinition::none())
                ->save();
        } catch (\InvalidArgumentException) {
            return;
        }

        $this->applyToZip($doc->getXlsxPath(), $chartIndex, $sheetName, $withThresholds, $barValues);
    }

    // ── Internal ZIP/XML manipulation ─────────────────────────────────────────

    private function applyToZip(
        string $xlsxPath,
        int    $chartIndex,
        string $sheetName,
        bool   $withThresholds,
        array  $barValues,
    ): void {
        $zip = new \ZipArchive();
        if ($zip->open($xlsxPath) !== true) return;

        $chartFile = $this->chartFile($zip, $chartIndex);
        if ($chartFile === null) { $zip->close(); return; }

        $xml = $zip->getFromName($chartFile);

        $xml = $this->injectBarColors($xml, $barValues);
        $xml = $this->injectThresholdLines($xml, $sheetName, $withThresholds);

        $zip->addFromString($chartFile, $xml);
        $zip->close();
    }

    // ── Bar coloring ──────────────────────────────────────────────────────────

    /**
     * Inject <c:dPt> elements into series 0 for bars exceeding score thresholds.
     *
     * @param float[] $barValues
     */
    private function injectBarColors(string $xml, array $barValues): string
    {
        if (empty($barValues)) return $xml;

        $dPtXml = '';
        foreach ($barValues as $idx => $value) {
            $abs   = abs((float) $value);
            $color = match (true) {
                $abs > 3.0 => ExcelColors::RED,
                $abs > 2.0 => ExcelColors::ORANGE,
                default    => null,
            };
            if ($color === null) continue;

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

        // Insert after <c:order> in the first <c:ser> of the barChart
        return (string) preg_replace(
            '/(<c:ser>(\s*<c:idx[^\/]*\/>)(\s*<c:order[^\/]*\/>))/s',
            '$1' . $dPtXml,
            $xml,
            1,
        );
    }

    // ── Reference line overlay ────────────────────────────────────────────────

    private function injectThresholdLines(string $xml, string $sheetName, bool $withThresholds): string
    {
        if (! $withThresholds) return $xml;

        $r1 = (string)(ExcelLayout::TABLE_START_ROW + 1);
        $r2 = (string)(ExcelLayout::TABLE_START_ROW + 100); // EMPTY_AS_GAP ignores blanks

        $makeSer = function (int $idx, string $col, string $color) use ($r1, $r2, $sheetName): string {
            return
                "<c:ser>" .
                "<c:idx val=\"{$idx}\"/><c:order val=\"{$idx}\"/>" .
                "<c:spPr><a:ln w=\"19050\">" .
                "<a:solidFill><a:srgbClr val=\"{$color}\"/></a:solidFill>" .
                "<a:prstDash val=\"dash\"/>" .
                "</a:ln></c:spPr>" .
                "<c:marker><c:symbol val=\"none\"/></c:marker>" .
                "<c:val><c:numRef><c:f>'{$sheetName}'!\${$col}\${$r1}:'{$sheetName}'!\${$col}\${$r2}</c:f></c:numRef></c:val>" .
                "</c:ser>";
        };

        $lineChart =
            "<c:lineChart>" .
            "<c:grouping val=\"standard\"/>" .
            $makeSer(1, 'D', ExcelColors::ORANGE) .   // +2 orange dashed
            $makeSer(2, 'E', ExcelColors::ORANGE) .   // -2 orange dashed
            $makeSer(3, 'F', ExcelColors::RED) .      // +3 red dashed
            $makeSer(4, 'G', ExcelColors::RED) .      // -3 red dashed
            "<c:axId val=\"200\"/><c:axId val=\"201\"/>" .
            "</c:lineChart>";

        // Hidden axes for the overlay (IDs 200/201 — distinct from bar chart axes)
        $axes =
            "<c:valAx>" .
            "<c:axId val=\"201\"/>" .
            "<c:scaling><c:orientation val=\"minMax\"/></c:scaling>" .
            "<c:delete val=\"1\"/><c:axPos val=\"l\"/><c:crossAx val=\"200\"/>" .
            "</c:valAx>" .
            "<c:catAx>" .
            "<c:axId val=\"200\"/>" .
            "<c:scaling><c:orientation val=\"minMax\"/></c:scaling>" .
            "<c:delete val=\"1\"/><c:axPos val=\"b\"/><c:crossAx val=\"201\"/>" .
            "</c:catAx>";

        return str_replace('</c:plotArea>', $lineChart . $axes . '</c:plotArea>', $xml);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

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
