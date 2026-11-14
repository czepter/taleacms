<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;
use Talea\Builder\Build;
use Talea\Builder\Context;
use Talea\Builder\Elements\Form;
use Talea\Builder\Renderer;
use Talea\Core\App;
use Talea\Core\Request;
use Talea\Core\Settings;

/** #342: a file field can be limited to what a CV is, and the content of a CV is checked against its extension. */
final class FormAttachmentsTest extends TestCase
{
    private function build(string $accept): array
    {
        [$build] = Build::sanitize(['v' => 1, 'children' => [['type' => 'form', 'id' => 'f1', 'content' => ['fields' => [['label' => 'CV', 'type' => 'file', 'accept' => $accept]]]]]]);

        return $build;
    }

    public function testCvFieldsTakeDocumentsCheckedByContent(): void
    {
        $this->assertSame(Form::ATTACHMENT_EXTENSIONS, Form::attachmentExtensions(['type' => 'file']));
        $this->assertSame(Form::CV_EXTENSIONS, Form::attachmentExtensions(['type' => 'file', 'accept' => 'cv']));

        $cv = ['type' => 'file', 'accept' => 'cv'];
        $any = ['type' => 'file'];
        $this->assertSame([true, false, false, true, true, true, true], [
            Form::attachmentAllowed($cv, 'pdf', 'application/pdf'), Form::attachmentAllowed($cv, 'zip', 'application/zip'), Form::attachmentAllowed($cv, 'pdf', 'text/html'),
            Form::attachmentAllowed($cv, 'docx', 'application/zip'), Form::attachmentAllowed($cv, 'doc', 'application/CDFV2'),
            Form::attachmentAllowed($any, 'dwg', 'application/octet-stream'), Form::attachmentAllowed($any, 'zip', 'application/zip'),
        ]);

        $field = $this->build('cv')['children'][0]['content']['fields'][0];
        $this->assertSame('cv', $field['accept'] ?? null, 'a CV field keeps its setting');
        $unknown = $this->build('everything')['children'][0]['content']['fields'][0];
        $this->assertSame('any', $unknown['accept'] ?? 'any', 'an unknown value becomes the default');

        $app = new App(['db' => ['driver' => 'mysql', 'host' => 'x', 'name' => 'x']], new Request([], [], ['REQUEST_URI' => '/jobs', 'HTTP_HOST' => 'localhost']));
        $settings = (new \ReflectionClass(Settings::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(Settings::class, 'values'))->setValue($settings, ['secret_key' => str_repeat('a', 64)]);
        (new \ReflectionProperty(App::class, 'settings'))->setValue($app, $settings);
        $html = Renderer::html($this->build('cv'), new Context($app));
        $this->assertStringContainsString('accept=".pdf,.doc,.docx,.odt,.jpg,.jpeg,.png"', $html);
        $this->assertStringContainsString('Up to 10 MB: PDF, Word, OpenDocument or an image.', $html);
        $this->assertStringContainsString('accept=".pdf,.jpg,.jpeg,.png,.webp', Renderer::html($this->build('any'), new Context($app)));
    }
}
