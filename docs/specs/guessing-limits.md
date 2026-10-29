# Guessing limits: password reset and page passwords

Two places where a visitor with many addresses gets around the per-address limit. Both get a limit per target (the account,
the page) that actually holds.

| Flow | Per address today | Per target today | Gap |
|------|-------------------|------------------|-----|
| Admin sign-in | 10 failures / 15 min (`ip_checks`) | 10 errors in a row lock the account 15 min | none |
| Admin password reset | 5 requests / 15 min (`ip_checks`) | none | unlimited mails to one account (part 1) |
| Page password | 10 tries / 15 min (`page-lock` tally) | 100 wrong / 15 min (`page-lock-all`), but the right password still opens past it | the page cap stops nothing (part 2) |
| Member links | 10 / 15 min | 3 per e-mail / hour | none |

## Part 1: password reset, a limit per account

### Problem

A reset request from the sign-in page (`Admin\PasswordReset::handleRequest`) is limited only per visitor address. Whoever has
many addresses can send one user as many reset mails as they like. Every request also replaces the token, so the link in the
mail the owner opens may already be dead because a later request replaced it.

It is not a takeover (the token is 256 random bits, single use, one hour). The damage: a flooded inbox, harm to the site's mail
reputation, and a real reset that doesn't work reliably while the flood goes on.

Member links already have the same guard: `Members::requestLink` counts per e-mail
(`Antispam::tally($email, 'member-link-email', 3600) > EMAIL_ATTEMPTS`). The admin reset gets the same thing.

### Change

In `PasswordReset::handleRequest`, after the account is found and before `sendLink`:

```php
if ($user !== null && Antispam::tally('user:' . $user['user_id'], 'reset-account', 3600) <= self::ACCOUNT_REQUESTS) {
    $this->sendLink($user);
}
$sent = true;
```

- New constant `private const int ACCOUNT_REQUESTS = 3;`: at most 3 reset mails per account per hour (the link lives one
  hour, so three links per link lifetime).
- **Counted by account, not by the typed text.** The key is the `user_id`, so switching between user name and e-mail does not
  give a second allowance. An unknown name is not counted (no counter file per invented name).
- **Over the limit nothing changes for the visitor.** Same "sent" page, same status 200, same response time (the mail is
  already sent after the response, `Mail::later`). The account's existence stays hidden.
- **Over the limit the token is not replaced.** The last link that went out stays valid, which is what the owner needs.
- The per-address limit (5 per 15 minutes, `ip_checks`) stays as it is and is checked first.
- Only the self-service request is limited. Invitations and links an administrator sends (`Modules\Users` →
  `sendLink($user, 'invitation' | 'administrator')`) are not counted and not blocked.

## Part 2: page passwords, a cap that holds

### Problem

`Core\PageLock::unlock` counts wrong passwords per page from all addresses (`PAGE_ATTEMPTS` = 100 per 15 minutes). Past that
cap it still runs `password_verify` on every try: a wrong guess gets "Too many attempts", the right one opens the page (3.3.3,
N58). The cap changes only the message. It slows down nobody: an attacker with many addresses keeps guessing at 10 tries per
address per 15 minutes, and the page opens on the right guess. Page passwords are short (`MIN_LENGTH` = 6), so the guessing
can succeed.

N58 chose this so that wrong guesses from many addresses could not lock out every reader. That trade-off was wrong: it gives
away confidentiality, the reason the page has a password, to protect availability. And the availability risk is small:

- readers who already opened the page keep their cookie for 30 days (`isUnlocked` is checked before `unlock`);
- users with the Pages right never see the form;
- the door closes only while the flood goes on, and opens again at most 15 minutes after it stops.

### Change

In `PageLock::unlock`, every try takes its place in the page count **before** the password is checked:

```php
$tries = Antispam::tally($pageKey, 'page-lock-all', self::WINDOW);
if ($tries > self::PAGE_ATTEMPTS) {
    return t('Too many attempts. Try again in a few minutes.');
}
if ($password === '' || !password_verify($password, (string) $page['password_hash'])) {
    if ($tries === self::PAGE_ATTEMPTS) {
        ChangeLog::write($app, 'pages', 'page password paused', 'too many wrong passwords, the page opens again in at most 15 minutes: ' . $page['title']);
    }

    return t('The password is not right.');
}
Antispam::untally($pageKey, 'page-lock-all', self::WINDOW);
```

- **Past the cap no password is checked**, the right one included. No guess gets an answer, so the cap holds against any
  number of addresses: at most `PAGE_ATTEMPTS` wrong guesses per page per window, 9,600 a day.
- **Taken before, given back after.** A read-then-check would let guesses sent at the same moment all read a count under
  the cap and all be checked. Taking the place first (the append is atomic, `LOCK_EX`) closes that. The right password gives
  its place back (`Antispam::untally`, one byte off under `flock`), so only wrong passwords fill the counter: readers who
  type the right one never close the page.
- **The owner finds out.** The wrong guess that reaches the cap writes one change-log row per window, so a reader's
  complaint about a closed page has an explanation and the owner knows to pick a stronger password. No mail: a long attack
  would send one mail every 15 minutes.
- The per-address limit (`ATTEMPTS` = 10) stays as it is and is checked first. The visitor key moves to
  `Antispam::visitorKey()` like every other limit (same result, one place).
- The class doc comment and the N58 comments in `PageLock` are rewritten to say the new rule.

No migration, no setting, no new translation (both texts already exist) for either part.

## Known ceiling

`Antispam::tally` counts in files under `storage/cache/limits`, one set per host. With several replicas each counts on its
own, so each cap is multiplied by the number of replicas. Members work the same way. If it ever matters, the counter moves to
the database for all of them at once, not for one alone.

## Tests

Part 1, site test in `tests/Site/SiteMoveSecurity/AccountSecurityTest.php` (it already covers reset and lock):

1. Four reset requests for one account from one client (under the per-address limit of 5), alternating user name and e-mail.
2. All four answers are the same "sent" page with status 200.
3. `tl_mail` holds exactly 3 rows for the account's address.
4. The token hash after the fourth request equals the one after the third (not replaced).
5. A link an administrator sends for the same account (`module=users`, send link) still goes out: a 4th row.

Part 2, `tests/Site/FormsHygiene/PageLockTest.php`:

1. `testPastThePageCapOnlyWrongPasswordsAreRefused` flips to `testPastThePageCapNoPasswordIsChecked`: with the counter
   files filled past the cap, a wrong password **and the right one** both answer 403 "Too many attempts"; the page does not open.
2. A visitor who unlocked the page before the cap was reached still sees it (the cookie wins).
3. Filling the counter up to the cap with wrong passwords writes exactly one `change_log` row.

`tests/Unit/AuthenticationTest.php` (N58 assertion): the source check flips. The read-only cap check
(`'page-lock-all', self::WINDOW, false)`) now comes **before** `password_verify(`.

Run the site tests on MySQL and PostgreSQL (`TALEA_TEST_DB_DRIVER=pgsql`), as for every site test.

## Out of scope

- A rate limit on using the reset link: the token cannot be guessed.
- Stronger page passwords (`MIN_LENGTH` above 6): the cap now holds, so the length matters less. Raise it separately if wanted.
- Logging every refused reset request or page try: a flood would flood the log too.
