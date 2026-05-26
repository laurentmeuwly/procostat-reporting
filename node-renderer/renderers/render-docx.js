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
    ImageRun, Header, Footer, AlignmentType, BorderStyle, WidthType, ShadingType,
    VerticalAlign, PageBreak, SimpleField, BookmarkStart, BookmarkEnd,
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

/**
 * Format a number in scientific notation with 3 significant digits and uppercase E.
 * Examples: 2520 → "2.52E3", 70 → "7.00E1", 0.044 → "4.40E-2", 2.72 → "2.72E0"
 */
function sciOrDash(v) {
    if (v === null || v === undefined) return '—';
    const n = parseFloat(v);
    if (isNaN(n)) return '—';
    // toExponential(2) = 3 significant digits, always outputs "X.XXe+YY" or "X.XXe-YY"
    const raw = n.toExponential(2);
    const [mantissa, expPart] = raw.split('e');
    const exp = parseInt(expPart, 10); // strips leading zeros and + sign
    return mantissa + 'E' + exp;      // e.g. "2.52E3", "4.40E-2", "2.72E0"
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
            border: { bottom: { style: BorderStyle.SINGLE, size: 4, color: COLOR_HEADER } },
            spacing: { after: 0 },
            children: logoBuf
                ? [new ImageRun({ data: logoBuf, transformation: { width: 120, height: 46 }, type: 'png' })]
                : [new TextRun({ text: 'PROCORAD', bold: true, font: 'Calibri', size: 24 })],
        }),
    ],
});

