# HF-06 part 2 – CSS custom properties, classes, hooks and scripts (final names)

Done by a scripted pass over `system/`, `image/`, `tests/`, `tools/` and `docs/` (prefix `ka-` kept; the Talea `tl-` rebrand is a later pass).
One word lexicon drove every rename (Czech word -> English word, applied per hyphen segment). The `ka-` family, `data-*` attributes and
custom properties were replaced everywhere, the other class names in selectors and class values only.

## Design-system tokens (`DesignSystem::css()`, one stored name, no aliases any more)

| old | new |
|---|---|
| `--ka-barva-primarni` / `-sekundarni` / `-text` / `-pozadi` / `-plocha` | `--ka-color-primary` / `-secondary` / `-text` / `-background` / `-surface` |
| `--ka-barva-na-primarni` / `-bila` / `-cerna` | `--ka-color-on-primary` / `-white` / `-black` |
| `--ka-barva-text-svetle` / `-text-tmave` | `--ka-color-text-light` / `-text-dark` |
| `--ka-barva-tlumeny` / `-linka` / `-primarni-jemna` | `--ka-color-muted` / `-line` / `-primary-soft` |
| `--ka-akcent` | `--ka-accent` |
| `--ka-pismo-text` / `--ka-pismo-titulky` | `--ka-font-body` / `--ka-font-heading` |
| `--ka-sirka` / `--ka-sirka-textu` | `--ka-width` / `--ka-text-width` |
| `--ka-zaobleni` / `--ka-zaobleni-{0,s,m,l,plne}` | `--ka-radius` / `--ka-radius-{0,s,m,l,full}` |
| `--ka-krok-{-1..5}` | `--ka-step-{-1..5}` |
| `--ka-mezera-{2xs..3xl}` | `--ka-space-{2xs..3xl}` |
| `--ka-stin-{s,m,l}` | `--ka-shadow-{s,m,l}` |
| `--ka-typ-{title,section-heading,subheading,lead,text,small,eyebrow}` (`--ka-typ-perex`) | `--ka-type-…` (`--ka-type-lead`); body text is `--ka-type-text` (the `TYPOGRAPHY` key) |

Other builder/element properties: `--ka-prekryv` -> `--ka-overlay`, `--ka-delic` -> `--ka-split`, `--ka-naraz` -> `--ka-per-view`,
`--ka-osa-x` -> `--ka-axis-x`, `--ka-okraj` -> `--ka-margin`, `--ka-nav-{mezera,odsazeni,tloustka,hover,tlacitko-okraj}` ->
`--ka-nav-{space,padding,weight,hover,button-border}`, `--ka-tlacitko-ikona` -> `--ka-button-icon`.
Admin/editor/builder/install properties: `--akcent*` -> `--accent*`, `--plocha` -> `--surface`, `--podklad` -> `--base`, `--linka` -> `--line`,
`--text-slaby` -> `--text-weak`, `--text-tlumeny` -> `--text-muted`, `--chyba` -> `--error`, `--varovani` -> `--warning`, `--ed-*` likewise
(`--ed-popis-galerie` -> `--ed-gallery-label`).

## Design system JSON (`design_system` setting) and layers

`barvy` `colors`, `barvy_tmave` `colors_dark`, `pismo_titulky` `font_heading`, `pismo_text` `font_body`, `sirka_textu` `text_width`,
`vlastni_pisma[{nazev, file, tucny}]` `custom_fonts[{name, file, bold}]`, `typografie[key].tloustka` `typography[key].weight`,
`zaklad_min/max` `base_min/max`, `pomer_min/max` `ratio_min/ratio_max`; font choices `moderni|klasicke|knizni|grotesk|zaoblene|strojove|patkove|elegantni`
-> `modern|classic|book|grotesque|rounded|typewriter|serif|elegant`, `vlastni-N` -> `custom-N`; `contrasts()` items `{description, ratio, ok}`;
DTCG export keys `barva-tmava mezera velikost obsah` -> `color-dark space size content`.
Not renamed (shared with the starter sites): preset keys `firemni|remeslo|pratelsky|elegantni|technologie`, `vychozi`.
`@layer tokeny, spolecne, sablona, stavitel, tridy, prvky` -> `tokens, shared, template, builder, classes, elements`
(`template.base|layout|elements` inside the template layer).

