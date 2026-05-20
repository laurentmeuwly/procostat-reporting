<?php

namespace Procorad\ProcostatReporting\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;
use Procorad\ProcostatReporting\Infrastructure\ChartJsNodeRenderer;
use Procorad\ProcostatReporting\Infrastructure\LocalFileStorage;
use Procorad\ProcostatReporting\ValueObject\PlotSpec;
use Procorad\ProcostatReporting\ValueObject\PlotType;
use Procorad\ProcostatReporting\ValueObject\Series;
use Procorad\ProcostatReporting\ValueObject\ThresholdBand;
use Procorad\ProcostatReporting\ValueObject\SortOrder;

class ChartJsNodeRendererTest extends TestCase
{
    public function test_render_real_png(): void
    {
        $labels = [
            'Lab01', 'Lab02', 'Lab03', 'Lab04', 'Lab05',
            'Lab06', 'Lab07', 'Lab08', 'Lab09', 'Lab10',
            'Lab11', 'Lab12', 'Lab13', 'Lab14', 'Lab15',
        ];

        // Scores répartis : 2 non-conformes, 2 discutables, 11 conformes
        $values = [
             0.42,   // conforme
            -0.87,   // conforme
             1.55,   // conforme
            -1.30,   // conforme
             0.08,   // conforme
             3.21,   // non-conforme  (+)
            -0.64,   // conforme
             2.15,   // discutable    (+)
            -2.47,   // discutable    (-)
             1.01,   // conforme
            -3.58,   // non-conforme  (-)
             0.73,   // conforme
            -0.19,   // conforme
             1.88,   // conforme
            -0.55,   // conforme
        ];

        $plot = new PlotSpec(
            type: PlotType::BAR,
            title: "z-score — Intercomparaison 26XGA — ⁴⁰K",
            yLabel: 'z-score',
            series: [
                new Series(
                    labels: $labels,
                    values: $values,
                    label: 'z-score'
                ),
            ],
            thresholds: [
                new ThresholdBand(-2.0, 2.0, 'conforme'),
                new ThresholdBand(-3.0, 3.0, 'discutable'),
            ],
            sortOrder: SortOrder::VALUE_ASC,
        );

        $renderer = new ChartJsNodeRenderer(
            __DIR__ . '/../../node-renderer/render.js'
        );

        $chart = $renderer->render($plot);

        $storage = new LocalFileStorage(__DIR__ . '/output');
        $storage->save('charts/zscore_15labs.png', $chart->binaryContent);

        $this->assertEquals('image/png', $chart->mimeType);
        $this->assertNotEmpty($chart->binaryContent);
    }
}
