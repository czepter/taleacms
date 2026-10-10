<?php

declare(strict_types=1);

namespace Talea\Builder\Elements;

use Talea\Builder\Context;
use Talea\Builder\Element;
use Talea\Core\Members;
use Talea\Front\MemberArea;

/**
 * The member menu for the header (Member login extension, Core\Members): "Sign in" for a visitor, the member's name and "Sign out" for a
 * signed-in member. An anonymous visitor sees the same cached page as everybody else; a signed-in member always gets a fresh one
 * (the member cookie bypasses the page cache), so the menu is right for both.
 */
final class MemberMenu extends Element
{
    public const string TYPE = 'member_menu';
    public const string NAME = 'Member menu';
    public const string DESCRIPTION = 'A sign-in link that turns into the member’s name and a sign-out button once they are signed in.';
    public const string ICON = 'member';
    public const string GROUP = 'Site parts';
    public const array HTML_TAGS = ['div'];
    public const string EXTENSION = 'members';
    public const bool PARTS_ONLY = true;

    public static function properties(): array
    {
        return [
            'sign_in_text' => ['type' => 'text', 'label' => 'Sign-in link text', 'default' => t('Sign in'), 'max' => 40],
            'sign_out_text' => ['type' => 'text', 'label' => 'Sign-out button text', 'default' => t('Sign out'), 'max' => 40],
        ];
    }

    public static function baseCss(): string
    {
        return '.tl-member-menu { display: flex; flex-wrap: wrap; align-items: center; gap: var(--tl-space-xs); }
.tl-member-menu form { margin: 0; }
.tl-member-menu .tl-field { margin: 0; }
.tl-member-menu button { padding: 0.4em 0.9em; border: 1px solid var(--tl-color-line); border-radius: var(--tl-radius); background: transparent; color: inherit; font: inherit; cursor: pointer; }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['content'];
        $member = Members::current($k->app);
        if ($member === null || $k->editor) {
            return '<div' . Text::withClass($a, 'tl-member-menu') . '><a href="' . e($k->url('member')) . '">' . e($o['sign_in_text']) . '</a></div>';
        }

        return '<div' . Text::withClass($a, 'tl-member-menu') . '><a href="' . e($k->url('member')) . '">' . e((string) $member['name'] !== '' ? (string) $member['name'] : (string) $member['email']) . '</a>'
            . MemberArea::signOutForm($k->app, (string) $o['sign_out_text']) . '</div>';
    }
}
