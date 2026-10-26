<?php

// Search data (2.13, Core\SearchData): Google Search Console (the properties, searchAnalytics.query with no, date, query, page and page+query dimensions, the sitemaps – Bearer
// token required) and Bing Webmaster Tools (GetQueryStats, GetPageStats, GetRankAndTrafficStats – the key as ?apikey=bing-test-key, otherwise 401).
// The log keeps what was asked (property, dimension, dates, whether a key came), never the key itself.
if ($path === '/webmasters/v3/sites' && $method === 'GET') {
    if (!str_starts_with($headers['authorization'] ?? '', 'Bearer ')) {
        return $reply(401, ['error' => ['code' => 401, 'message' => 'Login Required.']]);
    }
    $log('search', ['sites' => true]);

    return $reply(200, ['siteEntry' => [['siteUrl' => 'https://example.com/', 'permissionLevel' => 'siteFullUser'], ['siteUrl' => 'sc-domain:example.com', 'permissionLevel' => 'siteOwner']]]);
}
if (preg_match('#^/webmasters/v3/sites/([^/]+)/(searchAnalytics/query|sitemaps)$#', $path, $m) === 1) {
    if (!str_starts_with($headers['authorization'] ?? '', 'Bearer ')) {
        return $reply(401, ['error' => ['code' => 401, 'message' => 'Login Required.']]);
    }
    $site = rawurldecode($m[1]);
    if ($m[2] === 'sitemaps') {
        $log('search', ['site' => $site, 'sitemaps' => true]);

        return $reply(200, ['sitemap' => [['path' => rtrim(str_starts_with($site, 'sc-domain:') ? 'https://' . substr($site, 10) : $site, '/') . '/sitemap.xml', 'isPending' => false,
            'contents' => [['type' => 'web', 'submitted' => '12', 'indexed' => '9'], ['type' => 'image', 'submitted' => '3', 'indexed' => '1']]]]]);
    }
    $dimensions = (array) ($json['dimensions'] ?? []);
    $dimension = (string) ($dimensions[0] ?? '');
    $log('search', ['site' => $site, 'dimension' => implode('+', $dimensions), 'start' => $json['startDate'] ?? '', 'end' => $json['endDate'] ?? '', 'limit' => $json['rowLimit'] ?? 0]);
    if ($dimensions === []) {
        return $reply(200, ['rows' => [['clicks' => 50, 'impressions' => 1200, 'ctr' => 0.0417, 'position' => 5.5]], 'responseAggregationType' => 'byProperty']);
    }
    if ($dimension === 'date') {
        $days = [];
        for ($i = 0; $i < 28; $i++) {
            $days[] = ['keys' => [date('Y-m-d', strtotime($json['endDate'] . ' -' . $i . ' days'))], 'clicks' => 2, 'impressions' => 40, 'ctr' => 0.05, 'position' => 5.0];
        }

        return $reply(200, ['rows' => $days, 'responseAggregationType' => 'byProperty']);
    }
    if ($dimensions === ['page', 'query']) {
        return $reply(200, ['rows' => [
            ['keys' => ['https://example.com/services', 'talea services'], 'clicks' => 20, 'impressions' => 300, 'ctr' => 0.0667, 'position' => 4.0],
            ['keys' => ['https://example.com/services', 'company website free'], 'clicks' => 5, 'impressions' => 120, 'ctr' => 0.0417, 'position' => 9.5],
            ['keys' => ['https://example.com/', 'talea cms'], 'clicks' => 12, 'impressions' => 200, 'ctr' => 0.06, 'position' => 2.1],
        ], 'responseAggregationType' => 'byProperty']);
    }
    $rows = $dimension === 'page'
        ? [['keys' => ['https://example.com/services'], 'clicks' => 31, 'impressions' => 640, 'ctr' => 0.0484, 'position' => 6.2], ['keys' => ['https://example.com/'], 'clicks' => 12, 'impressions' => 200, 'ctr' => 0.06, 'position' => 2.1]]
        : [['keys' => ['talea cms'], 'clicks' => 42, 'impressions' => 900, 'ctr' => 0.0467, 'position' => 3.4], ['keys' => ['company website free'], 'clicks' => 7, 'impressions' => 310, 'ctr' => 0.0226, 'position' => 11.8]];

    return $reply(200, ['rows' => $rows, 'responseAggregationType' => 'byProperty']);
}
if (preg_match('#^/webmaster/api.svc/json/(GetQueryStats|GetPageStats|GetRankAndTrafficStats)$#', $path, $m) === 1) {
    $log('search', ['bing' => $m[1], 'site' => $query['siteUrl'] ?? '', 'has_key' => ($query['apikey'] ?? '') !== '']);
    if (($query['apikey'] ?? '') !== 'bing-test-key') {
        return $reply(401, ['ErrorCode' => 3, 'Message' => 'InvalidApiKey']);
    }
    $date = '/Date(' . ((time() - 5 * 86400) * 1000) . ')/';
    $old = '/Date(' . ((time() - 60 * 86400) * 1000) . ')/';
    if ($m[1] === 'GetRankAndTrafficStats') {
        return $reply(200, ['d' => [['Date' => $date, 'Clicks' => 6, 'Impressions' => 120], ['Date' => '/Date(' . ((time() - 6 * 86400) * 1000) . ')/', 'Clicks' => 3, 'Impressions' => 60], ['Date' => $old, 'Clicks' => 9, 'Impressions' => 90]]]);
    }
    $rows = $m[1] === 'GetPageStats'
        ? [['Query' => 'https://example.com/contact', 'Clicks' => 4, 'Impressions' => 50, 'AvgImpressionPosition' => 5.0, 'AvgClickPosition' => 3.0, 'Date' => $date]]
        : [['Query' => 'talea bing', 'Clicks' => 5, 'Impressions' => 100, 'AvgImpressionPosition' => 4.0, 'AvgClickPosition' => 2.0, 'Date' => $date],
            ['Query' => 'talea bing', 'Clicks' => 1, 'Impressions' => 20, 'AvgImpressionPosition' => 10.0, 'AvgClickPosition' => 8.0, 'Date' => $date],
            ['Query' => 'stale query', 'Clicks' => 9, 'Impressions' => 90, 'AvgImpressionPosition' => 1.0, 'AvgClickPosition' => 1.0, 'Date' => $old]];

    return $reply(200, ['d' => $rows]);
}

return false;
