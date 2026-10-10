<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

/** Baseline of the database schema. */
final class CreateStatsTables extends AbstractMigration
{
    public function change(): void
    {
        $prefix = (string) $this->getAdapter()->getOption('table_prefix'); // foreign key names are unique per database

        $this->table('stats_days', ['id' => false, 'primary_key' => ['day']])
            ->addColumn('day', 'date', ['null' => false])
            ->addColumn('visits', 'integer', ['signed' => false, 'null' => false, 'default' => 0, 'comment' => 'unique visitors of the day'])
            ->addColumn('views', 'integer', ['signed' => false, 'null' => false, 'default' => 0, 'comment' => 'page views'])
            ->create();

        $this->table('stats_visitors', ['id' => false, 'primary_key' => ['day', 'visitor_hash']])
            ->addColumn('day', 'date', ['null' => false])
            ->addColumn('visitor_hash', 'char', ['limit' => 32, 'null' => false])
            ->create();

        $this->table('stats_news', ['id' => false, 'primary_key' => ['day', 'news_id']])
            ->addColumn('day', 'date', ['null' => false])
            ->addColumn('news_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('views', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->addIndex(['news_id'], ['name' => 'ix_stats_news_news_id'])
            ->addForeignKey('news_id', 'news', 'news_id', ['constraint' => $prefix . 'fk_stats_news_news_id', 'delete' => 'CASCADE'])
            ->create();

        $this->table('stats_pages', ['id' => false, 'primary_key' => ['day', 'path']])
            ->addColumn('day', 'date', ['null' => false])
            ->addColumn('path', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('views', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->create();

        $this->table('stats_campaigns', ['id' => false, 'primary_key' => ['day', 'campaign']])
            ->addColumn('day', 'date', ['null' => false])
            ->addColumn('campaign', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('visits', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->create();

        $this->table('stats_devices', ['id' => false, 'primary_key' => ['day', 'device']])
            ->addColumn('day', 'date', ['null' => false])
            ->addColumn('device', 'string', ['limit' => 10, 'null' => false, 'comment' => 'phone | tablet | computer'])
            ->addColumn('visits', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->create();

        $this->table('stats_conversions', ['id' => false, 'primary_key' => ['day', 'path', 'type']])
            ->addColumn('day', 'date', ['null' => false])
            ->addColumn('path', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('type', 'string', ['limit' => 10, 'null' => false, 'comment' => 'tel | mailto | whatsapp'])
            ->addColumn('count', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->create();

        $this->table('stats_sources', ['id' => false, 'primary_key' => ['day', 'source']])
            ->addColumn('day', 'date', ['null' => false])
            ->addColumn('source', 'string', ['limit' => 100, 'null' => false, 'comment' => 'the domain the visitor came from'])
            ->addColumn('count', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->create();

        $this->table('web_vitals', ['id' => false, 'primary_key' => ['day', 'path', 'metric', 'bucket']])
            ->addColumn('day', 'date', ['null' => false])
            ->addColumn('path', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('metric', 'string', ['limit' => 3, 'null' => false, 'comment' => 'lcp | cls | inp'])
            ->addColumn('bucket', 'tinyinteger', ['signed' => false, 'null' => false, 'comment' => 'index into Core\\WebVitals::BUCKETS[metric], the last one is open'])
            ->addColumn('samples', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->create();

        $this->table('search_stats', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('day', 'date', ['null' => false, 'comment' => 'the day of the snapshot (it covers the 28 days before it)'])
            ->addColumn('engine', 'string', ['limit' => 10, 'null' => false, 'comment' => 'google | bing'])
            ->addColumn('kind', 'string', ['limit' => 10, 'null' => false, 'comment' => 'query | page | sitemap'])
            ->addColumn('key', 'string', ['limit' => 255, 'null' => false, 'comment' => 'the query, the page address or the sitemap address'])
            ->addColumn('clicks', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('impressions', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('ctr', 'decimal', ['precision' => 6, 'scale' => 2, 'null' => false, 'default' => 0, 'comment' => 'per cent'])
            ->addColumn('position', 'decimal', ['precision' => 6, 'scale' => 1, 'null' => false, 'default' => 0, 'comment' => 'the average position in the results, 1 = first'])
            ->addIndex(['engine', 'kind', 'day'], ['name' => 'ix_search_stats_engine_kind_day'])
            ->create();

        $this->table('social_drafts', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('news_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('network', 'string', ['limit' => 20, 'null' => false, 'comment' => 'facebook | linkedin | x | instagram'])
            ->addColumn('text', 'text', ['null' => false])
            ->addColumn('link', 'string', ['limit' => 500, 'null' => false, 'default' => '', 'comment' => 'the news URL with utm_source, utm_medium, utm_campaign'])
            ->addColumn('image', 'string', ['limit' => 500, 'null' => false, 'default' => '', 'comment' => 'the news image or the picture the site draws (/og/…)'])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('copied_at', 'datetime', ['null' => true, 'comment' => 'when the person marked it as posted'])
            ->addIndex(['news_id', 'network'], ['name' => 'uq_social_drafts_news_id_network', 'unique' => true])
            ->addForeignKey('news_id', 'news', 'news_id', ['constraint' => $prefix . 'fk_social_drafts_news_id', 'delete' => 'CASCADE'])
            ->create();

        $this->table('google_reviews', ['id' => false, 'primary_key' => ['review_id']])
            ->addColumn('review_id', 'string', ['limit' => 190, 'null' => false, 'comment' => 'Google\'s reviewId'])
            ->addColumn('author', 'string', ['limit' => 190, 'null' => false, 'default' => '', 'comment' => 'the reviewer\'s public display name'])
            ->addColumn('stars', 'tinyinteger', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('comment', 'text', ['null' => true])
            ->addColumn('reviewed_at', 'datetime', ['null' => false])
            ->addColumn('reply', 'text', ['null' => true, 'comment' => 'the owner\'s reply'])
            ->addColumn('replied_at', 'datetime', ['null' => true])
            ->addColumn('fetched_at', 'datetime', ['null' => false, 'comment' => 'the last fetch that returned it'])
            ->addIndex(['stars', 'reviewed_at'], ['name' => 'ix_google_reviews_stars_reviewed_at'])
            ->create();

    }
}
