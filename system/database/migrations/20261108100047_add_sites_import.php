<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Bringing an existing site in (docs/specs/multisite.md, "Bringing existing sites in"): a site made from a Talea archive records the
 * archive's file name while cron imports it, the error that stopped it, and the people the archive named (authors, booking staff),
 * which the administrator can invite once it is done. Owner table.
 */
final class AddSitesImport extends AbstractMigration
{
    public function change(): void
    {
        $this->table('sites')
            ->addColumn('import_file', 'string', ['limit' => 150, 'null' => false, 'default' => '', 'comment' => 'archive being imported (storage/import/sites/<prefix>/); empty = not an import'])
            ->addColumn('import_error', 'string', ['limit' => 500, 'null' => false, 'default' => '', 'comment' => 'why the import stopped; empty = none'])
            ->addColumn('import_people', 'text', ['null' => true, 'comment' => 'JSON list of the names and e-mails the archive held, for the administrator to invite'])
            ->update();
    }
}
