'use strict';

/**
 * render-docx.js
 *
 * Generates a DOCX with:
 *   - Page 1: Cover (logo, IC title, analyses list, year)
 *   - For each analysis:
 *     - Page: Tableau des résultats + Résumé statistique
 *   Charts are injected separately by DocxChartInjector.php
 */

const fs = require('fs');
const {
    Document, Packer, Paragraph, TextRun, Table, TableRow, TableCell,
    ImageRun, Header, AlignmentType, BorderStyle, WidthType, ShadingType,
    VerticalAlign, PageBreak,
} = require('docx');

const [,, payloadPath, outputPath] = process.argv;
if (!payloadPath || !outputPath) {
    console.error('Usage: node render-docx.js <payload.json> <output.docx>');
    process.exit(1);
}

const data = JSON.parse(fs.readFileSync(payloadPath, 'utf8'));

// ── Constants ─────────────────────────────────────────────────────────────────
// A4 page: 11906 DXA wide, margins 1134 each side → content = 9638 DXA
const PAGE_WIDTH     = 11906;
const MARGIN         = 1134;
const CONTENT_WIDTH  = PAGE_WIDTH - 2 * MARGIN; // 9638

// Colours
const COLOR_HEADER   = '1F3864';
const COLOR_ROW_ALT  = 'EEF3F9';
const COLOR_ROW_EVEN = 'FFFFFF';
const COLOR_OK       = 'FFFFFF';
const COLOR_WARN     = 'FFF3CC'; // orange ≥2
const COLOR_ACTION   = 'FFCCCC'; // red ≥3
const COLOR_EXCL     = 'FFFDE7'; // yellow excluded

// ── Helpers ───────────────────────────────────────────────────────────────────

function loadLogo(logoPath) {
    if (!logoPath || !fs.existsSync(logoPath)) return null;
    return fs.readFileSync(logoPath);
}

function esc(v) {
    if (v === null || v === undefined) return '—';
    return String(v);
}

function sciOrDash(v, digits = 4) {
    if (v === null || v === undefined) return '—';
    const n = parseFloat(v);
    if (isNaN(n)) return '—';
    return n.toExponential(digits - 1).replace('e+', 'e').replace('e-0', 'e-').replace('e+0', 'e');
}

function roundOrDash(v, decimals = 2) {
    if (v === null || v === undefined) return '—';
    const n = parseFloat(v);
    if (isNaN(n)) return '—';
    return n.toFixed(decimals);
}

function scoreCellFill(score) {
    if (score === null || score === undefined) return COLOR_ROW_EVEN;
    const abs = Math.abs(parseFloat(score));
    if (abs >= 3) return COLOR_ACTION;
    if (abs >= 2) return COLOR_WARN;
    return COLOR_OK;
}

function cell(text, opts = {}) {
    const {
        bold = false, size = 18, color = '111111', fill = COLOR_ROW_EVEN,
        align = AlignmentType.CENTER, vAlign = VerticalAlign.CENTER,
        width = null, italic = false,
    } = opts;
    const cellWidth = width ? { size: width, type: WidthType.DXA } : undefined;
    const border = { style: BorderStyle.SINGLE, size: 1, color: 'CCCCCC' };
    return new TableCell({
        borders: { top: border, bottom: border, left: border, right: border },
        shading: { fill, type: ShadingType.CLEAR },
        verticalAlign: vAlign,
        margins: { top: 60, bottom: 60, left: 100, right: 100 },
        ...(cellWidth ? { width: cellWidth } : {}),
        children: [new Paragraph({
            alignment: align,
            children: [new TextRun({ text: String(text ?? '—'), bold, italic, size, font: 'Calibri', color })],
        })],
    });
}

function headerCell(text, width = null) {
    return cell(text, { bold: true, size: 18, color: 'FFFFFF', fill: COLOR_HEADER, width });
}

function separator() {
    return new Paragraph({
        border: { bottom: { style: BorderStyle.SINGLE, size: 6, color: COLOR_HEADER, space: 1 } },
        spacing: { before: 200, after: 200 },
        children: [],
    });
}

function sectionTitle(text) {
    return new Paragraph({
        spacing: { before: 240, after: 120 },
        children: [new TextRun({ text, bold: true, size: 22, font: 'Calibri', color: COLOR_HEADER })],
    });
}

function pageBreak() {
    return new Paragraph({ children: [new PageBreak()] });
}

// ── Header (logo + title line) ────────────────────────────────────────────────
const logoBuf = loadLogo(data.logoPath);

