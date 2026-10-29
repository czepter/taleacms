<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;
use Talea\Builder\Build;
use Talea\Builder\FormRecords;

/** Forms as records (docs/specs/form-builder.md): what a form's build holds and what a placement may say. */
final class FormRecordsTest extends TestCase
{
    public function testAFormsBuildIsItsFirstFormElementAlone(): void
    {
        $form = Build::fresh('form');
        $build = ['v' => 1, 'children' => [Build::fresh('section', [], [Build::fresh('heading'), $form]), Build::fresh('form')]];
        $this->assertSame([$form], FormRecords::definitionBuild($build)['children'], 'the first Form element, found inside a section, and nothing else');
        $this->assertSame([], FormRecords::definitionBuild(['v' => 1, 'children' => [Build::fresh('heading')]])['children'], 'a build without one is empty');
        $this->assertNull(FormRecords::definition(null));
    }

    public function testPlacementWidths(): void
    {
        $this->assertSame(['kab12' => 3, 'kcd34' => 2], FormRecords::widths(['kab12' => '3', 'kcd34' => 2, 'kef56' => 6, 'kgh78' => 5, 'name' => 3, 'k1' => 4]),
            'columns of six: 2, 3 and 4 by field key; full width (6) is no entry; anything else is dropped');
        $this->assertSame([], FormRecords::widths('nonsense'));
        [$clean] = Build::sanitize(['v' => 1, 'children' => [['type' => 'form_embed', 'content' => ['form' => '<b>x</b>', 'widths' => ['kab12' => 4, 'x' => 2]]]]]);
        $this->assertSame(['x', ['kab12' => 4]], [$clean['children'][0]['content']['form'], $clean['children'][0]['content']['widths']], 'the validator keeps the form id as plain text and the widths as FormRecords::widths');
    }

    public function testStartersHaveRealFields(): void
    {
        $types = fn (string $starter): array => array_column(FormRecords::definition(FormRecords::starterBuild($starter))['content']['fields'], 'type');
        $this->assertSame(['text', 'email', 'tel', 'textarea', 'checkbox'], $types('enquiry'));
        $this->assertSame(['text', 'email', 'checkbox'], $types('download'));
        $this->assertSame(['email'], $types('empty'));
        $this->assertMatchesRegularExpression('/^k[a-z0-9]{4}$/', FormRecords::definition(FormRecords::starterBuild('enquiry'))['content']['fields'][0]['key'], 'the fields get their keys from the validator');
    }
}
