<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Talea\Core\MigrationSupport;

/**
 * PostgreSQL only (nothing happens on MySQL, which already ignores case and accents by default): the columns that are
 * looked up by what a person types - sign-in names, e-mails, slugs, redirect paths - compare case- and accent-insensitively.
 */
final class IgnoreCaseInLookupColumns extends AbstractMigration
{
    public function change(): void
    {
        MigrationSupport::createInsensitiveCollation($this);
        MigrationSupport::ignoreCase($this, [
            'users' => ['username', 'email'],
            'role' => ['name'],
            'subscribers' => ['email'],
            'categories' => ['slug'],
            'news' => ['slug'],
            'tags' => ['slug'],
            'pages' => ['slug'],
            'popups' => ['slug'],
            'collections' => ['slug'],
            'collection_items' => ['slug'],
            'redirects' => ['from_path'],
        ]);
    }
}
