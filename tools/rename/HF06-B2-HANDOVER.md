# HF-06 agent B2 -> agent C handover (image/*.js, image/*.css, HTML hooks)

Temporary file (delete when C has finished). PHP side is renamed and tested; the JS/CSS below still reads OLD names.
Most of what I listed first (pop-up data attributes, menu items, collection fields, builder JSON) C has already followed in image/*.js
(checked with a scan at the end: only the items under "Still open" remain).

## Still open in image/*.js (found by scanning for the old names)
- `image/admin.js` ~510-511: `formEl.elements.ohnisko_x / ohnisko_y` -> `focus_x / focus_y` (media focal point fields; Media::save reads `focus_x`, `focus_y`).
- `image/klice.js` (passkeys; the server reads the new names):
  - `step: 'klic_moznosti'` -> `step: 'passkey_options'` (sign-in second step; Admin\Kernel), `{ step: 'key', answer }` stays;
  - registration/account posts: field `co` -> `op`; values `klic_moznosti` -> `passkey_options`, `klic_uloz` -> `passkey_save`;
  - `soucasne` -> `current_password`; the passkey name field `nazev` -> `name` (view `account.php` has `name="name"` now; selector `[name="nazev"]` -> `[name="name"]`, post key `nazev` -> `name`);
  - the sign-in JSON answer carries `redirect` (was `kam`).
- `image/editor.js` ~133: media upload `data.append('soubory[]', ...)` -> `'files[]'`.
- `image/helper.js` / `image/builder.js`: AI assistant rewrite modes are now `shorter | longer | formal | friendly | fix` (were `kratsi | delsi | formalne | pratelsky | oprava`) - check the buttons that post `mode`.

## Request/model names I renamed (for reference; JS that posts or reads them must use the new name)
Admin POST fields: `zpet`->`back`, `provest`->`bulk`, `oznacene[]`->`selected[]`, `po_ulozeni`->`after_save` (values `vypis|zustat|stavitel` -> `list|stay|builder`),
`platnost`->`lifetime`, `soucasne`->`current_password`, `rozsireni[]`->`extensions[]`, `sablona`->`template`, `novy_nazev`->`new_name`,
`zadani`->`prompt`, `ukol`->`task`, `pouziti`->`usage`, `idk`(passkey)->`passkey_id`, `co`->`op`, `prelozit_do`->`translate_to`, `do_sekce`->`to_folder`,
`jmeno`->`name`, `nazev`->`name`, `kod`->`code` (login second step: `step=code`, field `code`), `citace`->`quote` (comment widget), `key` (hidden field of the comment widget, was `klic`),
`web_adresa`->`website` (honeypot), `as_cas`->`as_time`, `as_podpis`->`as_signature` (antispam), cookie fields `ka_vstup|ka_kampan|ka_odkud` -> `ka_landing|ka_campaign|ka_referrer`,
`ka_heslo_stranky`->`ka_page_password`, cookie `ka_nahled`->`ka_preview`, cookie-bar consent POST field `kategorie`->`category` (inline script in views/front/cookies.php already follows),
installer fields `nazev_webu|casove_pasmo|jazyk_webu|web` -> `site_name|time_zone|site_language|starter`, per-language settings `site_name_<lang>`, `site_description_<lang>`.
Pop-up counter beacon: `POST /popup` fields `id`, `event` = `view|close|conversion`.
Form result codes in the URL (`?form=<id>&result=<code>`): `pole|rychle|overeni|plno|uzavreno` -> `field|too_fast|verification|full|closed`; booking `souhlas` -> `consent`.
Item/page export envelope: `format: kaleta-page` (was `kaleta-stranka`), `version` (was `verze`); site export file `content.json` (was `obsah.json`), header key `format_version`.

## Pop-ups (already followed by web.js - listed so you can verify)
Types `window | slide_in | top_bar | bottom_bar | fullscreen`; data attributes `data-trigger data-frequency data-days data-device data-counter data-open data-campaign data-referrer data-value data-popup`;
trigger values `time|scroll|exit|idle|pages|click`; frequency `session|days|until_closed|until_submitted|always`; device `all|desktop|phone`.

## Menu (image/menu.js - already followed)
Items: `{type: page|link|news|group, text, page_id, url, new_window, icon, description, children}`.

## Collections (builder.js)
Field definition `{key, label, type, collection, options}`; types `text|lines|html|image|link|number|date|datetime|file|location|item|radio|parameters|variants`;
built-in placeholders `{{name}} {{url}} {{date}}`; `Admin\BuilderActions` JSON `collections[].fields` / `.name` (English since before).

## Not renamed (outside my scope, listed so nobody searches)
- URL query VALUES such as `?build=koncept` (CLAUDE.md: values stay).
- Design-system preset keys `firemni|remeslo|elegantni|pratelsky|...` and the font key `vychozi` (Settings `brand_*_font`, `SiteIdentity::TITLE_FONTS`, `DesignSystem`) - C.
- HTML ids/classes/forms like `form="smazani"`, `form="hromadne"`, `data-booking`, `data-locator` ... - C (several site tests still assert the old hooks).
- Change-log action/category stored values (`ucet`, `uloz`, `smaz`, `obnov`, ...), route/extension/part-type words (`novinky`, `hlavicka`, `vypis`, `rubrika`, ...) - B1.
