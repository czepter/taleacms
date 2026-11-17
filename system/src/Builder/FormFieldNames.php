<?php

declare(strict_types=1);

namespace Talea\Builder;

use Talea\Builder\Elements\Form;

/**
 * The technical names of form fields (docs/specs/form-builder.md, Field names): which name a field may have (Form::sanitizeElement
 * asks problem()) and the names a form gets when it is published (fromLabels()).
 */
final class FormFieldNames
{
    /**
     * The technical names a form gets when it is PUBLISHED (FormRecords::store, Publisher::form): every field without a name takes
     * (a) the name of the field with the same key in the form as it is published now, so a draft that never carried the names renames
     * nothing, else (b) a readable name made from its label ("E-Mail" -> e-mail, "Vorname" -> vorname), else (c) its key, written out.
     * The name is stored once and never follows the label afterwards; names that are set (the owner's, the frozen p<i>) are not touched.
     * Not run on a draft save: a field just added still has its default label.
     *
     * @param array<string, mixed> $content   the form's content (a draft about to be published)
     * @param array<string, mixed> $published the content of the same form as published now (empty when it is new)
     * @return array<string, mixed>
     */
    public static function fromLabels(array $content, array $published): array
    {
        $fields = array_values(array_filter((array) ($content['fields'] ?? []), 'is_array'));
        if ($fields === [] || count($fields) !== count((array) $content['fields'])) {
            return $content;
        }
        $before = [];
        foreach ((array) ($published['fields'] ?? []) as $field) {
            if (is_array($field) && (string) ($field['key'] ?? '') !== '' && trim((string) ($field['name'] ?? '')) !== '') {
                $before[(string) $field['key']] = (string) $field['name'];
            }
        }
        $taken = [];
        // the names that exist come first, so a new field never takes the name of a published one
        foreach ($fields as $i => $field) {
            $key = (string) ($field['key'] ?? '');
            if (trim((string) ($field['name'] ?? '')) === '' && isset($before[$key]) && !isset($taken[$before[$key]])) {
                $fields[$i]['name'] = $before[$key];
                $taken[$before[$key]] = true;
            } elseif (trim((string) ($field['name'] ?? '')) !== '') {
                $taken[trim((string) $field['name'])] = true;
            }
        }
        foreach ($fields as $i => $field) {
            if (trim((string) ($field['name'] ?? '')) !== '') {
                continue;
            }
            $key = (string) ($field['key'] ?? '');
            $name = self::slugName((string) ($field['label'] ?? ''), $taken);
            $fields[$i]['name'] = $name !== '' ? $name : $key; // no usable label: the key, written out so that a later label does not change it
            if ($fields[$i]['name'] !== '') {
                $taken[$fields[$i]['name']] = true;
            }
        }
        $content['fields'] = $fields;

        return $content;
    }

    /** A valid technical name from a label that is not in $taken yet (name-2, name-3 … on a clash), or '' when the label gives none. @param array<string, true> $taken */
    private static function slugName(string $label, array $taken): string
    {
        $slug = slugify($label, 60);
        if ($slug === 'n-a' && mb_strtolower(trim($label)) !== 'n-a') {
            return '';
        }
        if (preg_match('/^[a-z]/', $slug) !== 1) {
            $slug = 'f-' . $slug; // a name starts with a letter
        }
        for ($n = 1; $n < 100; $n++) {
            $suffix = $n > 1 ? '-' . $n : '';
            $candidate = rtrim(substr($slug, 0, 40 - strlen($suffix)), '-_') . $suffix;
            if (preg_match(Form::NAME_PATTERN, $candidate) === 1 && !in_array($candidate, Form::RESERVED_NAMES, true) && !isset($taken[$candidate])
                && !str_starts_with($candidate, 'tl_') && !str_starts_with($candidate, 'as_') && preg_match('/^k[a-z0-9]{4}$/', $candidate) !== 1) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Why a technical name cannot be used, or null when it can. The first field that has a name keeps it, so $taken holds the names of the earlier ones.
     *
     * @param list<string>       $keys  the key of every field of the form
     * @param array<string, true> $taken the technical names of the fields before this one
     */
    public static function problem(string $name, string $ownKey, int $i, array $keys, array $taken): ?string
    {
        if (preg_match(Form::NAME_PATTERN, $name) !== 1) {
            return 'The technical name may use a–z, 0–9, _ and -, start with a letter and be at most 40 characters; it was removed.';
        }
        if (in_array($name, Form::RESERVED_NAMES, true) || str_starts_with($name, 'tl_') || str_starts_with($name, 'as_')) {
            return 'This technical name is used by the form itself; it was removed.';
        }
        // a name shaped like a key would take the key of a field added later; it is only the field's own
        if ($name === $ownKey) {
            return null;
        }
        if (preg_match('/^k[a-z0-9]{4}$/', $name) === 1 || isset($taken[$name]) || in_array($name, array_diff_key($keys, [$i => true]), true)) {
            return 'Another field of this form already has this technical name; it was removed.';
        }

        return null;
    }
}
