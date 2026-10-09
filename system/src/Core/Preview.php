<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Signed link to a draft preview (page or site part) without login – for Claude over MCP and for sharing with a colleague.
 * The key "expiry.signature" is valid only for one target and until it expires; the signature is an HMAC with the installation's
 * secret key (Antispam::key).
 * The preview has noindex not only in meta: a page with the parameter is not cached and search engines do not get it into their index.
 * A key may allow comments (2.15, Core\DraftComments): "expiryk.signature" – the flag is part of the signed message, so a plain
 * key cannot be turned into a commenting one by hand.
 */
final class Preview
{
    public const int MAX_MINUTES = 7 * 24 * 60;

    /** Preview key for the target "page:12" or "part:header:en", valid for the given number of minutes. */
    public static function key(Db $db, Settings $settings, string $target, int $minutes, bool $comments = false): string
    {
        $to = time() + 60 * max(5, min(self::MAX_MINUTES, $minutes));

        return $to . ($comments ? 'k' : '') . '.' . self::signature($db, $settings, $target, $to, $comments);
    }

    public static function verify(Db $db, Settings $settings, string $target, string $key): bool
    {
        return self::parse($db, $settings, $target, $key) !== null;
    }

    /** Does a valid key for the target also allow comments on the draft? */
    public static function allowsComments(Db $db, Settings $settings, string $target, string $key): bool
    {
        return self::parse($db, $settings, $target, $key) === true;
    }

    /** @return bool|null null = invalid or expired; otherwise whether the key allows comments */
    private static function parse(Db $db, Settings $settings, string $target, string $key): ?bool
    {
        if (!preg_match('/^(\d{10})(k?)\.([a-f0-9]{64})$/', $key, $m) || (int) $m[1] < time()) {
            return null;
        }
        $comments = $m[2] === 'k';

        return hash_equals(self::signature($db, $settings, $target, (int) $m[1], $comments), $m[3]) ? $comments : null;
    }

    private static function signature(Db $db, Settings $settings, string $target, int $to, bool $comments): string
    {
        return hash_hmac('sha256', 'nahled|' . $target . '|' . $to . ($comments ? '|komentare' : ''), (new Antispam($db, $settings))->key());
    }
}
