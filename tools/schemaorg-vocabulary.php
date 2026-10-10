<?php

/**
 * Generates system/data/schemaorg.json, the curated schema.org vocabulary of the structured-data element (issue HF-11).
 * A maintainer runs it by hand against a downloaded schema.org release; the site never downloads anything.
 *
 *   curl -sL -o /tmp/schemaorg.jsonld https://schema.org/version/latest/schemaorg-current-https.jsonld
 *   php tools/schemaorg-vocabulary.php /tmp/schemaorg.jsonld [--check]
 *
 * SPEC below is the editorial decision: which types a company site needs and which properties are required or recommended
 * (after Google's rich-result guidance). Labels, descriptions, value types and enum members come from the release; a type or
 * property that does not exist in it stops the run. --check only validates the committed file.
 */

declare(strict_types=1);

const SPEC = [
    // the thing, its parts
    'Product' => ['required' => ['name'], 'recommended' => ['image', 'description', 'sku', 'brand', 'offers', 'aggregateRating', 'review'], 'other' => ['gtin', 'mpn', 'url', 'color']],
    'Service' => ['required' => ['name'], 'recommended' => ['description', 'provider', 'areaServed', 'offers', 'image'], 'other' => ['serviceType', 'url']],
    'Offer' => ['required' => ['price', 'priceCurrency'], 'recommended' => ['availability', 'url', 'priceValidUntil', 'itemCondition'], 'other' => []],
    'Event' => ['required' => ['name', 'startDate', 'location'], 'recommended' => ['endDate', 'description', 'image', 'eventStatus', 'eventAttendanceMode', 'offers', 'organizer', 'performer'], 'other' => ['url']],
    'JobPosting' => ['required' => ['title', 'description', 'datePosted', 'hiringOrganization', 'jobLocation'], 'recommended' => ['validThrough', 'employmentType', 'baseSalary', 'directApply'], 'other' => ['identifier']],
    'Person' => ['required' => ['name'], 'recommended' => ['image', 'jobTitle', 'url', 'sameAs', 'worksFor'], 'other' => ['email', 'telephone', 'description']],
    'Course' => ['required' => ['name', 'description'], 'recommended' => ['provider', 'courseCode', 'offers'], 'other' => ['url', 'inLanguage']],
    'Recipe' => ['required' => ['name', 'image'], 'recommended' => ['author', 'description', 'prepTime', 'cookTime', 'totalTime', 'recipeYield', 'recipeIngredient', 'recipeInstructions', 'recipeCategory', 'recipeCuisine', 'aggregateRating'], 'other' => ['datePublished', 'keywords']],
    'HowTo' => ['required' => ['name', 'step'], 'recommended' => ['description', 'image', 'totalTime', 'supply', 'tool'], 'other' => []],
    'HowToStep' => ['required' => ['text'], 'recommended' => ['name', 'image', 'url'], 'other' => []],
    'VideoObject' => ['required' => ['name', 'thumbnailUrl', 'uploadDate'], 'recommended' => ['description', 'contentUrl', 'embedUrl', 'duration'], 'other' => []],
    'Review' => ['required' => ['itemReviewed', 'reviewRating', 'author'], 'recommended' => ['reviewBody', 'datePublished', 'name'], 'other' => []],
    'Rating' => ['required' => ['ratingValue'], 'recommended' => ['bestRating', 'worstRating'], 'other' => []],
    'AggregateRating' => ['required' => ['ratingValue', 'ratingCount'], 'recommended' => ['bestRating', 'worstRating', 'reviewCount'], 'other' => []],
    // businesses and places
    'Organization' => ['required' => ['name'], 'recommended' => ['url', 'logo', 'sameAs', 'contactPoint', 'address'], 'other' => ['email', 'telephone', 'description']],
    'LocalBusiness' => ['required' => ['name', 'address'], 'recommended' => ['image', 'telephone', 'url', 'openingHours', 'priceRange', 'geo', 'aggregateRating'], 'other' => ['email', 'description']],
    'Restaurant' => ['required' => ['name', 'address'], 'recommended' => ['image', 'telephone', 'servesCuisine', 'menu', 'acceptsReservations', 'openingHours', 'priceRange', 'aggregateRating'], 'other' => ['url']],
    'Store' => ['required' => ['name', 'address'], 'recommended' => ['image', 'telephone', 'openingHours', 'priceRange'], 'other' => ['url']],
    'ProfessionalService' => ['required' => ['name', 'address'], 'recommended' => ['image', 'telephone', 'openingHours', 'areaServed'], 'other' => ['url']],
    'HealthAndBeautyBusiness' => ['required' => ['name', 'address'], 'recommended' => ['image', 'telephone', 'openingHours', 'priceRange'], 'other' => ['url']],
    'Place' => ['required' => ['name'], 'recommended' => ['address', 'geo', 'image', 'telephone'], 'other' => ['url']],
    'PostalAddress' => ['required' => ['streetAddress', 'addressLocality', 'addressCountry'], 'recommended' => ['postalCode', 'addressRegion'], 'other' => []],
    'GeoCoordinates' => ['required' => ['latitude', 'longitude'], 'recommended' => [], 'other' => []],
    'ContactPoint' => ['required' => ['contactType'], 'recommended' => ['telephone', 'email', 'availableLanguage'], 'other' => []],
    'MonetaryAmount' => ['required' => ['currency', 'value'], 'recommended' => [], 'other' => []],
    // content
    'Article' => ['required' => ['headline'], 'recommended' => ['image', 'datePublished', 'dateModified', 'author', 'description', 'publisher'], 'other' => ['url']],
    'BlogPosting' => ['required' => ['headline'], 'recommended' => ['image', 'datePublished', 'dateModified', 'author', 'description', 'publisher'], 'other' => ['url']],
    'WebPage' => ['required' => ['name'], 'recommended' => ['description', 'image'], 'other' => ['url', 'datePublished', 'dateModified']],
    'FAQPage' => ['required' => ['mainEntity'], 'recommended' => [], 'other' => []],
    'Question' => ['required' => ['name', 'acceptedAnswer'], 'recommended' => [], 'other' => []],
    'Answer' => ['required' => ['text'], 'recommended' => [], 'other' => []],
    'ImageObject' => ['required' => ['url'], 'recommended' => ['caption', 'width', 'height'], 'other' => ['creator', 'license']],
    'SoftwareApplication' => ['required' => ['name', 'offers'], 'recommended' => ['applicationCategory', 'operatingSystem', 'aggregateRating'], 'other' => ['url', 'description']],
];

