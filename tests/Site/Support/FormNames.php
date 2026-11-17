<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Support;

/**
 * The names a rendered form posts its fields under (Form::fieldName: a technical name or the field's key). A test that fills in a form
 * reads them from the page, as the browser would, and addresses a field by its position: names($html)[1] is the second field.
 */
final class FormNames
{
    /**
     * @param string $html    a page with the form
     * @param string $element the element id of the form (data-form); empty = the first form of the page
     * @return list<string> the posted name of every field, in field order
     */
    public static function of(string $html, string $element = ''): array
    {
        preg_match_all('/<form\b[^>]*\bdata-form="([^"]*)"[^>]*\bdata-fields="([^"]*)"/', $html, $forms, PREG_SET_ORDER);
        foreach ($forms as [, $id, $names]) {
            if ($element === '' || html_entity_decode($id) === $element) {
                return json_decode(html_entity_decode($names), true) ?: [];
            }
        }

        return [];
    }

    /**
     * Answers given by position (p0, p1 …) under the names the form posts: 'p1' => 'x' becomes $names[1] => 'x'. Other entries stay.
     *
     * @param array<string, mixed> $fields
     * @param list<string>         $names
     * @return array<string, mixed>
     */
    public static function map(array $fields, array $names): array
    {
        $mapped = [];
        foreach ($fields as $key => $value) {
            $mapped[preg_match('/^p(\d+)$/', (string) $key, $m) === 1 && isset($names[(int) $m[1]]) ? $names[(int) $m[1]] : $key] = $value;
        }

        return $mapped;
    }
}
