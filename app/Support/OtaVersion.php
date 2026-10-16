<?php
namespace App\Support;

final class OtaVersion
{
    public static function parts(string $version): ?array
    {
        if (!preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:\+(\d+))?$/', $version, $m)) return null;
        return [(int) $m[1], (int) ($m[2] ?? 0), (int) ($m[3] ?? 0), (int) ($m[4] ?? 0)];
    }
    public static function compare(string $left, string $right): int
    {
        return self::parts($left) <=> self::parts($right);
    }
}
