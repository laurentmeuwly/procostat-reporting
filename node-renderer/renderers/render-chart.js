#!/usr/bin/env node
/**
 * render.js — Reçoit un objet de config Chart.js (JSON) sur stdin,
 * génère un PNG et l'écrit sur stdout.
 *
 * Usage: echo '<json>' | node render.js
 *
 * Dépendances (dans node-renderer/) :
 *   npm install canvas chartjs-node-canvas chart.js @sgratzl/chartjs-chart-error-bars chartjs-plugin-annotation
 */

'use strict';

const { ChartJSNodeCanvas } = require('chartjs-node-canvas');

const WIDTH  = 900;
const HEIGHT = 500;

async function main() {
    // 1. Lire stdin
    let raw = '';
    for await (const chunk of process.stdin) raw += chunk;

    let chartConfig;
    try {
        chartConfig = JSON.parse(raw);
    } catch (e) {
        process.stderr.write('render.js: JSON invalide sur stdin\n');
        process.exit(1);
    }

    // 2. Initialiser le renderer
    const renderer = new ChartJSNodeCanvas({
        width:  WIDTH,
        height: HEIGHT,
        backgroundColour: 'white',
        plugins: {
            modern: ['chartjs-plugin-annotation'],
        },
    });

    // 3. Générer le PNG
    const buffer = await renderer.renderToBuffer(chartConfig, 'image/png');

    // 4. Écrire sur stdout (le PHP lit ce stream)
    process.stdout.write(buffer);
}

main().catch(err => {
    process.stderr.write('render.js error: ' + err.message + '\n');
    process.exit(1);
});
