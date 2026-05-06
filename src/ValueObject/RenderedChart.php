<?php

namespace Procorad\ProcostatReporting\ValueObject;

final class RenderedChart
{
    public function __construct(
        public readonly string $mimeType,
        public readonly string $binaryContent
    ) {}
}
