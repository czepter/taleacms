<?php

declare(strict_types=1);

namespace Talea\Core;

/** UUID v4 (random) – the identifier of a row that leaves the server: admin URLs, MCP, previews, webhooks, exports. */
final class Uuid
{
    public const string PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public static function v4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant 10xx

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /** Whether the text has the shape of a UUID v4; checked before any SQL uses it. */
    public static function valid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }
}
