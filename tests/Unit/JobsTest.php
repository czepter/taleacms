<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class JobsTest extends TestCase
{
    /** 2.11: job openings – JobPosting (Builder\CollectionSchema), the application form of the preset, retention of applications (Core\Jobs) */
    public function testJobOpenings(): void
    {
        $jobsPreset = \Talea\Builder\Presets::get('jobs');
        $jobFields = \Talea\Builder\Collections::sanitizeFields(array_map(fn (array $f): array => ['key' => $f[0], 'label' => $f[1], 'type' => $f[2]], $jobsPreset['fields']));
        $jobCollection = fn (string $currency): array => ['slug' => 'jobs', 'detail' => 1, 'fields' => $jobFields, 'schema_org' => json_encode(['currency' => $currency] + $jobsPreset['schema'])];
        $jobIssuer = ['@type' => 'LocalBusiness', '@id' => 'https://example.cz/#firma', 'name' => 'Web', 'legalName' => 'Truhlárna s.r.o.', 'url' => 'https://example.cz/', 'logo' => 'https://example.cz/media/logo.svg', // check-english: allow
            'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Brno', 'addressCountry' => 'CZ']];
        $jobItem = ['name' => 'Truhlář', 'slug' => 'truhlar', 'created_at' => '2026-10-02 10:00:00', 'valid_until' => '2026-11-30', // check-english: allow
            'data' => ['location' => 'Brno', 'employment_type' => 'plný úvazek', 'salary_min' => '35 000', 'salary_max' => '45000', 'salary_unit' => 'per month', 'description' => '<p>Výroba <b>nábytku</b>.</p>']]; // check-english: allow
        $posting = \Talea\Builder\CollectionSchema::forItem($jobCollection('CZK'), $jobItem, 'https://example.cz/jobs/truhlar', 'meta description', '', $jobIssuer['@id'], $jobIssuer);
        $this->assertSame([
            '@type' => 'JobPosting', 'title' => 'Truhlář', 'url' => 'https://example.cz/jobs/truhlar', 'description' => 'Výroba nábytku.', 'datePosted' => date('c', strtotime('2026-10-02 10:00:00')), 'validThrough' => '2026-11-30', // check-english: allow
            'employmentType' => 'FULL_TIME', 'hiringOrganization' => ['@type' => 'Organization', 'name' => 'Truhlárna s.r.o.', 'sameAs' => 'https://example.cz/', 'logo' => 'https://example.cz/media/logo.svg'], // check-english: allow
            'jobLocation' => ['@type' => 'Place', 'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Brno', 'addressCountry' => 'CZ']],
            'baseSalary' => ['@type' => 'MonetaryAmount', 'currency' => 'CZK', 'value' => ['@type' => 'QuantitativeValue', 'minValue' => 35000.0, 'maxValue' => 45000.0, 'unitText' => 'MONTH']]], $posting, '2.11 JobPosting: title, dates, the hiring organization from the company, the place with the company country, a salary range with the collection currency, recognised type and unit');
        $bareItem = ['name' => 'Svářeč', 'slug' => 'svarec', 'created_at' => '2026-10-02 10:00:00', 'valid_until' => null, 'data' => ['employment_type' => 'podle dohody', 'salary_min' => '', 'salary_max' => '']]; // check-english: allow
        $bare = \Talea\Builder\CollectionSchema::forItem($jobCollection('CZK'), $bareItem, 'https://example.cz/jobs/svarec', 'meta description', '', $jobIssuer['@id'], []);
        $this->assertSame(['@type' => 'JobPosting', 'title' => 'Svářeč', 'url' => 'https://example.cz/jobs/svarec', 'description' => 'meta description', 'datePosted' => date('c', strtotime('2026-10-02 10:00:00')), 'employmentType' => 'podle dohody'], $bare, '2.11 JobPosting: what is missing is left out, never guessed – no validThrough, salary, place or organization; an unknown employment type stays the text; the meta description'); // check-english: allow
        $oneFigure = ['data' => ['salary_min' => '250', 'salary_unit' => 'za hodinu', 'employment_type' => 'Teilzeit']] + $jobItem;
        $this->assertSame([['@type' => 'MonetaryAmount', 'currency' => 'EUR', 'value' => ['@type' => 'QuantitativeValue', 'value' => 250.0, 'unitText' => 'HOUR']], 'PART_TIME', false], [
            \Talea\Builder\CollectionSchema::forItem($jobCollection('EUR'), $oneFigure, 'u', '', '', 'i', [])['baseSalary'], \Talea\Builder\CollectionSchema::forItem($jobCollection('EUR'), $oneFigure, 'u', '', '', 'i', [])['employmentType'],
            isset(\Talea\Builder\CollectionSchema::forItem($jobCollection(''), $jobItem, 'u', '', '', 'i', [])['baseSalary'])], '2.11 JobPosting: a single salary figure is a value; without the collection currency no salary at all');
        $this->assertSame(// check-english: allow
            [['FULL_TIME', 'FULL_TIME', 'FULL_TIME', 'FULL_TIME', 'PART_TIME', 'PART_TIME', 'PART_TIME', 'CONTRACTOR', 'CONTRACTOR', 'TEMPORARY', 'INTERN', 'INTERN', '', 'flexible'],
                ['MONTH', 'MONTH', 'MONTH', 'MONTH', 'HOUR', 'HOUR', 'HOUR', 'YEAR', 'YEAR', 'WEEK', 'DAY', '', '']], [
            array_map(\Talea\Builder\CollectionSchema::employmentType(...), ['full-time', 'HPP', 'Vollzeit', 'pełny etat', 'part-time', 'zkrácený úvazek', 'niepełny etat', 'na IČO', 'freelance contract', 'brigáda', 'stáž', 'Internship', '', 'flexible']), // check-english: allow
            array_map(\Talea\Builder\CollectionSchema::salaryUnit(...), ['per month', 'měsíčně', 'pro Monat', 'miesięcznie', 'per hour', 'za hodinu', 'pro Stunde', 'za rok', 'p. a.', 'týdně', 'per day', '', 'brutto'])], '2.11 CollectionSchema::employmentType and salaryUnit in several languages; unknown = the text, resp. nothing'); // check-english: allow
        $this->assertSame(// check-english: allow
            ['@type' => 'Offer', 'price' => '1200', 'priceCurrency' => 'EUR', 'url' => 'u'], \Talea\Builder\CollectionSchema::forItem(['slug' => 's', 'detail' => 1, 'fields' => [['key' => 'cena', 'label' => 'Cena', 'type' => 'number']],
            'schema_org' => '{"type":"Service","fields":{"price":"cena"},"currency":"EUR"}'], ['name' => 'Montáž', 'data' => ['cena' => '1200']], 'u', 'd', '', 'https://example.cz/#firma')['offers'], '2.11 JobPosting: the other types are unchanged – a service still gets its offer'); // check-english: allow
        // the item template the preset brings: the job text and the Job application form with a CV and the hidden job name
        $findForm = function (array $nodes) use (&$findForm): ?array {
            foreach ($nodes as $n) {
                if (($n['type'] ?? '') === 'form') {
                    return $n;
                }
                if (($found = $findForm($n['children'] ?? [])) !== null) {
                    return $found;
                }
            }

            return null;
        };
        $jobForm = $findForm(\Talea\Builder\Presets::itemTemplate($jobsPreset, $jobFields)['children']);
        $this->assertSame([t('Job application'), ['text', 'email', 'tel', 'file', 'textarea', 'checkbox', 'hidden'], true, '{{name}}', true], [
            $jobForm['content']['name'] ?? null, array_column($jobForm['content']['fields'] ?? [], 'type'), $jobForm['content']['fields'][3]['required'] ?? null, $jobForm['content']['fields'][6]['value'] ?? null, $jobForm['content']['fields'][5]['required'] ?? null], '2.11 jobs preset: the item template has a Job application form – name, e-mail, phone, a required CV attachment, a message, consent and the hidden job name that survives sanitizing'); // t(): the dictionary of an earlier test is still set
        $this->assertSame(['JobPosting', ['sort' => 'newest'], true, ['preset' => 'people'], true], [$jobsPreset['schema']['type'], $jobsPreset['list'], $jobsPreset['redirect_hidden'], $jobsPreset['fields'][8][3] ?? null, str_contains($jobsPreset['claude'], 'valid_until')], '2.11 jobs preset: JobPosting data, newest first, hidden jobs lead to the jobs page, the contact links to the team');
        // retention of applications: enquiries from a jobs collection (their source) older than the months
        $jobSources = ['collection:5'];
        $applications = [
            ['enquiry_id' => 1, 'source' => 'collection:5', 'created_at' => '2026-03-01 10:00:00'], // an old application
            ['enquiry_id' => 2, 'source' => 'collection:5', 'created_at' => '2026-09-01 10:00:00'], // a recent one
            ['enquiry_id' => 3, 'source' => 'page:7', 'created_at' => '2025-01-01 10:00:00'], // an old enquiry from a page – not an application
            ['enquiry_id' => 4, 'source' => 'collection:9', 'created_at' => '2025-01-01 10:00:00'], // an old enquiry from another collection's item page
            ['enquiry_id' => 5, 'source' => 'collection:5', 'created_at' => '2026-04-02 12:00:00'], // exactly six months – not older yet
        ];
        $this->assertSame([1], array_column(\Talea\Core\Jobs::expiredApplications($applications, $jobSources, 6, '2026-10-02 12:00:00'), 'enquiry_id'), '2.11 Jobs::expiredApplications – only from a jobs collection and older than the months');
        $this->assertSame([[1, 2, 5], [], []], [
            array_column(\Talea\Core\Jobs::expiredApplications($applications, $jobSources, 1, '2026-10-02 12:00:00'), 'enquiry_id'), \Talea\Core\Jobs::expiredApplications($applications, $jobSources, 0, '2026-10-02 12:00:00'),
            \Talea\Core\Jobs::expiredApplications($applications, [], 6, '2026-10-02 12:00:00')], '2.11 Jobs::expiredApplications – a shorter retention takes the one at the limit too; 0 months or no jobs collection = nothing');
        $this->assertSame([['CZ', 6], ['DE', 6], ['AT', 6], null, null], [\Talea\Core\Jobs::suggestedRetention('CZ', 'en'), \Talea\Core\Jobs::suggestedRetention('', 'de'), \Talea\Core\Jobs::suggestedRetention('at', ''),
            \Talea\Core\Jobs::suggestedRetention('FR', 'fr'), \Talea\Core\Jobs::suggestedRetention('', 'en')], '2.11 Jobs::suggestedRetention – by the company country, then by the site language; unknown = no hint');
        $this->assertSame(['Job openings', true, '0', 'Job opening'], [\Talea\Core\Audit::KINDS['job'], isset(\Talea\Core\Events::TYPES['applications.purged']), \Talea\Core\Settings::DEFAULTS['job_applications_months'], \Talea\Builder\CollectionSchema::TYPES['JobPosting'][0]], '2.11: the audit kind, the event and the setting of job applications are known');
    }
}
