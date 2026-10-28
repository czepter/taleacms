<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Front;

use PHPUnit\Framework\TestCase;
use Talea\Core\Routes;

final class RoutesTest extends TestCase
{
    public function testRoutes(): void
    {
        $this->assertSame(['news/category/akce', 'news/tag/x', 'search?q=a', 'news/category/akce', 'news-akce', 'news'], [Routes::publicPath('news/category/akce', null), Routes::publicPath('news/tag/x', null), Routes::publicPath('search?q=a', null),
            Routes::publicPath('news/category/akce', null), Routes::publicPath('news-akce', null), Routes::publicPath('news', null)], 'Routes: system addresses are the same in every language');
        $this->assertSame([['/news/tag/x', '/news/tag/x'], ['/news/x', '/news/x'], ['/news', '/news'], ['/about-us', '/about-us']], [Routes::internalPath('/news/tag/x', null), Routes::internalPath('/news/x', null), Routes::internalPath('/news', null), Routes::internalPath('/about-us', null)], 'Routes: request path to the internal and canonical form');
        $this->assertSame(['/o-nas?a=1', null, '/o-nas/?a=1', null, '/o-nas.html', '/o-nas.html', null, '/o-nas', '/o-nas/', null, null, null, null, null, null], [Routes::slashRedirect('/o-nas', '/o-nas/?a=1', 'none'), Routes::slashRedirect('/o-nas', '/o-nas', 'none'), Routes::slashRedirect('/o-nas', '/o-nas?a=1', 'slash'), Routes::slashRedirect('/o-nas', '/en/o-nas/', 'slash'),
            Routes::slashRedirect('/o-nas', '/o-nas', 'html'), Routes::slashRedirect('/o-nas', '/o-nas/', 'html'), Routes::slashRedirect('/o-nas.html', '/o-nas.html', 'html'), Routes::slashRedirect('/o-nas.html', '/o-nas.html', 'none'), Routes::slashRedirect('/o-nas.html', '/o-nas.html', 'slash'),
            Routes::slashRedirect('/', '/', 'slash'), Routes::slashRedirect('/rss.xml', '/rss.xml', 'slash'), Routes::slashRedirect('/api/x', '/api/x', 'slash'), Routes::slashRedirect('/mcp', '/mcp', 'slash'), Routes::slashRedirect('/form', '/form', 'slash'),
            Routes::slashRedirect('/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource', 'slash')], 'Routes: address shape by the url_slash setting');
        Routes::setNewsSlug('blog');
        $this->assertSame(['blog', 'blog/category/akce', 'blog/category/akce', 'blog/x.md', 'search', 'about-us'], [Routes::publicPath('news', null), Routes::publicPath('news/category/akce', null), Routes::publicPath('news/category/akce', null),
            Routes::publicPath('news/x.md', null), Routes::publicPath('search', null), Routes::publicPath('about-us', null)], 'Routes: the custom news address applies in every language');
        $this->assertSame([['/news/x', '/blog/x'], ['/news/category/x', '/blog/category/x'], ['/news/x', '/blog/x'], ['/news', '/blog'], ['/blogger', '/blogger']], [Routes::internalPath('/blog/x', null), Routes::internalPath('/blog/category/x', null), Routes::internalPath('/news/x', null), Routes::internalPath('/news', null), Routes::internalPath('/blogger', null)], 'Routes: the custom news address – request and redirect from the plain form');
        $this->assertSame([true, false, false], [Routes::isNewsSlug('blog', null), Routes::isNewsSlug('o-nas', null), Routes::isNewsSlug('', null)], 'Routes: a custom news address collides with a page');
        $this->assertSame(['This URL is used by the system, choose another one.', 'This URL is used by the system, choose another one.', 'This URL is used by the system, choose another one.', 'This URL is used by the system, choose another one.', null, null, null], [Routes::systemSlugError('mcp'), Routes::systemSlugError('en'), Routes::systemSlugError('Blog'), Routes::systemSlugError('a/b'), Routes::systemSlugError('blog'), Routes::systemSlugError('news'), Routes::systemSlugError('')], 'Routes: the news address must not be a system path or have a bad shape');
        Routes::setNewsSlug(null);
    }
}
