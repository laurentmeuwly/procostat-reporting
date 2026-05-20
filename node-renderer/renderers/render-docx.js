'use strict';

/**
 * render-docx.js
 *
 * Usage: node render-docx.js <payload.json> <output.docx>
 *
 * Payload: IntercomparisonReportData::toArray() + logoPath + propertyFileTitle + locale
 *
 * Document structure:
 *   Page 1 — Cover (logo header, IC title, analyses list, metadata)
 *   (future) Page N+1 per analysis × chart type
 */

const fs = require('fs');
const {
    Document, Packer, Paragraph, TextRun, ImageRun,
    Header, AlignmentType, BorderStyle,
} = require('docx');

const [,, payloadPath, outputPath] = process.argv;
if (!payloadPath || !outputPath) {
    console.error('Usage: node render-docx.js <payload.json> <output.docx>');
    process.exit(1);
}

const data = JSON.parse(fs.readFileSync(payloadPath, 'utf8'));

// ── Helpers ───────────────────────────────────────────────────────────────────

function loadLogo(logoPath) {
    if (!logoPath || !fs.existsSync(logoPath)) return null;
    return fs.readFileSync(logoPath);
}

function metaLine(label, value) {
    return new Paragraph({
        alignment: AlignmentType.LEFT,
        spacing: { before: 80, after: 80 },
        indent: { left: 2880 },
        children: [
            new TextRun({ text: label + ' : ', bold: true, size: 22, font: 'Calibri', color: '444444' }),
            new TextRun({ text: String(value ?? '—'), size: 22, font: 'Calibri', color: '111111' }),
        ],
    });
}

function separator() {
    return new Paragraph({
        border: { bottom: { style: BorderStyle.SINGLE, size: 6, color: '1F497D', space: 1 } },
        spacing: { before: 240, after: 480 },
        children: [],
    });
}

// ── Logo ──────────────────────────────────────────────────────────────────────
const logoBuf = loadLogo(data.logoPath);

// ── Header ────────────────────────────────────────────────────────────────────
const docHeader = new Header({
    children: [
        new Paragraph({
            alignment: AlignmentType.LEFT,
            children: logoBuf
                ? [new ImageRun({ data: logoBuf, transformation: { width: 120, height: 46 }, type: 'png' })]
                : [new TextRun({ text: 'PROCORAD', bold: true, font: 'Calibri', size: 24 })],
        }),
        new Paragraph({
            border: { bottom: { style: BorderStyle.SINGLE, size: 4, color: '1F497D' } },
            alignment: AlignmentType.RIGHT,
            spacing: { after: 0 },
            children: [
                new TextRun({
                    text: data.propertyFileTitle || 'Property File Title',
                    size: 18, color: '666666', font: 'Calibri',
                }),
            ],
        }),
    ],
});

// ── Cover children ────────────────────────────────────────────────────────────
const coverChildren = [
    new Paragraph({ spacing: { before: 1440, after: 0 }, children: [] }),

    // IC title
    new Paragraph({
        alignment: AlignmentType.CENTER,
        spacing: { before: 0, after: 120 },
        children: [
            new TextRun({ text: data.icTitle, bold: true, size: 56, font: 'Calibri', color: '1a1a1a' }),
        ],
    }),

    // Analyses subtitle: "25CB — 14C" per analysis, stacked
    ...(data.analyses || []).map(a =>
        new Paragraph({
            alignment: AlignmentType.CENTER,
            spacing: { before: 0, after: 60 },
            children: [
                new TextRun({ text: `${a.sampleCode}  —  ${a.isotope}`, size: 28, font: 'Calibri', color: '404040' }),
            ],
        })
    ),

    separator(),

    metaLine('Année',              data.year),
    metaLine('IC',                 data.icCode),
    metaLine('Date de génération', new Date().toLocaleDateString('fr-FR')),
];

// ── Assemble ──────────────────────────────────────────────────────────────────
const doc = new Document({
    sections: [{
        properties: {
            page: {
                size: { width: 11906, height: 16838 }, // A4
                margin: { top: 1000, right: 1134, bottom: 1134, left: 1134 },
            },
        },
        headers: { default: docHeader },
        children: coverChildren,
    }],
});

// ── Write ─────────────────────────────────────────────────────────────────────
Packer.toBuffer(doc)
    .then(buf => {
        fs.writeFileSync(outputPath, buf);
        console.log(`[render-docx] Written: ${outputPath}`);
    })
    .catch(err => { console.error('[render-docx] ERROR:', err.message); process.exit(1); });
