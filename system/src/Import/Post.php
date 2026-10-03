<?php

declare(strict_types=1);

namespace Kaleta\Import;

/**
 * A post or page of the old site (Import\Source), normalised: what every system has in some form. The mapping
 * (Import\Mapping) decides whether it becomes a news item or a page; Import\Batch cleans the HTML, creates the record,
 * the redirect from $oldUrl and later downloads the images.
 */
final readonly class Post
{
    public const array TYPES = ['post', 'page'];

    /** scheduled = published with a future date (the site publishes it itself when the date comes). */
    public const array STATUSES = ['published', 'draft', 'scheduled'];

    /**
     * @param string $key stable identifier in the source (post number, uuid, Atom id)
     * @param string $type post | page
     * @param string $html raw HTML of the source – cleaned by the runner, never trusted
     * @param string $status published | draft | scheduled
     * @param string $publishedAt ISO 8601 or any strtotime() date; empty = today
     * @param list<string> $categoryKeys keys of Category records (a system without categories may put its primary tag here)
     * @param list<string> $tagKeys keys of Tag records
     * @param string $featureImageUrl absolute URL, or empty
     * @param string $oldUrl the address on the old site (absolute, or a path like /2019/05/slug.html) for the redirect
     * @param string $language two-letter code when the source knows it, else empty (the mapping decides)
     * @param list<string> $warnings what the source could not convert, as codes ('block:image'); the preview counts them
     */
    public function __construct(
        public string $key,
        public string $type,
        public string $title,
        public string $slug,
        public string $html,
        public string $excerpt = '',
        public string $status = 'published',
        public string $publishedAt = '',
        public string $updatedAt = '',
        public string $authorKey = '',
        public array $categoryKeys = [],
        public array $tagKeys = [],
        public string $featureImageUrl = '',
        public string $seoTitle = '',
        public string $seoDescription = '',
        public string $oldUrl = '',
        public string $language = '',
        public array $warnings = [],
    ) {
    }
}
