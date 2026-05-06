<?php

namespace Procorad\ProcostatReporting\Tests\ValueObject;

use PHPUnit\Framework\TestCase;
use Procorad\ProcostatReporting\ValueObject\PlotSpec;
use Procorad\ProcostatReporting\ValueObject\PlotType;

final class PlotSpecTest extends TestCase
{

    public function test_plot_spec_requires_series()
    {
        $this->expectException(\InvalidArgumentException::class);

        new PlotSpec(
            type: PlotType::BAR,
            title: 'Test',
            yLabel: 'yLabel',
            series: []
        );
    }
}
