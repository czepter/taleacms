<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Core\Search reads ة as ه and ٱ as ا (#159). News whose stored index still has those letters lose it here; Search::complete
 * fills it in again before the next search, as for news from before the index.
 */
final class RefoldArabicSearchIndex extends AbstractMigration
{
    public function up(): void
    {
        $this->getUpdateBuilder()
            ->update((string) $this->getAdapter()->getOption('table_prefix') . 'news')
            ->set('search_text', null)
            ->where(['OR' => [['search_text LIKE' => '%ة%'], ['search_text LIKE' => '%ٱ%']]])
            ->execute();
    }

    public function down(): void
    {
    }
}
