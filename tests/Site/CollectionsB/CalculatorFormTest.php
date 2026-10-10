<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\CollectionsB;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Was: section 68 (2.12 multi-step forms, conditions and a price estimate – the calculator form is built here). */
#[Group('site')]
final class CalculatorFormTest extends SiteTestCase
{
    use Helpers;

    public function testStepsConditionsAndTheServerSideEstimate(): void
    {
        $site = $this->site();
        $page = (int) $site->mcpResult('create_page', ['title' => 'Calculator 212', 'visible' => true])['id'];
        $site->mcp('save_build', ['id' => $page, 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => [['type' => 'form', 'content' => ['name' => 'Calculation', 'no_captcha' => true, 'fields' => [
            ['label' => 'Type', 'type' => 'radio', 'required' => true, 'choices' => "Windows | 1200\nDoors | 9 900"],
            ['label' => 'Count', 'type' => 'number', 'unit_price' => '1500'],
            ['label' => 'Details', 'type' => 'step'],
            ['label' => 'Door colour', 'type' => 'select', 'required' => true, 'options' => "White\nOak | 3000", 'show_when_field' => 'Type', 'show_when_value' => 'Doors'],
            ['label' => 'Email', 'type' => 'email', 'required' => true],
            ['label' => 'Estimate', 'type' => 'estimate', 'base_price' => '500', 'currency' => '$'],
        ]]]]]]]]);
        $site->clearPageCache();

        $visitor = $site->client();
        $form = $visitor->get('/calculator-212');
        $this->assertSame(2, substr_count($form->body, 'class="ka-step"'), 'multi-step: the form is split into two steps');
        $this->assertStringContainsString('data-steps', $form->body, 'multi-step: the form carries the steps marker');
        $this->assertStringContainsString('<legend>Details</legend>', $form->body, 'multi-step: the step has its legend');

        foreach (['data-when="p0" data-when-value="Doors"', 'value="Windows" data-price="1200"', 'data-price="9900"', 'data-price-per="1500"', 'data-estimate data-base="500" data-currency="$"'] as $needle) {
            $this->assertStringContainsString($needle, $form->body, "calculator: conditions and prices go to the script ($needle)");
        }
        $this->assertStringNotContainsString('| 1200', $form->body, 'calculator: the visitor never sees the price syntax');

        $site->exec("DELETE FROM ka_ip_checks WHERE type = 'form'");
        $fields = ['source' => $form->field('source'), 'element' => $form->field('element'), 'back' => '/calculator-212', 'as_time' => $form->field('as_time'), 'as_signature' => $form->field('as_signature')];
        sleep(4); // the anti-spam signature has a minimum age

        $redirect = $visitor->post('/form', $fields + ['p0' => 'Doors', 'p4' => 'd@example.com', 'p5' => 1])->redirect;
        $this->assertMatchesRegularExpression('/result=field.*field=3/', $redirect, 'conditions: a required field shown by the answer is checked on the server');

        $redirect = $visitor->post('/form', $fields + ['p0' => 'Windows', 'p1' => 4, 'p4' => 'o@example.com', 'p3' => 'Oak', 'p5' => 1])->redirect;
        $this->assertStringContainsString('result=ok', $redirect, 'conditions: a hidden required field does not block the form');

        $stored = json_decode((string) $site->value('SELECT data FROM ka_enquiries ORDER BY enquiry_id DESC LIMIT 1'), true);
        $this->assertSame('Type=Windows|Count=4|Email=o@example.com|Estimate=7,700 $', implode('|', array_map(fn ($r) => $r[0] . '=' . str_replace("\u{a0}", ' ', $r[1]), $stored)), 'calculator: the server computes the estimate and drops the hidden answer');
    }
}
