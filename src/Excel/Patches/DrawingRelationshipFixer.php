<?php

namespace Procorad\ProcostatReporting\Excel\Patches;


/**
 * Repairs xl/drawings/drawingN.xml files for Excel compatibility.
 *
 * PhpSpreadsheet sometimes generates <xdr:twoCellAnchor> nodes that lack a
 * proper <xdr:graphicFrame> wrapper for chart references. Excel validates these
 * on open and silently removes the offending drawing part, causing the chart to
 * disappear. LibreOffice is permissive and renders the file without complaint.
 *
 * For every drawing file that contains chart relationships but has anchors
 * without a graphicFrame, this patcher rebuilds the full drawing XML from
 * scratch using the chart relationship IDs, placing each chart at A1:M26
 * (twoCellAnchor). Column widths on score sheets are fixed by the sheet
 * builders so the chart always renders at a consistent physical size.
 */
final class DrawingRelationshipFixer
{
    private const NS_DRAWING = 'http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing';
    private const NS_MAIN    = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    private const NS_REL     = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const TYPE_CHART = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart';

    public function fix(string $xlsxPath): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($xlsxPath) !== true) return;

        $drawingFiles = $this->findDrawingFiles($zip);

        foreach ($drawingFiles as $drawingFile) {
            $this->fixDrawingFile($zip, $drawingFile);
        }

        $zip->close();
    }

    // ── Per-drawing fix ───────────────────────────────────────────────────────

    private function fixDrawingFile(\ZipArchive $zip, string $drawingFile): void
    {
        $xml    = $zip->getFromName($drawingFile);
        $relXml = $zip->getFromName('xl/drawings/_rels/' . basename($drawingFile) . '.rels');

        if ($xml === false || $relXml === false) return;

        $chartRels = $this->chartRelationships($relXml);
        if (empty($chartRels)) return;

        if (! $this->needsRebuild($xml)) return;

        $zip->addFromString($drawingFile, $this->buildDrawingXml($chartRels));
    }

    // ── Detection ─────────────────────────────────────────────────────────────

    private function needsRebuild(string $xml): bool
    {
        $dom = new \DOMDocument();
        if (! @$dom->loadXML($xml)) return true;

        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('xdr', self::NS_DRAWING);

        foreach ($xp->query('//xdr:twoCellAnchor') as $anchor) {
            $frames = $xp->query('xdr:graphicFrame', $anchor);
            if ($frames === false || $frames->length === 0) return true;
        }

        return false;
    }

    // ── Rebuild ───────────────────────────────────────────────────────────────

    /**
     * Build a minimal valid drawing XML with one twoCellAnchor per chart relationship.
     * Each chart is anchored at A1:M26 with a twoCellAnchor.
     *
     * @param array<string,string> $chartRels  Map of rId → target path
     */
    private function buildDrawingXml(array $chartRels): string
    {
        $ns  = self::NS_DRAWING;
        $ans = self::NS_MAIN;
        $rns = self::NS_REL;

        $dom  = new \DOMDocument('1.0', 'UTF-8');
        $root = $dom->createElementNS($ns, 'xdr:wsDr');
        $root->setAttribute('xmlns:a',   $ans);
        $root->setAttribute('xmlns:r',   $rns);
        $root->setAttribute('xmlns:xdr', $ns);
        $dom->appendChild($root);

        foreach (array_values($chartRels) as $idx => $rId) {
            $root->appendChild($this->buildAnchor($dom, $rId, $idx));
        }

        return (string) $dom->saveXML();
    }

    private function buildAnchor(\DOMDocument $dom, string $rId, int $idx): \DOMElement
    {
        $ns  = self::NS_DRAWING;
        $ans = self::NS_MAIN;
        $rns = self::NS_REL;

        $anchor = $dom->createElementNS($ns, 'xdr:twoCellAnchor');
        $anchor->setAttribute('editAs', 'oneCell');

        // from A1 (col 0, row 0)
        $from = $dom->createElementNS($ns, 'xdr:from');
        foreach (['xdr:col' => '0', 'xdr:colOff' => '0', 'xdr:row' => '0', 'xdr:rowOff' => '0'] as $tag => $val) {
            $from->appendChild($dom->createElementNS($ns, $tag, $val));
        }

        // to M26 (col 12, row 25) — score sheet default; column widths control actual size
        $ext = $dom->createElementNS($ns, 'xdr:to');
        foreach (['xdr:col' => '12', 'xdr:colOff' => '0', 'xdr:row' => '25', 'xdr:rowOff' => '0'] as $tag => $val) {
            $ext->appendChild($dom->createElementNS($ns, $tag, $val));
        }

        // graphicFrame
        $gf      = $dom->createElementNS($ns, 'xdr:graphicFrame');
        $gf->setAttribute('macro', '');
        $nvGf    = $dom->createElementNS($ns, 'xdr:nvGraphicFramePr');
        $cNvPr   = $dom->createElementNS($ns, 'xdr:cNvPr');
        $cNvPr->setAttribute('id',   (string)(2 + $idx));
        $cNvPr->setAttribute('name', 'Chart ' . $idx);
        $nvGf->appendChild($cNvPr);
        $nvGf->appendChild($dom->createElementNS($ns, 'xdr:cNvGraphicFramePr'));

        $xfrm = $dom->createElementNS($ns, 'xdr:xfrm');
        $off  = $dom->createElementNS($ans, 'a:off');
        $off->setAttribute('x', '0'); $off->setAttribute('y', '0');
        $ext  = $dom->createElementNS($ans, 'a:ext');
        $ext->setAttribute('cx', '0'); $ext->setAttribute('cy', '0');
        $xfrm->appendChild($off);
        $xfrm->appendChild($ext);

        $graphic = $dom->createElementNS($ans, 'a:graphic');
        $gData   = $dom->createElementNS($ans, 'a:graphicData');
        $gData->setAttribute('uri', 'http://schemas.openxmlformats.org/drawingml/2006/chart');
        $cChart  = $dom->createElementNS('http://schemas.openxmlformats.org/drawingml/2006/chart', 'c:chart');
        $cChart->setAttributeNS($rns, 'r:id', $rId);
        $gData->appendChild($cChart);
        $graphic->appendChild($gData);

        $gf->appendChild($nvGf);
        $gf->appendChild($xfrm);
        $gf->appendChild($graphic);

        $anchor->appendChild($from);
        $anchor->appendChild($ext); // $ext holds the <to> element here
        $anchor->appendChild($gf);
        $anchor->appendChild($dom->createElementNS($ns, 'xdr:clientData'));

        return $anchor;
    }

    // ── Relationship parsing ──────────────────────────────────────────────────

    /** @return array<string,string>  rId → rId (we only need the IDs) */
    private function chartRelationships(string $relXml): array
    {
        $dom = new \DOMDocument();
        if (! @$dom->loadXML($relXml)) return [];

        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');

        $result = [];
        foreach ($xp->query('//r:Relationship[@Type="' . self::TYPE_CHART . '"]') as $rel) {
            $rId          = $rel->getAttribute('Id');
            $result[$rId] = $rId;
        }

        return $result;
    }

    // ── File discovery ────────────────────────────────────────────────────────

    /** @return string[] */
    private function findDrawingFiles(\ZipArchive $zip): array
    {
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^xl/drawings/drawing\d+\.xml$#', $name)) {
                $files[] = $name;
            }
        }
        return $files;
    }
}
