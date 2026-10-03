<?php

// Google Business Profile (2.13, Core\GoogleBusiness): one account with two locations, the hours PATCH and the local post
// are logged (google-business.log) so the test can read what was sent, the reviews list has three reviews – two when the
// flag file kaleta-fake-<port>-google-fewer exists (a review deleted on Google must disappear from the site).
if (($headers['authorization'] ?? '') === '' && str_starts_with($path, '/v')) {
    return false; // every Business Profile call is authorised; an unauthorised one falls through to the 404
}
if ($method === 'GET' && $path === '/v1/accounts') {
    return $reply(200, ['accounts' => [['name' => 'accounts/100', 'accountName' => 'Test Company', 'type' => 'PERSONAL']]]);
}
if ($method === 'GET' && $path === '/v1/accounts/100/locations') {
    $log('google-business', ['locations' => true, 'readMask' => $query['readMask'] ?? '']);

    return $reply(200, ['locations' => [['name' => 'locations/2001', 'title' => 'Test Company – Prague'], ['name' => 'locations/2002', 'title' => 'Test Company – Brno']]]);
}
if ($method === 'PATCH' && $path === '/v1/locations/2001') {
    $log('google-business', ['patch' => $json, 'updateMask' => $query['updateMask'] ?? '']);

    return $reply(200, ['name' => 'locations/2001'] + (array) $json);
}
if ($method === 'POST' && $path === '/v4/accounts/100/locations/2001/localPosts') {
    $log('google-business', ['post' => $json]);

    return $reply(200, ['name' => 'accounts/100/locations/2001/localPosts/777', 'state' => 'LIVE'] + (array) $json);
}
if ($method === 'GET' && $path === '/v4/accounts/100/locations/2001/reviews') {
    $reviews = [
        ['reviewId' => 'rev-a', 'reviewer' => ['displayName' => 'Alena K.'], 'starRating' => 'FIVE', 'comment' => "Fast and friendly.\nRecommended.", 'createTime' => '2026-09-20T10:00:00Z', 'updateTime' => '2026-09-20T10:00:00Z',
            'reviewReply' => ['comment' => 'Thank you, Alena!', 'updateTime' => '2026-09-21T08:00:00Z']],
        ['reviewId' => 'rev-b', 'reviewer' => ['displayName' => 'Petr <b>N.</b>'], 'starRating' => 'FOUR', 'comment' => 'Good work, a bit late.', 'createTime' => '2026-09-10T09:00:00Z', 'updateTime' => '2026-09-10T09:00:00Z'],
        ['reviewId' => 'rev-c', 'reviewer' => ['displayName' => 'Anonymous', 'isAnonymous' => true], 'starRating' => 'TWO', 'comment' => 'Nobody answered the phone.', 'createTime' => '2026-08-01T12:00:00Z', 'updateTime' => '2026-08-01T12:00:00Z'],
    ];
    if (file_exists($fakeFile('google-fewer'))) {
        array_splice($reviews, 1, 1); // Petr deleted his review
    }
    $log('google-business', ['reviews' => count($reviews)]);

    return $reply(200, ['reviews' => $reviews, 'averageRating' => 4.3, 'totalReviewCount' => 27]);
}

return false;
