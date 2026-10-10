<?php

declare(strict_types=1);

namespace Talea\Front;

use Talea\Core\Antispam;
use Talea\Core\App;
use Talea\Core\Db;
use Talea\Core\Response;

/**
 * OAuth 2.1 for the Claude connector (and other MCP clients) according to the MCP authorization specification:
 *   /.well-known/oauth-protected-resource   protected resource metadata (RFC 9728) – who issues tokens for /mcp
 *   /.well-known/oauth-authorization-server authorization server metadata (RFC 8414)
 *   /oauth/register                         dynamic client registration (RFC 7591)
 *   /oauth/authorize                        start of sign-in → consent in the administration (admin.php?action=oauth)
 *   /oauth/token                            exchange of a code for tokens (PKCE S256) and token refresh
 *
 * The application gets the permissions of the user who allowed it – all of them, or (chosen on the consent screen, 2.2)
 * only drafts or only reading. The access token is valid for an hour, the
 * refresh token for 30 days, and it is exchanged for a new one on every use. Tokens are stored in tl_api_tokens (hashes
 * only) – disconnecting the application in "My account" deletes them.
 * At the domain root the metadata are at /.well-known/. A site in a subfolder serves them at /folder/.well-known/
 * (openid-configuration included), where MCP clients that follow the current specification look; for others, sign-in with
 * a personal token remains.
 */
final class OAuth
{
    public const int ACCESS_LIFETIME = 3600;
    public const int REFRESH_LIFETIME = 30 * 86400;
    private const int CODE_LIFETIME = 600;
    private const string CLIENT_PATTERN = '/^[a-f0-9]{32}$/';

    public function __construct(private readonly App $app)
    {
    }

    /** Handles an OAuth URL, or returns null when the path does not belong to OAuth. */
    public function handle(string $path): ?Response
    {
        $r = $this->app->request;
        $isOAuth = str_starts_with($path, '/.well-known/oauth-') || in_array($path, ['/.well-known/openid-configuration', '/oauth/register', '/oauth/authorize', '/oauth/token'], true);
        if (!$isOAuth) {
            return null;
        }
        if (!\Talea\Core\Extensions::isEnabled($this->app->settings(), 'claude')) {
            return Response::json(['error' => 'not_found', 'error_description' => 'The connection to Claude is turned off on this site.'], 404);
        }
        $isLocal = in_array((string) parse_url($r->origin(), PHP_URL_HOST), ['localhost', '127.0.0.1'], true);
        if (!$r->isHttps() && !$isLocal) {
            return Response::json(['error' => 'invalid_request', 'error_description' => 'OAuth is available only over HTTPS.'], 403);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            return new Response('', 204, self::cors() + ['Access-Control-Allow-Methods' => 'GET, POST, OPTIONS', 'Access-Control-Allow-Headers' => 'Authorization, Content-Type, MCP-Protocol-Version']);
        }

        return match (true) {
            str_starts_with($path, '/.well-known/oauth-protected-resource') => $this->json($this->resourceMetadata()),
            // a site in a subfolder cannot answer at the domain root (/.well-known/oauth-authorization-server/folder); MCP clients
            // then look for {issuer}/.well-known/openid-configuration, which it can (2.2)
            str_starts_with($path, '/.well-known/oauth-authorization-server'), $path === '/.well-known/openid-configuration' => $this->json($this->serverMetadata()),
            $path === '/oauth/register' => $this->register(),
            $path === '/oauth/authorize' => $this->authorize(),
            default => $this->token(),
        };
    }

    public function issuer(): string
    {
        return $this->app->request->origin() . rtrim($this->app->url(''), '/');
    }

    /** URL of the protected resource metadata – MCP sends it in the WWW-Authenticate header. */
    public function metadataUrl(): string
    {
        return $this->issuer() . '/.well-known/oauth-protected-resource';
    }

    /** @return array<string, mixed> */
    private function resourceMetadata(): array
    {
        return ['resource' => $this->issuer() . '/mcp', 'authorization_servers' => [$this->issuer()], 'bearer_methods_supported' => ['header'],
            'scopes_supported' => ['mcp'], 'resource_name' => $this->app->settings()->get('site_name')];
    }

