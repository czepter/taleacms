<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The personal links of a form (a testimonial request is the first kind): the table is called form_links, a link needs no
 * enquiry and no e-mail address, and carries a label for the person it was made for. No installed base, so no copy of the old name.
 */
final class RenameTestimonialRequestsToFormLinks extends AbstractMigration
{
    public function change(): void
    {
        $this->table('testimonial_requests')->rename('form_links')->update();
        $this->table('form_links')
            ->addColumn('label', 'string', ['limit' => 190, 'null' => false, 'default' => '', 'after' => 'email', 'comment' => 'who the link was made for'])
            ->update();
    }
}
