<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;
use Talea\Builder\Build;
use Talea\Builder\FormLogic;

final class FormLogicTest extends TestCase
{
    public function testTimeRangeHours(): void
    {
        $f = ['type' => 'timerange'];
        $this->assertSame([4.0, 0.0, 8.5, null, null], [FormLogic::value($f, '22:00-02:00'), FormLogic::value($f, '10:00-10:00'), FormLogic::value($f, '09:30-18:00'), FormLogic::value($f, '25:00-01:00'), FormLogic::value($f, '10:60-11:00')]);
        $this->assertSame(1.5, FormLogic::value(['type' => 'number'], '1,5'));
    }

    public function testOperators(): void
    {
        $radio = ['type' => 'radio'];
        $boxes = ['type' => 'checkboxes'];
        $num = ['type' => 'number'];
        $date = ['type' => 'date'];
        $this->assertTrue(FormLogic::holds(['op' => 'is', 'value' => 'Abroad'], $radio, 'Abroad'));
        $this->assertTrue(FormLogic::holds(['op' => 'is_not', 'value' => 'Abroad'], $radio, ''));
        $this->assertTrue(FormLogic::holds(['op' => 'contains', 'value' => 'Album'], $boxes, ['Album', 'Photo booth']));
        $this->assertTrue(FormLogic::holds(['op' => 'not_contains', 'value' => 'Video'], $boxes, ['Album']));
        $this->assertTrue(FormLogic::holds(['op' => 'between', 'value' => '50', 'to' => '120'], $num, '120'));
        $this->assertTrue(FormLogic::holds(['op' => 'weekday', 'value' => '6'], $date, '2026-10-31')); // Saturday = 6 (ISO)
        $this->assertTrue(FormLogic::holds(['op' => 'month', 'value' => '10'], $date, '2026-10-31'));
        $this->assertTrue(FormLogic::holds(['op' => 'not_empty'], $boxes, ['x']));
        $this->assertTrue(FormLogic::holds(['op' => 'empty'], $boxes, []));
        $this->assertFalse(FormLogic::holds(['op' => 'gt', 'value' => '5'], $num, 'abc'));
        $this->assertFalse(FormLogic::holds(['op' => 'weekday', 'value' => '6'], $date, 'nonsense'));
    }

    public function testVisibilityCombination(): void
    {
        $fields = [['key' => 'ka000', 'type' => 'radio'], ['key' => 'kb000', 'type' => 'text'], ['key' => 'kc000', 'type' => 'text']];
        $show = ['match' => 'any', 'when' => [['field' => 'ka000', 'op' => 'is', 'value' => 'Yes']], 'then' => [['do' => 'show_when', 'field' => 'kb000']]];
        $skip = ['match' => 'all', 'when' => [['field' => 'ka000', 'op' => 'is', 'value' => 'Yes']], 'then' => [['do' => 'skip', 'field' => 'kb000']]];
        $chain = ['match' => 'all', 'when' => [['field' => 'kb000', 'op' => 'empty']], 'then' => [['do' => 'skip', 'field' => 'kc000']]];
        $this->assertSame([true, true, true], array_values(FormLogic::visible($fields, [$show, $chain], [0 => 'Yes', 1 => 'x', 2 => ''])));
        $this->assertFalse(FormLogic::visible($fields, [$show, $skip], [0 => 'Yes'])[1], 'skip wins over show');
        $this->assertFalse(FormLogic::visible($fields, [$show, $chain], [0 => 'No', 1 => 'typed'])[2], 'a hidden answer counts as empty');
    }

    public function testLegacyConditionsBecomeRules(): void
    {
        $content = ['fields' => [['key' => 'ka000', 'label' => 'Type', 'type' => 'radio'], ['key' => 'kb000', 'label' => 'Colour', 'type' => 'text', 'show_when_field' => 'type', 'show_when_value' => '*']], 'logic' => ['rules' => [], 'results' => []]];
        $rule = FormLogic::rules($content)[0];
        $this->assertSame(['field' => 'ka000', 'op' => 'not_empty'], array_intersect_key($rule['when'][0], ['field' => 1, 'op' => 1]));
        $this->assertSame(['do' => 'show_when', 'field' => 'kb000'], $rule['then'][0]);
    }

    public function testRuleOnMissingOptionIsReportedAndInert(): void
    {
        $errors = [];
        $fields = [['key' => 'ka000', 'type' => 'radio', 'choices' => "North\nSouth"], ['key' => 'kb000', 'type' => 'text']];
        $logic = FormLogic::sanitize(['rules' => [['match' => 'all', 'when' => [['field' => 'ka000', 'op' => 'is', 'value' => 'East']], 'then' => [['do' => 'skip', 'field' => 'kb000']]]]], $fields, 'x', $errors);
        $this->assertNotEmpty($errors);
        $this->assertTrue($logic['rules'][0]['broken']);
        $this->assertTrue(FormLogic::visible($fields, FormLogic::rules(['fields' => $fields, 'logic' => $logic]), [0 => 'East'])[1]);
    }

    public function testRulesMayOnlyLookBack(): void
    {
        $errors = [];
        $fields = [['key' => 'ka000', 'type' => 'text'], ['key' => 'kb000', 'type' => 'text']];
        $logic = FormLogic::sanitize(['rules' => [['match' => 'all', 'when' => [['field' => 'kb000', 'op' => 'empty']], 'then' => [['do' => 'skip', 'field' => 'ka000']]]]], $fields, 'x', $errors);
        $this->assertSame([], $logic['rules']);
        $this->assertNotEmpty($errors);
    }

