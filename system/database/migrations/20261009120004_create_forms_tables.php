<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;
use Talea\Core\MigrationSupport;

/** Baseline of the database schema. */
final class CreateFormsTables extends AbstractMigration
{
    public function change(): void
    {
        $prefix = (string) $this->getAdapter()->getOption('table_prefix'); // foreign key names are unique per database

        $this->table('enquiries', ['id' => false, 'primary_key' => ['enquiry_id']])
            ->addColumn('enquiry_id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('public_id', ...MigrationSupport::publicId($this->getAdapter()))
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('form', 'string', ['limit' => 120, 'null' => false, 'default' => ''])
            ->addColumn('source', 'string', ['limit' => 40, 'null' => false, 'default' => ''])
            ->addColumn('element', 'string', ['limit' => 16, 'null' => false, 'default' => ''])
            ->addColumn('page', 'string', ['limit' => 255, 'null' => false, 'default' => ''])
            ->addColumn('topic', 'string', ['limit' => 255, 'null' => false, 'default' => '', 'comment' => 'what it was about (2.12, Front\\EnquiryTopic): "<collection> – <item>", the page title or the pop-up name'])
            ->addColumn('landing_page', 'string', ['limit' => 255, 'null' => false, 'default' => '', 'comment' => 'the first page of the visit (2.3; only with consent to marketing)'])
            ->addColumn('referrer', 'string', ['limit' => 100, 'null' => false, 'default' => '', 'comment' => 'the site that sent the visitor (2.3; likewise)'])
            ->addColumn('campaign', 'string', ['limit' => 255, 'null' => false, 'default' => '', 'comment' => 'utm_* parameters of the page with the form (or of the visit, 2.3)'])
            ->addColumn('email', 'string', ['limit' => 190, 'null' => false, 'default' => ''])
            ->addColumn('data', 'text', ['limit' => MysqlAdapter::TEXT_MEDIUM, 'null' => false])
            ->addColumn('status', 'tinyinteger', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('category', 'string', ['limit' => 12, 'null' => false, 'default' => '', 'comment' => 'triage (2.12, Core\\Triage): sales | support | job | supplier | spam | other; \'\' = not sorted yet'])
            ->addColumn('priority', 'tinyinteger', ['signed' => false, 'null' => false, 'default' => 0, 'comment' => '0 = not set, 1 low, 2 normal, 3 high'])
            ->addColumn('suggested_reply', 'text', ['null' => true, 'comment' => 'a drafted reply (never sent by itself)'])
            ->addColumn('triaged_by', 'string', ['limit' => 40, 'null' => false, 'default' => '', 'comment' => 'claude | assistant | rule | the user\'s name'])
            ->addColumn('triaged_at', 'datetime', ['null' => true])
            ->addColumn('note', 'text', ['null' => true, 'comment' => 'internal note (the visitor does not see it)'])
            ->addColumn('assigned_to', 'integer', ['signed' => false, 'null' => true, 'comment' => 'which user handles the enquiry'])
            ->addColumn('anonymised_at', 'datetime', ['null' => true, 'comment' => 'the person\'s data was blanked at this time (2.14, Core\\Privacy); NULL = still held'])
            ->addIndex(['public_id'], ['name' => 'uq_enquiries_public_id', 'unique' => true])
            ->addIndex(['status', 'enquiry_id'], ['name' => 'ix_enquiries_status_enquiry_id'])
            ->addIndex(['category', 'enquiry_id'], ['name' => 'ix_enquiries_category_enquiry_id'])
            ->create();

        $this->table('consents', ['id' => false, 'primary_key' => ['consent_id']])
            ->addColumn('consent_id', 'biginteger', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('visitor_token', 'char', ['limit' => 32, 'null' => false, 'comment' => 'a random identifier stored in the visitor\'s cookie'])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('categories', 'string', ['limit' => 60, 'null' => false, 'comment' => '"analytics,marketing" or "none"'])
            ->addIndex(['created_at'], ['name' => 'ix_consents_created_at'])
            ->addIndex(['visitor_token'], ['name' => 'ix_consents_visitor_token'])
            ->create();

        $this->table('subscribers', ['id' => false, 'primary_key' => ['subscriber_id']])
            ->addColumn('subscriber_id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('public_id', ...MigrationSupport::publicId($this->getAdapter()))
            ->addColumn('email', 'string', ['limit' => 190, 'null' => false])
            ->addColumn('status', 'tinyinteger', ['signed' => false, 'null' => false, 'default' => 0, 'comment' => '0 awaiting confirmation, 1 confirmed'])
            ->addColumn('token', 'char', ['limit' => 32, 'null' => false, 'comment' => 'confirming and unsubscribing via a link'])
            ->addColumn('source', 'string', ['limit' => 255, 'null' => false, 'default' => '', 'comment' => 'the page they subscribed from'])
            ->addColumn('campaign', 'string', ['limit' => 255, 'null' => false, 'default' => '', 'comment' => 'utm_* of the page or of the visit (2.3)'])
            ->addColumn('landing_page', 'string', ['limit' => 255, 'null' => false, 'default' => '', 'comment' => 'the first page of the visit (2.3; only with consent to marketing)'])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('confirmed_at', 'datetime', ['null' => true])
            ->addColumn('sync', 'string', ['limit' => 10, 'null' => false, 'default' => '', 'comment' => 'mailing service: \'\' nothing, pending, ok, error'])
            ->addColumn('sync_error', 'string', ['limit' => 255, 'null' => false, 'default' => ''])
            ->addIndex(['public_id'], ['name' => 'uq_subscribers_public_id', 'unique' => true])
            ->addIndex(['email'], ['name' => 'uq_subscribers_email', 'unique' => true])
            ->addIndex(['token'], ['name' => 'uq_subscribers_token', 'unique' => true])
            ->create();

        $this->table('subscription_queue', ['id' => false, 'primary_key' => ['queue_id']])
            ->addColumn('queue_id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('email', 'string', ['limit' => 190, 'null' => false])
            ->addColumn('action', 'string', ['limit' => 10, 'null' => false, 'comment' => 'add | remove'])
            ->addColumn('attempts', 'tinyinteger', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('next_attempt_at', 'datetime', ['null' => true, 'comment' => 'next attempt; NULL = given up (visible in Subscribers)'])
            ->addColumn('error', 'string', ['limit' => 255, 'null' => false, 'default' => ''])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['next_attempt_at'], ['name' => 'ix_subscription_queue_next_attempt_at'])
            ->create();

        $this->table('newsletters', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('public_id', ...MigrationSupport::publicId($this->getAdapter()))
            ->addColumn('subject', 'string', ['limit' => 200, 'null' => false])
            ->addColumn('preheader', 'string', ['limit' => 200, 'null' => false, 'default' => '', 'comment' => 'preview text shown next to the subject in the inbox'])
            ->addColumn('intro', 'text', ['null' => false])
            ->addColumn('news_mode', 'string', ['limit' => 10, 'null' => false, 'default' => 'latest', 'comment' => 'latest | chosen | none'])
            ->addColumn('news_count', 'tinyinteger', ['signed' => false, 'null' => false, 'default' => 3, 'comment' => 'how many of the latest news items'])
            ->addColumn('news_ids', 'string', ['limit' => 500, 'null' => false, 'default' => '', 'comment' => 'chosen news items (idc, comma separated)'])
            ->addColumn('button_label', 'string', ['limit' => 80, 'null' => false, 'default' => ''])
            ->addColumn('button_url', 'string', ['limit' => 500, 'null' => false, 'default' => ''])
            ->addColumn('language', 'char', ['limit' => 2, 'null' => false, 'default' => '', 'comment' => 'language of the fixed texts and the news items (empty = the site language)'])
            ->addColumn('status', 'string', ['limit' => 10, 'null' => false, 'default' => 'draft', 'comment' => 'draft | scheduled | sending | sent'])
            ->addColumn('scheduled_at', 'datetime', ['null' => true])
            ->addColumn('html', 'text', ['limit' => MysqlAdapter::TEXT_MEDIUM, 'null' => true, 'comment' => 'the rendered e-mail, kept from the start of sending'])
            ->addColumn('text', 'text', ['limit' => MysqlAdapter::TEXT_MEDIUM, 'null' => true])
            ->addColumn('recipients', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('sent_count', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('failed_count', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('author', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('changed', 'datetime', ['null' => true])
            ->addColumn('started_at', 'datetime', ['null' => true])
            ->addColumn('finished_at', 'datetime', ['null' => true])
            ->addIndex(['public_id'], ['name' => 'uq_newsletters_public_id', 'unique' => true])
            ->addIndex(['status', 'scheduled_at'], ['name' => 'ix_newsletters_status_scheduled_at'])
            ->create();

        $this->table('newsletter_queue', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('newsletter_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('subscriber_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('attempts', 'tinyinteger', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('next_attempt', 'datetime', ['null' => true, 'comment' => 'NULL = done (sent or given up)'])
            ->addColumn('sent_at', 'datetime', ['null' => true])
            ->addColumn('error', 'string', ['limit' => 255, 'null' => false, 'default' => ''])
            ->addIndex(['newsletter_id', 'subscriber_id'], ['name' => 'uq_newsletter_queue_newsletter_id_subscriber_id', 'unique' => true])
            ->addIndex(['next_attempt'], ['name' => 'ix_newsletter_queue_next_attempt'])
            ->addIndex(['sent_at'], ['name' => 'ix_newsletter_queue_sent_at'])
            ->create();

        $this->table('testimonial_requests', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('enquiry_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('token_hash', 'char', ['limit' => 64, 'null' => false])
            ->addColumn('email', 'string', ['limit' => 190, 'null' => false, 'default' => ''])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('expires_at', 'datetime', ['null' => false])
            ->addColumn('used_at', 'datetime', ['null' => true])
            ->addColumn('item_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('consent', 'text', ['null' => true])
            ->addIndex(['token_hash'], ['name' => 'uq_testimonial_requests_token_hash', 'unique' => true])
            ->addIndex(['enquiry_id'], ['name' => 'ix_testimonial_requests_enquiry_id'])
            ->create();

        $this->table('requests', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('public_id', ...MigrationSupport::publicId($this->getAdapter()))
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addColumn('author_id', 'integer', ['signed' => false, 'null' => false, 'comment' => 'tl_users.user_id of the staff member who wrote it'])
            ->addColumn('title', 'string', ['limit' => 190, 'null' => false])
            ->addColumn('text', 'text', ['null' => false])
            ->addColumn('about', 'string', ['limit' => 500, 'null' => false, 'default' => '', 'comment' => 'what it is about: page:<ids> | news:<idc> | item:<idp> | a URL | \'\''])
            ->addColumn('attachments', 'string', ['limit' => 255, 'null' => false, 'default' => '[]', 'comment' => 'JSON list of tl_media.media_id (up to 5)'])
            ->addColumn('status', 'string', ['limit' => 12, 'null' => false, 'default' => 'new', 'comment' => 'new | in_progress | done | declined'])
            ->addColumn('done_at', 'datetime', ['null' => true, 'comment' => 'when it was marked done or declined'])
            ->addIndex(['public_id'], ['name' => 'uq_requests_public_id', 'unique' => true])
            ->addIndex(['status', 'id'], ['name' => 'ix_requests_status_id'])
            ->create();

        $this->table('request_messages', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('request_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('sender', 'string', ['limit' => 10, 'null' => false, 'comment' => 'claude | person'])
            ->addColumn('sender_name', 'string', ['limit' => 190, 'null' => false, 'default' => '', 'comment' => 'the person\'s name, or the name of the Claude connection'])
            ->addColumn('text', 'text', ['null' => false])
            ->addColumn('links', 'text', ['null' => true, 'comment' => 'JSON list of {"label": …, "url": …} – the drafts Claude made'])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['request_id', 'id'], ['name' => 'ix_request_messages_request_id_id'])
            ->addForeignKey('request_id', 'requests', 'id', ['constraint' => $prefix . 'fk_request_messages_request_id', 'delete' => 'CASCADE'])
            ->create();

    }
}
