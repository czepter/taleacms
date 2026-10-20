<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;

final class PopupsTest extends TestCase
{
    public function testRules(): void
    {
        // popups: server rules (places, language, period) and English MCP parameters
        $popupWhere = fn (array $x): array => $x + ['page_id' => null, 'collection' => null, 'news' => false, 'language' => 'cs', 'today' => '2026-09-25'];
        $popupSelected = \Talea\Builder\Popups::sanitizeRules(['where' => 'selected', 'pages' => ['4', 'x', 4], 'collections' => ['tym', 'Ne platna'], 'news' => 1, 'from' => '2026-02-30', 'campaign' => 'jaro<b>']);
        $this->assertSame([[4], ['tym'], true, '', 'jarob', 'all'], [$popupSelected['pages'], $popupSelected['collections'], $popupSelected['news'], $popupSelected['from'], $popupSelected['campaign'], $popupSelected['device']], 'Pop-up: sanitized rules');
        $this->assertSame([true, true, true, false], [
            \Talea\Builder\Popups::matches($popupSelected, $popupWhere(['page_id' => 4])), \Talea\Builder\Popups::matches($popupSelected, $popupWhere(['collection' => 'tym'])),
            \Talea\Builder\Popups::matches($popupSelected, $popupWhere(['news' => true])), \Talea\Builder\Popups::matches($popupSelected, $popupWhere(['page_id' => 5])),
        ], 'Pop-up: selected places – page, collection, news, nowhere else');
        $popupPeriod = \Talea\Builder\Popups::sanitizeRules(['from' => '2026-10-01', 'to' => '2026-10-31', 'language' => 'en']);
        $this->assertSame([false, true, false, false], [
            \Talea\Builder\Popups::matches($popupPeriod, $popupWhere(['language' => 'en'])), \Talea\Builder\Popups::matches($popupPeriod, $popupWhere(['language' => 'en', 'today' => '2026-10-15'])),
            \Talea\Builder\Popups::matches($popupPeriod, $popupWhere(['language' => 'cs', 'today' => '2026-10-15'])), \Talea\Builder\Popups::matches($popupPeriod, $popupWhere(['language' => 'en', 'today' => '2026-11-01'])),
        ], 'Pop-up: period and language apply to the whole site too');
    }
}
