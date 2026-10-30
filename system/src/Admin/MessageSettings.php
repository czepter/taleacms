<?php

declare(strict_types=1);

namespace Talea\Admin;

use Talea\Core\App;
use Talea\Core\Language;
use Talea\Core\Mail;
use Talea\Core\Messages;
use Talea\Core\Request;
use Talea\Core\Settings;

/** The parts of Settings → Messages that are not the generic settings form: its fields, "Back to the template" and "Send me a test". */
final class MessageSettings
{
    /**
     * The own texts: `message_<id>_<part>` for the default language and `…_<language>` for each further one => `message:<id>`.
     *
     * @return array<string, string>
     */
    public static function fields(Settings $settings): array
    {
        $fields = [];
        foreach (['', ...Language::additional($settings)] as $language) {
            foreach (array_keys(Messages::CATALOGUE) as $id) {
                foreach (Messages::parts($id) as $part) {
                    $fields[Messages::key($id, $part, $language)] = 'message:' . $id;
                }
            }
        }

        return $fields;
    }

    /** Is the value the template of its language (the form was filled with it and nothing was changed)? Then it is no own text. */
    public static function isTemplate(Settings $settings, string $key, string $value): bool
    {
        $message = Messages::parseKey($key);

        return $message !== null && str_replace("\r\n", "\n", trim($value)) === Language::runWith($message['language'] !== '' ? $message['language'] : Language::defaults($settings),
            fn (): string => trim(Messages::template($message['id'], $message['part'])));
    }

    /** "Back to the template": the keys of the message named by `reset_message`, in the shown language, with an empty text. @return array<string, string> */
    public static function reset(Request $request): array
    {
        $id = $request->post('reset_message');

        $keys = isset(Messages::CATALOGUE[$id]) ? array_map(fn (string $part): string => Messages::key($id, $part, $request->post('language')), Messages::parts($id)) : [];

        return array_fill_keys($keys, '');
    }

    /** What the form returns to: the tab with the group and language that were shown. @return array<string, string> */
    public static function tabParams(Request $request): array
    {
        return ['tab' => 'messages', 'group' => $request->post('group'), 'language' => $request->post('language')];
    }

    /** The name of an invalid field for the message above the form, with what is wrong. @param array{id: string, part: string, language: string} $message */
    public static function invalid(array $message, string $value): string
    {
        return '„' . Messages::title($message['id']) . '“ (' . (Messages::check($message['id'], $value) ?? t('At most %d characters.', Messages::MAX)) . ')';
    }

    /** @return array{0: string, 1: string, 2: array<string, string>} the message, its kind (ok | error) and where to return to */
    public static function test(App $app, Request $request): array
    {
        $id = $request->post('id');
        $language = $request->post('language');
        $back = ['tab' => 'messages', 'group' => Messages::CATALOGUE[$id]['group'] ?? '', 'language' => $language];
        if (!$request->isPost() || (Messages::CATALOGUE[$id]['kind'] ?? '') !== 'mail' || !in_array($language, ['', ...Language::additional($app->settings())], true)) {
            return ['This message cannot be sent as a test.', 'error', ['tab' => 'messages']];
        }
        $to = (string) ($app->auth()->user()['email'] ?? '');
        if ($to === '') {
            return ['Your account has no e-mail address to send the test to.', 'error', $back];
        }
        $ok = Messages::send($app, $id, $language, $to, Messages::sample($id));

        return [$ok ? t('A test has been handed over for delivery to %s. It shows the saved text, with example values in brackets.', $to) : t('Sending failed: %s', t(Mail::$error)), $ok ? 'ok' : 'error', $back];
    }
}
