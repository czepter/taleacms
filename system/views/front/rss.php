<?php
/**
 * @var Kaleta\Core\Settings $web
 * @var list<array<string, mixed>> $novinky
 * @var string $adresa  absolute url of the site with a trailing slash
 */
echo '<?xml version="1.0" encoding="utf-8"?>' . "\n";
?>
<rss version="2.0">
<channel>
	<title><?= e($web->get('site_name')) ?></title>
	<link><?= e($adresa) ?></link>
	<description><?= e($web->get('site_description')) ?></description>
	<language><?= e(\Kaleta\Core\Language::code()) ?></language>
	<generator>Kaleta <?= e(KALETA_VERSION) ?></generator>
<?php foreach ($novinky as $c): ?>
	<item>
		<title><?= e($c['title']) ?></title>
		<link><?= e($adresa . 'novinky/' . $c['slug']) ?></link>
		<guid isPermaLink="false">novinka-<?= (int) $c['news_id'] ?></guid>
		<pubDate><?= e(date(DATE_RSS, strtotime($c['published_at']))) ?></pubDate>
		<category><?= e($c['tema_jm']) ?></category>
		<description><?= e($c['intro']) ?></description>
	</item>
<?php endforeach ?>
</channel>
</rss>
