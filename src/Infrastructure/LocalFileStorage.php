<?php

namespace Procorad\ProcostatReporting\Infrastructure;

use Procorad\ProcostatReporting\Contract\StorageInterface;

final class LocalFileStorage implements StorageInterface
{
    public function __construct(
        private readonly string $baseDirectory
    ) {}

    public function save(string $path, string $content): void
    {
        $fullPath = rtrim($this->baseDirectory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . ltrim($path, DIRECTORY_SEPARATOR);

        $directory = dirname($fullPath);

        if (!is_dir($directory)) {
            if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new \RuntimeException(
                    sprintf('Directory "%s" could not be created.', $directory)
                );
            }
        }

        if (file_put_contents($fullPath, $content) === false) {
            throw new \RuntimeException(
                sprintf('File "%s" could not be written.', $fullPath)
            );
        }
    }
}
