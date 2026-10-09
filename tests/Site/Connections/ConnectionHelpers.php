<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\Connections;

use Kaleta\Tests\Site\Support\Http;

/** Shared helpers of the connection tests: MCP answers as text, and the OAuth connector flow of section 43 (PKCE, consent, tokens). */
trait ConnectionHelpers
{
    protected const string REDIRECT_URI = 'https://claude.ai/api/mcp/auth_callback';

    /** The text a tool returned (old mcp_text). @param array<string, mixed> $answer */
    protected function answerText(array $answer): string
    {
        return (string) ($answer['result']['content'][0]['text'] ?? '');
    }

    /** The whole JSON-RPC answer as one string (what the old script grepped in $WORK/response). @param array<string, mixed> $answer */
    protected function answerRaw(array $answer): string
    {
        return (string) json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, mixed>|object $arguments */
    protected function toolText(string $tool, array|object $arguments = [], ?string $token = null): string
    {
        return $this->answerText($this->site()->mcp($tool, $arguments, $token));
    }

    /** The first "id" number in a tool's text, like the old grep. @param array<string, mixed> $answer */
    protected function firstId(array $answer): int
    {
        return preg_match('/"id":(\d+)/', $this->answerText($answer), $m) === 1 ? (int) $m[1] : 0;
    }

    protected function pkceVerifier(): string
    {
        return str_repeat('v', 50);
    }

    protected function pkceChallenge(): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $this->pkceVerifier(), true)), '+/', '-_'), '=');
    }

    /** Dynamic registration of a connector client (section 43). */
    protected function registerClient(): string
    {
        $body = json_encode(['client_name' => 'Claude', 'redirect_uris' => [self::REDIRECT_URI], 'token_endpoint_auth_method' => 'none']);
        $response = $this->site()->client('oauth')->post('/oauth/register', (string) $body, ['Content-Type: application/json']);
        $client = (string) (($response->json())['client_id'] ?? '');
        $this->assertNotSame('', $client, 'dynamic client registration');

        return $client;
    }

    /**
     * authorize -> consent page -> approve; returns the authorization code.
     *
     * @param array<string, string> $consent extra consent fields (access=drafts)
     */
    protected function authorizationCode(Http $browser, string $client, string $state, array $consent = []): string
    {
        $browser->get('/oauth/authorize?response_type=code&client_id=' . $client . '&redirect_uri=' . self::REDIRECT_URI
            . '&code_challenge=' . $this->pkceChallenge() . '&code_challenge_method=S256&state=' . $state);
        $csrf = $browser->get('/admin.php?action=oauth')->csrf();
        $redirect = $browser->post('/admin.php?action=oauth', ['_csrf' => $csrf, 'allow' => '1'] + $consent)->redirect;

        return preg_match('/code=([a-f0-9]+)/', $redirect, $m) === 1 ? $m[1] : '';
    }

    /** @return array<string, mixed> the token endpoint's JSON */
    protected function exchangeCode(string $client, string $code): array
    {
        $response = $this->site()->client('oauth')->post('/oauth/token', [
            'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::REDIRECT_URI, 'client_id' => $client, 'code_verifier' => $this->pkceVerifier(),
        ]);

        return (array) $response->json();
    }
}
