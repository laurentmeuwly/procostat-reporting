<?php

namespace Procorad\ProcostatReporting\Tests\ValueObject;

use PHPUnit\Framework\TestCase;
use Procorad\ProcostatReporting\ValueObject\Series;

final class SeriesTest extends TestCase
{

    public function test_series_requires_same_size()
    {
        $this->expectException(\InvalidArgumentException::class);

        new Series(
            labels: ['Lab1'],
            values: [1.0, 2.0],
            label: 'z'
        );
    }
}