    /** @return array<string, mixed> */
    private function serverMetadata(): array
    {
        $v = $this->issuer();

        return [
            'issuer' => $v, 'authorization_endpoint' => $v . '/oauth/authorize', 'token_endpoint' => $v . '/oauth/token', 'registration_endpoint' => $v . '/oauth/register',
            'response_types_supported' => ['code'], 'grant_types_supported' => ['authorization_code', 'refresh_token'], 'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'], 'scopes_supported' => ['mcp'],
            'service_documentation' => 'https://taleacms.com',
        ];
    }

    /** Dynamic client registration (RFC 7591): this is how Claude creates its own client_id with its redirect URI. */
    private function register(): Response
    {
        $r = $this->app->request;
        if (!$r->isPost()) {
            return $this->error('invalid_request', 'Registration is sent with the POST method.', 405);
        }
        $antispam = new Antispam($this->app->db(), $this->app->settings());
        if ($antispam->count($r->ip(), 'oauth-registrace', 0, 60) >= 20) {
            return $this->error('invalid_request', 'Too many registrations from this address. Try again in an hour.', 429);
        }
        $antispam->write($r->ip(), 'oauth-registrace', 0);
        $data = json_decode((string) file_get_contents('php://input'), true);
        $addresses = is_array($data['redirect_uris'] ?? null) ? array_values(array_filter($data['redirect_uris'], 'is_string')) : [];
        if ($addresses === [] || count($addresses) > 5 || array_filter($addresses, fn (string $a): bool => !self::isValidRedirectUri($a)) !== []) {
            return $this->error('invalid_redirect_uri', 'redirect_uris: 1–5 https:// addresses (or http://localhost for desktop applications) without a # part.');
        }
        $authMethod = in_array($data['token_endpoint_auth_method'] ?? 'none', ['none', 'client_secret_post', 'client_secret_basic'], true) ? (string) ($data['token_endpoint_auth_method'] ?? 'none') : 'none';
        $clientId = bin2hex(random_bytes(16));
        $secret = $authMethod === 'none' ? '' : bin2hex(random_bytes(32));
        $name = mb_substr(trim(strip_tags((string) ($data['client_name'] ?? ''))), 0, 100) ?: 'Aplikace MCP';
        $this->app->db()->insert('oauth_clients', ['client_id' => $clientId, 'secret_hash' => $secret === '' ? '' : hash('sha256', $secret), 'name' => $name,
            'redirect_uris' => (string) json_encode($addresses, JSON_UNESCAPED_SLASHES), 'created_at' => date('Y-m-d H:i:s')]);

        return $this->json(['client_id' => $clientId, 'client_id_issued_at' => time(), 'client_name' => $name, 'redirect_uris' => $addresses,
            'token_endpoint_auth_method' => $authMethod, 'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code']]
            + ($secret !== '' ? ['client_secret' => $secret, 'client_secret_expires_at' => 0] : []), 201);
    }

    /**
     * Start of sign-in: verifies the client and the redirect URI, saves the parameters in the session and sends the user
     * to consent in the administration (where they sign in first if needed). An invalid client or redirect URI is not
     * redirected anywhere (open redirect).
     */
    private function authorize(): Response
    {
        $r = $this->app->request;
        $client = $this->client($r->get('client_id'));
        $redirectUri = $r->get('redirect_uri');
        if ($client === null || !in_array($redirectUri, json_decode((string) $client['redirect_uris'], true) ?: [], true)) {
            return new Response('<!doctype html><meta charset="utf-8"><title>' . e(t('Invalid sign-in request')) . '</title><p style="font:16px system-ui;margin:3em">'
                . e(t('The application did not register correctly with this website (unknown client or return address). Please connect it again.')) . '</p>', 400, ['Content-Type' => 'text/html; charset=utf-8']);
        }
        // 3.3.2 (N8): before consent nothing is redirected – a client registered by anyone with any https address would make
        // this site a redirector; a request Claude never sends (no code flow, no PKCE) gets a page with the reason instead
        $back = fn (string $error, string $description): Response => new Response('<!doctype html><meta charset="utf-8"><title>' . e(t('Invalid sign-in request')) . '</title><p style="font:16px system-ui;margin:3em">'
            . e(t('The application sent an incomplete sign-in request, so it was stopped here. Please connect it again.')) . ' <code>' . e($error) . '</code></p>', 400, ['Content-Type' => 'text/html; charset=utf-8']);
        if ($r->get('response_type') !== 'code') {
            return $back('unsupported_response_type', 'Only response_type=code is supported.');
        }
        if (!preg_match('/^[A-Za-z0-9_-]{43,128}$/', $r->get('code_challenge')) || $r->get('code_challenge_method') !== 'S256') {
            return $back('invalid_request', 'PKCE is missing (code_challenge with the S256 method).');
        }
        $this->app->session->set('pending_oauth', [
            'client_id' => $client['client_id'], 'name' => $client['name'], 'redirect_uri' => $redirectUri, 'state' => mb_substr($r->get('state'), 0, 500),
            'challenge' => $r->get('code_challenge'), 'time' => time(),
        ]);

        return Response::redirect($this->app->url('admin.php?action=oauth'));
    }

    /**
     * User consent (called by the administration after confirmation): a one-time code valid for 10 minutes and a return
     * to the application.
     *
     * @param array<string, mixed> $pending parameters saved in authorize()
     */
    public function issueCode(array $pending, int $idu, string $access = 'full'): string
    {
        $code = bin2hex(random_bytes(32));
        $this->app->db()->insert('oauth_codes', ['code_hash' => hash('sha256', $code), 'client_id' => $pending['client_id'], 'user_id' => $idu, 'redirect_uri' => $pending['redirect_uri'],
            'code_challenge' => $pending['challenge'], 'access' => self::access($access), 'expires_at' => date('Y-m-d H:i:s', time() + self::CODE_LIFETIME)]);

        return self::withParams((string) $pending['redirect_uri'], ['code' => $code, 'state' => (string) $pending['state'], 'iss' => $this->issuer()]);
    }

    /** @param array<string, mixed> $pending */
    public function deny(array $pending): string
    {
        return self::withParams((string) $pending['redirect_uri'], ['error' => 'access_denied', 'error_description' => 'The user did not allow access.', 'state' => (string) $pending['state'], 'iss' => $this->issuer()]);
    }

    private function token(): Response
    {
        $r = $this->app->request;
        if (!$r->isPost()) {
            return $this->error('invalid_request', 'The token is requested with the POST method.', 405);
        }
        [$clientId, $secret] = $this->clientCredentials();
        $client = $this->client($clientId);
        if ($client === null || ($client['secret_hash'] !== '' && !hash_equals((string) $client['secret_hash'], hash('sha256', $secret)))) {
            return $this->error('invalid_client', 'Unknown client or wrong client secret.', 401);
        }
        $db = $this->app->db();
        $now = date('Y-m-d H:i:s');
        if ($r->post('grant_type') === 'authorization_code') {
            $code = $db->one('SELECT * FROM {oauth_codes} WHERE code_hash = ?', [hash('sha256', $r->post('code'))]);
            if ($code !== null) {
                $db->delete('oauth_codes', ['code_hash' => $code['code_hash']]); // the code is valid only once
            }
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $r->post('code_verifier'), true)), '+/', '-_'), '=');
            if ($code === null || $code['expires_at'] < $now || $code['client_id'] !== $clientId || $code['redirect_uri'] !== $r->post('redirect_uri') || !hash_equals((string) $code['code_challenge'], $challenge)) {
                return $this->error('invalid_grant', 'The code is invalid, expired, already used, or the return address or PKCE does not match.');
            }

            return $this->issueTokens($db, (int) $code['user_id'], $client, (string) $code['access']);
        }
        if ($r->post('grant_type') === 'refresh_token') {
            $refresh = $db->one("SELECT * FROM {api_tokens} WHERE token_hash = ? AND kind = 'refresh'", [hash('sha256', $r->post('refresh_token'))]);
            if ($refresh === null || $refresh['client_id'] !== $clientId || (string) $refresh['expires_at'] < $now) {
                return $this->error('invalid_grant', 'The refresh token is invalid or expired – connect the application again.');
            }
            $db->delete('api_tokens', ['token_id' => (int) $refresh['token_id']]); // rotation: the old refresh token ends

            return $this->issueTokens($db, (int) $refresh['user_id'], $client, (string) $refresh['access']); // the access chosen at consent stays
        }

        return $this->error('unsupported_grant_type', 'Only authorization_code and refresh_token are supported.');
    }

    /** @param array<string, mixed> $client */
    private function issueTokens(Db $db, int $idu, array $client, string $level): Response
    {
        $user = $db->one('SELECT user_id FROM {users} WHERE user_id = ? AND blocked = 0', [$idu]);
        if ($user === null) {
            return $this->error('invalid_grant', 'The account that allowed the application no longer has access.');
        }
        $access = 'talea_oa_' . bin2hex(random_bytes(24));
        $refresh = 'talea_or_' . bin2hex(random_bytes(24));
        foreach ([[$access, 'access', self::ACCESS_LIFETIME], [$refresh, 'refresh', self::REFRESH_LIFETIME]] as [$token, $kind, $lifetime]) {
            $db->insert('api_tokens', ['user_id' => $idu, 'name' => $client['name'], 'client_id' => $client['client_id'], 'kind' => $kind, 'access' => self::access($level),
                'expires_at' => date('Y-m-d H:i:s', time() + $lifetime), 'token_hash' => hash('sha256', $token), 'created_at' => date('Y-m-d H:i:s')]);
        }
        // cleanup of expired tokens and codes
        $db->run("DELETE FROM {api_tokens} WHERE kind <> 'token' AND expires_at < ?", [date('Y-m-d H:i:s')]);
        $db->run('DELETE FROM {oauth_codes} WHERE expires_at < ?', [date('Y-m-d H:i:s')]);

        return $this->json(['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => self::ACCESS_LIFETIME, 'refresh_token' => $refresh, 'scope' => 'mcp']);
    }

    /** @return array{0: string, 1: string} client_id and secret from the Basic header or from the form */
    private function clientCredentials(): array
    {
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Basic\s+(\S+)$/i', $header, $m) && str_contains((string) base64_decode($m[1], true), ':')) {
            [$id, $secret] = explode(':', (string) base64_decode($m[1], true), 2);

            return [rawurldecode($id), rawurldecode($secret)];
        }

        return [$this->app->request->post('client_id'), $this->app->request->post('client_secret')];
    }

    /** @return array<string, mixed>|null */
    private function client(string $clientId): ?array
    {
        return preg_match(self::CLIENT_PATTERN, $clientId) ? $this->app->db()->one('SELECT * FROM {oauth_clients} WHERE client_id = ?', [$clientId]) : null;
    }

    /** A known connection access (Mcp\Catalog::CONNECTION_ACCESS); anything else is read-only. */
    public static function access(string $access): string
    {
        return isset(\Talea\Mcp\Catalog::CONNECTION_ACCESS[$access]) ? $access : 'read';
    }

    public static function isValidRedirectUri(string $url): bool
    {
        if (strlen($url) > 500 || str_contains($url, '#') || preg_match('/[\s<>"\\\\]/', $url)) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts) && isset($parts['host']) && (($parts['scheme'] ?? '') === 'https'
            || (($parts['scheme'] ?? '') === 'http' && in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true)));
    }

    /** @param array<string, string> $params */
    private static function withParams(string $url, array $params): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query(array_filter($params, fn (string $h): bool => $h !== ''));
    }

    /** @return array<string, string> */
    private static function cors(): array
    {
        return ['Access-Control-Allow-Origin' => '*'];
    }

    private function json(array $data, int $status = 200): Response
    {
        return new Response((string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $status,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'] + self::cors());
    }

    private function error(string $code, string $description, int $status = 400): Response
    {
        return $this->json(['error' => $code, 'error_description' => $description], $status);
    }
}
