<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Core\Facts;
use Kaleta\Core\Language;

/**
 * MCP tools for business facts (2.10, Core\Facts): the facts, saving them, and the claims inventory – sentences that
 * state numbers as plain text, or still state the old value of a fact. Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait FactTools
{
    /** list_facts */
    private function toolListFacts(string $name, array $a): mixed
    {
        $language = preg_match('/^[a-z]{2}$/', (string) ($a['language'] ?? '')) ? (string) $a['language'] : '';
        $usage = Facts::usage($this->app->db());

        return [
            'facts' => array_values(array_map(fn (array $f): array => ['key' => $f['key'], 'label' => $f['label'], 'type' => $f['type'], 'value' => $f['value'], 'shown_as' => $f['display'],
                'schema_property' => $f['schema'] !== '' ? $f['schema'] : null, 'source' => $f['source'] !== '' ? $f['source'] : null, 'from_settings' => $f['builtIn'],
                'used_in' => $usage[$f['key']] ?? 0], Facts::all($this->app, $language))),
            'token' => '{{fact.<key>}} in texts, buttons and links (tel:{{fact.company_phone}}); the site fills it in for visitors.',
            'types' => array_keys(Facts::TYPES),
            'schema_properties' => array_values(array_filter(array_keys(Facts::SCHEMA_PROPS))),
        ];
    }

    /** save_fact */
    private function toolSaveFact(string $name, array $a): mixed
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('Facts are changed by administrators – the site states them everywhere.');
        }
        $key = mb_strtolower(trim((string) ($a['key'] ?? '')));
        $language = preg_match('/^[a-z]{2}$/', (string) ($a['language'] ?? '')) && in_array((string) $a['language'], Language::additional($this->app->settings()), true) ? (string) $a['language'] : '';
        $before = Facts::all($this->app)[$key] ?? null;
        $data = array_filter(['label' => $a['label'] ?? null, 'type' => $a['type'] ?? null, 'value' => isset($a['value']) ? (string) $a['value'] : null,
            'schema' => $a['schema_property'] ?? null, 'source' => $a['source'] ?? null], fn (mixed $v): bool => $v !== null);
        $error = Facts::save($this->app, $key, $data, $language);
        if ($error !== null) {
            throw new \DomainException($error);
        }
        $after = Facts::all($this->app, $language)[$key];
        $stillOld = $before !== null && $language === '' && $before['value'] !== '' && $before['value'] !== $after['value']
            ? Facts::occurrences($this->app->db(), $before['display'], 50) : [];

        return ['key' => $key, 'value' => $after['value'], 'shown_as' => $after['display'], 'token' => '{{fact.' . $key . '}}',
            'still_states_the_old_value' => $stillOld,
            'next' => $stillOld !== [] ? 'These sentences still state the old value as plain text: replace the value with the token {{fact.' . $key . '}} (edit_build, update_news…), so the next change updates them too.' : null];
    }

    /** delete_fact */
    private function toolDeleteFact(string $name, array $a): mixed
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('Facts are changed by administrators – the site states them everywhere.');
        }
        $key = (string) ($a['key'] ?? '');
        $used = Facts::occurrences($this->app->db(), '{{fact.' . $key . '}}', 50);
        if (!Facts::delete($this->app, $key)) {
            throw new \DomainException('The fact does not exist (built-in facts come from the settings). Use list_facts.');
        }

        return ['deleted' => $key, 'still_used_in' => $used];
    }

    /** find_claims */
    private function toolFindClaims(string $name, array $a): mixed
    {
        $text = trim((string) ($a['text'] ?? ''));
        $limit = max(1, min(300, (int) ($a['limit'] ?? 100)));
        $found = $text !== '' ? Facts::occurrences($this->app->db(), $text, $limit) : Facts::claims($this->app->db(), $limit);

        return ['sentences' => $found, 'count' => count($found),
            'next' => $text !== '' ? 'Sentences that state this text. Replace it with a fact token where it is the same fact.'
                : 'Sentences with years, numbers, percentages or amounts written as plain text. Ask the user which are facts; save_fact creates one, then put {{fact.key}} in place of the number.'];
    }

    /** list_hours */
    private function toolListHours(string $name, array $a): mixed
    {
        $s = $this->app->settings();
        $week = \Kaleta\Core\Hours::week($s);

        return [
            'week' => array_map(fn (array $ranges): array => array_map(fn (array $r): string => $r[0] . '-' . $r[1], $ranges), $week),
            'as_written' => $s->get('company_hours'),
            'exceptions' => \Kaleta\Core\Hours::exceptions($this->app->db(), (bool) ($a['past_too'] ?? false)),
            'now' => \Kaleta\Core\Hours::statusText($this->app),
            'today' => \Kaleta\Core\Hours::todayText($this->app),
            'tokens' => '{{hours.status}} (open now, until when) and {{hours.today}} (today\'s hours) in texts; the Company details element shows the hours with the exceptions of the next 30 days.',
            'next' => 'The regular week is changed with update_settings (company_hours, one day or range per line). Holidays and other days: save_hours_exception.',
        ];
    }

    /** save_hours_exception */
    private function toolSaveHoursException(string $name, array $a): mixed
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('Opening hours are changed by administrators.');
        }
        $error = \Kaleta\Core\Hours::save($this->app, ['from' => (string) ($a['from'] ?? ''), 'to' => (string) ($a['to'] ?? ''), 'closed' => ($a['hours'] ?? '') === '' || (bool) ($a['closed'] ?? false),
            'hours' => (string) ($a['hours'] ?? ''), 'note' => (string) ($a['note'] ?? ''), 'notice_days' => (int) ($a['notice_days'] ?? 7)], (int) ($a['id'] ?? 0));
        if ($error !== null) {
            throw new \DomainException($error);
        }

        return ['exceptions' => \Kaleta\Core\Hours::exceptions($this->app->db()), 'now' => \Kaleta\Core\Hours::statusText($this->app)];
    }

    /** delete_hours_exception */
    private function toolDeleteHoursException(string $name, array $a): mixed
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('Opening hours are changed by administrators.');
        }
        if (!\Kaleta\Core\Hours::delete($this->app, (int) ($a['id'] ?? 0))) {
            throw new \DomainException('No such exception. Use list_hours.');
        }

        return ['exceptions' => \Kaleta\Core\Hours::exceptions($this->app->db())];
    }
}
