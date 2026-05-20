<?php

namespace Procorad\ProcostatReporting\Support;

/**
 * Single source of truth for paths inside the package.
 * Uses __DIR__ — no Laravel helpers, fully portable.
 */
final class PackagePaths
{
    private static ?string $root = null;

    public static function root(): string
    {
        return self::$root ??= dirname(__DIR__, 2);
    }

    public static function nodeRenderer(string $name): string
    {
        return self::root() . "/node-renderer/renderers/{$name}";
    }

    public static function asset(string $name): string
    {
        return self::root() . "/resources/assets/{$name}";
    }
}