const docHeader = new Header({
    children: [
        new Paragraph({
            alignment: AlignmentType.LEFT,
            children: logoBuf
                ? [new ImageRun({ data: logoBuf, transformation: { width: 120, height: 46 }, type: 'png' })]
                : [new TextRun({ text: 'PROCORAD', bold: true, font: 'Calibri', size: 24 })],
        }),
        new Paragraph({
            border: { bottom: { style: BorderStyle.SINGLE, size: 4, color: COLOR_HEADER } },
            alignment: AlignmentType.RIGHT,
            spacing: { after: 0 },
            children: [new TextRun({
                text: data.propertyFileTitle || '',
                size: 18, color: '666666', font: 'Calibri',
            })],
        }),
    ],
});

// ── Cover ─────────────────────────────────────────────────────────────────────
const coverChildren = [
    new Paragraph({ spacing: { before: 1440, after: 0 }, children: [] }),
    new Paragraph({
        alignment: AlignmentType.CENTER,
        spacing: { before: 0, after: 120 },
        children: [new TextRun({ text: data.icTitle, bold: true, size: 56, font: 'Calibri', color: '1a1a1a' })],
    }),
    ...(data.analyses || []).map(a => new Paragraph({
        alignment: AlignmentType.CENTER,
        spacing: { before: 0, after: 60 },
        children: [new TextRun({ text: `${a.sampleCode}  —  ${a.isotope}`, size: 28, font: 'Calibri', color: '404040' })],
    })),
    separator(),
    ...['Année', 'IC', 'Date de génération'].map((label, i) => {
        const values = [data.year, data.icCode, new Date().toLocaleDateString('fr-FR')];
        return new Paragraph({
            alignment: AlignmentType.LEFT,
            spacing: { before: 80, after: 80 },
            indent: { left: 2880 },
            children: [
                new TextRun({ text: label + ' : ', bold: true, size: 22, font: 'Calibri', color: '444444' }),
                new TextRun({ text: String(values[i] ?? '—'), size: 22, font: 'Calibri', color: '111111' }),
            ],
        });
    }),
];

// ── Analysis data pages (one per analysis) ────────────────────────────────────

