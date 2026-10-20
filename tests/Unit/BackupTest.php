<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BackupTest extends TestCase
{
    /** backups to S3: AWS Signature V4 signing (the value verified by an independent computation) */
    public function testS3Signature(): void
    {
        $this->assertSame([true, true, true, true, true, false, false, false], array_map(\Talea\Core\NotFound::isBot(...), ['wp/v2/users', 'sellers.json', 'api/session/properties', '_next', 'api/news/x', 'about-us', 'en', 'cenik-2019']), '404: robot probes are not logged, real addresses are');
        // 1.9: structured data of collection item pages – only mapped fields, an offer needs a price and a currency
        $sdFields = [['key' => 'cena', 'label' => 'Cena', 'type' => 'text'], ['key' => 'kind', 'label' => 'Druh', 'type' => 'text'], ['key' => 'zacatek', 'label' => 'Začátek', 'type' => 'date']]; // check-english: allow
        $this->assertSame([['type' => 'Service', 'fields' => ['price' => 'cena'], 'currency' => 'EUR'], null], [\Talea\Builder\CollectionSchema::sanitize(['type' => 'Service', 'fields' => ['price' => 'cena', 'serviceType' => 'neni', 'hack' => 'kind'], 'currency' => 'eur'], $sdFields),
            \Talea\Builder\CollectionSchema::sanitize(['type' => 'Recipe'], $sdFields)], 'Collection structured data: an unknown field and type are dropped');
        $sdCollection = ['fields' => $sdFields, 'schema_org' => '{"type":"Service","fields":{"price":"cena","serviceType":"kind"},"currency":"EUR"}'];
        $this->assertSame(// check-english: allow
            ['@type' => 'Service', 'name' => 'Revize', 'url' => 'https://x.test/sluzby/revize', 'description' => 'Popis', 'serviceType' => 'Elektro', 'provider' => ['@id' => 'https://x.test/#firma'], // check-english: allow
                'offers' => ['@type' => 'Offer', 'price' => '1200.50', 'priceCurrency' => 'EUR', 'url' => 'https://x.test/sluzby/revize']], \Talea\Builder\CollectionSchema::forItem($sdCollection, ['name' => 'Revize', 'data' => ['cena' => '1 200,50', 'kind' => '<b>Elektro</b>']], 'https://x.test/sluzby/revize', 'Popis', '', 'https://x.test/#firma'), 'Collection structured data: a service with an offer'); // check-english: allow
        $this->assertSame([null, null], [\Talea\Builder\CollectionSchema::forItem(['fields' => $sdFields, 'schema_org' => '{"type":"Event","fields":{"startDate":"zacatek"}}'], ['name' => 'A', 'data' => []], 'u', '', '', 'i'),
            \Talea\Builder\CollectionSchema::forItem(['fields' => $sdFields], ['name' => 'A', 'data' => []], 'u', '', '', 'i')], 'Collection structured data: an event without a start does not pass, without a type nothing');
    }
}
