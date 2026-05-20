<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            color: #222;
        }

        .page {
            width: 100%;
            height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            page-break-after: always;
        }

        /* ── Cover ─────────────────────────────────── */
        .cover { text-align: center; }

        .cover__lab-label {
            font-size: 13px;
            font-weight: bold;
            letter-spacing: 1px;
            text-transform: uppercase;
            margin-bottom: 48px;
        }

        .cover__number {
            font-size: 96px;
            font-weight: bold;
            line-height: 1;
            margin-bottom: 48px;
        }

        .cover__year {
            font-size: 14px;
            font-weight: bold;
            color: #1a8fbf;
            margin-bottom: 4px;
        }

        /* ── IC page ────────────────────────────────── */
        .ic-page {
            justify-content: flex-start;
            padding-top: 40px;
        }

        .ic-page__title {
            font-size: 18px;
            font-weight: bold;
            text-align: center;
            text-transform: uppercase;
            color: #1a8fbf;
        }

        .ic-page__lab {
            font-size: 12px;
            text-align: center;
            margin-top: 8px;
            color: #666;
        }
    </style>
</head>
<body>

    {{-- Cover page --}}
    <div class="page cover">
        <div class="cover__lab-label">Laboratory N° {{ $model->labNumber }}</div>
        <div class="cover__number">N° {{ $model->labNumber }}</div>
        <div class="cover__year">{{ $model->year }}</div>
    </div>

    {{-- One page per intercomparison --}}
    @foreach($model->pages as $page)
    <div class="page ic-page">
        <div class="ic-page__title">{{ $model->year }} — {{ $page->icTitle }}</div>
        <div class="ic-page__lab">Laboratory N° {{ $model->labNumber }}</div>
    </div>
    @endforeach

</body>
</html>
