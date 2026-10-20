<?php

declare(strict_types=1);

namespace Talea\Tests\Support;

use ReflectionClass;
use ReflectionProperty;
use Talea\Core\Db;
use Talea\Core\Settings;

/** Settings without a database for unit tests: only the values given; a key read through the database never connects when its value is set. */
final class FakeSettings
{
    /** A site's settings: its name and English unless the values say otherwise. */
    public static function site(array $values = []): Settings
    {
        return self::make($values + ['site_name' => 'Testovací firma', 'site_language' => 'en']); // check-english: allow
    }

    /** Settings whose signing secret is set (Preview, DraftComments), with a database that is never reached. */
    public static function withSecretKey(string $key): Settings
    {
        return self::make(['secret_key' => $key], true);
    }

    private static function make(array $values, bool $withDb = false): Settings
    {
        $settings = (new ReflectionClass(Settings::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Settings::class, 'values'))->setValue($settings, $values);
        if ($withDb) {
            (new ReflectionProperty(Settings::class, 'db'))->setValue($settings, new Db('mysql:host=127.0.0.1;dbname=none', '', ''));
        }

        return $settings;
    }
}
