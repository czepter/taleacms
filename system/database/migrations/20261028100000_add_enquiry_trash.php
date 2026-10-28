<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A trash for enquiries (Admin\Modules\Enquiries): a deleted enquiry stays restorable for 30 days, then it is removed with
 * its attachments. Every list, count and report leaves rows with deleted_at out.
 */
final class AddEnquiryTrash extends AbstractMigration
{
    public function change(): void
    {
        $this->table('enquiries')
            ->addColumn('deleted_at', 'datetime', ['null' => true, 'comment' => 'in the trash since (deleted permanently after 30 days); NULL = not in the trash'])
            ->addIndex(['deleted_at'], ['name' => 'ix_enquiries_deleted_at'])
            ->update();
    }
}
