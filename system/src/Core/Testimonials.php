<?php

declare(strict_types=1);

namespace Talea\Core;

use Talea\Builder\Build;
use Talea\Builder\Elements\Form;
use Talea\Builder\FormRecords;
use Talea\Builder\Presets;

/**
 * The testimonial form (2.12): a form of Enquiries → Forms like any other, made once when it is first needed and marked as the
 * system's own (origin system:testimonial: it cannot be deleted). A customer opens it from a personal link (Core\FormLinks) and
 * writes their words, name and role, a photo if they want, and ticks the consent; the answer arrives as a hidden draft item of the
 * References collection (Core\CollectionSubmission) together with the consent and when it was given. Nothing is published
 * without the administrator.
 *
 * The owner changes the form like any other: the fields, the texts, the collection and the mapping. The mapping names the fields
 * by their labels, so a renamed label needs the same name in the mapping.
 */
final class Testimonials
{
    public const string PRESET = 'references';

    public const string ORIGIN = 'system:testimonial';

    /** Is this form row the system's testimonial form? @param array<string, mixed> $form */
    public static function isSystem(array $form): bool
    {
        return ($form['origin'] ?? '') === self::ORIGIN;
    }

    /** The testimonial form; made (with the References collection, when the site has none) the first time. @return array<string, mixed> */
    public static function form(App $app): array
    {
        $db = $app->db();
        $row = $db->one('SELECT * FROM {forms} WHERE origin = ?', [self::ORIGIN]);
        if ($row !== null) {
            return $row;
        }
        $collection = $db->one('SELECT * FROM {collections} WHERE preset = ? ORDER BY collection_id LIMIT 1', [self::PRESET]);
        $collectionId = $collection !== null ? (int) $collection['collection_id'] : (int) Presets::create($app, self::PRESET);
        $collectionPublic = $db->publicId('collections', $collectionId);
        $build = Language::runWith($app->settings()->get('site_language') ?: 'en', function () use ($app, $collectionPublic): array {
            $site = (string) $app->settings()->get('site_name');
            $words = t('Your words');
            $name = t('Your name');
            $role = t('Role and company (optional)');
            $photo = t('Your photo (optional)');
            [$build] = Build::sanitize(['v' => Build::VERSION, 'children' => [Build::fresh(Form::TYPE, [
                'name' => t('Share your experience'), 'heading' => t('Share your experience with %s', $site),
                'intro' => t('It takes a minute, and we publish it only with your consent.'),
                'fields' => [
                    ['label' => $words, 'type' => 'textarea', 'required' => true],
                    ['label' => $name, 'type' => 'text', 'required' => true],
                    ['label' => $role, 'type' => 'text'],
                    ['label' => $photo, 'type' => 'file'],
                    ['label' => t('I agree that %s may publish my words with my name and role, and the photo if I upload one, on its website.', $site), 'type' => 'checkbox', 'required' => true],
                ],
                'access' => 'link', 'link_days' => FormLinks::DAYS, 'destination' => 'collection', 'collection' => $collectionPublic, 'item_name' => '{' . $name . '}',
                'mapping' => [['target' => 'quote', 'template' => '{' . $words . '}'], ['target' => 'client', 'template' => '{' . $name . '}, {' . $role . '}'], ['target' => 'image', 'template' => '{' . $photo . '}']],
                'button_text' => t('Send'), 'thank_you' => t('Thank you! We have received your words. We will publish them after a short check.'), 'no_captcha' => true,
                'link_subject' => t('Would you share your experience with %s?', $site),
                'link_text' => t("Hello,\n\nthank you for working with us. Would you write a few words about your experience? It takes a minute, and we publish it only with your consent:\n\n{link}\n\nThe link works for {days} days.\n\n{site name}"),
            ])]], true);

            return $build;
        });
        $json = Build::toJson($build);
        $now = date('Y-m-d H:i:s');
        $id = $db->insert('forms', ['name' => (string) ($build['children'][0]['content']['name'] ?? 'Share your experience'), 'origin' => self::ORIGIN, 'build' => $json, 'created_at' => $now, 'updated_at' => $now]);
        FormRecords::forget();

        return (array) FormRecords::byId($db, $id);
    }
}