/** Properties whose schema.org range is the open "Thing" or too wide: the curated nested types a company site uses. */
const NESTED = [
    'FAQPage.mainEntity' => ['Question'], 'Review.itemReviewed' => ['Product', 'Service', 'LocalBusiness', 'Organization', 'Event', 'Course', 'Recipe'],
    'Review.author' => ['Person', 'Organization'], 'Product.brand' => ['Organization'],
];

/** Properties whose value is a long text, not a line. */
const LONG_TEXT = ['description', 'reviewBody', 'text', 'recipeInstructions', 'abstract'];

$file = $argv[1] ?? '';
$out = dirname(__DIR__) . '/system/data/schemaorg.json';

if (in_array('--check', $argv, true)) {
    exit(check($out));
}
if (!is_file($file)) {
    fwrite(STDERR, "Usage: php tools/schemaorg-vocabulary.php <schemaorg-current-https.jsonld> [--check]\n");
    exit(2);
}

$graph = json_decode((string) file_get_contents($file), true)['@graph'] ?? [];
$nodes = [];
foreach ($graph as $n) {
    $nodes[(string) $n['@id']] = $n;
}
$text = static function (mixed $v): string {
    $v = is_array($v) ? ($v['@value'] ?? '') : $v;

    return trim(strip_tags(html_entity_decode((string) $v)));
};
$ids = static fn (mixed $v): array => array_values(array_map(static fn (array $x): string => (string) $x['@id'], isset($v['@id']) ? [$v] : (array) $v));
$known = array_keys(SPEC);

