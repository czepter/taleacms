<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Shared slugs, step 1 (expand; nothing an existing site notices):
 * 1. every language column takes a BCP 47 tag (pt-br, zh-hant) - VARCHAR(35) instead of VARCHAR(2); the stored values stay
 *    two-letter codes or '', and the primary keys that contain the language are rebuilt by the same statement;
 * 2. pages, news and categories get a unique key per language (language, slug) next to the global slug key, which implies it,
 *    so no row can fail. The global keys are dropped only when an administrator switches on "the same address in every
 *    language version" (setting slugs_per_language, Core\Slug::switchPerLanguage) and put back when it is switched off.
 */
final class WidenLanguageTagsAndSlugKeys extends AbstractMigration
{
    /** table => [column definition in the form changeColumn() needs: default (null = none), comment] */
    private const array COLUMNS = [
        'users' => ['', 'admin language; \'\' = the default'],
        'categories' => ['', 'language version; \'\' = the site\'s default language'],
        'news' => ['', 'taken from the category on save'],
        'pages' => ['', 'language version; \'\' = the site\'s default language'],
        'site_parts' => ['', null],
        'menus' => ['', '\'\' = the site\'s default language'],
        'collection_items' => ['', null],
        'collection_templates' => [null, null],
        'newsletters' => ['', 'language of the fixed texts and the news items (empty = the site language)'],
        'facts' => ['', '\'\' = the default language'],
        'fact_history' => ['', null],
        'bookings' => ['', 'the site language version the customer used (\'\' = default)'],
    ];

    /** table => name of the per-language unique key */
    private const array KEYS = [
        'pages' => 'uq_pages_language_slug',
        'news' => 'uq_news_language_slug',
        'categories' => 'uq_categories_language_slug',
    ];

    public function up(): void
    {
        $this->resize(35);
        foreach (self::KEYS as $table => $name) {
            $this->table($table)->addIndex(['language', 'slug'], ['name' => $name, 'unique' => true])->update();
        }
    }

    public function down(): void
    {
        foreach (self::KEYS as $table => $name) {
            $this->table($table)->removeIndexByName($name)->update();
        }
        $this->resize(2);
    }

    private function resize(int $limit): void
    {
        foreach (self::COLUMNS as $table => [$default, $comment]) {
            $options = ['limit' => $limit, 'null' => false];
            if ($default !== null) {
                $options['default'] = $default;
            }
            if ($comment !== null) {
                $options['comment'] = $comment;
            }
            $this->table($table)->changeColumn('language', 'string', $options)->update();
        }
    }
}
