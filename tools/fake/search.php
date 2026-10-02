<?php

// Search data (2.13, Core\SearchData): Google Search Console (the properties, searchAnalytics.query, the sitemaps – Bearer
// token required) and Bing Webmaster Tools (GetQueryStats, GetPageStats – the key as ?apikey=bing-test-key, otherwise 401).
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
    $dimension = (string) (($json['dimensions'] ?? [])[0] ?? '');
    $log('search', ['site' => $site, 'dimension' => $dimension, 'start' => $json['startDate'] ?? '', 'end' => $json['endDate'] ?? '', 'limit' => $json['rowLimit'] ?? 0]);
    $rows = $dimension === 'page'
        ? [['keys' => ['https://example.com/sluzby'], 'clicks' => 31, 'impressions' => 640, 'ctr' => 0.0484, 'position' => 6.2], ['keys' => ['https://example.com/'], 'clicks' => 12, 'impressions' => 200, 'ctr' => 0.06, 'position' => 2.1]]
        : [['keys' => ['kaleta cms'], 'clicks' => 42, 'impressions' => 900, 'ctr' => 0.0467, 'position' => 3.4], ['keys' => ['firemní web zdarma'], 'clicks' => 7, 'impressions' => 310, 'ctr' => 0.0226, 'position' => 11.8]];

    return $reply(200, ['rows' => $rows, 'responseAggregationType' => 'byProperty']);
}
if (preg_match('#^/webmaster/api.svc/json/(GetQueryStats|GetPageStats)$#', $path, $m) === 1) {
    $log('search', ['bing' => $m[1], 'site' => $query['siteUrl'] ?? '', 'has_key' => ($query['apikey'] ?? '') !== '']);
    if (($query['apikey'] ?? '') !== 'bing-test-key') {
        return $reply(401, ['ErrorCode' => 3, 'Message' => 'InvalidApiKey']);
    }
    $date = '/Date(' . ((time() - 5 * 86400) * 1000) . ')/';
    $old = '/Date(' . ((time() - 60 * 86400) * 1000) . ')/';
    $rows = $m[1] === 'GetPageStats'
        ? [['Query' => 'https://example.com/kontakt', 'Clicks' => 4, 'Impressions' => 50, 'AvgImpressionPosition' => 5.0, 'AvgClickPosition' => 3.0, 'Date' => $date]]
        : [['Query' => 'kaleta bing', 'Clicks' => 5, 'Impressions' => 100, 'AvgImpressionPosition' => 4.0, 'AvgClickPosition' => 2.0, 'Date' => $date],
            ['Query' => 'kaleta bing', 'Clicks' => 1, 'Impressions' => 20, 'AvgImpressionPosition' => 10.0, 'AvgClickPosition' => 8.0, 'Date' => $date],
            ['Query' => 'stale query', 'Clicks' => 9, 'Impressions' => 90, 'AvgImpressionPosition' => 1.0, 'AvgClickPosition' => 1.0, 'Date' => $old]];

    return $reply(200, ['d' => $rows]);
}

return false;
