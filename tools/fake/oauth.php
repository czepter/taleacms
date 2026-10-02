<?php

// OAuth of every service (2.13): the token endpoint exchanges the code (it must come with the PKCE verifier) and refreshes;
// revoke answers OK. /echo returns the Authorization header it got, so a test can see a call was authorised.
if ($method === 'POST' && $path === '/token') {
    parse_str($body, $form);
    $log('oauth', ['grant' => $form['grant_type'] ?? '', 'has_verifier' => ($form['code_verifier'] ?? '') !== '', 'client' => $form['client_id'] ?? '']);
    if (($form['client_secret'] ?? '') !== 'test-client-secret') {
        return $reply(401, ['error' => 'invalid_client', 'error_description' => 'The client secret is wrong.']);
    }
    if (($form['grant_type'] ?? '') === 'authorization_code' && ($form['code'] ?? '') === 'test-code' && ($form['code_verifier'] ?? '') !== '') {
        $claims = rtrim(strtr(base64_encode((string) json_encode(['email' => 'owner@example.com'])), '+/', '-_'), '=');

        return $reply(200, ['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 3600, 'scope' => 'openid email', 'id_token' => 'x.' . $claims . '.y']);
    }
    if (($form['grant_type'] ?? '') === 'refresh_token' && ($form['refresh_token'] ?? '') === 'refresh-1') {
        return $reply(200, ['access_token' => 'access-2', 'expires_in' => 3600]);
    }

    return $reply(400, ['error' => 'invalid_grant']);
}
if ($method === 'POST' && $path === '/revoke') {
    $log('oauth', ['revoked' => true]);

    return $reply(200, []);
}
if ($path === '/echo') {
    return $reply(200, ['authorization' => $headers['authorization'] ?? '', 'method' => $method, 'body' => $json]);
}

return false;
