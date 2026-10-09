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
        $page = (int) $site->mcpResult('vytvor_stranku', ['title' => 'Kalkulacka 212', 'visible' => true])['id'];
        $site->mcp('stavba_uloz', ['id' => $page, 'publikovat' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => [['type' => 'form', 'content' => ['name' => 'Kalkulace', 'no_captcha' => true, 'fields' => [
            ['label' => 'Typ', 'type' => 'radio', 'required' => true, 'choices' => "Okna | 1200\nDveře | 9 900"],
            ['label' => 'Počet', 'type' => 'number', 'unit_price' => '1500'],
            ['label' => 'Upřesnění', 'type' => 'step'],
            ['label' => 'Barva dveří', 'type' => 'select', 'required' => true, 'options' => "Bílá\nDub | 3000", 'show_when_field' => 'Typ', 'show_when_value' => 'Dveře'],
            ['label' => 'Email', 'type' => 'email', 'required' => true],
            ['label' => 'Odhad', 'type' => 'estimate', 'base_price' => '500', 'currency' => 'Kč'],
        ]]]]]]]]);
        $site->clearPageCache();

        $visitor = $site->client();
        $form = $visitor->get('/kalkulacka-212');
        $this->assertSame(2, substr_count($form->body, 'class="ka-krok"'), 'multi-step: the form is split into two steps');
        $this->assertStringContainsString('data-kroky', $form->body, 'multi-step: the form carries the steps marker');
        $this->assertStringContainsString('<legend>Upřesnění</legend>', $form->body, 'multi-step: the step has its legend');

        foreach (['data-kdyz="p0" data-kdyz-hodnota="Dveře"', 'value="Okna" data-cena="1200"', 'data-cena="9900"', 'data-cena-za="1500"', 'data-odhad data-zaklad="500" data-mena="Kč"'] as $needle) {
            $this->assertStringContainsString($needle, $form->body, "calculator: conditions and prices go to the script ($needle)");
        }
        $this->assertStringNotContainsString('| 1200', $form->body, 'calculator: the visitor never sees the price syntax');

        $site->exec("DELETE FROM ka_ip_checks WHERE type = 'formular'");
        $fields = ['source' => $form->field('source'), 'element' => $form->field('element'), 'zpet' => '/kalkulacka-212', 'as_cas' => $form->field('as_cas'), 'as_podpis' => $form->field('as_podpis')];
        sleep(4); // the anti-spam signature has a minimum age

        $redirect = $visitor->post('/formular', $fields + ['p0' => 'Dveře', 'p4' => 'd@example.cz', 'p5' => 1])->redirect;
        $this->assertMatchesRegularExpression('/result=pole.*field=3/', $redirect, 'conditions: a required field shown by the answer is checked on the server');

        $redirect = $visitor->post('/formular', $fields + ['p0' => 'Okna', 'p1' => 4, 'p4' => 'o@example.cz', 'p3' => 'Dub', 'p5' => 1])->redirect;
        $this->assertStringContainsString('result=ok', $redirect, 'conditions: a hidden required field does not block the form');

        $stored = json_decode((string) $site->value('SELECT data FROM ka_enquiries ORDER BY enquiry_id DESC LIMIT 1'), true);
        $this->assertSame('Typ=Okna|Počet=4|Email=o@example.cz|Odhad=7 700 Kč', implode('|', array_map(fn ($r) => $r[0] . '=' . str_replace("\u{a0}", ' ', $r[1]), $stored)), 'calculator: the server computes the estimate and drops the hidden answer');
    }
}
