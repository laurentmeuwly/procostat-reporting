<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: Arial, sans-serif;
            font-size: 9px;
            color: #222;
        }

        /* ── Page base ──────────────────────────────────────── */
        .page {
            width: 100%;
            min-height: 100vh;
            page-break-after: always;
        }
        .page:last-child { page-break-after: auto; }

        /* ── Cover ──────────────────────────────────────────── */
        .cover {
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            padding: 40px 50px;
        }

        .cover__logo img {
            height: 70px;
        }

        .cover__body {
            margin-top: 180px;
            text-align: center;
        }

        .cover__subtitle {
            font-size: 13px;
            font-variant: small-caps;
            font-weight: bold;
            letter-spacing: 1px;
            color: #222;
            margin-bottom: 16px;
        }

        .cover__title {
            font-size: 24px;
            font-weight: bold;
            color: #222;
            margin-bottom: 32px;
            line-height: 1.2;
        }

        .cover__year {
            font-size: 14px;
            color: #1a8fbf;
            font-weight: normal;
        }

        /* ── Data pages ─────────────────────────────────────── */
        .data-page {
            padding: 20px 24px;
        }

        .page-title {
            text-align: center;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            color: #1a8fbf;
            margin-bottom: 2px;
        }

        .page-subtitle {
            text-align: center;
            font-size: 10px;
            color: #555;
            margin-bottom: 10px;
        }

        /* ── Meta row ───────────────────────────────────────── */
        .meta {
            margin-bottom: 8px;
            font-size: 9px;
        }
        .meta span { margin-right: 20px; }
        .meta strong { color: #333; }

        /* ── Results table ──────────────────────────────────── */
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8.5px;
        }
        th {
            background: #1a5276;
            color: #fff;
            padding: 3px 5px;
            text-align: center;
            font-weight: bold;
            white-space: nowrap;
        }
        td {
            padding: 2px 5px;
            text-align: center;
            border-bottom: 1px solid #e5e5e5;
            white-space: nowrap;
        }
        tr:nth-child(even) td { background: #f7f9fb; }

        /* ── Score colour coding ────────────────────────────── */
        .badge {
            display: inline-block;
            border-radius: 2px;
            padding: 1px 3px;
            font-size: 8px;
            font-weight: bold;
        }
        .ok           { background: #27ae60; color: #fff; }
        .questionable { background: #f39c12; color: #fff; }
        .discrepant   { background: #c0392b; color: #fff; }
        .none         { color: #aaa; }

        /* ── Legend ─────────────────────────────────────────── */
        .legend {
            margin-top: 10px;
            font-size: 8px;
            display: flex;
            gap: 16px;
        }
        .legend-group { display: flex; flex-direction: column; gap: 3px; }
        .legend-group strong { font-size: 7.5px; text-transform: uppercase; color: #666; }
        .legend-item { display: flex; align-items: center; gap: 4px; }
        .legend-dot {
            width: 10px; height: 10px;
            border-radius: 2px;
            display: inline-block;
            flex-shrink: 0;
        }
    </style>
</head>
<body>

    {{-- ── Cover page ── --}}
    @include('procostat-reporting::intercomparison-report.cover', ['model' => $model])

    {{-- ── One page per sample × isotope ── --}}
    @foreach($model->pages as $page)
    <div class="page data-page">

        <div class="page-title">{{ $model->year }} — {{ $model->icTitle }}</div>
        <div class="page-subtitle">
            {{ $page->sampleCode }} &mdash; {{ $page->isotope }}
        </div>

        <div class="meta">
            <span><strong>Matrix :</strong> {{ $page->matrix }}</span>
            <span><strong>Unit :</strong> {{ $page->unit }}</span>
            @if($page->assignedValue !== null)
                <span><strong>Assigned value :</strong> {{ number_format($page->assignedValue, 3, '.', '') }}</span>
            @endif
            @if($page->assignedUncertainty !== null)
                <span><strong>± :</strong> {{ number_format($page->assignedUncertainty, 3, '.', '') }}</span>
            @endif
            @if($page->robustMean !== null)
                <span><strong>Robust mean :</strong> {{ number_format($page->robustMean, 3, '.', '') }}</span>
            @endif
        </div>

        <table>
            <thead>
                <tr>
                    <th>N° Lab</th>
                    <th>Bias (%)</th>
                    <th>En</th>
                    <th>Z-score</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($page->rows as $row)
                <tr>
                    <td><strong>{{ str_pad($row->labNumber, 2, '0', STR_PAD_LEFT) }}</strong></td>
                    <td>
                        @if($row->specialStatus === 'no_answer')
                            <em>No answer</em>
                        @elseif($row->specialStatus === 'below_ld')
                            &lt; LD
                        @else
                            {{ $row->biasPercent !== null ? $row->biasPercent . ' %' : '—' }}
                        @endif
                    </td>
                    <td>
                        @if($row->enScore !== null)
                            <span class="badge {{ $row->enScoreStatus() }}">
                                {{ number_format($row->enScore, 2, '.', '') }}
                            </span>
                        @else
                            <span class="none">—</span>
                        @endif
                    </td>
                    <td>
                        @if($row->zScore !== null)
                            <span class="badge {{ $row->zScoreStatus() }}">
                                {{ number_format($row->zScore, 2, '.', '') }}
                            </span>
                        @else
                            <span class="none">—</span>
                        @endif
                    </td>
                    <td>
                        <span class="badge {{ $row->zScoreStatus() }}">
                            {{ $row->fitnessStatus ?? '—' }}
                        </span>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>

        {{-- Legend --}}
        <div class="legend">
            <div class="legend-group">
                <strong>Z-score / En</strong>
                <div class="legend-item">
                    <span class="legend-dot" style="background:#27ae60;"></span> In agreement (|z| ≤ 2)
                </div>
                <div class="legend-item">
                    <span class="legend-dot" style="background:#f39c12;"></span> Questionable (2 &lt; |z| ≤ 3)
                </div>
                <div class="legend-item">
                    <span class="legend-dot" style="background:#c0392b;"></span> Discrepant (|z| &gt; 3)
                </div>
            </div>
        </div>

    </div>
    @endforeach

</body>
</html>
