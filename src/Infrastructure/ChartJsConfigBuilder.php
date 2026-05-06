<?php

namespace Procorad\ProcostatReporting\Infrastructure;

use Procorad\ProcostatReporting\ValueObject\PlotSpec;
use Procorad\ProcostatReporting\ValueObject\PlotType;
use Procorad\ProcostatReporting\ValueObject\ThresholdBand;

final class ChartJsConfigBuilder
{
    private const COLOR_CONFORME   = 'rgba(29, 158, 117, 0.75)';
    private const COLOR_DISCUTABLE = 'rgba(186, 117, 23, 0.75)';
    private const COLOR_NC         = 'rgba(226, 75, 74, 0.75)';

    private const BORDER_CONFORME   = 'rgb(29, 158, 117)';
    private const BORDER_DISCUTABLE = 'rgb(186, 117, 23)';
    private const BORDER_NC         = 'rgb(226, 75, 74)';

    public function fromPlotSpec(PlotSpec $plot): array
    {
        $series = $plot->series[0];
        $values = $series->values;

        $levels = $this->extractSymmetricLevels($plot->thresholds);

        $bgColors     = array_map(fn($v) => $this->bgColor($v, $levels),     $values);
        $borderColors = array_map(fn($v) => $this->borderColor($v, $levels), $values);

        $annotations = $this->buildAnnotations($plot->thresholds, $plot->yLabel);

        return [
            'type' => 'bar',
            'data' => [
                'labels'   => $series->labels,
                'datasets' => [[
                    'label'           => $series->label,
                    'data'            => $values,
                    'backgroundColor' => $bgColors,
                    'borderColor'     => $borderColors,
                    'borderWidth'     => 1.5,
                    'borderRadius'    => 3,
                ]],
            ],
            'options' => [
                'responsive'          => false,
                'maintainAspectRatio' => false,
                'plugins' => [
                    'title' => [
                        'display' => true,
                        'text'    => $plot->title,
                        'font'    => ['size' => 14, 'weight' => 'bold'],
                        'padding' => ['bottom' => 16],
                    ],
                    'legend'     => ['display' => false],
                    'annotation' => ['annotations' => $annotations],
                ],
                'scales' => [
                    'x' => [
                        'title' => ['display' => true, 'text' => 'Laboratoire', 'font' => ['size' => 12]],
                        'grid'  => ['color' => 'rgba(0,0,0,0.06)'],
                    ],
                    'y' => [
                        'title' => ['display' => true, 'text' => $plot->yLabel, 'font' => ['size' => 12]],
                        'grid'  => ['color' => 'rgba(0,0,0,0.06)'],
                        'min'   => $this->yMin($values, $levels),
                        'max'   => $this->yMax($values, $levels),
                    ],
                ],
            ],
        ];
    }

    // --- Couleurs -------------------------------------------------------

    /** @param float[] $levels niveaux positifs triés croissant, ex: [2.0, 3.0] */
    private function bgColor(float $value, array $levels): string
    {
        return match (true) {
            empty($levels)                   => self::COLOR_CONFORME,
            abs($value) >= $levels[1] ?? INF => self::COLOR_NC,
            abs($value) >= $levels[0]        => self::COLOR_DISCUTABLE,
            default                          => self::COLOR_CONFORME,
        };
    }

    private function borderColor(float $value, array $levels): string
    {
        return match (true) {
            empty($levels)                   => self::COLOR_CONFORME,
            abs($value) >= $levels[1] ?? INF => self::BORDER_NC,
            abs($value) >= $levels[0]        => self::BORDER_DISCUTABLE,
            default                          => self::BORDER_CONFORME,
        };
    }

    // --- Annotations (lignes horizontales) ------------------------------

    /** @param ThresholdBand[] $thresholds */
    private function buildAnnotations(array $thresholds, string $yLabel): array
    {
        $annotations = [];
        $levels      = $this->extractSymmetricLevels($thresholds);

        // Ligne zéro
        $annotations['zero'] = [
            'type'        => 'line',
            'yMin'        => 0,
            'yMax'        => 0,
            'borderColor' => self::BORDER_CONFORME,
            'borderWidth' => 1.5,
        ];

        $styles = [
            0 => ['color' => self::BORDER_DISCUTABLE, 'dash' => [6, 4], 'suffix' => '=2'],
            1 => ['color' => self::BORDER_NC,         'dash' => [4, 3], 'suffix' => '=3'],
        ];

        foreach ($levels as $i => $level) {
            $style = $styles[$i] ?? $styles[1];
            $label = "|{$yLabel}|{$style['suffix']}";

            $annotations["pos_{$i}"] = [
                'type'        => 'line',
                'yMin'        => $level,
                'yMax'        => $level,
                'borderColor' => $style['color'],
                'borderWidth' => 1.5,
                'borderDash'  => $style['dash'],
                'label'       => [
                    'content'         => $label,
                    'display'         => true,
                    'position'        => 'end',
                    'color'           => $style['color'],
                    'font'            => ['size' => 11],
                    'backgroundColor' => 'transparent',
                ],
            ];

            $annotations["neg_{$i}"] = [
                'type'        => 'line',
                'yMin'        => -$level,
                'yMax'        => -$level,
                'borderColor' => $style['color'],
                'borderWidth' => 1.5,
                'borderDash'  => $style['dash'],
            ];
        }

        return $annotations;
    }

    // --- Helpers --------------------------------------------------------

    /**
     * Extrait les niveaux positifs uniques triés depuis les ThresholdBands symétriques.
     * Ex: ThresholdBand(-2,2) + ThresholdBand(-3,3) → [2.0, 3.0]
     *
     * @param  ThresholdBand[] $thresholds
     * @return float[]
     */
    private function extractSymmetricLevels(array $thresholds): array
    {
        $levels = [];
        foreach ($thresholds as $band) {
            $levels[] = abs($band->max);
        }
        $levels = array_unique($levels);
        sort($levels);

        return array_values($levels);
    }

    /** @param float[] $values @param float[] $levels */
    private function yMin(array $values, array $levels): float
    {
        $dataMin  = min($values);
        $levelMax = empty($levels) ? 0 : max($levels);
        $margin   = max(0.5, $levelMax * 0.3);

        return floor(min($dataMin, -$levelMax) - $margin);
    }

    /** @param float[] $values @param float[] $levels */
    private function yMax(array $values, array $levels): float
    {
        $dataMax  = max($values);
        $levelMax = empty($levels) ? 0 : max($levels);
        $margin   = max(0.5, $levelMax * 0.3);

        return ceil(max($dataMax, $levelMax) + $margin);
    }
}
