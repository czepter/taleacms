<?php

declare(strict_types=1);

namespace Talea\Builder;

use Talea\Core\App;
use Talea\Core\Assistant;
use Talea\Mcp\PublicIds;

/**
 * The builder's Ask box (#31): a short request ("make the hero shorter", "add a pricing section") becomes edit operations
 * (Builder\Edits, the same ones Claude uses over MCP), applied to the draft of the page. Only computes: saving, the journal
 * (undo), the change log and the guardrails are the caller's (Admin\BuilderActions::actionBuildAsk).
 *
 * The model's reply is untrusted input: only the known operations are kept (no delete unless the owner allows destructive
 * changes), they run on a copy and the result passes Build::sanitize like any build; no code elements. Nothing is published.
 */
final class AskBox
{
    public const array OPERATIONS = ['update', 'replace', 'insert', 'move'];

    /**
     * @param array<string, mixed>|null $current the build now (the draft, otherwise the published one)
     * @return array{build: array<string, mixed>, summary: string, applied: int, errors: array<string, string>, changed: bool}
     * @throws \RuntimeException with a message for the user
     */
    public static function run(App $app, Assistant $assistant, ?array $current, string $request, string $selected, string $page, string $language): array
    {
        $request = trim($request);
        if (mb_strlen($request) < 4) {
            throw new \RuntimeException('Write what the assistant should change, in a few words.');
        }
        $current ??= ['v' => Build::VERSION, 'children' => []];
        $db = $app->db();
        $mayDelete = $app->settings()->bool('claude_destructive');
        $schema = Build::schema(false, $language, false, \Talea\Core\Extensions::enabled($app->settings()));
        $vocabulary = Build::overview($schema);
        unset($vocabulary['rules']);
        $reply = \Talea\Core\Language::runWith($language, fn (): array => $assistant->editBuild($request, PublicIds::buildOut($db, Build::compact($current)),
            preg_match('/^[A-Za-z0-9_-]{1,40}$/', $selected) === 1 ? $selected : '', $page, $language, $mayDelete, (string) json_encode($vocabulary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        $operations = array_values(array_filter($reply['operations'], fn (mixed $o): bool => is_array($o) && is_string($o['op'] ?? null)
            && (in_array($o['op'], self::OPERATIONS, true) || ($mayDelete && $o['op'] === 'delete'))));
        $errors = [];
        $before = Build::toJson($current);
        [$build] = Build::sanitize(Edits::apply($current, PublicIds::buildIn($db, $operations), $errors), false, $current);
        $changed = Build::toJson($build) !== $before;

        return ['build' => $build, 'summary' => $reply['summary'], 'applied' => $changed ? count($operations) - count($errors) : 0, 'errors' => $errors, 'changed' => $changed];
    }
}
