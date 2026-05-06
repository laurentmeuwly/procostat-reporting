<?php

namespace Procorad\ProcostatReporting\ValueObject;

final class Table
{
    /**
     * @param TableColumn[] $columns
     * @param array<int,array<string,mixed>> $rows
     */
    public function __construct(
        public readonly array $columns,
        public readonly array $rows,
    ) {
        $this->assertValid();
    }

    private function assertValid(): void
    {
        $keys = array_map(fn (TableColumn $c) => $c->key, $this->columns);

        foreach ($this->rows as $index => $row) {
            foreach ($keys as $key) {
                if (!array_key_exists($key, $row)) {
                    throw new \InvalidArgumentException(
                        "Missing column '{$key}' in row {$index}"
                    );
                }
            }
        }
    }
}
