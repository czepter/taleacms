# Public demo

A Kaleta site where anyone signs in with a shared account and tries the admin and the builder. Everything goes back to
a saved state every hour. kaletacms.com runs one at **demo.kaletacms.com** (2.6).

## Setting it up

1. Create the subdomain (for example `demo.kaletacms.com`) and an empty database on the hosting.
2. Upload the Kaleta release package to the subdomain, open `https://demo.kaletacms.com/install.php` and install it with
   the sign-in name `demo` and the password visitors will use (for example `demo-kaleta`).

3. Switch the demo mode on in `config.php`, with the same sign-in:

   ```php
   'demo' => ['user' => 'demo', 'password' => 'demo-kaleta'],
   ```

4. Prepare the content visitors should start from (a starter site, a few pages, images) and switch on the features they
   should see under **Features** – Bookings and Whistleblowing are off on a new installation, and visitors cannot switch
   features. Then save it:

   ```bash
   php system/demo.php snapshot
   ```

5. Reset it every hour with cron:

   ```text
   0 * * * * php /path/to/demo/system/demo.php reset
   ```

To change the starting state later, edit the site and run `snapshot` again.

## What the demo mode does

- The sign-in screen shows and fills in the shared account; the admin shows when the next reset comes.
- Public pages carry `noindex` and a small "Kaleta demo" badge; `robots.txt` disallows everything.
- Refused, so the demo cannot be abused or taken over:
  - sending e-mail, webhooks, downloads from other sites, updates, off-site backups, IndexNow;
  - the Claude connection (MCP and OAuth);
  - users and roles, My account (password, two-step sign-in, passkeys);
  - imports, extensions settings, newsletters;
  - backups, mail, webhooks and system status settings, and downloading backups;
  - code fields (head code, marketing code, page head code) and secret keys.
- Everything else – pages, the builder, collections, the look, menus, pop-ups, forms, media – works and is reset every hour.

`tools/test-demo.sh` checks all of this on a fresh installation.
