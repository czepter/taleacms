<?php

// The AI provider of the site tests (#31, Core\Assistant): answers like the Claude API on POST /ai/v1/messages (the tests point
// TALEA_AI_URL here). The task is recognized by the system prompt; the log keeps what was sent (task, the user's text), never the key.
// Words in the request steer the answer: "HOSTILE" gives a hostile reply (code, unknown keys, deletions), "GARBAGE" text that is not JSON.
if ($path !== '/ai/v1/messages' || $method !== 'POST') {
    return false;
}
$system = (string) ($json['system'] ?? '');
$user = (string) ($json['messages'][0]['content'] ?? '');
$answer = fn (string $text): bool => $reply(200, ['content' => [['type' => 'text', 'text' => $text]], 'stop_reason' => 'end_turn']);
// the part of the message the words steer: for the Ask box the request only (the page and the instructions contain words like "add" too)
$said = preg_match('#<request>\s*(.*?)\s*</request>#s', $user, $q) === 1 ? $q[1] : $user;
$hostile = str_contains($said, 'HOSTILE');
$task = match (true) {
    str_contains($system, 'You edit one page') => 'ask',
    str_contains($system, 'You plan the first version') => 'plan',
    str_contains($system, 'web designer and copywriter') => 'page',
    default => 'other',
};
$log('ai', ['task' => $task, 'has_key' => ($headers['x-api-key'] ?? '') !== '', 'user' => mb_substr($user, 0, 4000)]);
if (str_contains($said, 'GARBAGE')) {
    return $answer('Sorry, I cannot do that. {not json');
}
if ($task === 'ask') {
    preg_match('#<build>\s*(.*?)\s*</build>#s', $user, $m);
    $build = json_decode($m[1] ?? '', true) ?: ['children' => []];
    $heading = null;
    $walk = function (array $nodes) use (&$walk, &$heading): void {
        foreach ($nodes as $n) {
            if ($heading === null && ($n['type'] ?? '') === 'heading') {
                $heading = $n;
            }
            $walk($n['children'] ?? []);
        }
    };
    $walk($build['children'] ?? []);
    if ($hostile) {
        return $answer(json_encode(['summary' => 'Done <script>alert(1)</script>', 'operations' => [
            ['op' => 'insert', 'elements' => [['type' => 'html', 'content' => ['code' => '<script>alert(1)</script>']], ['type' => 'nonsense'], ['type' => 'heading', 'content' => ['text' => 'Hostile <img src=x onerror=alert(1)>', 'link' => 'javascript:alert(1)']]], 'into' => null],
            ['op' => 'delete', 'id' => $heading['id'] ?? 'x'],
            ['op' => 'publish', 'id' => 'x'], ['op' => 'update', 'id' => 'does-not-exist', 'content' => ['text' => 'x']], 'not an operation', ['op' => 'update', 'id' => $heading['id'] ?? 'x', 'style' => ['base' => ['no_such_property' => '1', 'color' => 'url(javascript:alert(1))']]],
        ]]));
    }
    if (str_contains($said, 'nothing') || $heading === null && !str_contains($said, 'add')) {
        return $answer(json_encode(['summary' => 'I could not find anything to change.', 'operations' => []]));
    }
    $operations = str_contains($said, 'add')
        ? [['op' => 'insert', 'elements' => [['type' => 'heading', 'tag' => 'h2', 'content' => ['text' => 'Pricing']]], 'into' => null]]
        : [['op' => 'update', 'id' => $heading['id'], 'content' => ['text' => 'Shorter title']]];

    return $answer(json_encode(['summary' => 'Changed the page as asked.', 'operations' => $operations]));
}
if ($task === 'plan') {
    if ($hostile) {
        return $answer(json_encode(['blueprint' => '../../etc/passwd', 'look' => 'nonexistent', 'pages' => [['title' => '<script>alert(1)</script>Home', 'brief' => 'x'], 'junk', ['title' => ''], ['title' => 'About', 'brief' => 'About us']]]));
    }

    return $answer(json_encode(['blueprint' => 'craftsman', 'look' => 'atelier', 'pages' => [['title' => 'Home', 'brief' => 'A welcome with the main services.'], ['title' => 'About us', 'brief' => 'Who we are.'], ['title' => 'Contact', 'brief' => 'How to reach us.']]]));
}
if ($task === 'page') {
    if ($hostile) {
        return $answer('<section onclick="alert(1)"><h2>Welcome</h2><script>alert(1)</script><p style="color:red">Text <a href="javascript:alert(1)">link</a></p></section>');
    }

    return $answer('<section><h2>Welcome</h2><p>Call us on {{fact.company_phone}} or write to {{fact.shop_license}}. Opening hours: [opening hours].</p></section>');
}

return $answer('ok');
