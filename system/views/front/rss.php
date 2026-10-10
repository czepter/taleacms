<?php
/**
 * @var Talea\Core\Settings $web
 * @var list<array<string, mixed>> $news
 * @var string $url  absolute url of the site with a trailing slash
 */
echo '<?xml version="1.0" encoding="utf-8"?>' . "\n";
?>
<rss version="2.0">
<channel>
	<title><?= e($web->get('site_name')) ?></title>
	<link><?= e($url) ?></link>
	<description><?= e($web->get('site_description')) ?></description>
	<language><?= e(\Talea\Core\Language::code()) ?></language>
	<generator>Talea <?= e(TALEA_VERSION) ?></generator>
<?php foreach ($news as $c): ?>
	<item>
		<title><?= e($c['title']) ?></title>
		<link><?= e($url . 'news/' . $c['slug']) ?></link>
		<guid isPermaLink="false">news-<?= e($c['public_id']) ?></guid>
		<pubDate><?= e(date(DATE_RSS, strtotime($c['published_at']))) ?></pubDate>
		<category><?= e($c['category_name']) ?></category>
		<description><?= e($c['intro']) ?></description>
	</item>
<?php endforeach ?>
</channel>
</rss>
