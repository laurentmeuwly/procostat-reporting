<?php

namespace Procorad\ProcostatReporting\ValueObject;

final class Series
{
    /**
     * @param string[] $labels
     * @param float[]  $values
     * @param float[]|null $errors  incertitudes ± (optionnel)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly array $labels,
        public readonly array $values,
        public readonly ?array $errors = null,
    ) {
        $this->assertValid();
    }

    private function assertValid(): void
    {
        if (count($this->labels) !== count($this->values)) {
            throw new \InvalidArgumentException(
                'Labels and values must have the same length'
            );
        }

        if ($this->errors !== null && count($this->errors) !== count($this->values)) {
            throw new \InvalidArgumentException(
                'Errors must have the same length as values'
            );
        }
    }
}
