<?php

declare(strict_types=1);

namespace Talea\Front;

use Talea\Core\App;
use Talea\Core\Response;

/**
 * The route table of the public site (Kernel::routes()). Routes are tried in the order they were added; the first one that answers wins,
 * so the order is behaviour. A route is:
 *
 *   - the request method: 'ANY' or 'POST';
 *   - a pattern: the whole path, with placeholders: /news/{slug} (a placeholder matches [a-z0-9-]+) or /_booking/cancel/{token:[a-f0-9]{32}}
 *     (its own regular expression);
 *   - a condition, asked only when the method and the path match: fn (App $app): bool, with a name for the table (a setting, an extension);
 *   - the handler [class, method]: dispatch() calls that method on the object given for the class, with the placeholders as strings in
 *     their order. It returns a Response, or null to let the next route try (no such page, a collection without events).
 *
 * The table holds class names, not objects, so it is read without a site or a database: tools/contracts.php records it in
 * tools/contracts/routes.json and Kernel::reservedSlugs() takes the addresses no page or add-on may have from it.
 */
final class Router
{
    /** @var list<array{methods: list<string>, pattern: string, regex: string, handler: array{0: class-string, 1: string}, when: ?\Closure, whenName: string}> */
    private array $routes = [];

    /**
     * @param string|list<string> $methods 'ANY', 'POST' or a list of methods
     * @param array{0: class-string, 1: string} $handler
     * @param (\Closure(App): bool)|null $when
     */
    public function add(string|array $methods, string $pattern, array $handler, ?\Closure $when = null, string $whenName = ''): self
    {
        $this->routes[] = ['methods' => (array) $methods, 'pattern' => $pattern, 'regex' => self::regex($pattern), 'handler' => $handler, 'when' => $when, 'whenName' => $whenName];

        return $this;
    }

    /**
     * The answer of the first route that matches and answers; null when none does.
     *
     * @param array<class-string, object> $handlers the object that answers for each handler class
     */
    public function dispatch(string $method, string $path, App $app, array $handlers): ?Response
    {
        foreach ($this->routes as $route) {
            if (!in_array('ANY', $route['methods'], true) && !in_array($method, $route['methods'], true)) {
                continue;
            }
            if (preg_match($route['regex'], $path, $m) !== 1 || ($route['when'] !== null && !($route['when'])($app))) {
                continue;
            }
            // the named groups in their order, as positional arguments
            $arguments = array_values(array_filter($m, is_string(...), ARRAY_FILTER_USE_KEY));
            \Talea\Core\ErrorReport::tag('route', $route['pattern']); // an error report names the route, never the address
            $response = $handlers[$route['handler'][0]]->{$route['handler'][1]}(...$arguments);
            if ($response !== null) {
                return $response;
            }
        }

        return null;
    }

    /**
     * The table as data, in order: methods, pattern, the condition's name and the handler (class without namespace :: method).
     *
     * @return list<array{methods: list<string>, pattern: string, when: string, handler: string}>
     */
    public function routes(): array
    {
        return array_map(fn (array $r): array => [
            'methods' => $r['methods'], 'pattern' => $r['pattern'], 'when' => $r['whenName'],
            'handler' => substr((string) strrchr('\\' . $r['handler'][0], '\\'), 1) . '::' . $r['handler'][1],
        ], $this->routes);
    }

    /**
     * The fixed first segments of the patterns (/news/{slug} → news, /member{rest} → member), without files (robots.txt) and patterns
     * that start with a placeholder.
     *
     * @return list<string>
     */
    public function prefixes(): array
    {
        $prefixes = [];
        foreach ($this->routes as $route) {
            if (preg_match('#^/([a-z0-9_-]+)(?:[/{]|$)#', $route['pattern'], $m) === 1) {
                $prefixes[] = $m[1];
            }
        }

        return array_values(array_unique($prefixes));
    }

    /** The anchored regular expression of a pattern: literal text is quoted, a placeholder becomes a named group. */
    private static function regex(string $pattern): string
    {
        $regex = '';
        // a placeholder's own expression may hold one level of braces: {token:[a-f0-9]{32}}
        foreach (preg_split('/(\{\w+(?::(?:[^{}]|\{[^{}]*\})+)?\})/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $i => $part) {
            if ($i % 2 === 0) {
                $regex .= preg_quote($part, '#');
            } elseif (preg_match('/^\{(\w+)(?::(.+))?\}$/s', $part, $p) === 1) {
                $regex .= '(?P<' . $p[1] . '>' . ($p[2] ?? '[a-z0-9-]+') . ')';
            }
        }

        return '#^' . $regex . '$#Ds';
    }
}
