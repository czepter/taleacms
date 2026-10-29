<?php

declare(strict_types=1);

namespace Talea\Front;

use Talea\Builder\Elements\Form;
use Talea\Core\Db;

/**
 * Drop-off counters of multi-step forms: a daily count of visitors per form and step, sent by a beacon from the page (no cookie,
 * no visitor id, no answers). Step 1 is the visitor's first answer, step n the nth step reached.
 */
final class FormSteps
{
    public const int MAX_STEP = 40;

    /** The number of steps a published form really has (1 + its step fields), or 0 when the source and element name no published form. */
    public static function stepCount(Db $db, string $source, string $element): int
    {
        $form = strlen($source) <= 40 ? Forms::findElement($db, $source, $element, Form::TYPE) : null;

        return $form === null ? 0 : 1 + count(array_filter((array) ($form['content']['fields'] ?? []), fn ($f): bool => ($f['type'] ?? '') === 'step'));
    }

    public static function record(Db $db, string $source, string $element, int $step): void
    {
        $db->upsert('form_steps', ['source' => $source, 'element' => $element, 'step' => $step, 'day' => date('Y-m-d'), 'reached' => 1],
            ['source', 'element', 'step', 'day'], ['reached' => '{old.reached} + 1']);
        if (random_int(1, 50) === 1) {
            $db->run('DELETE FROM {form_steps} WHERE day < ?', [date('Y-m-d', strtotime('-400 days'))]);
        }
    }

    /** @return list<array{step: int, reached: int}> */
    public static function funnel(Db $db, string $source, string $element, int $days): array
    {
        return array_map(fn (array $r): array => ['step' => (int) $r['step'], 'reached' => (int) $r['reached']],
            $db->all('SELECT step, SUM(reached) AS reached FROM {form_steps} WHERE source = ? AND element = ? AND day >= ? GROUP BY step ORDER BY step',
                [$source, $element, date('Y-m-d', strtotime('-' . max(1, $days) . ' days'))]));
    }

    /**
     * The funnel with the step titles read from the PUBLISHED form: step 1 is the part before the first step field (titled by the
     * form's name), step n the label of the (n-1)th step field. A step nobody reached has 0.
     *
     * @return list<array{step: int, title: string, reached: int}>
     */
    public static function steps(Db $db, string $source, string $element, int $days): array
    {
        $form = Forms::findElement($db, $source, $element, Form::TYPE);
        $titles = [trim((string) ($form['content']['name'] ?? '')) ?: t('Step %d', 1)];
        foreach ((array) ($form['content']['fields'] ?? []) as $field) {
            if (($field['type'] ?? '') === 'step') {
                $titles[] = trim((string) ($field['label'] ?? '')) ?: t('Step %d', count($titles) + 1);
            }
        }
        $reached = array_column(self::funnel($db, $source, $element, $days), 'reached', 'step');
        $out = [];
        for ($n = 1; $n <= max(count($titles), $reached === [] ? 0 : max(array_keys($reached))); $n++) {
            $out[] = ['step' => $n, 'title' => $titles[$n - 1] ?? t('Step %d', $n), 'reached' => $reached[$n] ?? 0];
        }

        return $out;
    }

    /** The biggest fall between two consecutive steps, or null when nobody dropped. @param list<array{step: int, title: string, reached: int}> $steps */
    public static function biggestDrop(array $steps): ?string
    {
        $worst = null;
        for ($i = 1; $i < count($steps); $i++) {
            $lost = $steps[$i - 1]['reached'] - $steps[$i]['reached'];
            if ($lost > 0 && ($worst === null || $lost > $worst[0])) {
                $worst = [$lost, $steps[$i - 1], $steps[$i]];
            }
        }

        return $worst === null ? null : t('Most visitors leave between "%s" and "%s": %d of %d did not go on.', $worst[1]['title'], $worst[2]['title'], $worst[0], $worst[1]['reached']);
    }

    /** Per form that counted something in the period (get_stats → forms). @return list<array{form: string, page: string, steps: list<array{step: int, title: string, reached: int}>, sent: int}> */
    public static function overview(Db $db, int $days): array
    {
        $out = [];
        foreach ($db->all('SELECT DISTINCT source, element FROM {form_steps} WHERE day >= ? ORDER BY source, element', [date('Y-m-d', strtotime('-' . max(1, $days) . ' days'))]) as $r) {
            $form = Forms::findElement($db, (string) $r['source'], (string) $r['element'], Form::TYPE);
            if ($form === null) {
                continue;
            }
            $page = preg_match('/^page:(\d+)$/', (string) $r['source'], $m) ? (string) $db->value('SELECT title FROM {pages} WHERE page_id = ?', [(int) $m[1]]) : (string) $r['source'];
            $out[] = ['form' => (string) ($form['content']['name'] ?? ''), 'page' => $page, 'steps' => self::steps($db, (string) $r['source'], (string) $r['element'], $days),
                'sent' => self::sent($db, (string) $r['source'], (string) $r['element'], $days)];
        }

        return $out;
    }

    public static function sent(Db $db, string $source, string $element, int $days): int
    {
        return (int) $db->value("SELECT COUNT(*) FROM {enquiries} WHERE source = ? AND element = ? AND category <> 'spam' AND deleted_at IS NULL AND created_at >= ?",
            [$source, $element, date('Y-m-d 00:00:00', strtotime('-' . max(1, $days) . ' days'))]);
    }
}
