<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Front;

use PHPUnit\Framework\TestCase;
use Talea\Core\App;
use Talea\Core\Request;
use Talea\Core\Response;
use Talea\Front\Router;

final class RouterTest extends TestCase
{
    /** The public route table (Front\Router): order, placeholders, methods, conditions, null lets the next route try */
    public function testFirstAnswerInOrderWins(): void
    {
        $handler = new class {
            /** @var list<string> */
            public array $calls = [];

            public function __call(string $name, array $args): ?Response
            {
                $this->calls[] = $name . '(' . implode(',', $args) . ')';

                return $name === 'skip' ? null : new Response($name . '(' . implode(',', $args) . ')');
            }
        };
        $class = $handler::class;
        $router = (new Router())
            ->add('ANY', '/news/{slug}', [$class, 'news'])
            ->add('POST', '/consent', [$class, 'consent'])
            ->add('ANY', '/off', [$class, 'off'], fn (App $app): bool => false, 'never')
            ->add('ANY', '/_booking/cancel/{token:[a-f0-9]{32}}', [$class, 'cancel'])
            ->add('ANY', '/{c:(?!news/)[a-z0-9-]{1,110}}/{i:[a-z0-9-]{1,160}}.ics', [$class, 'skip'])
            ->add('ANY', '/{c:(?!news/)[a-z0-9-]{1,110}}/{i:[a-z0-9-]{1,160}}{rest:(?:/.*)?}', [$class, 'item']);
        $app = new App(['db' => []], new Request([], [], []));
        $answer = fn (string $method, string $path): ?string => $router->dispatch($method, $path, $app, [$class => $handler])?->body;

        $this->assertSame(['news(a-b)', null, 'consent()', null, null, 'cancel(' . str_repeat('ab', 16) . ')', null, 'item(shop,x,)', 'item(shop,x,/latest)', null, null, null], [
            $answer('GET', '/news/a-b'), $answer('GET', '/news/A'), $answer('POST', '/consent'), $answer('GET', '/consent'), $answer('GET', '/off'),
            $answer('GET', '/_booking/cancel/' . str_repeat('ab', 16)), $answer('GET', '/_booking/cancel/xyz'), $answer('GET', '/shop/x'), $answer('GET', '/shop/x/latest'),
            $answer('GET', '/news/x/latest'), $answer('GET', "/news/a\n"), $answer('GET', '/shop/x.ics'),
        ], 'a placeholder is [a-z0-9-]+ or its own expression, anchored at both ends; POST routes ignore a GET; a false condition and a null answer let the next route try');
        $this->assertContains('skip(shop,x)', $handler->calls, 'the route that answered null was asked');
        $off = array_values(array_filter($router->routes(), fn (array $r): bool => $r['pattern'] === '/off'))[0];
        $this->assertSame([['ANY'], 'never', true], [$off['methods'], $off['when'], str_ends_with($off['handler'], '::off')], 'routes() is the table as data');
    }
}
