'use strict';

/**
 * render-pptx.js
 *
 * Usage: node render-pptx.js <payload.json> <output.pptx>
 *
 * Payload: IntercomparisonReportData::toArray() + logoPath + locale
 *
 * Slide structure:
 *   Slide 1  — Cover (logo, IC title, year)
 *   (future) Slide N+1 — one per ChartConfig per analysis
 */

const fs      = require('fs');
const pptxgen = require('pptxgenjs');

const [,, payloadPath, outputPath] = process.argv;
if (!payloadPath || !outputPath) {
    console.error('Usage: node render-pptx.js <payload.json> <output.pptx>');
    process.exit(1);
}

const data = JSON.parse(fs.readFileSync(payloadPath, 'utf8'));

// ── Helpers ───────────────────────────────────────────────────────────────────

function loadLogoB64(logoPath) {
    if (!logoPath || !fs.existsSync(logoPath)) return null;
    return 'image/png;base64,' + fs.readFileSync(logoPath).toString('base64');
}

// ── Palette ───────────────────────────────────────────────────────────────────
const C = {
    bg:      'E8E8E8',
    title:   '1F3864',
    sub:     '404040',
    muted:   '808080',
};

// ── Build presentation ────────────────────────────────────────────────────────
const pres = new pptxgen();
pres.layout  = 'LAYOUT_16x9';
pres.title   = `${data.icTitle} ${data.year}`;
pres.subject = 'Procorad Reporting';
pres.author  = 'procostat-reporting';

const logoB64 = loadLogoB64(data.logoPath);

// ─────────────────────────────────────────────────────────────────────────────
// SLIDE 1 — Cover
// ─────────────────────────────────────────────────────────────────────────────
(function buildCover() {
    const slide = pres.addSlide();
    slide.background = { color: C.bg };

    if (logoB64) {
        slide.addImage({ data: logoB64, x: 0.45, y: 0.30, w: 2.20, h: 0.85 });
    }

    // IC title (e.g. "CARBON 14")
    slide.addText(data.icTitle, {
        x: 0, y: 1.80, w: 10, h: 1.50,
        fontSize: 52, fontFace: 'Calibri', color: C.title,
        align: 'center', valign: 'middle', margin: 0,
    });

    // Analyses summary — "25CB · 14C" per analysis
    const analysesSummary = (data.analyses || [])
        .map(a => `${a.sampleCode}  ·  ${a.isotope}`)
        .join('     ');

    if (analysesSummary) {
        slide.addText(analysesSummary, {
            x: 0, y: 3.40, w: 10, h: 0.65,
            fontSize: 20, fontFace: 'Calibri', color: C.sub,
            align: 'center', valign: 'middle', margin: 0,
        });
    }

    // Year
    slide.addText(String(data.year), {
        x: 0, y: 4.20, w: 10, h: 0.45,
        fontSize: 16, fontFace: 'Calibri', color: C.muted,
        align: 'center', margin: 0,
    });
})();

// ─────────────────────────────────────────────────────────────────────────────
// FUTURE SLIDES — one per analysis × chart type (stubbed)
// ─────────────────────────────────────────────────────────────────────────────
// (data.analyses || []).forEach(analysis => {
//     buildResultsSlide(pres, analysis, data);
//     buildZscoreSlide(pres, analysis, data);
//     buildBiasSlide(pres, analysis, data);
// });

// ── Write ─────────────────────────────────────────────────────────────────────
pres.writeFile({ fileName: outputPath })
    .then(() => console.log(`[render-pptx] Written: ${outputPath}`))
    .catch(err => { console.error('[render-pptx] ERROR:', err.message); process.exit(1); });
