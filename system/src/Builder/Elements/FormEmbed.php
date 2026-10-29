<?php

declare(strict_types=1);

namespace Talea\Builder\Elements;

use Talea\Builder\Context;
use Talea\Builder\Element;
use Talea\Builder\FormRecords;

/**
 * A form placed on a page (docs/specs/form-builder.md): which form (a record from Enquiries → Forms), a thank-you page instead
 * of the form's message, the button text on this page and a width per field. Fields, their order, the e-mails and the spam
 * protection belong to the form; Builder\FormRecords::resolve() makes the Form element this placement shows.
 */
final class FormEmbed extends Element
{
    public const string TYPE = 'form_embed';
    public const string EXTENSION = 'enquiries';
    public const string NAME = 'Form';
    public const string DESCRIPTION = 'Places a form from Enquiries → Forms – its answers are in Enquiries and arrive by email. The fields are edited in the form.';
    public const string ICON = 'form';
    public const string GROUP = 'Dynamic';
    public const array HTML_TAGS = ['div'];

    public static function properties(): array
    {
        return [
            // the form's public id; the builder offers the site's forms as a choice (Admin\BuilderActions)
            'form' => ['type' => 'text', 'label' => 'Form', 'default' => '', 'max' => 36],
            'thank_you_page' => ['type' => 'link', 'label' => 'After sending, go to a page (empty = the form’s thank-you message in place of the form)', 'default' => '',
                'note' => 'A form that shows its estimate after the contact details shows the result at the form: no other page is opened.'],
            'button_text' => ['type' => 'text', 'label' => 'Button text on this page (empty = the form’s)', 'default' => '', 'max' => 80],
            // field key => columns of six (2, 3, 4); a field without an entry takes the full width
            'widths' => ['type' => 'form_widths', 'label' => 'Width of the fields on this page', 'default' => []],
        ];
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $form = FormRecords::resolve($k->app->db(), $p, $k->editor || $k->draft);
        if ($form === null) {
            if (!$k->editor) {
                return '';
            }
            $known = FormRecords::byPublicId($k->app->db(), (string) ($p['content']['form'] ?? ''));

            return '<div' . $a . '><p>' . e($known === null ? t('Choose a form in the Content panel.') : t('The form “%s” has not been published yet – visitors see nothing here.', (string) $known['name'])) . '</p></div>';
        }
        $k->types[Form::TYPE] = true; // the form's styles

        return Form::render($form, $a, '', $k);
    }
}
