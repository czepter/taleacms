<?php

declare(strict_types=1);

namespace Talea\Tests\Site\FormsHygiene;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** find_claims reads every page, news item and collection item, so it needs the Business section like the other fact tools. */
#[Group('site')]
final class FindClaimsScopeTest extends SiteTestCase
{
    use McpHelpers;

    public function testANewsAuthorCannotReadOtherContentThroughFindClaims(): void
    {
        $site = $this->site();
        $site->mcpResult('create_news', ['title' => 'Secret draft', 'category' => 'News', 'content' => '<p>Marker Zebra draft costs 4711 euros.</p>']);
        $this->createPage(['title' => 'Hidden', 'visible' => false, 'content' => '<p>Marker Zebra page costs 4712 euros.</p>']);

        $this->adminPost('/admin.php?module=users&action=save', ['user_id' => 0, 'name' => 'Claims Author', 'username' => 'claimsauthor', 'password' => $site->password, 'admin' => 0], '/admin.php?module=users&action=new');
        $token = 'talea_' . str_repeat('b', 48);
        $site->exec('INSERT INTO tl_api_tokens (user_id, name, token_hash, created_at) SELECT user_id, ?, ?, NOW() FROM tl_users WHERE username = ?', ['claims', hash('sha256', $token), 'claimsauthor']);

        foreach ([['text' => 'Marker Zebra'], []] as $args) {
            $out = $site->mcpText('find_claims', $args, token: $token);
            $this->assertStringNotContainsString('Zebra', $out, 'no content of other people leaks');
            $this->assertStringContainsString('Business details', $out, 'the call is refused');
        }

        $this->assertStringContainsString('Marker Zebra', $site->mcpText('find_claims', ['text' => 'Marker Zebra']), 'the administrator still finds the sentences');
    }
}
