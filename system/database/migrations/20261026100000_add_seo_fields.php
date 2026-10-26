<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * "Search engines" section of pages, news and collection items: focus keywords (one per line, the first is scored), the
 * key page flag, robots directives (Core\Robots), canonical address, breadcrumb title, sharing title and description, and
 * the last SEO score (NULL = no focus keyword).
 */
final class AddSeoFields extends AbstractMigration
{
    public function change(): void
    {
        foreach (['pages', 'news', 'collection_items'] as $name) {
            $this->table($name)
                ->addColumn('focus_keywords', 'string', ['limit' => 500, 'null' => false, 'default' => '', 'comment' => 'up to 5 keywords, one per line; the first is scored'])
                ->addColumn('key_page', 'boolean', ['null' => false, 'default' => 0])
                ->addColumn('robots', 'string', ['limit' => 200, 'null' => false, 'default' => '', 'comment' => 'normalised directives (Core\Robots)'])
                ->addColumn('canonical_url', 'string', ['limit' => 500, 'null' => false, 'default' => ''])
                ->addColumn('breadcrumb_title', 'string', ['limit' => 200, 'null' => false, 'default' => ''])
                ->addColumn('share_title', 'string', ['limit' => 200, 'null' => false, 'default' => ''])
                ->addColumn('share_description', 'string', ['limit' => 320, 'null' => false, 'default' => ''])
                ->addColumn('seo_score', 'tinyinteger', ['signed' => false, 'null' => true, 'comment' => 'NULL = no focus keyword'])
                ->addColumn('seo_checked_at', 'datetime', ['null' => true])
                ->update();
        }
    }
}
