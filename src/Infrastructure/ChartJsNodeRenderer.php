<?php

namespace Procorad\ProcostatReporting\Infrastructure;

use Procorad\ProcostatReporting\Contract\ChartRendererInterface;
use Procorad\ProcostatReporting\ValueObject\PlotSpec;
use Procorad\ProcostatReporting\ValueObject\RenderedChart;

final class ChartJsNodeRenderer implements ChartRendererInterface
{
    public function __construct(
        private readonly string $nodeScriptPath
    ) {}

    public function render(PlotSpec $plot): RenderedChart
    {
        $builder = new ChartJsConfigBuilder();
        $config = $builder->fromPlotSpec($plot);

        $process = proc_open(
            "node {$this->nodeScriptPath}",
            [
                0 => ["pipe", "r"],
                1 => ["pipe", "w"],
                2 => ["pipe", "w"]
            ],
            $pipes
        );

        fwrite($pipes[0], json_encode($config));
        fclose($pipes[0]);

        $imageData = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        fclose($pipes[2]);
        proc_close($process);

        return new RenderedChart(
            mimeType: 'image/png',
            binaryContent: $imageData
        );
    }
}


