<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * A booking calendar, a form or a player from a known service (2.3). Only addresses of the services below are embedded,
 * and only after a click – until then the page sends nothing to the service and needs no consent. Next to it there is
 * always a plain link. No scripts: every service here works as a page in a frame.
 */
final class Embed extends Element
{
    public const string TYPE = 'embed';
    public const string NAME = 'Embed';
    public const string DESCRIPTION = 'A booking calendar, form or player from Calendly, Google, Microsoft Forms, Tally, Typeform, Airtable, Spotify or SoundCloud – loads after a click.';
    public const string ICON = 'kod';
    public const array HTML_TAGS = ['div', 'figure'];

    /**
     * service => [name, what it shows, pattern of the address people copy, the frame address from the match]. The frame
     * addresses are also allowed in image/web.js (data-vlozit) – keep both in step.
     */
    public const array SERVICES = [
        'calendly' => ['Calendly', 'booking calendar', '#^https://calendly\.com/([A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)?)/?(?:\?.*)?$#', 'https://calendly.com/%s?embed_type=Inline&hide_gdpr_banner=1'],
        'google-calendar' => ['Google Calendar', 'booking calendar', '#^https://calendar\.google\.com/calendar/appointments/schedules/([A-Za-z0-9_-]+)(?:\?.*)?$#', 'https://calendar.google.com/calendar/appointments/schedules/%s?gv=true'],
        'google-forms' => ['Google Forms', 'form', '#^https://docs\.google\.com/forms/d/e/([A-Za-z0-9_-]+)/viewform(?:\?.*)?$#', 'https://docs.google.com/forms/d/e/%s/viewform?embedded=true'],
        'microsoft-forms' => ['Microsoft Forms', 'form', '#^https://forms\.office\.com/Pages/ResponsePage\.aspx\?id=([A-Za-z0-9_-]+)#', 'https://forms.office.com/Pages/ResponsePage.aspx?id=%s&embed=true'],
        'tally' => ['Tally', 'form', '#^https://tally\.so/(?:r|embed)/([A-Za-z0-9]+)(?:\?.*)?$#', 'https://tally.so/embed/%s?alignLeft=1&transparentBackground=1'],
        'typeform' => ['Typeform', 'form', '#^https://(?:[a-z0-9-]+\.)?typeform\.com/to/([A-Za-z0-9]+)(?:\?.*)?$#', 'https://form.typeform.com/to/%s'],
        'airtable' => ['Airtable', 'form or table', '#^https://airtable\.com/(?:embed/)?(app[A-Za-z0-9]+/shr[A-Za-z0-9]+|shr[A-Za-z0-9]+)/?(?:\?.*)?$#', 'https://airtable.com/embed/%s'],
        'spotify' => ['Spotify', 'player', '#^https://open\.spotify\.com/(?:embed/)?((?:track|album|playlist|episode|show)/[A-Za-z0-9]+)(?:\?.*)?$#', 'https://open.spotify.com/embed/%s'],
        'soundcloud' => ['SoundCloud', 'player', '#^(https://soundcloud\.com/[A-Za-z0-9_-]+/[A-Za-z0-9_/-]+)(?:\?.*)?$#', 'https://w.soundcloud.com/player/?url=%s'],
    ];

    public static function properties(): array
    {
        return [
            'adresa' => ['type' => 'text', 'popisek' => 'Address of the booking page, form or track (copied from the service)', 'vychozi' => '', 'max' => 500],
            'title' => ['type' => 'text', 'popisek' => 'What it is, for screen readers (e.g. Book a consultation)', 'vychozi' => '', 'max' => 120],
            'height' => ['type' => 'vyber', 'popisek' => 'Height', 'vychozi' => '700', 'options' => ['160' => 'player (160 px)', '450' => 'small (450 px)', '700' => 'medium (700 px)', '950' => 'large (950 px)']],
        ];
    }

    /**
     * The service and the frame address of an address someone copied, or null for an address that is not allowed.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function resolve(string $url): ?array
    {
        $url = trim($url);
        foreach (self::SERVICES as $key => [, , $pattern, $frame]) {
            if (preg_match($pattern, $url, $m)) {
                return [$key, sprintf($frame, $key === 'soundcloud' ? rawurlencode($m[1]) : $m[1])];
            }
        }

        return null;
    }

    public static function baseCss(): string
    {
        return '.ka-vlozeni { position: relative; margin: 0; background: var(--ka-barva-plocha); border-radius: var(--ka-zaobleni); overflow: hidden; }
.ka-vlozeni > button, .ka-vlozeni > iframe { display: block; width: 100%; height: 700px; border: 0; }
.ka-vlozeni-160 > button, .ka-vlozeni-160 > iframe { height: 160px; }
.ka-vlozeni-450 > button, .ka-vlozeni-450 > iframe { height: 450px; }
.ka-vlozeni-950 > button, .ka-vlozeni-950 > iframe { height: min(950px, 90vh); }
.ka-vlozeni > button { display: grid; place-content: center; gap: var(--ka-mezera-xs); padding: var(--ka-mezera-m); background: var(--ka-barva-plocha); color: var(--ka-barva-text); font: inherit; text-align: center; cursor: pointer; }
.ka-vlozeni > button strong { font-size: var(--ka-krok-1); }
.ka-vlozeni > button small { color: var(--ka-barva-tlumeny); }
.ka-vlozeni > button:hover strong { color: var(--ka-barva-primarni); }
.ka-vlozeni figcaption, .ka-vlozeni > p { margin: 0; padding: 0.4em 0.8em; font-size: var(--ka-krok--1); }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['obsah'];
        $service = self::resolve((string) $o['adresa']);
        if ($service === null) {
            return $k->editor ? '<div' . $a . ' style="padding:2rem;text-align:center;background:var(--ka-barva-plocha)">'
                . e(t('Paste the address of a Calendly or Google booking page, a Google, Microsoft, Tally, Typeform or Airtable form, or a Spotify or SoundCloud track.')) . '</div>' : '';
        }
        [$key, $frame] = $service;
        [$name, $what] = self::SERVICES[$key];
        $title = trim((string) $o['title']) !== '' ? (string) $o['title'] : t('%s from %s', t(ucfirst($what)), $name);
        $height = in_array((string) $o['height'], ['160', '450', '700', '950'], true) ? (string) $o['height'] : '700';
        $button = '<button type="button" data-vlozit="' . e($frame) . '" data-titulek="' . e($title) . '">'
            . '<strong>' . e(t('Show: %s', $title)) . '</strong><small>' . e(t('Loads from %s after a click.', $name)) . '</small></button>';
        $link = '<a href="' . e((string) $o['adresa']) . '" target="_blank" rel="noopener">' . e(t('Open in %s', $name)) . '</a>';

        $classes = 'ka-vlozeni' . ($height !== '700' ? ' ka-vlozeni-' . $height : '');

        return $p['tag'] === 'figure'
            ? '<figure' . Text::withClass($a, $classes) . '>' . $button . '<figcaption>' . $link . '</figcaption></figure>'
            : '<div' . Text::withClass($a, $classes) . '>' . $button . '<p>' . $link . '</p></div>';
    }
}
