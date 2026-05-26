<?php

namespace Procorad\ProcostatReporting\Excel\Support;

/**
 * Layout constants shared across all sheet builders.
 *
 * Charts use twoCellAnchor (A1 → bottom-right cell). To ensure a consistent
 * visual size regardless of data content, sheet builders set fixed column
 * widths on the chart columns. The bottom-right cell is defined per chart type:
 *
 *   Results sheets (wider data table needs room):  A1:G26
 *   Score bar / scatter sheets:                    A1:M26
 *
 * Column widths are set to CHART_COL_WIDTH_RESULTS or CHART_COL_WIDTH_SCORE
 * so the chart always renders at the same physical size.
 *
 * Data tables always start at TABLE_START_ROW (row 28), leaving rows 1-27
 * for the chart frame (26 rows of chart + 1 blank buffer row).
 */
final class ExcelLayout
{
    // ── Chart anchoring ───────────────────────────────────────────────────────

    /** Top-left anchor cell for every chart. */
    public const CHART_TOP_LEFT = 'A1';

    /**
     * Bottom-right cell for results charts (lab asc / val asc).
     * Narrower: 7 columns × CHART_COL_WIDTH_RESULTS ≈ same physical width.
     */
    public const CHART_BOTTOM_RIGHT_RESULTS = 'G26';

    /**
     * Bottom-right cell for score charts (bias, zeta, zprime, scatter).
     * Wider range compensates for the narrower default column width.
     */
    public const CHART_BOTTOM_RIGHT_SCORE = 'M26';

    // ── Fixed column widths for chart columns ─────────────────────────────────

    /**
     * Width (in character units) applied to columns A-G on results sheets.
     * Results sheets have wide data columns (activity, uncertainty…), so the
     * chart naturally gets enough space with only 7 columns.
     */
    public const CHART_COL_WIDTH_RESULTS = 14.0;

    /**
     * Width (in character units) applied to columns A-M on score sheets.
     * Score sheets only use cols A-B for data, so we force the chart columns
     * to a fixed width so the chart always renders at the right size.
     */
    public const CHART_COL_WIDTH_SCORE = 8.5;

    // ── Table layout ──────────────────────────────────────────────────────────

    /**
     * Row at which the data table header is placed on all chart sheets.
     * Rows 1-27 are reserved for the chart frame.
     */
    public const TABLE_START_ROW = 28;

    /**
     * Column that holds the k=2 expanded uncertainty on results sheets.
     * Referenced by error bar definitions in the OOXML patch layer.
     */
    public const UNCERTAINTY_COL = 'C';

    // ── Business rules ────────────────────────────────────────────────────────

    /**
     * Population threshold above which z'-score and z' vs zeta sheets are added.
     * Condition is strictly greater than: n > ZPRIME_MIN_POPULATION.
     */
    public const ZPRIME_MIN_POPULATION = 12;

    /** Axis half-range for the z' vs zeta scatter chart. */
    public const SCATTER_AXIS_MAX = 4.0;
}
