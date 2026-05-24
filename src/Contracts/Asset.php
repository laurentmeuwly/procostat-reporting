<?php

namespace Procorad\ProcostatReporting\Contract;

final class Asset
{
    public function __construct(
        public readonly string $mimeType,
        public readonly string $filename,
        public readonly string $binaryContent,
    ) {}
}
