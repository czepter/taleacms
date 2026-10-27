<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\Assistant;

final class AssistantTest extends TestCase
{
    /** assistant: article translation (HTML skeleton from the original, texts from the model) */
    public function testArticleTranslation(): void
    {
        $articleHtml = '<h2>Nadpis oddílu</h2><p>První <strong>tučný</strong> a <a href="/x?a=1&amp;b=2">odkaz</a>.</p><figure><img src="a.jpg" alt="x"><figcaption>Popisek fotky</figcaption></figure><script>alert("nepřekládat")</script><p>2024</p>'; // check-english: allow
        $r = \Talea\Core\Assistant::decompose($articleHtml);
        $this->assertSame(['Nadpis oddílu', 'První [[0]]tučný[[1]] a [[2]]odkaz[[3]].', 'Popisek fotky'], $r['segments'], 'Assistant::decompose: segments to translate'); // check-english: allow
        $this->assertSame($articleHtml, \Talea\Core\Assistant::compose($r['skeleton'], $r['segments']), 'Assistant::compose: unchanged text returns the original HTML');
        $this->assertFalse(str_contains(\Talea\Core\Assistant::compose($r['skeleton'], ['<script>alert(1)</script>', 'x', '<img src=x onerror=alert(1)>']), '<script>alert(1)') || str_contains(\Talea\Core\Assistant::compose($r['skeleton'], ['a', 'b', '<img src=x onerror=alert(1)>']), '<img src=x'), 'Assistant::compose: HTML from the model is printed as text');
        $this->assertSame('<h2>N</h2><p>First bold and link.</p><figure><img src="a.jpg" alt="x"><figcaption>P</figcaption></figure><script>alert("nepřekládat")</script><p>2024</p>', \Talea\Core\Assistant::compose($r['skeleton'], ['N', 'First [[0]]bold[[1]] and link.', 'P']), 'Assistant::compose: a missing symbol = a segment without formatting'); // check-english: allow
        $this->assertFalse(str_contains(\Talea\Core\Assistant::compose($r['skeleton'], ['N', '[[1]]bold[[0]] [[2]]link[[3]]', 'P']), '<strong>'), 'Assistant::compose: wrongly nested symbols = a segment without formatting');
        $this->assertTrue(str_contains(\Talea\Core\Assistant::compose($r['skeleton'], ['N', 'A [[2]]link[[3]] and [[0]]bold[[1]] first.', 'P']), '<p>A <a href="/x?a=1&amp;b=2">link</a> and <strong>bold</strong> first.</p>'), 'Assistant::compose: a shuffled word order keeps the formatting');

        $settings = (new \ReflectionClass(\Talea\Core\Settings::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(\Talea\Core\Settings::class, 'values'))->setValue($settings, ['site_name' => 'Test', 'ai_key' => 'x', 'ai_model' => 'm1']);
        $fake = new class($settings) extends \Talea\Core\Assistant {
            public int $calls = 0;

            protected function call(array $body): array
            {
                $this->calls++;
                preg_match('#<segments>\n(.*)\n</segments>#s', $body['messages'][0]['content'], $m);

                return ['content' => [['type' => 'text', 'text' => json_encode(['translations' => array_map(mb_strtoupper(...), json_decode($m[1], true))], JSON_UNESCAPED_UNICODE)]]];
            }
        };
        $translated = $fake->translate(['title' => 'Tom & Jerry „znovu“ ve městě, tentokrát úplně jinak než kdy dřív', 'text' => '<p>Krátký <em>text</em> článku, který má aspoň pár desítek znaků.</p>', 'seo_description' => ''], 'en', ['title', 'seo_description']); // check-english: allow
        $this->assertSame('TOM & JERRY „ZNOVU“ VE MĚSTĚ, TENTOKRÁT ÚPLNĚ JINAK NEŽ KDY DŘÍV', $translated['title'], 'Assistant::translate: plain text is not escaped twice'); // check-english: allow
        $this->assertSame('<p>KRÁTKÝ <em>TEXT</em> ČLÁNKU, KTERÝ MÁ ASPOŇ PÁR DESÍTEK ZNAKŮ.</p>', $translated['text'], 'Assistant::translate: an HTML field keeps the skeleton'); // check-english: allow
        $this->assertSame('', $translated['seo_description'], 'Assistant::translate: an empty field stays empty');
        $fake->calls = 0;
        $long = $fake->translate(['text' => str_repeat('<p>' . str_repeat('Věta o něčem. ', 100) . '</p>', 9)], 'en'); // check-english: allow
        $this->assertSame([true, 9], [$fake->calls > 1, substr_count($long['text'], '<p>')], 'Assistant::translate: a long article goes in batches');
        try {
            $fake->translate(['text' => '<p>nic</p>'], 'xx');
            $this->assertSame('výjimka', 'prošlo', 'Assistant::translate: an unknown language is rejected'); // check-english: allow
        } catch (\RuntimeException) {
            $this->assertSame('výjimka', 'výjimka', 'Assistant::translate: an unknown language is rejected'); // check-english: allow
        }
    }

    /** assistant: the spam check of a new enquiry reads one word and waits at most 10 seconds */
    public function testSpamCheck(): void
    {
        $settings = (new \ReflectionClass(\Talea\Core\Settings::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(\Talea\Core\Settings::class, 'values'))->setValue($settings, ['ai_key' => 'x', 'ai_model' => 'm1']);
        $fake = new class($settings) extends Assistant {
            public string $answer = '';
            public ?int $seen = null;

            protected function call(array $body): array
            {
                $this->seen = (new \ReflectionProperty(Assistant::class, 'timeout'))->getValue($this);

                return ['content' => [['type' => 'text', 'text' => $this->answer]]];
            }
        };
        $verdicts = [];
        foreach (['spam', 'Spam.', '"spam"', 'ok', 'not spam', 'spammy', ''] as $answer) {
            $fake->answer = $answer;
            $verdicts[] = $fake->spam('Message: hello', 'Site');
        }
        $this->assertSame([true, true, true, false, false, false, false], $verdicts, 'Assistant::spam: only an answer that starts with the word spam is spam');
        $this->assertSame(10, $fake->seen, 'Assistant::spam: the visitor waits at most 10 seconds');
    }

    /** AI assistant: providers and builder */
    public function testProvidersAndBuilder(): void
    {
        $aiBody = ['model' => 'm1', 'max_tokens' => 50, 'system' => 'S', 'messages' => [['role' => 'username', 'content' => [['type' => 'image', 'source' => ['media_type' => 'image/png', 'data' => 'QQ==']], ['type' => 'text', 'text' => 'Ahoj']]]]];
        $this->assertSame([
            ['model' => 'm1', 'messages' => [['role' => 'system', 'content' => 'S'], ['role' => 'username', 'content' => [['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,QQ==']], ['type' => 'text', 'text' => 'Ahoj']]]], 'max_completion_tokens' => 50],
            ['model', 'messages', 'max_tokens'],
        ], [Assistant::toOpenAi($aiBody, 'openai'), array_keys(Assistant::toOpenAi($aiBody, 'mistral'))], 'Assistant::toOpenAi: system, image as a data URL, token limit by provider');
        $this->assertSame(['content' => [['type' => 'text', 'text' => 'Text']], 'stop_reason' => 'max_tokens'], Assistant::fromOpenAi(['choices' => [['message' => ['content' => 'Text'], 'finish_reason' => 'length']]]), 'Assistant::fromOpenAi: the response in the Claude API shape');
        $aiSettings = (new \ReflectionClass(\Talea\Core\Settings::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(\Talea\Core\Settings::class, 'values'))->setValue($aiSettings, ['site_name' => 'Test', 'ai_key' => 'x', 'ai_provider' => 'anthropic', 'ai_model' => 'claude-sonnet-5']);
        $aiFake = new class($aiSettings) extends Assistant {
            public string $answer = '';
            public array $last = [];

            protected function call(array $body): array
            {
                $this->last = $body;

                return ['content' => [['type' => 'text', 'text' => $this->answer]]];
            }
        };
        $aiFake->answer = "Tady je sekce:\n```html\n<section class=\"services-ai\"><h2>Služby</h2><p>Text <script>x</script></p><a class=\"btn\" href=\"javascript:alert(1)\">Klik</a></section><style>.sluzby-ai { padding: var(--tl-space-l); }</style>\n```"; // check-english: allow
        $aiHtml = $aiFake->suggestSection('Tři karty se službami a odkazem na kontakt.', 'cs', 'Služby'); // check-english: allow
        $aiConversion = \Talea\Builder\HtmlConverter::convert($aiHtml);
        [$aiBuild] = \Talea\Builder\Build::sanitize($aiConversion['build'], false);
        $this->assertSame([true, true, false, false, ['sluzby-ai' => 'padding: var(--tl-space-l);']], [
            str_starts_with($aiHtml, '<section'), str_contains((string) $aiFake->last['messages'][0]['content'], '<brief>'), str_contains(json_encode($aiBuild), 'script'), str_contains(json_encode($aiBuild), 'javascript'), $aiConversion['classes'],
        ], 'Assistant::suggestSection: HTML from a ```html block, the brief inside <brief>, the result without a script and a javascript: link');
        $aiFake->answer = '<p>Kratší <strong>text</strong> <img src=x onerror=alert(1)></p>'; // check-english: allow
        $this->assertSame(['<p>Kratší <strong>text</strong> </p>', 'Kratší text'], [$aiFake->rewrite('<p>Dlouhý text k přepsání.</p>', 'shorter', true), $aiFake->rewrite('Nadpis', 'formal', false)], 'Assistant::rewrite: an HTML answer sanitized, plain text without tags'); // check-english: allow
        // no model chosen: the newest of the provider's stored list (never a name from the code); a list of another provider does not count
        $stored = json_encode(['provider' => 'openai', 'time' => 1, 'models' => [['id' => 'gpt-new', 'name' => 'gpt-new'], ['id' => 'gpt-old', 'name' => 'gpt-old']]]);
        (new \ReflectionProperty(\Talea\Core\Settings::class, 'values'))->setValue($aiSettings, ['site_name' => 'Test', 'ai_key' => 'x', 'ai_provider' => 'openai', 'ai_model' => '', 'ai_models' => $stored]);
        $aiFake->rewrite('Text', 'shorter', false);
        $this->assertSame(['gpt-new', ['gpt-new', 'gpt-old']], [$aiFake->last['model'], array_column($aiFake->models(), 'id')], 'Assistant: no model chosen = the newest the provider listed');
        (new \ReflectionProperty(\Talea\Core\Settings::class, 'values'))->setValue($aiSettings, ['site_name' => 'Test', 'ai_key' => 'x', 'ai_provider' => 'mistral', 'ai_model' => '', 'ai_models' => $stored]);
        $this->assertSame([], $aiFake->models(), 'Assistant::models: the list of another provider is not offered');

        // the provider's list: newest first by its own date, Google's "models/" prefix dropped, models that cannot chat left out
        $this->assertSame([['id' => 'new', 'name' => 'New'], ['id' => 'old', 'name' => 'old']], Assistant::modelList(['data' => [
            ['id' => 'models/old', 'created' => 1], ['id' => 'models/new', 'created' => 2, 'display_name' => 'New'], ['id' => 'embed', 'created' => 3, 'capabilities' => ['completion_chat' => false]], ['id' => 'bad id!'], 'junk',
        ]]), 'Assistant::modelList: chat models from the provider, newest first');
        $listSettings = (new \ReflectionClass(\Talea\Core\Settings::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(\Talea\Core\Settings::class, 'values'))->setValue($listSettings, ['site_name' => 'Test', 'ai_key' => 'x', 'ai_provider' => 'google']);
        $lister = new class($listSettings) extends Assistant {
            public string $url = '';

            protected function fetch(string $url, ?array $body): array
            {
                $this->url = $url;

                return ['data' => [['id' => 'embed', 'capabilities' => ['completion_chat' => false]]]];
            }
        };
        try {
            $lister->loadModels();
            $listError = '';
        } catch (\RuntimeException $e) {
            $listError = $e->getMessage();
        }
        $this->assertSame(['https://generativelanguage.googleapis.com/v1beta/openai/models', true], [$lister->url, str_contains($listError, 'lists no models')], 'Assistant::loadModels: the models address next to the chat address; a list without a chat model is an error, not an empty choice');
    }
}