## Files and directories

`image/sablona.css` -> `template.css`, `stavitel.js|css` -> `builder.js|css`, `pomocnik.js` -> `helper.js`, `tema.js` -> `theme.js`,
`tisk.js` -> `print.js`, `pismo.css` -> `fonts.css`, `pisma/` -> `fonts/`, `jazyky/` -> `languages/` (both `image/` and `system/`),
`kaleta-znacka*` -> `kaleta-mark*`, `kaleta-logo-tmavy.svg` -> `kaleta-logo-dark.svg`, `media/ikona-<n>.png` -> `media/icon-<n>.png`.
Not renamed: `image/klice.js` (passkeys), the Czech view file names `views/front/{komentare,pristupnost,tema,jazyky,upravit,vypis,nenalezeno,...}.php`.

## Class names (a few, by area; the lexicon is in the pass scripts, not in the repository)

Front (`ka-` prefix): `ka-tlacitko[--primarni|--sekundarni|--obrys|--odkaz]` -> `ka-button[--primary|--secondary|--outline|--link]`, `ka-tl` -> `ka-btn`,
`ka-ikona[--kruh|--ctverec]` -> `ka-icon[--circle|--square]`, `ka-obal[--uzka]` -> `ka-wrap[--narrow]`, `ka-formular*` -> `ka-form*`, `ka-pole*` -> `ka-field*`,
`ka-krok*` -> `ka-step*`, `ka-galerie` -> `ka-gallery`, `ka-hlavicka-rolovani--pruhledna` -> `ka-header-scroll--transparent`, `ka-hlavicka-svetla|tmava` -> `ka-header-light|dark`,
`ka-pred-po*` -> `ka-before-after*`, `ka-casova-osa*` -> `ka-timeline*`, `ka-cenik*` -> `ka-pricing*`, `ka-pobocky*` -> `ka-locator*`, `ka-rezervace*` -> `ka-booking*`,
`ka-karusel*` -> `ka-carousel*`, `ka-oznameni*` -> `ka-whistleblowing*`, `ka-porovnani-stranka` -> `ka-system-page`, `ka-nahoru` -> `ka-back-to-top`,
`ka-popup--{okno,panel,lista-nahore,lista-dole,cela}` -> `ka-popup--{window,slide_in,top_bar,bottom_bar,fullscreen}` (the popup type keys),
`ka-delic` -> `ka-split`, `ka-st-*` -> `ka-bd-*`.
Unprefixed: front `navigace` -> `navigation`, `hlavicka|paticka` -> `header|footer`, `obal obsah` -> `wrap content`, `stavba` -> `build`, `podmenu|aktivni` -> `submenu|active`,
`menu-{ikona,nadpis,popis,skupina,sloupec}` -> `menu-{icon,heading,description,group,column}`; admin `hlaska[-ok|-chyba|-varovani]` -> `notice[-ok|-error|-warning]`,
`stitek[-vydano|-koncept|-chyba|-ceka|-plan]` -> `badge[-published|-draft|-error|-pending|-plan]`, `napoveda` -> `help`, `tl` -> `btn`, `radek` -> `row`,
`textpole` -> `textfield`, `siroke` -> `wide`, `formular` -> `form`, `vypis` -> `listing`, `st-*` (builder) -> `bd-*`, `vzhled-*` -> `appearance-*`.
Shared builder classes of the section library: `karta` -> `card`, `podtitul` -> `subtitle`.

## Data attributes (`data-*`)