    public function testSanitizeDropsJunkAndKeepsGoodParts(): void
    {
        $errors = [];
        $fields = [['key' => 'ka000', 'type' => 'number'], ['key' => 'kb000', 'type' => 'text']];
        $logic = FormLogic::sanitize(['rules' => [[
            'label' => str_repeat('x', 300), 'match' => 'weird',
            'when' => [['field' => 'ka000', 'op' => 'gt', 'value' => '5'], ['field' => 'nope', 'op' => 'gt', 'value' => '1'], ['field' => 'ka000', 'op' => 'bogus', 'value' => '1']],
            'then' => [['do' => 'multiply', 'by' => 500], ['do' => 'add', 'amount' => '1,5'], ['do' => 'run', 'code' => 'x'], ['do' => 'result', 'variant' => 'vabc']],
        ]], 'results' => [['key' => 'vabc', 'heading' => 'Hi', 'text' => 'T', 'show_amount' => 1, 'button_link' => 'javascript:alert(1)', 'image' => 'nope'], ['key' => 'BAD']]], $fields, 'x', $errors);
        $rule = $logic['rules'][0];
        $this->assertSame(120, mb_strlen($rule['label']));
        $this->assertSame('all', $rule['match']);
        $this->assertCount(3, $rule['when'], 'a bad condition is kept, never silently dropped');
        $this->assertTrue($rule['broken'], 'and it makes the whole rule inert');
        $this->assertSame([], FormLogic::rules(['fields' => $fields, 'logic' => $logic]));
        $this->assertSame([['do' => 'multiply', 'by' => 100.0], ['do' => 'add', 'amount' => 1.5], ['do' => 'result', 'variant' => 'vabc']], $rule['then']);
        $this->assertCount(1, $logic['results']);
        $this->assertSame(['show_amount' => true, 'button_link' => '', 'image' => ''], array_intersect_key($logic['results'][0], ['button_link' => 1, 'image' => 1, 'show_amount' => 1]));
        $this->assertNotEmpty($errors);
    }

    public function testUnknownActionTargetBreaksTheRule(): void
    {
        $errors = [];
        $fields = [['key' => 'ka000', 'type' => 'text'], ['key' => 'kb000', 'type' => 'text']];
        $logic = FormLogic::sanitize(['rules' => [['when' => [['field' => 'ka000', 'op' => 'empty']], 'then' => [['do' => 'skip', 'field' => 'kzzzz']]]]], $fields, 'x', $errors);
        $this->assertTrue($logic['rules'][0]['broken']);
        $this->assertSame('kzzzz', $logic['rules'][0]['then'][0]['field']);
    }

    public function testArraysInEverySlotDoNotThrow(): void
    {
        $errors = [];
        $fields = [['key' => 'ka000', 'type' => 'text'], ['key' => 'kb000', 'type' => 'text']];
        $x = ['x'];
        $logic = FormLogic::sanitize(['rules' => [
            ['label' => $x, 'match' => $x, 'when' => [['field' => $x, 'op' => $x, 'value' => $x, 'to' => $x]], 'then' => [['do' => $x, 'field' => $x, 'variant' => $x, 'amount' => $x, 'by' => $x]]],
            ['when' => [['field' => 'ka000', 'op' => 'empty']], 'then' => [['do' => 'skip', 'field' => $x], ['do' => 'result', 'variant' => $x]]],
        ], 'results' => [['key' => $x], ['key' => 'vabc', 'heading' => $x, 'text' => $x, 'button_text' => $x, 'button_link' => $x, 'image' => $x]]], $fields, 'x', $errors);
        $this->assertIsArray($logic['rules']);
        $this->assertCount(1, $logic['results']);
    }

    public function testLimitsAreReported(): void
    {
        $errors = [];
        $fields = [['key' => 'ka000', 'type' => 'text'], ['key' => 'kb000', 'type' => 'text']];
        $rule = ['when' => [['field' => 'ka000', 'op' => 'empty']], 'then' => [['do' => 'skip', 'field' => 'kb000']]];
        $logic = FormLogic::sanitize(['rules' => array_fill(0, FormLogic::MAX_RULES + 1, $rule)], $fields, 'x', $errors);
        $this->assertCount(FormLogic::MAX_RULES, $logic['rules']);
        $this->assertArrayHasKey('x.rules', $errors);
    }

    public function testKeylessFieldsAreNotAddressable(): void
    {
        $fields = [['type' => 'text'], ['key' => 'kb000', 'type' => 'text']];
        $rule = ['when' => [['field' => '', 'op' => 'empty']], 'then' => [['do' => 'show_when', 'field' => 'kb000']]];
        $this->assertFalse(FormLogic::visible($fields, [$rule], [])[1]);
    }

    public function testFormSanitizeElementRunsLogic(): void
    {
        [$build, $errors] = Build::sanitize(['v' => 1, 'children' => [['type' => 'form', 'content' => [
            'fields' => [['key' => 'kaaaa', 'label' => 'A', 'type' => 'text'], ['key' => 'kbbbb', 'label' => 'B', 'type' => 'text']],
            'logic' => ['rules' => [['when' => [['field' => 'kaaaa', 'op' => 'not_empty']], 'then' => [['do' => 'show_when', 'field' => 'kbbbb']]]], 'results' => []],
        ]]]]);
        $this->assertCount(1, $build['children'][0]['content']['logic']['rules']);
        $this->assertSame([], $build['children'][0]['content']['logic']['results']);
    }

    public function testFixtureVisibility(): void // shared with the browser walk (Task 6)
    {
        foreach (json_decode((string) file_get_contents(TALEA_ROOT . '/tests/Fixtures/form-logic.json'), true) as $case) {
            $visible = FormLogic::visible($case['content']['fields'], FormLogic::rules($case['content']), $case['answers']);
            $this->assertSame($case['visible'], array_values($visible), $case['name']);
        }
    }
}
