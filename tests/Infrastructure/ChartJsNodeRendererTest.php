<?php

namespace Procorad\ProcostatReporting\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;
use Procorad\ProcostatReporting\Infrastructure\ChartJsNodeRenderer;
use Procorad\ProcostatReporting\Infrastructure\LocalFileStorage;
use Procorad\ProcostatReporting\ValueObject\PlotSpec;
use Procorad\ProcostatReporting\ValueObject\PlotType;
use Procorad\ProcostatReporting\ValueObject\Series;

class ChartJsNodeRendererTest extends TestCase
{
    public function test_render_real_png()
    {
        $plot = new PlotSpec(
            type: PlotType::BAR,
            title: 'Test',
            yLabel: 'z',
            series: [
                new Series(
                    labels: ['Lab01','Lab02'],
                    values: [0.5,-1.2],
                    label: 'z'
                )
            ]
        );

        $renderer = new ChartJsNodeRenderer(
            __DIR__ . '/../../node-renderer/render.js'
        );

        $chart = $renderer->render($plot);

        $storage = new LocalFileStorage(__DIR__ . '/output');

$storage->save(
    'charts/zscore.png',
    $chart->binaryContent
);

        $this->assertEquals('image/png', $chart->mimeType);
        $this->assertNotEmpty($chart->binaryContent);
    }
}
