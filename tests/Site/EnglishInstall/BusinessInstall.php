<?php

declare(strict_types=1);

namespace Talea\Tests\Site\EnglishInstall;

/** The business starter with every extension, installed in English (options shared by the business classes). */
trait BusinessInstall
{
    use PublicSiteWalk;

    protected static function siteOptions(): array
    {
        return ['freshInstall' => true, 'web' => 'business', 'language' => 'en', 'siteName' => 'Acme', 'doneText' => 'Done, your website is running', 'extensions' => self::ALL, 'enabledExtensions' => implode(',', self::ALL)];
    }

    /** @return list<string> the query strings of the admin screens that are walked in every language */
    private function adminScreens(): array
    {
        $news = (string) $this->site()->value('SELECT public_id FROM tl_news LIMIT 1');
        $page = (string) $this->site()->value('SELECT public_id FROM tl_pages ORDER BY page_id LIMIT 1');

        return ['', 'module=pages', 'module=pages&action=new', "module=pages&action=builder&id=$page", 'module=enquiries', 'module=parts', 'module=parts&action=builder&type=header&language=',
            'module=components', 'module=collections', 'module=collections&action=new', 'module=news', 'module=news&action=new', "module=news&action=edit&id=$news", 'module=categories', 'module=categories&action=new',
            'module=tags', 'module=media', 'module=stats', 'module=appearance', 'module=menu', 'module=users', 'module=users&action=new', 'module=roles', 'module=roles&action=new', 'module=redirects',
            'module=changelog', 'module=transfer', 'module=extensions', 'module=claude_settings', 'module=addons', 'module=subscribers', 'module=newsletters', 'module=newsletters&action=new',
            'module=parts&action=templates&type=header', 'module=parts&action=templates&type=footer', 'action=account', 'module=settings&tab=general', 'module=business', 'module=settings&tab=seo',
            'module=settings&tab=analytics', 'module=settings&tab=cookies', 'module=settings&tab=mail', 'module=settings&tab=backups', 'module=status',
            'module=popups', 'module=popups&action=new', 'module=notebook', 'module=notebook&action=edit', 'module=requests', 'module=requests&action=new', 'module=schedules', 'module=schedules&action=edit',
            'module=bookings', 'module=bookings&action=new', 'module=bookings&action=services&new=1', 'module=bookings&action=staff', 'module=bookings&action=staff_edit'];
    }
}
