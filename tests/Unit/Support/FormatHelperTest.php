<?php

declare(strict_types=1);

namespace Procorad\ProcostatReporting\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Procorad\ProcostatReporting\Support\FormatHelper;

final class FormatHelperTest extends TestCase
{
    /** @dataProvider scientificProvider */
    public function test_scientific_notation(float $input, string $expected): void
    {
        $this->assertSame($expected, FormatHelper::scientific($input));
    }

    /** @return array<array{float, string}> */
    public static function scientificProvider(): array
    {
        return [
            [2520.0,  '2.52E+03'],
            [70.0,    '7.00E+01'],
            [0.0028,  '2.80E-03'],
            [0.0,     '0.00E+00'],
            [3040.0,  '3.04E+03'],
        ];
    }

    public function test_signed_positive(): void
    {
        $this->assertSame('+2.6', FormatHelper::signed(2.6));
    }

    public function test_signed_negative(): void
    {
        $this->assertSame('-0.3', FormatHelper::signed(-0.3));
    }

    /** @dataProvider zscoreColorProvider */
    public function test_zscore_color(float $z, string $expected): void
    {
        $color = FormatHelper::zscoreColor($z, -2.0, 2.0, -3.0, 3.0);
        $this->assertSame($expected, $color);
    }

    /** @return array<array{float, string}> */
    public static function zscoreColorProvider(): array
    {
        return [
            [0.0,  '4472C4'],   // satisfactory
            [2.5,  'FFA500'],   // warning
            [-2.5, 'FFA500'],   // warning
            [3.5,  'FF0000'],   // action
            [-3.5, 'FF0000'],   // action
        ];
    }
}
