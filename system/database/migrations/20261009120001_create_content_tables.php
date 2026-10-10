<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;
use Talea\Core\MigrationSupport;

/** Baseline of the database schema. */
final class CreateContentTables extends AbstractMigration
{
    public function change(): void
    {
        $prefix = (string) $this->getAdapter()->getOption('table_prefix'); // foreign key names are unique per database

        $this->table('categories', ['id' => false, 'primary_key' => ['category_id']])
            ->addColumn('category_id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('public_id', ...MigrationSupport::publicId($this->getAdapter()))
            ->addColumn('name', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('slug', 'string', ['limit' => 120, 'null' => false])
            ->addColumn('description', 'text', ['null' => false])
            ->addColumn('weight', 'smallinteger', ['signed' => false, 'null' => false, 'default' => 100, 'comment' => 'order, higher = higher up'])
            ->addColumn('language', 'string', ['limit' => 2, 'null' => false, 'default' => '', 'comment' => 'language version; \'\' = the site\'s default language'])
            ->addColumn('translation_of', 'integer', ['signed' => false, 'null' => true, 'comment' => 'counterpart in the default language (hreflang, language switcher)'])
            ->addIndex(['public_id'], ['name' => 'uq_categories_public_id', 'unique' => true])
            ->addIndex(['slug'], ['name' => 'uq_categories_slug', 'unique' => true])
            ->create();

        $news = $this->table('news', ['id' => false, 'primary_key' => ['news_id']])
            ->addColumn('news_id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('public_id', ...MigrationSupport::publicId($this->getAdapter()))
            ->addColumn('slug', 'string', ['limit' => 160, 'null' => false])
            ->addColumn('title', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('intro', 'text', ['limit' => MysqlAdapter::TEXT_MEDIUM, 'null' => false, 'comment' => 'intro'])
            ->addColumn('text', 'text', ['limit' => MysqlAdapter::TEXT_MEDIUM, 'null' => false])
            ->addColumn('image', 'string', ['limit' => 255, 'null' => false, 'default' => '', 'comment' => 'featured image'])
            ->addColumn('image_caption', 'string', ['limit' => 300, 'null' => false, 'default' => '', 'comment' => 'caption of the featured image (empty = the caption from the media library)'])
            ->addColumn('image_author', 'string', ['limit' => 120, 'null' => false, 'default' => '', 'comment' => 'author of the featured image (empty = the author from the media library)'])
            ->addColumn('category_id', 'integer', ['signed' => false, 'null' => false, 'comment' => 'category'])
            ->addColumn('author_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('published_at', 'datetime', ['null' => false, 'comment' => 'publish date (also a future one)'])
            ->addColumn('visible', 'boolean', ['null' => false, 'default' => 0, 'comment' => 'published news item (otherwise a draft)'])
            ->addColumn('keywords', 'string', ['limit' => 500, 'null' => false, 'default' => '', 'comment' => 'keywords'])
            ->addColumn('seo_title', 'string', ['limit' => 255, 'null' => false, 'default' => '', 'comment' => 'custom <title>, empty = the title'])
            ->addColumn('seo_description', 'string', ['limit' => 320, 'null' => false, 'default' => '', 'comment' => 'custom meta description, empty = from the intro'])
            ->addColumn('noindex', 'boolean', ['null' => false, 'default' => 0])
            ->addColumn('faq', 'text', ['null' => true, 'comment' => 'questions and answers: question, the answer below it, an empty line'])
            ->addColumn('visit', 'integer', ['signed' => false, 'null' => false, 'default' => 0, 'comment' => 'view count'])
            ->addColumn('edited_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true, 'comment' => 'when the published news item was substantially updated'])
            ->addColumn('announced_at', 'datetime', ['null' => true, 'comment' => 'when the system announced the publishing (webhook, IndexNow); NULL = not yet'])
            ->addColumn('valid_until', 'date', ['null' => true, 'comment' => 'true until: the day after, the news item hides itself (2.10, Core\\Validity)'])
            ->addColumn('review_by', 'date', ['null' => true, 'comment' => 'review by: on this day the site audit asks for a check (2.10)'])
            ->addColumn('language', 'string', ['limit' => 2, 'null' => false, 'default' => '', 'comment' => 'taken from the category on save'])
            ->addColumn('translation_of', 'integer', ['signed' => false, 'null' => true, 'comment' => 'idc of the news item this one is a translation of'])
            ->addColumn('search_text', 'text', ['limit' => MysqlAdapter::TEXT_MEDIUM, 'null' => true, 'comment' => 'text without diacritics for search (Core\\Search)'])
            ->addColumn('links_checked_at', 'datetime', ['null' => true, 'comment' => 'when the links were last checked'])
            ->addColumn('deleted_at', 'datetime', ['null' => true, 'comment' => 'in the trash since (deleted permanently after 30 days); NULL = not in the trash'])
            ->addIndex(['public_id'], ['name' => 'uq_news_public_id', 'unique' => true])
            ->addIndex(['slug'], ['name' => 'uq_news_slug', 'unique' => true])
            ->addIndex(['language', 'visible', 'published_at'], ['name' => 'ix_news_language_visible_published_at'])
            ->addIndex(['announced_at', 'visible', 'published_at'], ['name' => 'ix_news_announced_at_visible_published_at'])
            ->addIndex(['published_at'], ['name' => 'ix_news_published_at'])
            ->addIndex(['deleted_at'], ['name' => 'ix_news_deleted_at'])
            ->addIndex(['category_id', 'visible', 'published_at'], ['name' => 'ix_news_category_id_visible_published_at'])
            ->addIndex(['author_id'], ['name' => 'ix_news_author_id'])
            ->addForeignKey('category_id', 'categories', 'category_id', ['constraint' => $prefix . 'fk_news_category_id'])
            ->addForeignKey('author_id', 'users', 'user_id', ['constraint' => $prefix . 'fk_news_author_id', 'delete' => 'SET_NULL']);
        MigrationSupport::create($this, $news, 'news', ['ft_news_title_intro_text_keywords' => ['title', 'intro', 'text', 'keywords'], 'ft_news_search_text' => ['search_text']]);

        $this->table('tags', ['id' => false, 'primary_key' => ['tag_id']])
            ->addColumn('tag_id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('public_id', ...MigrationSupport::publicId($this->getAdapter()))
            ->addColumn('name', 'string', ['limit' => 80, 'null' => false])
            ->addColumn('slug', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('description', 'text', ['null' => true, 'comment' => 'intro of the topic page (HTML from the editors)'])
            ->addColumn('image', 'string', ['limit' => 255, 'null' => false, 'default' => ''])
            ->addIndex(['public_id'], ['name' => 'uq_tags_public_id', 'unique' => true])
            ->addIndex(['slug'], ['name' => 'uq_tags_slug', 'unique' => true])
            ->create();

        $this->table('news_tags', ['id' => false, 'primary_key' => ['news_id', 'tag_id']])
            ->addColumn('news_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('tag_id', 'integer', ['signed' => false, 'null' => false])
            ->addIndex(['tag_id'], ['name' => 'ix_news_tags_tag_id'])
            ->addForeignKey('news_id', 'news', 'news_id', ['constraint' => $prefix . 'fk_news_tags_news_id', 'delete' => 'CASCADE'])
            ->addForeignKey('tag_id', 'tags', 'tag_id', ['constraint' => $prefix . 'fk_news_tags_tag_id', 'delete' => 'CASCADE'])
            ->create();

        $this->table('news_revisions', ['id' => false, 'primary_key' => ['revision_id']])
            ->addColumn('revision_id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('news_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('title', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('intro', 'text', ['limit' => MysqlAdapter::TEXT_MEDIUM, 'null' => false])
            ->addColumn('text', 'text', ['limit' => MysqlAdapter::TEXT_MEDIUM, 'null' => false])
            ->addIndex(['news_id', 'created_at'], ['name' => 'ix_news_revisions_news_id_created_at'])
            ->addForeignKey('news_id', 'news', 'news_id', ['constraint' => $prefix . 'fk_news_revisions_news_id', 'delete' => 'CASCADE'])
            ->addForeignKey('user_id', 'users', 'user_id', ['constraint' => $prefix . 'fk_news_revisions_user_id', 'delete' => 'SET_NULL'])
            ->create();

        $this->table('news_drafts', ['id' => false, 'primary_key' => ['user_id', 'news_id']])
            ->addColumn('user_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('news_id', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('saved_at', 'datetime', ['null' => false])
            ->addColumn('data', 'text', ['limit' => MysqlAdapter::TEXT_MEDIUM, 'null' => false, 'comment' => 'JSON {form field name: value}'])
            ->create();

        $this->table('pages', ['id' => false, 'primary_key' => ['page_id']])
            ->addColumn('page_id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('public_id', ...MigrationSupport::publicId($this->getAdapter()))
            ->addColumn('slug', 'string', ['limit' => 120, 'null' => false])
            ->addColumn('title', 'string', ['limit' => 200, 'null' => false])
            ->addColumn('description', 'string', ['limit' => 300, 'null' => false, 'default' => '', 'comment' => 'meta description'])
            ->addColumn('seo_title', 'string', ['limit' => 200, 'null' => false, 'default' => '', 'comment' => 'custom <title>, empty = the title'])
            ->addColumn('image', 'string', ['limit' => 255, 'null' => false, 'default' => '', 'comment' => 'image for sharing (og:image), empty = the default from Settings'])
            ->addColumn('noindex', 'boolean', ['null' => false, 'default' => 0])
            ->addColumn('password_hash', 'string', ['limit' => 255, 'null' => true, 'comment' => 'password-protected page (2.14, Core\\PageLock): password_hash(); NULL = public'])
            ->addColumn('text', 'text', ['limit' => MysqlAdapter::TEXT_MEDIUM, 'null' => false])
            ->addColumn('visible', 'boolean', ['null' => false, 'default' => 1])
            ->addColumn('publish_at', 'datetime', ['null' => true, 'comment' => 'a hidden page publishes itself at this moment'])
            ->addColumn('valid_until', 'date', ['null' => true, 'comment' => 'true until: the day after, the page hides itself (2.10, Core\\Validity)'])
            ->addColumn('review_by', 'date', ['null' => true, 'comment' => 'review by: on this day the site audit asks for a check (2.10)'])
            ->addColumn('head_code', 'text', ['null' => true, 'comment' => 'code for <head> of this page only (administrators, 2.3)'])
            ->addColumn('in_menu', 'boolean', ['null' => false, 'default' => 1, 'comment' => 'link in the site footer / navigation'])
            ->addColumn('sort_order', 'smallinteger', ['signed' => false, 'null' => false, 'default' => 100])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addColumn('links_checked', 'datetime', ['null' => true, 'comment' => 'when the links of the published page were last checked (2.14, Core\\Links)'])
            ->addColumn('language', 'string', ['limit' => 2, 'null' => false, 'default' => '', 'comment' => 'language version; \'\' = the site\'s default language'])
            ->addColumn('translation_of', 'integer', ['signed' => false, 'null' => true, 'comment' => 'counterpart in the default language (hreflang, language switcher)'])
            ->addColumn('parent_id', 'integer', ['signed' => false, 'null' => true, 'comment' => 'parent page: the URL is /nadrazena/stranka'])
            ->addColumn('build', 'text', ['limit' => MysqlAdapter::TEXT_MEDIUM, 'null' => true, 'comment' => 'published build (JSON tree of builder elements); NULL = text page'])
            ->addColumn('build_draft', 'text', ['limit' => MysqlAdapter::TEXT_MEDIUM, 'null' => true, 'comment' => 'work-in-progress build from the editor; NULL = no unsaved changes'])
            ->addColumn('deleted_at', 'datetime', ['null' => true, 'comment' => 'in the trash since (deleted permanently after 30 days); NULL = not in the trash'])
            ->addIndex(['public_id'], ['name' => 'uq_pages_public_id', 'unique' => true])
            ->addIndex(['slug'], ['name' => 'uq_pages_slug', 'unique' => true])
            ->addIndex(['deleted_at'], ['name' => 'ix_pages_deleted_at'])
            ->addIndex(['publish_at'], ['name' => 'ix_pages_publish_at'])
            ->create();

        $this->table('page_revisions', ['id' => false, 'primary_key' => ['revision_id']])
            ->addColumn('revision_id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('page_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('title', 'string', ['limit' => 200, 'null' => false])
            ->addColumn('text', 'text', ['limit' => MysqlAdapter::TEXT_MEDIUM, 'null' => false])
            ->addIndex(['page_id', 'revision_id'], ['name' => 'ix_page_revisions_page_id_revision_id'])
            ->addForeignKey('page_id', 'pages', 'page_id', ['constraint' => $prefix . 'fk_page_revisions_page_id', 'delete' => 'CASCADE'])
            ->addForeignKey('user_id', 'users', 'user_id', ['constraint' => $prefix . 'fk_page_revisions_user_id', 'delete' => 'SET_NULL'])
            ->create();

        $this->table('site_parts', ['id' => false, 'primary_key' => ['type', 'language', 'variant']])
            ->addColumn('type', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('language', 'string', ['limit' => 2, 'null' => false, 'default' => ''])
            ->addColumn('variant', 'string', ['limit' => 40, 'null' => false, 'default' => '', 'comment' => '\'\' = default; otherwise the variant for the pages in the pages list (JSON of numbers)'])
            ->addColumn('name', 'string', ['limit' => 100, 'null' => false, 'default' => ''])
            ->addColumn('pages', 'text', ['null' => true])
            ->addColumn('build', 'text', ['limit' => MysqlAdapter::TEXT_MEDIUM, 'null' => true])
            ->addColumn('build_draft', 'text', ['limit' => MysqlAdapter::TEXT_MEDIUM, 'null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->create();

        $this->table('menus', ['id' => false, 'primary_key' => ['location', 'language']])
            ->addColumn('location', 'string', ['limit' => 20, 'null' => false, 'comment' => 'main | footer'])
            ->addColumn('language', 'string', ['limit' => 2, 'null' => false, 'default' => '', 'comment' => '\'\' = the site\'s default language'])
            ->addColumn('items', 'text', ['limit' => MysqlAdapter::TEXT_MEDIUM, 'null' => false, 'comment' => 'JSON [{type: page|link|news|group, page_id, url, text, new_window, children: […]}]'])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->create();

        $this->table('redirects', ['id' => false, 'primary_key' => ['redirect_id']])
            ->addColumn('redirect_id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('public_id', ...MigrationSupport::publicId($this->getAdapter()))
            ->addColumn('from_path', 'string', ['limit' => 255, 'null' => false, 'comment' => 'path on the site without the leading slash: news/old-address'])
            ->addColumn('to_path', 'string', ['limit' => 255, 'null' => false, 'comment' => 'path on the site, or a full URL https://...'])
            ->addColumn('type', 'smallinteger', ['signed' => false, 'null' => false, 'default' => 301, 'comment' => '301 permanent, 302 temporary'])
            ->addColumn('auto_score', 'tinyinteger', ['signed' => false, 'null' => true, 'comment' => 'NULL = by hand or a slug change; 0–100 = created by the daily job with this confidence (2.14, Core\\RedirectMatcher)'])
            ->addColumn('hits', 'integer', ['signed' => false, 'null' => false, 'default' => 0, 'comment' => 'how many times the redirect was used'])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['public_id'], ['name' => 'uq_redirects_public_id', 'unique' => true])
            ->addIndex(['from_path'], ['name' => 'uq_redirects_from_path', 'unique' => true])
            ->create();

        $this->table('not_found', ['id' => false, 'primary_key' => ['path']])
            ->addColumn('path', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('count', 'integer', ['signed' => false, 'null' => false, 'default' => 1])
            ->addColumn('last_seen_at', 'datetime', ['null' => false])
            ->addColumn('ignored_at', 'datetime', ['null' => true, 'comment' => 'ignored by the administrator (1.9): out of the warning and the list'])
            ->create();

        $this->table('broken_links', ['id' => false, 'primary_key' => ['link_id']])
            ->addColumn('link_id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('kind', 'string', ['limit' => 10, 'null' => false, 'default' => 'news', 'comment' => 'news | page | item (2.14): what idc is the id of'])
            ->addColumn('target_id', 'integer', ['signed' => false, 'null' => false, 'comment' => 'tl_news.news_id, tl_pages.page_id or tl_collection_items.item_id by kind'])
            ->addColumn('url', 'string', ['limit' => 500, 'null' => false])
            ->addColumn('element', 'string', ['limit' => 40, 'null' => false, 'default' => '', 'comment' => 'the builder element the link is in (page builds), otherwise empty'])
            ->addColumn('status', 'smallinteger', ['signed' => false, 'null' => false, 'default' => 0, 'comment' => 'response code; 0 = the server did not respond, 404 for an own article = does not exist'])
            ->addColumn('checked_at', 'datetime', ['null' => false])
            ->addIndex(['target_id'], ['name' => 'ix_broken_links_target_id'])
            ->create();

        $this->table('import_map', ['id' => false, 'primary_key' => ['source', 'type', 'source_id']])
            ->addColumn('source', 'string', ['limit' => 40, 'null' => false, 'comment' => 'where the record comes from: wp:<domain of the old site>'])
            ->addColumn('type', 'string', ['limit' => 20, 'null' => false, 'comment' => 'news | page | category | tag | image | item | enquiry'])
            ->addColumn('source_id', 'string', ['limit' => 190, 'null' => false, 'comment' => 'identifier in the source (post number, category URL, hash of the image URL)'])
            ->addColumn('local_id', 'integer', ['signed' => false, 'null' => false, 'comment' => 'the number of our record; 0 for an image = the download failed'])
            ->addIndex(['source', 'type', 'local_id'], ['name' => 'ix_import_map_source_type_local_id'])
            ->create();

        $this->table('notebook', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('topic', 'string', ['limit' => 60, 'null' => false, 'default' => 'other', 'comment' => 'decisions | style | credits | history | todo | other'])
            ->addColumn('title', 'string', ['limit' => 150, 'null' => false])
            ->addColumn('text', 'text', ['null' => false, 'comment' => 'plain text'])
            ->addColumn('pinned', 'boolean', ['null' => false, 'default' => 0])
            ->addColumn('author', 'string', ['limit' => 100, 'null' => false, 'default' => '', 'comment' => 'the user\'s name, or the name of the Claude connection'])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['topic', 'pinned', 'updated_at'], ['name' => 'ix_notebook_topic_pinned_updated_at'])
            ->create();

        $this->table('draft_comments', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('target', 'string', ['limit' => 80, 'null' => false, 'comment' => 'the draft the comment is about, as Core\\Preview signs it: \'stranka:12\''])
            ->addColumn('element', 'string', ['limit' => 40, 'null' => true, 'comment' => 'builder element id the comment points at; NULL = the page as a whole'])
            ->addColumn('quote', 'string', ['limit' => 300, 'null' => false, 'default' => '', 'comment' => 'the text the visitor had selected when writing'])
            ->addColumn('name', 'string', ['limit' => 80, 'null' => false])
            ->addColumn('text', 'text', ['null' => false, 'comment' => 'plain text'])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('resolved_at', 'datetime', ['null' => true])
            ->addColumn('resolved_by', 'integer', ['signed' => false, 'null' => true])
            ->addIndex(['target', 'resolved_at'], ['name' => 'ix_draft_comments_target_resolved_at'])
            ->addIndex(['resolved_by'], ['name' => 'ix_draft_comments_resolved_by'])
            ->addForeignKey('resolved_by', 'users', 'user_id', ['constraint' => $prefix . 'fk_draft_comments_resolved_by', 'delete' => 'SET_NULL'])
            ->create();

    }
}
