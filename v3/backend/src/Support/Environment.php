<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Support;

use RuntimeException;

final class Environment
{
    public static function requiredString(string $name, bool $allowEmpty = false): string
    {
        $value = getenv($name);
        if ($value === false || (!$allowEmpty && trim($value) === '')) {
            throw new RuntimeException("Missing required environment variable: {$name}");
        }
        return $value;
    }

    public static function string(string $name, string $default): string
    {
        $value = getenv($name);
        return $value === false ? $default : $value;
    }

    public static function boolean(string $name, bool $default): bool
    {
        $value = getenv($name);
        if ($value === false || $value === '') return $default;
        return match (strtolower(trim($value))) {
            '1', 'true' => true,
            '0', 'false' => false,
            default => throw new RuntimeException("Invalid boolean environment variable: {$name}"),
        };
    }
}
