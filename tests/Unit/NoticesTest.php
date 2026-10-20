<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class NoticesTest extends TestCase
{
    /** 2.11: official notice board (Core\Notices) */
    public function testNoticeBoard(): void
    {
        $board = ['collection_id' => 7, 'preset' => 'notices', 'slug' => 'deska', 'detail' => 1,
            'fields' => \Talea\Builder\Collections::sanitizeFields(array_map(fn (array $f): array => ['key' => $f[0], 'label' => $f[1], 'type' => $f[2]], \Talea\Builder\Presets::get('notices')['fields']))];
        $this->assertSame([['posted', 'taken_down', 'reference', 'issuer', 'category', 'document', 'summary'], 'archive', 'past', true, true, false, true, false], [
            array_column(\Talea\Builder\Presets::get('notices')['fields'], 0), \Talea\Builder\Presets::get('notices')['extra_pages'][0]['suffix'], \Talea\Builder\Presets::get('notices')['extra_pages'][0]['list']['period'],
            is_callable(\Talea\Builder\Presets::get('notices')['template']), \Talea\Core\Notices::isBoard($board), \Talea\Core\Notices::isBoard(['preset' => 'people', 'fields' => $board['fields']]),
            \Talea\Core\Notices::isNotices(['preset' => 'notices', 'fields' => []]), \Talea\Core\Notices::isBoard(['preset' => 'notices', 'fields' => []])], '2.11 Notices: the preset has the dates, the archive page and its own item template; the board is recognised by preset and field');
        $this->assertSame(['upcoming', 'current', 'current', 'archived', 'current', ''], [
            \Talea\Core\Notices::status('2026-10-03', '2026-10-18', '2026-10-02'), \Talea\Core\Notices::status('2026-10-03', '2026-10-18', '2026-10-03'), \Talea\Core\Notices::status('2026-10-03', '2026-10-18', '2026-10-18'),
            \Talea\Core\Notices::status('2026-10-03', '2026-10-18', '2026-10-19'), \Talea\Core\Notices::status('2026-10-03', '', '2027-01-01'), \Talea\Core\Notices::status('', '', '2026-10-02')], '2.11 Notices::status – to be posted, on the board (the takedown day still counts), archived the day after, no dates');
        $this->assertSame([t('Posted from %s to %s', format_date('2026-10-03'), format_date('2026-10-18')), t('Taken down on %s – archived', format_date('2026-10-18')), t('To be posted on %s', format_date('2026-10-03')), t('Posted from %s', format_date('2026-10-03')), ''], [
            \Talea\Core\Notices::statusText('2026-10-03', '2026-10-18', '2026-10-10'), \Talea\Core\Notices::statusText('2026-10-03', '2026-10-18', '2026-10-19'), \Talea\Core\Notices::statusText('2026-10-03', '2026-10-18', '2026-10-01'),
            \Talea\Core\Notices::statusText('2026-10-03', '', '2026-10-10'), \Talea\Core\Notices::statusText('', '', '2026-10-10')], '2.11 Notices::statusText – the sentence for visitors with the site\'s date format');
        $noticeItem = ['name' => 'Záměr', 'slug' => 'zamer', 'created_at' => '2026-10-02 10:00:00', 'data' => ['posted' => '2026-10-03', 'taken_down' => '2026-10-18', 'reference' => 'MU/1', 'document' => '/media/zamer.pdf']]; // check-english: allow
        $this->assertSame([true, false], [isset(\Talea\Builder\Collections::values($board, $noticeItem, fn (string $p): string => '/' . $p)['notice_status']),
            isset(\Talea\Builder\Collections::values(['preset' => 'people', 'slug' => 'lide', 'detail' => 1, 'fields' => $board['fields']], $noticeItem, fn (string $p): string => '/' . $p)['notice_status'])], '2.11 {{notice_status}} only for a notice board – the people preset has none');
        $this->assertSame([true, false, false, true], [\Talea\Core\Notices::canHide('2026-10-03', '2026-10-02'), \Talea\Core\Notices::canHide('2026-10-02', '2026-10-02'), \Talea\Core\Notices::canHide('2026-09-01', '2026-10-02'), \Talea\Core\Notices::canHide('', '2026-10-02')], '2.11 Notices::canHide – only a notice still to be posted (or without a date) may be hidden');
        $noticeRows = [
            ['item_id' => 1, 'visible' => true, 'posted' => '2026-10-01', 'taken_down' => '2026-10-18'],  // on the board: posted today
            ['item_id' => 2, 'visible' => true, 'posted' => '2026-09-01', 'taken_down' => '2026-10-01'],  // taken down yesterday: both in one run
            ['item_id' => 3, 'visible' => true, 'posted' => '2026-09-01', 'taken_down' => '2026-10-02'],  // the takedown day itself still counts
            ['item_id' => 4, 'visible' => false, 'posted' => '2026-09-01', 'taken_down' => ''],          // hidden – never posted
            ['item_id' => 5, 'visible' => true, 'posted' => '2026-10-03', 'taken_down' => ''],           // to be posted tomorrow
            ['item_id' => 6, 'visible' => true, 'posted' => '2026-09-01', 'taken_down' => '2026-09-20'], // already recorded, both
            ['item_id' => 7, 'visible' => true, 'posted' => '2026-09-01', 'taken_down' => '2026-09-20'], // posted recorded, the takedown not yet
        ];
        $this->assertSame([[1, 'posted', '2026-10-01'], [2, 'posted', '2026-09-01'], [2, 'taken_down', '2026-10-01'], [3, 'posted', '2026-09-01'], [7, 'taken_down', '2026-09-20']], \Talea\Core\Notices::due($noticeRows, [6 => ['posted' => true, 'taken_down' => true], 7 => ['posted' => true]], '2026-10-02'), '2.11 Notices::due – posted when the day comes (visible only), taken_down the day after, each once');
        $this->assertSame([], \Talea\Core\Notices::due($noticeRows, [1 => ['posted' => true], 2 => ['posted' => true, 'taken_down' => true], 3 => ['posted' => true], 6 => ['posted' => true, 'taken_down' => true], 7 => ['posted' => true, 'taken_down' => true]], '2026-10-02'), '2.11 Notices::due – a second run adds nothing');
        $noticePrevious = ['item_id' => 5, 'name' => 'Rozpočet', 'slug' => 'rozpocet', 'visible' => 1, 'data' => '{"posted":"2026-10-01","taken_down":"","reference":"MU/12","issuer":"","category":"","document":"","summary":""}']; // check-english: allow
        $this->assertSame(// check-english: allow
            [['name' => ['', 'Rozpočet'], 'slug' => ['', 'rozpocet'], 'visible' => ['', 'yes'], 'posted' => ['', '2026-10-01'], 'reference' => ['', 'MU/12']], // check-english: allow
                ['name' => ['Rozpočet', 'Rozpočet 2026'], 'taken_down' => ['', '2026-10-20']], []], [ // check-english: allow
            \Talea\Core\Notices::changes($board, null, ['name' => 'Rozpočet', 'slug' => 'rozpocet', 'visible' => 1, 'data' => '{"posted":"2026-10-01","taken_down":"","reference":"MU/12"}']), // check-english: allow
            \Talea\Core\Notices::changes($board, $noticePrevious, ['name' => 'Rozpočet 2026', 'data' => '{"posted":"2026-10-01","taken_down":"2026-10-20","reference":"MU/12","issuer":"","category":"","document":"","summary":""}', 'updated_at' => 'x']), // check-english: allow
            \Talea\Core\Notices::changes($board, $noticePrevious, ['name' => 'Rozpočet', 'slug' => 'rozpocet', 'data' => $noticePrevious['data']])], '2.11 Notices::changes – a new notice lists its values, a change only what differs, no change nothing'); // check-english: allow
        $this->assertSame(['posted: → 2026-10-03; name: A → B; taken_down: 2026-10-18', 3600, 'any', true], [\Talea\Core\Notices::changesText(['posted' => ['', '2026-10-03'], 'name' => ['A', 'B'], 'taken_down' => '2026-10-18']),
            \Talea\Core\Scheduler::JOBS['notices'][0], \Talea\Core\Scheduler::JOBS['notices'][1], isset(\Talea\Core\Scheduler::jobs()['notices'])], '2.11 Notices::changesText and the job are known');
    }
}