function buildAnalysisPage(analysis) {
    const children = [];
    const hasZprime = (analysis.labResults || []).length >= 12;

    // ── Header: sample + isotope ──────────────────────────────────────────
    children.push(pageBreak());
    children.push(new Paragraph({
        spacing: { before: 0, after: 80 },
        children: [
            new TextRun({ text: `${analysis.isotope}  —  ${analysis.sampleCode}`, bold: true, size: 32, font: 'Calibri', color: COLOR_HEADER }),
        ],
    }));
    children.push(separator());

    // ── Résumé statistique (right-aligned 2-col key/value table) ─────────
    children.push(sectionTitle('Résumé statistique'));

    const statRows = [
        ['Unité',              analysis.unit],
        ['Valeur assignée',    sciOrDash(analysis.assignedValue)],
        ['Incertitude (k=2)',  sciOrDash(analysis.assignedUncertainty)],
        ['Nb laboratoires',    String((analysis.labResults || []).length)],
        ['Moyenne robuste',    sciOrDash(analysis.robustMean)],
        ['Écart-type robuste', sciOrDash(analysis.robustStdDev)],
    ];

    // Two columns of stats side by side (3 left, 3 right)
    const half = Math.ceil(statRows.length / 2);
    const leftStats  = statRows.slice(0, half);
    const rightStats = statRows.slice(half);
    // Pad right column to same length
    while (rightStats.length < leftStats.length) rightStats.push(['', '']);

    const statColW = Math.floor(CONTENT_WIDTH / 4); // 4 cols total (key+val) × 2
    const statTable = new Table({
        width: { size: CONTENT_WIDTH, type: WidthType.DXA },
        columnWidths: [statColW, statColW, statColW, statColW],
        rows: leftStats.map((left, i) => {
            const right = rightStats[i] || ['', ''];
            return new TableRow({
                children: [
                    cell(left[0],  { bold: true, size: 18, fill: COLOR_ROW_ALT, align: AlignmentType.LEFT, width: statColW }),
                    cell(left[1],  { size: 18, fill: COLOR_ROW_EVEN, align: AlignmentType.LEFT, width: statColW }),
                    cell(right[0], { bold: true, size: 18, fill: COLOR_ROW_ALT, align: AlignmentType.LEFT, width: statColW }),
                    cell(right[1], { size: 18, fill: COLOR_ROW_EVEN, align: AlignmentType.LEFT, width: statColW }),
                ],
            });
        }),
    });
    children.push(statTable);
    children.push(new Paragraph({ spacing: { before: 200, after: 0 }, children: [] }));

    // ── Tableau des résultats ─────────────────────────────────────────────
    children.push(sectionTitle('Résultats des laboratoires'));

    // Column definitions
    // LAB N° | Activité | Incertitude | LD | Biais% | Z-score | [Z'-score] | Zeta | Exclu
    const colDefs = hasZprime
        ? [
            { header: 'LAB\nN°',        w: 600  },
            { header: `Activité\n(${analysis.unit})`, w: 1300 },
            { header: `Incertitude\n(k=2)`,           w: 1300 },
            { header: `LD\n(${analysis.unit})`,       w: 1000 },
            { header: 'Biais\n%',        w: 700  },
            { header: 'Z-score',         w: 800  },
            { header: "Z'-score",        w: 800  },
            { header: 'Zeta',            w: 800  },
            { header: 'Exclu\ndes stats', w: 1338 },
          ]
        : [
            { header: 'LAB\nN°',        w: 700  },
            { header: `Activité\n(${analysis.unit})`, w: 1400 },
            { header: `Incertitude\n(k=2)`,           w: 1400 },
            { header: `LD\n(${analysis.unit})`,       w: 1100 },
            { header: 'Biais\n%',        w: 800  },
            { header: 'Z-score',         w: 900  },
            { header: 'Zeta',            w: 900  },
            { header: 'Exclu\ndes stats', w: 1438 },
          ];

    // Adjust widths to exactly CONTENT_WIDTH
    const totalW = colDefs.reduce((s, c) => s + c.w, 0);
    const scale  = CONTENT_WIDTH / totalW;
    colDefs.forEach(c => { c.w = Math.round(c.w * scale); });
    // Fix rounding error on last col
    const diff = CONTENT_WIDTH - colDefs.reduce((s, c) => s + c.w, 0);
    colDefs[colDefs.length - 1].w += diff;

    const headerRow = new TableRow({
        tableHeader: true,
        children: colDefs.map(c => headerCell(c.header, c.w)),
    });

    const dataRows = (analysis.labResults || []).map((lab, idx) => {
        const isExcluded = !lab.isIncluded || lab.isTruncated;
        const rowFill = isExcluded ? COLOR_EXCL : (idx % 2 === 0 ? COLOR_ROW_EVEN : COLOR_ROW_ALT);
        const zscore  = lab.zScore;
        const zprime  = lab.zPrimeScore;
        const zeta    = lab.zetaScore;

        const zscorefill  = isExcluded ? rowFill : scoreCellFill(zscore);
        const zprimefill  = isExcluded ? rowFill : scoreCellFill(zprime);
        const zetafill    = isExcluded ? rowFill : scoreCellFill(zeta);

        const cells = [
            cell(lab.labNumber,                          { size: 18, fill: rowFill, bold: true }),
            cell(sciOrDash(lab.activity),                { size: 18, fill: rowFill, italic: lab.isTruncated }),
            cell(sciOrDash(lab.expandedUncertainty),     { size: 18, fill: rowFill }),
            cell(sciOrDash(lab.detectionLimit),          { size: 18, fill: rowFill }),
            cell(lab.biasPercent !== null && lab.biasPercent !== undefined ? Math.round(lab.biasPercent) : '—', { size: 18, fill: rowFill }),
            cell(roundOrDash(zscore),                    { size: 18, fill: zscorefill }),
        ];
        if (hasZprime) {
            cells.push(cell(roundOrDash(zprime), { size: 18, fill: zprimefill }));
        }
        cells.push(cell(roundOrDash(zeta),          { size: 18, fill: zetafill }));
        cells.push(cell(lab.exclusionLabel || '',   { size: 16, fill: rowFill, color: '666666' }));

        return new TableRow({ children: cells });
    });

    const resultsTable = new Table({
        width: { size: CONTENT_WIDTH, type: WidthType.DXA },
        columnWidths: colDefs.map(c => c.w),
        rows: [headerRow, ...dataRows],
    });

    children.push(resultsTable);

    return children;
}

// ── Assemble sections ─────────────────────────────────────────────────────────

const pageProps = {
    page: {
        size: { width: PAGE_WIDTH, height: 16838 },
        margin: { top: MARGIN, right: MARGIN, bottom: MARGIN, left: MARGIN },
    },
};

// Single section: cover + all analysis data pages (no blank page between sections)
const allChildren = [
    ...coverChildren,
    ...(data.analyses || []).flatMap(buildAnalysisPage),
];

const sections = [{
    properties: pageProps,
    headers: { default: docHeader },
    children: allChildren,
}];

const doc = new Document({ sections });

Packer.toBuffer(doc)
    .then(buf => {
        fs.writeFileSync(outputPath, buf);
        console.log(`[render-docx] Written: ${outputPath}`);
    })
    .catch(err => { console.error('[render-docx] ERROR:', err.message); process.exit(1); });