$types = [];
foreach (SPEC as $name => $spec) {
    $node = $nodes["schema:$name"] ?? exit("Type $name is not in this schema.org release\n");
    $props = [];
    $groups = ['required' => true, 'recommended' => false, 'other' => false];
    foreach ($groups as $group => $flag) {
        foreach ($spec[$group] as $prop) {
            $pn = $nodes["schema:$prop"] ?? exit("Property $prop (of $name) is not in this schema.org release\n");
            $ranges = array_map(fn (string $r): string => substr($r, 7), $ids($pn['schema:rangeIncludes'] ?? []));
            $ranges = array_merge($ranges, NESTED["$name.$prop"] ?? []);
            $props[$prop] = ['required' => $group === 'required', 'recommended' => $group === 'recommended'] + describe($prop, $pn, $ranges, $known, $nodes, $text);
        }
    }
    $types[$name] = [
        'label' => $text($node['rdfs:label']),
        'description' => $text($node['rdfs:comment'] ?? ''),
        'extends' => substr($ids($node['rdfs:subClassOf'] ?? [])[0] ?? 'schema:Thing', 7),
        'properties' => $props,
    ];
}
ksort($types);
file_put_contents($out, json_encode(['source' => 'schema.org ' . ($graph[0]['schema:schemaVersion'] ?? 'release'), 'types' => $types], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
echo 'Wrote ', $out, ' (', count($types), " types)\n";
exit(check($out));

/**
 * @param list<string> $ranges
 * @param list<string> $known
 * @return array<string, mixed>
 */
function describe(string $prop, array $pn, array $ranges, array $known, array $nodes, callable $text): array
{
    $base = ['label' => ucfirst(trim((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $prop))), 'description' => $text($pn['rdfs:comment'] ?? '')];
    $base['label'] = $text($pn['rdfs:label'] ?? $prop) === $prop ? $base['label'] : $base['label'];
    // an enumeration (ItemAvailability …): its members
    foreach ($ranges as $r) {
        $members = [];
        foreach ($nodes as $id => $n) {
            if (($n['@type'] ?? null) === "schema:$r") {
                $members[] = substr($id, 7);
            }
        }
        if ($members !== [] && !in_array($r, $known, true) && !in_array($r, ['Boolean', 'Text', 'URL', 'Number', 'Integer', 'Float', 'Date', 'DateTime', 'Time', 'DataType'], true)) {
            sort($members);

            return $base + ['type' => 'enum', 'options' => $members];
        }
    }
    $nested = array_values(array_intersect($known, $ranges));
    $kind = match (true) {
        $prop === 'image' || in_array('ImageObject', $ranges, true) && $prop !== 'logo' && !in_array('Text', $ranges, true) => 'image',
        in_array($prop, ['logo', 'thumbnailUrl', 'contentUrl', 'embedUrl', 'url', 'sameAs', 'menu'], true) => 'url',
        in_array('DateTime', $ranges, true) && !in_array('Date', $ranges, true) => 'datetime',
        in_array('Date', $ranges, true) || in_array('DateTime', $ranges, true) => 'date',
        in_array('Boolean', $ranges, true) && count($ranges) <= 2 => 'boolean',
        $nested !== [] && !in_array('Text', $ranges, true) => 'thing',
        in_array('Integer', $ranges, true) && !in_array('Number', $ranges, true) => 'integer',
        in_array('Number', $ranges, true) || in_array('Float', $ranges, true) => 'number',
        in_array('Duration', $ranges, true) => 'duration',
        $nested !== [] && !in_array('Text', $ranges, true) => 'thing',
        in_array($prop, LONG_TEXT, true) => 'long_text',
        default => 'text',
    };
    $spec = $base + ['type' => $kind];
    if ($kind === 'thing' || ($nested !== [] && in_array($kind, ['text', 'long_text'], true))) {
        $spec['of'] = $nested;
    }
    if (in_array($prop, ['sameAs', 'recipeIngredient', 'recipeInstructions', 'step', 'offers', 'review', 'mainEntity', 'contactPoint', 'openingHours', 'jobLocation', 'supply', 'tool', 'performer', 'servesCuisine', 'availableLanguage', 'areaServed', 'keywords'], true)) {
        $spec['multiple'] = true;
    }

    return $spec;
}

function check(string $file): int
{
    $data = json_decode((string) @file_get_contents($file), true);
    $errors = [];
    if (!is_array($data['types'] ?? null)) {
        fwrite(STDERR, "Not readable: $file\n");

        return 1;
    }
    $allowed = ['text', 'long_text', 'url', 'date', 'datetime', 'number', 'integer', 'boolean', 'image', 'enum', 'thing', 'duration'];
    foreach ($data['types'] as $type => $t) {
        foreach ($t['properties'] as $prop => $p) {
            if (!in_array($p['type'] ?? '', $allowed, true)) {
                $errors[] = "$type.$prop: unknown value type";
            }
            foreach ($p['of'] ?? [] as $of) {
                if (!isset($data['types'][$of])) {
                    $errors[] = "$type.$prop: nested type $of is not in the vocabulary";
                }
            }
            if (($p['type'] ?? '') === 'enum' && ($p['options'] ?? []) === []) {
                $errors[] = "$type.$prop: enum without options";
            }
        }
    }
    foreach ($errors as $e) {
        fwrite(STDERR, "  $e\n");
    }
    echo $errors === [] ? "Vocabulary well-formed\n" : count($errors) . " problems\n";

    return $errors === [] ? 0 : 1;
}