// ── Footer (page numbering) ───────────────────────────────────────────────────
// "Page X / Y" centred, with a top border line.
// Uses SimpleField('PAGE') and SimpleField('NUMPAGES') — the correct OOXML field approach.
// PageNumberElement generates invalid <w:pgNum/> and breaks Word.
const docFooter = new Footer({
    children: [
        new Paragraph({
            alignment: AlignmentType.CENTER,
            border: { top: { style: BorderStyle.SINGLE, size: 4, color: COLOR_HEADER, space: 4 } },
            spacing: { before: 80 },
            children: [
                new TextRun({ text: 'Page ', size: 18, font: 'Calibri', color: '666666' }),
                new SimpleField('PAGE'),
                new TextRun({ text: ' / ', size: 18, font: 'Calibri', color: '666666' }),
                new SimpleField('NUMPAGES'),
            ],
        }),
    ],
});
function buildCoverChildren(sorted) {
    return [
        new Paragraph({ spacing: { before: 1440, after: 0 }, children: [] }),
        new Paragraph({
            alignment: AlignmentType.CENTER,
            spacing: { before: 0, after: 120 },
            children: [new TextRun({ text: data.icTitle, bold: true, size: 56, font: 'Calibri', color: '1a1a1a' })],
        }),
        // Cover lists analyses in sorted order
        ...sorted.map(a => new Paragraph({
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
}

// ── Analysis data pages (one per analysis) ────────────────────────────────────

// ── Analysis data pages ───────────────────────────────────────────────────────

/**
 * Builds the tableau + stats page for one analysis.
 *
 * @param {object} analysis
 * @param {number} chartIndex  Position of this analysis in the sorted list (0-based).
 *   A BookmarkStart/End named "isotope_charts_<chartIndex>" is appended as the very last
 *   paragraph. DocxChartInjector.php locates this bookmark and inserts the chart pages
 *   immediately before it, achieving the [tableau N][graphes N] interleaving.
 */
function buildAnalysisPage(analysis, chartIndex) {
    const children = [];
    const allLabs  = analysis.labResults || [];

    // ── Categorise labs ───────────────────────────────────────────────────
    // Evaluated: fully included, not truncated, not below LD
    const evaluatedLabs = allLabs.filter(l => l.isIncluded && !l.isTruncated && !l.isBelowLod);
    // Excluded from stats: outliers (Grubbs/Dixon/manual) or z>5 truncated
    const excludedLabs  = allLabs.filter(l => !l.isBelowLod && (!l.isIncluded || l.isTruncated));
    // Below detection limit
    const belowLodLabs  = allLabs.filter(l => l.isBelowLod);

    const nbParticipants = allLabs.length;
    const nbEvalues      = evaluatedLabs.length;
    const hasZprime      = nbEvalues >= 12;

    // ── Header: sample + isotope ──────────────────────────────────────────
    children.push(pageBreak());
    children.push(new Paragraph({
        spacing: { before: 0, after: 80 },
        children: [
            new TextRun({ text: `${analysis.isotope}  —  ${analysis.sampleCode}`, bold: true, size: 32, font: 'Calibri', color: COLOR_HEADER }),
        ],
    }));
    children.push(separator());

    // ── Résumé statistique ────────────────────────────────────────────────
    children.push(sectionTitle('Résumé statistique'));

    const statRows = [
        ['Unité',                        analysis.unit],
        ['Valeur assignée',              sciOrDash(analysis.assignedValue)],
        ['Incertitude (k=2)',            sciOrDash(analysis.assignedUncertainty)],
        ['Nb laboratoires participants', String(nbParticipants)],
        ['Nb laboratoires évalués',      String(nbEvalues)],
        ['Moyenne robuste',              sciOrDash(analysis.robustMean)],
        ['Écart-type robuste',           sciOrDash(analysis.robustStdDev)],
    ];

    // Two columns of stats side by side
    const half       = Math.ceil(statRows.length / 2);
    const leftStats  = statRows.slice(0, half);
    const rightStats = statRows.slice(half);
    while (rightStats.length < leftStats.length) rightStats.push(['', '']);

    const statColW = Math.floor(CONTENT_WIDTH / 4);
    const statTable = new Table({
        width: { size: CONTENT_WIDTH, type: WidthType.DXA },
        columnWidths: [statColW, statColW, statColW, statColW],
        rows: leftStats.map((left, i) => {
            const right = rightStats[i] || ['', ''];
            return new TableRow({
                children: [
                    cell(left[0],  { bold: true, size: 18, fill: COLOR_ROW_ALT,  align: AlignmentType.LEFT, width: statColW }),
                    cell(left[1],  { size: 18,             fill: COLOR_ROW_EVEN, align: AlignmentType.LEFT, width: statColW }),
                    cell(right[0], { bold: true, size: 18, fill: COLOR_ROW_ALT,  align: AlignmentType.LEFT, width: statColW }),
                    cell(right[1], { size: 18,             fill: COLOR_ROW_EVEN, align: AlignmentType.LEFT, width: statColW }),
                ],
            });
        }),
    });
    children.push(statTable);
    children.push(new Paragraph({ spacing: { before: 200, after: 0 }, children: [] }));

    // ── Tableau des résultats (labs évalués uniquement) ────────────────────
    children.push(sectionTitle('Résultats des laboratoires évalués'));

    // Column definitions — no "Exclu des stats" column (evaluated labs only)
    const colDefs = hasZprime
        ? [
            { header: 'LAB\nN°',                          w: 650  },
            { header: `Activité\n(${analysis.unit})`,     w: 1400 },
            { header: `Incertitude\n(k=2)`,               w: 1400 },
            { header: `LD\n(${analysis.unit})`,           w: 1100 },
            { header: 'Biais\n%',                         w: 750  },
            { header: 'Z-score',                          w: 850  },
            { header: "Z'-score",                         w: 850  },
            { header: 'Zeta',                             w: 838  },
          ]
        : [
            { header: 'LAB\nN°',                          w: 750  },
            { header: `Activité\n(${analysis.unit})`,     w: 1600 },
            { header: `Incertitude\n(k=2)`,               w: 1600 },
            { header: `LD\n(${analysis.unit})`,           w: 1200 },
            { header: 'Biais\n%',                         w: 850  },
            { header: 'Z-score',                          w: 1000 },
            { header: 'Zeta',                             w: 1238 },
          ];

    // Adjust widths to exactly CONTENT_WIDTH
    const totalW = colDefs.reduce((s, c) => s + c.w, 0);
    const scale  = CONTENT_WIDTH / totalW;
    colDefs.forEach(c => { c.w = Math.round(c.w * scale); });
    colDefs[colDefs.length - 1].w += CONTENT_WIDTH - colDefs.reduce((s, c) => s + c.w, 0);

    const mainHeaderRow = new TableRow({
        tableHeader: true,
        children: colDefs.map(c => headerCell(c.header, c.w)),
    });

    const mainDataRows = evaluatedLabs.map((lab, idx) => {
        const rowFill    = idx % 2 === 0 ? COLOR_ROW_EVEN : COLOR_ROW_ALT;
        const zscorefill = scoreCellFill(lab.zScore);
        const zprimefill = scoreCellFill(lab.zPrimeScore);
        const zetafill   = scoreCellFill(lab.zetaScore);

        const cells = [
            cell(lab.labNumber,                  { size: 18, fill: rowFill, bold: true }),
            cell(sciOrDash(lab.activity),         { size: 18, fill: rowFill }),
            cell(sciOrDash(lab.expandedUncertainty), { size: 18, fill: rowFill }),
            cell(sciOrDash(lab.detectionLimit),   { size: 18, fill: rowFill }),
            cell(lab.biasPercent !== null && lab.biasPercent !== undefined
                ? Math.round(lab.biasPercent) : '—',  { size: 18, fill: rowFill }),
            cell(roundOrDash(lab.zScore),         { size: 18, fill: zscorefill }),
        ];
        if (hasZprime) {
            cells.push(cell(roundOrDash(lab.zPrimeScore), { size: 18, fill: zprimefill }));
        }
        cells.push(cell(roundOrDash(lab.zetaScore), { size: 18, fill: zetafill }));

        return new TableRow({ children: cells });
    });

    children.push(new Table({
        width: { size: CONTENT_WIDTH, type: WidthType.DXA },
        columnWidths: colDefs.map(c => c.w),
        rows: [mainHeaderRow, ...mainDataRows],
    }));

    // ── Tableau des exclus (si non vide) ──────────────────────────────────
    const hasExclusions = excludedLabs.length > 0 || belowLodLabs.length > 0;
    if (hasExclusions) {
        children.push(new Paragraph({ spacing: { before: 240, after: 0 }, children: [] }));
        children.push(sectionTitle('Laboratoires non évalués'));

        // Simple 3-column table: Lab N° | Activité | Motif
        const exColW = [Math.round(CONTENT_WIDTH * 0.12), Math.round(CONTENT_WIDTH * 0.30), Math.round(CONTENT_WIDTH * 0.58)];
        exColW[2] += CONTENT_WIDTH - exColW.reduce((s, v) => s + v, 0);

        const exHeader = new TableRow({
            tableHeader: true,
            children: [
                headerCell('LAB\nN°',                       exColW[0]),
                headerCell(`Activité\n(${analysis.unit})`,  exColW[1]),
                headerCell('Motif',                         exColW[2]),
            ],
        });

        // Map exclusion reason to French label
        function exclusionMotif(lab) {
            if (lab.isBelowLod)    return 'Valeur < LD';
            if (lab.isTruncated)   return 'Tronqué (z-score > 5)';
            if (!lab.isIncluded) {
                switch (lab.exclusionReason) {
                    case 'outlier_grubbs': return 'Aberrant (Grubbs)';
                    case 'outlier_dixon':  return 'Aberrant (Dixon)';
                    case 'below_lod':      return 'Valeur < LD';
                    case 'manual':         return 'Exclu manuellement';
                    default:               return lab.exclusionLabel || 'Exclu';
                }
            }
            return lab.exclusionLabel || '—';
        }

        const exRows = [...excludedLabs, ...belowLodLabs].map((lab, idx) => {
            const rowFill = idx % 2 === 0 ? COLOR_ROW_EVEN : COLOR_ROW_ALT;
            return new TableRow({
                children: [
                    cell(lab.labNumber,            { size: 18, fill: rowFill, bold: true }),
                    cell(sciOrDash(lab.activity),  { size: 18, fill: rowFill }),
                    cell(exclusionMotif(lab),       { size: 18, fill: rowFill, align: AlignmentType.LEFT }),
                ],
            });
        });

        children.push(new Table({
            width: { size: CONTENT_WIDTH, type: WidthType.DXA },
            columnWidths: exColW,
            rows: [exHeader, ...exRows],
        }));
    }

    // ── Chart insertion marker ────────────────────────────────────────────
    // DocxChartInjector.php finds this bookmark and inserts chart pages before it.
    // BookmarkStart positional API: (name, id) — name first, numeric id second.
    // BookmarkEnd positional API: (id).
    const bmId = chartIndex;
    children.push(new Paragraph({
        children: [
            new BookmarkStart(`isotope_charts_${chartIndex}`, bmId),
            new BookmarkEnd(bmId),
        ],
    }));

    return children;
}

// ── Isotope sorting ───────────────────────────────────────────────────────────

/**
 * Atomic number (Z) lookup by element symbol.
 * Covers the full periodic table up to Og (Z=118).
 * Isotope strings like "14C", "228Th", "210Pb" are parsed as:
 *   leading digits → mass number A (for secondary sort within same element)
 *   trailing letters → element symbol → Z (primary sort key)
 */
const ATOMIC_NUMBER = {
    H:1,He:2,Li:3,Be:4,B:5,C:6,N:7,O:8,F:9,Ne:10,
    Na:11,Mg:12,Al:13,Si:14,P:15,S:16,Cl:17,Ar:18,K:19,Ca:20,
    Sc:21,Ti:22,V:23,Cr:24,Mn:25,Fe:26,Co:27,Ni:28,Cu:29,Zn:30,
    Ga:31,Ge:32,As:33,Se:34,Br:35,Kr:36,Rb:37,Sr:38,Y:39,Zr:40,
    Nb:41,Mo:42,Tc:43,Ru:44,Rh:45,Pd:46,Ag:47,Cd:48,In:49,Sn:50,
    Sb:51,Te:52,I:53,Xe:54,Cs:55,Ba:56,La:57,Ce:58,Pr:59,Nd:60,
    Pm:61,Sm:62,Eu:63,Gd:64,Tb:65,Dy:66,Ho:67,Er:68,Tm:69,Yb:70,
    Lu:71,Hf:72,Ta:73,W:74,Re:75,Os:76,Ir:77,Pt:78,Au:79,Hg:80,
    Tl:81,Pb:82,Bi:83,Po:84,At:85,Rn:86,Fr:87,Ra:88,Ac:89,Th:90,
    Pa:91,U:92,Np:93,Pu:94,Am:95,Cm:96,Bk:97,Cf:98,Es:99,Fm:100,
    Md:101,No:102,Lr:103,Rf:104,Db:105,Sg:106,Bh:107,Hs:108,Mt:109,Ds:110,
    Rg:111,Cn:112,Nh:113,Fl:114,Mc:115,Lv:116,Ts:117,Og:118,
};

/** Parse "228Th" → { A: 228, Z: 90 }. Returns { A: 0, Z: 999 } for unrecognised strings. */
function parseIsotope(str) {
    const m = String(str || '').match(/^(\d*)([A-Za-z]+)/);
    if (!m) return { A: 0, Z: 999 };
    const symbol = m[2].charAt(0).toUpperCase() + m[2].slice(1).toLowerCase();
    return { A: parseInt(m[1], 10) || 0, Z: ATOMIC_NUMBER[symbol] ?? 999 };
}

/** Sort comparator: ascending by (Z, A), i.e. atomic number then mass number. */
function compareIsotope(a, b) {
    const ia = parseIsotope(a.isotope), ib = parseIsotope(b.isotope);
    return ia.Z !== ib.Z ? ia.Z - ib.Z : ia.A - ib.A;
}

// Sort analyses once — used by both cover list and data pages
const sortedAnalyses = [...(data.analyses || [])].sort(compareIsotope);

// ── Assemble document ─────────────────────────────────────────────────────────

const pageProps = {
    page: {
        size: { width: PAGE_WIDTH, height: 16838 },
        margin: { top: MARGIN, right: MARGIN, bottom: MARGIN, left: MARGIN },
    },
};

// Single section: cover + data pages in isotope order.
// Each data page ends with an "isotope_charts_N" bookmark that DocxChartInjector
// uses to insert chart pages immediately after that analysis's tableau.
const allChildren = [
    ...buildCoverChildren(sortedAnalyses),
    ...sortedAnalyses.flatMap((analysis, idx) => buildAnalysisPage(analysis, idx)),
];

const sections = [{
    properties: pageProps,
    headers: { default: docHeader },
    footers: { default: docFooter },
    children: allChildren,
}];

const doc = new Document({ sections });

Packer.toBuffer(doc)
    .then(buf => {
        fs.writeFileSync(outputPath, buf);
        console.log(`[render-docx] Written: ${outputPath}`);
    })
    .catch(err => { console.error('[render-docx] ERROR:', err.message); process.exit(1); });
