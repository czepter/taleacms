<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * More search data per snapshot: the kinds total, day and page_query (Core\SearchData). page_query keeps the query in
 * `key` and the page address in `page`; for every other kind `page` is empty.
 */
final class AddSearchStatsPage extends AbstractMigration
{
    public function change(): void
    {
        $this->table('search_stats')
            ->addColumn('page', 'string', ['limit' => 255, 'null' => false, 'default' => '', 'after' => 'key', 'comment' => 'page_query: the page address'])
            ->addIndex(['engine', 'kind', 'day', 'page'], ['name' => 'ix_search_stats_engine_kind_day_page'])
            ->update();
    }
}
