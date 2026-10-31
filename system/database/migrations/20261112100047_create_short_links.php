<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Talea\Core\MigrationSupport;

/**
 * Short links (docs/superpowers/specs/2026-10-31-short-links-design.md, Core\ShortLinks): a short address for print that leads to a
 * page, news item, collection item or any address, with campaign parameters that stay off the paper. Site table.
 */
final class CreateShortLinks extends AbstractMigration
{
    public function change(): void
    {
        $this->table('short_links', ['id' => false, 'primary_key' => ['short_link_id']])
            ->addColumn('short_link_id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('public_id', ...MigrationSupport::publicId($this->getAdapter()))
            ->addColumn('path', 'string', ['limit' => 70, 'null' => false, 'comment' => 'the short address without the slash: "flyer" or "s/k7f2qm"'])
            ->addColumn('target_type', 'string', ['limit' => 10, 'null' => false, 'comment' => 'page | news | item | url (ShortLinks::TYPES)'])
            ->addColumn('target_id', 'integer', ['signed' => false, 'null' => true, 'comment' => 'page_id / news_id / item_id by target_type; NULL for url'])
            ->addColumn('target_url', 'string', ['limit' => 2000, 'null' => true, 'comment' => 'target_type url: a site path or an https:// address'])
            ->addColumn('utm_source', 'string', ['limit' => 80, 'null' => false, 'default' => ''])
            ->addColumn('utm_medium', 'string', ['limit' => 80, 'null' => false, 'default' => ''])
            ->addColumn('utm_campaign', 'string', ['limit' => 80, 'null' => false, 'default' => ''])
            ->addColumn('utm_term', 'string', ['limit' => 80, 'null' => false, 'default' => ''])
            ->addColumn('utm_content', 'string', ['limit' => 80, 'null' => false, 'default' => ''])
            ->addColumn('label', 'string', ['limit' => 120, 'null' => false, 'default' => '', 'comment' => 'the owner\'s note, e.g. "Spring flyer 2027"'])
            ->addColumn('hits', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('last_hit_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['public_id'], ['name' => 'uq_short_links_public_id', 'unique' => true])
            ->addIndex(['path'], ['name' => 'uq_short_links_path', 'unique' => true])
            ->create();

        MigrationSupport::createInsensitiveCollation($this);
        MigrationSupport::ignoreCase($this, ['short_links' => ['path']]);
    }
}
