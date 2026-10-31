<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Slugs are unique per language version, always: a page, news item or category may share its slug with the version in another
 * language (/contact and /de/contact). The per-language keys (language, slug) of 20261010130000 stay; the global slug keys go,
 * and the setting slugs_per_language that used to switch them is deleted. Dropping a key cannot fail.
 *
 * down() puts the global unique keys back. It fails while two language versions share a slug; that is acceptable for a rollback.
 */
final class SlugsPerLanguageAlways extends AbstractMigration
{
    /** table => name of the global slug key */
    private const array KEYS = [
        'pages' => 'uq_pages_slug',
        'news' => 'uq_news_slug',
        'categories' => 'uq_categories_slug',
    ];

    public function up(): void
    {
        foreach (self::KEYS as $table => $name) {
            $t = $this->table($table);
            if ($t->hasIndexByName($name)) {
                $t->removeIndexByName($name)->update();
            }
        }
        $this->getDeleteBuilder()->from($this->getAdapter()->getOption('table_prefix') . 'settings')->where(['name' => 'slugs_per_language'])->execute();
    }

    public function down(): void
    {
        foreach (self::KEYS as $table => $name) {
            $t = $this->table($table);
            if (!$t->hasIndexByName($name)) {
                $t->addIndex(['slug'], ['name' => $name, 'unique' => true])->update();
            }
        }
    }
}
