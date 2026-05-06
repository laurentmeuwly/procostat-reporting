<?php

namespace Procorad\ProcostatReporting\Contract;

interface StorageInterface
{
    public function save(string $path, string $content): void;
}
