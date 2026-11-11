<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Talea\Core\Uuid;

/**
 * A personal link carries what the person sees filled in (Core\FormLinks): its drafts (our suggestion first, then the person's own), fixed values for the item it
 * makes, and optionally an existing collection item the person reviews (their answer waits as a draft next to it). A link is
 * edited and revoked from the admin, so it gets a public id; existing rows get one here.
 */
final class FormLinksCarryPrefill extends AbstractMigration
{
    public function up(): void
    {
        $this->table('form_links')
            ->addColumn('public_id', 'string', ['limit' => 36, 'null' => true, 'comment' => 'UUID v4 of the link (Core\\Uuid, filled by Db::insert)'])
            ->addColumn('drafts', 'text', ['null' => true, 'comment' => 'JSON list of drafts the form shows filled in, newest last: [{at, by: site|person, values: field key => answer}]; the first is usually our suggestion'])
            ->addColumn('fixed', 'text', ['null' => true, 'comment' => 'JSON: collection field key => value the item gets, never shown to the person'])
            ->addColumn('target_item_id', 'integer', ['signed' => false, 'null' => true, 'comment' => 'tl_collection_items.item_id the person reviews; their answer is a draft next to it'])
            ->addColumn('state', 'string', ['limit' => 10, 'null' => false, 'default' => '', 'comment' => 'revoked, or the answer to a reviewed item: waiting | applied | discarded'])
            ->update();
        $pdo = $this->getAdapter()->getConnection();
        $table = (string) $this->getAdapter()->getOption('table_prefix') . 'form_links';
        $update = $pdo->prepare("UPDATE {$table} SET public_id = ? WHERE id = ?");
        foreach ($pdo->query("SELECT id FROM {$table}")->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $update->execute([Uuid::v4(), (int) $row['id']]);
        }
        $this->table('form_links')->addIndex(['public_id'], ['name' => 'uq_form_links_public_id', 'unique' => true])->update();
    }

    public function down(): void
    {
        $this->table('form_links')->removeIndexByName('uq_form_links_public_id')
            ->removeColumn('public_id')->removeColumn('drafts')->removeColumn('fixed')->removeColumn('target_item_id')->removeColumn('state')->update();
    }
}
