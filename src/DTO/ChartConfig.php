<?php

declare(strict_types=1);

namespace Procorad\ProcostatReporting\DTO;

/**
 * Declarative chart definition passed to Node.js renderers.
 *
 * This is NOT a rendered image — it is a configuration object that the
 * renderer (pptxgenjs / docx) translates into a native chart element,
 * keeping the output fully editable by end-users in Excel/PowerPoint.
 */
final readonly class ChartConfig
{
    /**
     * @param  string               $type    Chart type: 'results', 'results_sorted', 'bias', 'zscore', 'en'
     * @param  string               $title   Human-readable chart title (already translated)
     * @param  array<string, mixed> $options Additional renderer-specific options
     */
    public function __construct(
        public string $type,
        public string $title,
        public array $options = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type'    => $this->type,
            'title'   => $this->title,
            'options' => $this->options,
        ];
    }
}
