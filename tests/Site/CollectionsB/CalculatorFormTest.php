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
        $page = (int) $site->mcpResult('vytvor_stranku', ['titulek' => 'Kalkulacka 212', 'zobrazit' => true])['id'];
        $site->mcp('stavba_uloz', ['id' => $page, 'publikovat' => true, 'stavba' => ['v' => 1, 'deti' => [['typ' => 'sekce', 'deti' => [['typ' => 'formular', 'obsah' => ['nazev' => 'Kalkulace', 'bez_captcha' => true, 'pole' => [
            ['popisek' => 'Typ', 'typ' => 'volba', 'povinne' => true, 'moznosti_volby' => "Okna | 1200\nDveře | 9 900"],
            ['popisek' => 'Počet', 'typ' => 'cislo', 'cena_za_jednotku' => '1500'],
            ['popisek' => 'Upřesnění', 'typ' => 'krok'],
            ['popisek' => 'Barva dveří', 'typ' => 'vyber', 'povinne' => true, 'moznosti' => "Bílá\nDub | 3000", 'kdyz_pole' => 'Typ', 'kdyz_hodnota' => 'Dveře'],
            ['popisek' => 'Email', 'typ' => 'email', 'povinne' => true],
            ['popisek' => 'Odhad', 'typ' => 'odhad', 'zaklad' => '500', 'mena' => 'Kč'],
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

        $site->exec("DELETE FROM ka_kontrola_ip WHERE typ = 'formular'");
        $fields = ['zdroj' => $form->field('zdroj'), 'prvek' => $form->field('prvek'), 'zpet' => '/kalkulacka-212', 'as_cas' => $form->field('as_cas'), 'as_podpis' => $form->field('as_podpis')];
        sleep(4); // the anti-spam signature has a minimum age

        $redirect = $visitor->post('/formular', $fields + ['p0' => 'Dveře', 'p4' => 'd@example.cz', 'p5' => 1])->redirect;
        $this->assertMatchesRegularExpression('/result=pole.*field=3/', $redirect, 'conditions: a required field shown by the answer is checked on the server');

        $redirect = $visitor->post('/formular', $fields + ['p0' => 'Okna', 'p1' => 4, 'p4' => 'o@example.cz', 'p3' => 'Dub', 'p5' => 1])->redirect;
        $this->assertStringContainsString('result=ok', $redirect, 'conditions: a hidden required field does not block the form');

        $stored = json_decode((string) $site->value('SELECT data FROM ka_poptavky ORDER BY idp DESC LIMIT 1'), true);
        $this->assertSame('Typ=Okna|Počet=4|Email=o@example.cz|Odhad=7 700 Kč', implode('|', array_map(fn ($r) => $r[0] . '=' . str_replace("\u{a0}", ' ', $r[1]), $stored)), 'calculator: the server computes the estimate and drops the hidden answer');
    }
}
