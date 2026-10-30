<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Multisite, shared components and facts (docs/specs/multisite.md): both are owner tables, so one row can serve every site of the
 * owner. site_id says whose it is: the site that made it, or NULL = shared with every site. The rows that exist now belong to site 1.
 *
 * The unique key of facts becomes (fact_key, language, site_id). A NULL in a unique key never collides (MySQL, PostgreSQL, SQLite),
 * so "one shared row per key and language" is kept by Core\Facts::save, not by the key. Owner tables: changed once, in the owner run.
 */
final class AddComponentAndFactSite extends AbstractMigration
{
    public function up(): void
    {
        $this->table('components')
            ->addColumn('site_id', 'integer', ['signed' => false, 'null' => true, 'comment' => 'the site the component belongs to; NULL = shared with every site of the owner'])
            ->addIndex(['site_id'], ['name' => 'ix_components_site_id'])
            ->update();
        $this->table('facts')
            ->addColumn('site_id', 'integer', ['signed' => false, 'null' => true, 'comment' => 'the site the fact belongs to; NULL = shared with every site of the owner'])
            ->update();
        $this->table('facts')->removeIndexByName('uq_facts_fact_key_language')->update();
        $this->table('facts')->addIndex(['fact_key', 'language', 'site_id'], ['name' => 'uq_facts_fact_key_language_site_id', 'unique' => true])->update();

        if ((string) $this->getAdapter()->getOption('table_prefix') === (string) $this->getAdapter()->getOption('owner_prefix')) {
            foreach (['components', 'facts'] as $table) {
                $this->getUpdateBuilder()->update($this->getAdapter()->getOption('table_prefix') . $table)->set('site_id', 1)->execute();
            }
        }
    }

    public function down(): void
    {
        $this->table('facts')->removeIndexByName('uq_facts_fact_key_language_site_id')->update();
        $this->table('facts')->addIndex(['fact_key', 'language'], ['name' => 'uq_facts_fact_key_language', 'unique' => true])->update();
        $this->table('facts')->removeColumn('site_id')->update();
        $this->table('components')->removeIndexByName('ix_components_site_id')->update();
        $this->table('components')->removeColumn('site_id')->update();
    }
}