`data-potvrdit` -> `data-confirm`, `data-tema[-volba|-vychozi|-prepinac]` -> `data-theme[-option|-default|-switch]`, `data-tmavy` -> `data-dark`,
`data-zapnuto` -> `data-enabled`, `data-vlozit|sdilet|kopirovat` -> `data-insert|share|copy`, `data-zalozky|karusel|pred-po` -> `data-tabs|carousel|before-after`,
`data-krok(y)` -> `data-step(s)`, `data-pocitadlo` -> `data-counter`, `data-obrazek` -> `data-image`, `data-kosik*` -> `data-basket*`, `data-formular` -> `data-form`,
`data-rezervace` -> `data-booking`, `data-pobocky` -> `data-locator`, `data-porovnani` -> `data-compare-url`, `data-dny` -> `data-days-url`, `data-auto-odeslat` -> `data-auto-submit`,
`data-odeslat-pri-zmene` -> `data-submit-on-change`, `data-aktivni-kdyz` -> `data-active-when`, `data-kdyz[-hodnota]` -> `data-when[-value]`, `data-ka-zamek` -> `data-ka-lock`,
`data-ka-typ` -> `data-ka-type`, `data-ka-komentare*` -> `data-ka-comments*`, popups `data-spoustec|cetnost|zarizeni|utm` -> `data-trigger|frequency|device|campaign`
(values: `time|scroll|exit|idle|pages|click`, `session|days|until_closed|until_submitted|always`, `all|desktop|phone`), cookies `data-cookies="vse|nastavit|ulozit"` -> `all|settings|save`.
`Builder\Build::ATTRIBUTE_PATTERN` (reserved hooks) regenerated from the new names. Passkey hooks `data-klic*`, `data-klice` are left to the passkey pass.

## Ids and storage keys

`#cookies-lista|nadpis|znovu` -> `#cookies-bar|heading|reopen`, `#obsah` -> `#main`, `#navigace` -> `#navigation`, `#paleta*` -> `#palette*`, `#poptavka` -> `#enquiry`,
form ids `titulek|uvod|seo_popis|stitky` -> `title|intro|seo_description|tags` (news form), `localStorage`: `kaleta-tema` -> `kaleta-theme`, `kaleta-koncept:*` -> `kaleta-draft:*`,
`kaleta-poptavka|porovnani` -> `kaleta-enquiry|compare`; custom events `kaleta:odeslano|seznam` -> `kaleta:form_sent|list` (GTM `kaleta_formular_odeslan` dropped).
Theme values `svetly|tmavy` -> `light|dark` (`data-theme`, `data-option`).

## Admin AJAX envelopes (PHP `Admin\BuilderActions`, `Modules\*` and the scripts together)

Builder data (`#builder-data`): `page{title,url,visible,published,can_publish,headings}`, `build`, `changed`, `version`, `schema`, `collections[{slug,name,fields,detail}]`, `components[{id,name,properties}]`,
`ai`, `detail_collection`, `library[{key,name,description,category}]`, `library_categories`, `languages[{code,name}]`, `classes`, `my_sections`, `colors`, `links`, `preview`, `comments`, `settings_text`,
`back{url,text}`, `urls{save,publish,discard,section,class,versions,restore,ai_section,ai_text,save_section,share,package,paste,comment_resolve,delete_section,admin,settings,component,section_preview,guide}`.
Responses: `errors`, `changed`, `version`, `conflict`, `element`/`elements`, `notes`, `components`, `sections`, `clipboard`, `revisions[{revision_id,when,who}]`, `usage`, `valid_until`, `comments[{id,element,quote,name,text,when,resolved}]`.
POST: `build`, `version`, `overwrite`, `elements`, `clipboard`, `name`, `element`, `prompt`, `instruction`, `usage`, `new_name`, `delete`, `section_id`, `revision_id`, `comments`.
Media: `folders`, `errors`, image `{id,name,description,url,thumbnail,file,extension,size,width,height}`; news search `articles[{title,url,published}]`; draft autosave `{time, fields}` (POST `fields`);
assistant: POST `task` (`titles|lead|seo|tags|proofread|posts|alt`), answers `suggestions`, `corrections[{original,fix,reason}]`; appearance preview `contrasts`; menu editor items `page|link|news|group`.
Builder node/element/style names are the English ones from the builder model (schema keys `elements`, `properties`, `default_style`, `default_children`, `tags`, `style_groups`, field `label|default|fields|when`).
