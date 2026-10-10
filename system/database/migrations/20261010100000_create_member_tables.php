<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Talea\Core\MigrationSupport;

/** Member login (HF-33, Core\Members): visitor accounts, groups, sign-in tokens, member sessions and the gating of content. */
final class CreateMemberTables extends AbstractMigration
{
    public function change(): void
    {
        $prefix = (string) $this->getAdapter()->getOption('table_prefix'); // foreign key names are unique per database

        $this->table('member_groups', ['id' => false, 'primary_key' => ['group_id']])
            ->addColumn('group_id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('public_id', ...MigrationSupport::publicId($this->getAdapter()))
            ->addColumn('name', 'string', ['limit' => 80, 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['public_id'], ['name' => 'uq_member_groups_public_id', 'unique' => true])
            ->addIndex(['name'], ['name' => 'uq_member_groups_name', 'unique' => true])
            ->create();

        $this->table('members', ['id' => false, 'primary_key' => ['member_id']])
            ->addColumn('member_id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('public_id', ...MigrationSupport::publicId($this->getAdapter()))
            ->addColumn('email', 'string', ['limit' => 190, 'null' => false, 'comment' => 'lower case; personal data (Core\\PersonalData finds, exports and erases it)'])
            ->addColumn('name', 'string', ['limit' => 100, 'null' => false, 'default' => ''])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('confirmed_at', 'datetime', ['null' => true, 'comment' => 'the first sign-in link that was used; NULL = invited or signed up, not confirmed yet (cannot sign in)'])
            ->addColumn('last_login_at', 'datetime', ['null' => true])
            ->addIndex(['public_id'], ['name' => 'uq_members_public_id', 'unique' => true])
            ->addIndex(['email'], ['name' => 'uq_members_email', 'unique' => true])
            ->create();

        $this->table('member_group_links', ['id' => false, 'primary_key' => ['member_id', 'group_id']])
            ->addColumn('member_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('group_id', 'integer', ['signed' => false, 'null' => false])
            ->addIndex(['group_id'], ['name' => 'ix_member_group_links_group_id'])
            ->addForeignKey('member_id', 'members', 'member_id', ['constraint' => $prefix . 'fk_member_group_links_member_id', 'delete' => 'CASCADE'])
            ->addForeignKey('group_id', 'member_groups', 'group_id', ['constraint' => $prefix . 'fk_member_group_links_group_id', 'delete' => 'CASCADE'])
            ->create();

        $this->table('member_tokens', ['id' => false, 'primary_key' => ['token_id']])
            ->addColumn('token_id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('member_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('token_hash', 'string', ['limit' => 64, 'null' => false, 'comment' => 'sha256 of the one-time token in the e-mailed link; the token itself is never stored'])
            ->addColumn('expires_at', 'datetime', ['null' => false])
            ->addColumn('used_at', 'datetime', ['null' => true, 'comment' => 'set once, atomically: a link works one time'])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['token_hash'], ['name' => 'uq_member_tokens_token_hash', 'unique' => true])
            ->addIndex(['member_id'], ['name' => 'ix_member_tokens_member_id'])
            ->addForeignKey('member_id', 'members', 'member_id', ['constraint' => $prefix . 'fk_member_tokens_member_id', 'delete' => 'CASCADE'])
            ->create();

        $this->table('member_sessions', ['id' => false, 'primary_key' => ['session_id']])
            ->addColumn('session_id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('member_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('token_hash', 'string', ['limit' => 64, 'null' => false, 'comment' => 'sha256 of the value of the tl_member cookie'])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('expires_at', 'datetime', ['null' => false])
            ->addIndex(['token_hash'], ['name' => 'uq_member_sessions_token_hash', 'unique' => true])
            ->addIndex(['member_id'], ['name' => 'ix_member_sessions_member_id'])
            ->addForeignKey('member_id', 'members', 'member_id', ['constraint' => $prefix . 'fk_member_sessions_member_id', 'delete' => 'CASCADE'])
            ->create();

        // which groups may read a page, a collection item or a news item; no row = public (content_type: page | item | news)
        $this->table('content_groups', ['id' => false, 'primary_key' => ['content_type', 'content_id', 'group_id']])
            ->addColumn('content_type', 'string', ['limit' => 10, 'null' => false])
            ->addColumn('content_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('group_id', 'integer', ['signed' => false, 'null' => false])
            ->addIndex(['group_id'], ['name' => 'ix_content_groups_group_id'])
            ->addForeignKey('group_id', 'member_groups', 'group_id', ['constraint' => $prefix . 'fk_content_groups_group_id', 'delete' => 'CASCADE'])
            ->create();
    }
}
