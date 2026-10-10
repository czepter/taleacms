<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Builder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Talea\Builder\Experiments;

#[CoversClass(Experiments::class)]
final class ExperimentsTest extends TestCase
{
    private function counts(int $viewsA, int $goalsA, int $viewsB, int $goalsB): array
    {
        return ['a' => ['views' => $viewsA, 'goals' => $goalsA], 'b' => ['views' => $viewsB, 'goals' => $goalsB]];
    }

    public function testTheNormalDistributionFunction(): void
    {
        $this->assertEqualsWithDelta(0.5, Experiments::normalCdf(0.0), 1e-6);
        $this->assertEqualsWithDelta(0.97725, Experiments::normalCdf(2.0), 1e-4);
        $this->assertEqualsWithDelta(0.02275, Experiments::normalCdf(-2.0), 1e-4);
    }

    public function testEqualCountsGiveNoPreference(): void
    {
        $a = Experiments::analyze(1000, 50, 1000, 50);

        $this->assertEqualsWithDelta(0.5, $a['probability_b'], 1e-6);
        $this->assertEqualsWithDelta(0.0, $a['uplift'], 1e-6);
        $this->assertEqualsWithDelta(0.05, $a['rate_a'], 1e-9);
    }

    public function testAClearlyBetterVariantIsFoundWithAnUpliftRange(): void
    {
        $a = Experiments::analyze(1000, 50, 1000, 80); // 5% against 8%

        $this->assertGreaterThan(0.99, $a['probability_b']);
        $this->assertGreaterThan(0.4, $a['uplift']);
        $this->assertGreaterThan($a['uplift_low'], $a['uplift']);
        $this->assertGreaterThan($a['uplift'], $a['uplift_high']);
        $this->assertGreaterThan(0.0, $a['uplift_low']);
    }

    public function testAWorseVariantHasALowProbability(): void
    {
        $this->assertLessThan(0.01, Experiments::analyze(1000, 80, 1000, 50)['probability_b']);
    }

    public function testNothingCountedYetIsHarmless(): void
    {
        $a = Experiments::analyze(0, 0, 0, 0);

        $this->assertEqualsWithDelta(0.5, $a['probability_b'], 1e-6);
        $this->assertSame(0.0, $a['rate_a']);
        $this->assertSame('collecting', Experiments::verdict($this->counts(0, 0, 0, 0), 0)['state']);
    }

    public function testGoalsAreNeverMoreThanViews(): void
    {
        $this->assertLessThanOrEqual(1.0, Experiments::analyze(10, 99, 10, 99)['rate_a'] + 0.0);
    }

    public function testTooFewViewsOrDaysKeepTheResultUntrusted(): void
    {
        $this->assertSame('collecting', Experiments::verdict($this->counts(150, 5, 150, 30), 30)['state'], 'under 200 views of a version');
        $this->assertSame('collecting', Experiments::verdict($this->counts(1000, 50, 1000, 90), 6)['state'], 'under 7 days');
    }

    public function testAWinnerNeedsTheViewsTheDaysAndTheProbability(): void
    {
        $v = Experiments::verdict($this->counts(1000, 50, 1000, 90), 7);

        $this->assertSame('winner', $v['state']);
        $this->assertSame('b', $v['winner']);
        $this->assertNotContains(false, array_column($v['checks'], 'met'));
    }

    public function testTheOriginalCanWin(): void
    {
        $v = Experiments::verdict($this->counts(1000, 90, 1000, 50), 10);

        $this->assertSame('winner', $v['state']);
        $this->assertSame('a', $v['winner']);
    }

    public function testASmallDifferenceIsInconclusive(): void
    {
        $v = Experiments::verdict($this->counts(1000, 50, 1000, 52), 14);

        $this->assertSame('inconclusive', $v['state']);
        $this->assertNull($v['winner']);
    }

    public function testBMustBeBetterByTheMinimumUpliftEvenWhenCertain(): void
    {
        // a million views each: 5.00% against 5.10% (2% uplift) is nearly certain but below the 5% minimum
        $v = Experiments::verdict($this->counts(1000000, 50000, 1000000, 51000), 30);

        $this->assertGreaterThanOrEqual(Experiments::MIN_PROBABILITY, $v['analysis']['probability_b']);
        $this->assertNotSame('b', $v['winner']);
    }

    public function testTheGuardrailsAreTheDocumentedOnes(): void
    {
        $this->assertSame([200, 7, 0.95, 0.05], [Experiments::MIN_VIEWS, Experiments::MIN_DAYS, Experiments::MIN_PROBABILITY, Experiments::MIN_UPLIFT]);
    }

    public function testTheHeadChoosesBeforePaintAndStoresOnlyWithConsent(): void
    {
        $html = Experiments::head(['x' => [['id' => 'abc', 'goal' => 'form', 'target' => '']], 'g' => []], '/experiment');

        $this->assertStringContainsString('data-variants', $html);
        $this->assertStringContainsString('html:not([data-variants~="abc:b"]) [data-experiment="abc"][data-variant="b"]{display:none}', $html);
        // the only write to local storage sits behind the consent test (the cookie bar's own cookie)
        $this->assertSame(1, substr_count($html, 'localStorage.setItem'));
        $this->assertMatchesRegularExpression('/if\(c\)\{try\{localStorage\.setItem/', $html);
        $this->assertStringNotContainsString('document.cookie=', $html);
        $this->assertSame('', Experiments::head(['x' => [], 'g' => []], '/experiment'));
    }
}
