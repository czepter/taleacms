<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Multisite, the hub card of a site (docs/specs/multisite.md, "Solopreneur additions"): a short summary, an image and whether the
 * site is listed by the "Projects" element (Builder\Elements\SiteCards) on the owner's other sites. Owner table, written once.
 */
final class AddSiteCards extends AbstractMigration
{
    public function change(): void
    {
        $this->table('sites')
            ->addColumn('card_summary', 'string', ['limit' => 300, 'null' => false, 'default' => '', 'comment' => 'one or two sentences shown on the hub card'])
            ->addColumn('card_image', 'string', ['limit' => 255, 'null' => false, 'default' => '', 'comment' => 'media path of the hub card image'])
            ->addColumn('show_in_hub', 'boolean', ['null' => false, 'default' => false, 'comment' => 'listed by the Projects element on the other sites'])
            ->update();
    }
}
