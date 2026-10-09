<?php

/**
 * Hard fork (issue #5): the master map of every Czech identifier of the data model and its English name.
 *
 * Check it with `php tools/hard-fork-inventory.php`. The map is data only; the rename tools of phases 2-3 read it.
 * Table names are given without the `ka_` prefix (the prefix changes in phase 6). The builder vocabulary (build JSON keys,
 * element types, style properties, tokens) lives in tools/rename/hard-fork-builder.php, generated from Mcp\Vocabulary.
 *
 * Column names are mapped PER TABLE: `idp` is the id of a redirect, a mail, a log row and a collection item, so one global
 * map would be wrong. Primary keys are named after what they identify (`news_id`, `page_id`), and a foreign key carries the
 * name of the column it points at. A column that is not listed is English already and must be named in 'keep' (the inventory
 * fails on a column that is in neither).
 */

return [
    'tables' => [
        'uzivatele' => 'users', 'uzivatele_prava' => 'user_permissions', 'uzivatele_klice' => 'user_passkeys', 'nastaveni' => 'settings',
        'kategorie' => 'categories', 'novinky' => 'news', 'novinky_stitky' => 'news_tags', 'novinky_revize' => 'news_revisions',
        'novinky_koncepty' => 'news_drafts', 'stitky' => 'tags', 'media_slozky' => 'media_folders', 'media_pouziti' => 'media_usage',
        'kontrola_ip' => 'ip_checks', 'stranky' => 'pages', 'stranky_revize' => 'page_revisions', 'casti' => 'site_parts',
        'stavba_revize' => 'build_revisions', 'tridy' => 'classes', 'presmerovani' => 'redirects', 'souhlasy' => 'consents',
        'stat_dny' => 'stats_days', 'stat_navstevnici' => 'stats_visitors', 'stat_novinky' => 'stats_news', 'stat_stranky' => 'stats_pages',
        'stat_kampane' => 'stats_campaigns', 'stat_zarizeni' => 'stats_devices', 'stat_konverze' => 'stats_conversions', 'stat_zdroje' => 'stats_sources',
        'protokol' => 'change_log', 'api_tokeny' => 'api_tokens', 'posta' => 'mail', 'nenalezeno' => 'not_found', 'odkazy_vadne' => 'broken_links',
        'import_mapa' => 'import_map', 'poptavky' => 'enquiries', 'kolekce' => 'collections', 'kolekce_polozky' => 'collection_items',
        'kolekce_sablony' => 'collection_templates', 'menu' => 'menus', 'sekce' => 'sections', 'popupy' => 'popups', 'komponenty' => 'components',
        'odberatele' => 'subscribers', 'odber_fronta' => 'subscription_queue', 'oauth_klienti' => 'oauth_clients', 'oauth_kody' => 'oauth_codes',
    ],

    // table (Czech, no prefix) => [Czech column => English column]
    'columns' => [
        'uzivatele' => [
            'idu' => 'user_id', 'user' => 'username', 'jmeno' => 'name', 'blokovat' => 'blocked', 'blokovano_automaticky' => 'auto_blocked_at',
            'pocet_chyb' => 'failed_logins', 'zamceno_do' => 'locked_until', 'obnova_otisk' => 'reset_token_hash', 'obnova_cas' => 'reset_sent_at',
            'totp_tajemstvi' => 'totp_secret', 'totp_zalozni' => 'totp_backup_codes', 'posledni_login' => 'last_login_at', 'potvrzeno' => 'confirmed_at',
            'jazyk' => 'language', 'pozice' => 'position', 'foto' => 'photo',
        ],
        'role' => ['idr' => 'role_id', 'nazev' => 'name', 'popis' => 'description', 'uroven' => 'level', 'moduly' => 'modules'],
        'uzivatele_prava' => ['fk_id_user' => 'user_id', 'ident_modulu' => 'module'],
        'nastaveni' => ['promenna' => 'name', 'hodnota' => 'value'],
        'kategorie' => [
            'idt' => 'category_id', 'nazev' => 'name', 'seo_link' => 'slug', 'popis' => 'description', 'hodnost' => 'weight', 'jazyk' => 'language',
            'preklad_z' => 'translation_of',
        ],
        'novinky' => [
            'idc' => 'news_id', 'seo_link' => 'slug', 'titulek' => 'title', 'uvod' => 'intro', 'obrazek' => 'image', 'obrazek_popis' => 'image_caption',
            'obrazek_autor' => 'image_author', 'tema' => 'category_id', 'autor' => 'author_id', 'datum' => 'published_at', 't_slova' => 'keywords',
            'seo_titulek' => 'seo_title', 'seo_popis' => 'seo_description', 'zmeneno' => 'edited_at', 'aktualizovano' => 'updated_at',
            'oznameno' => 'announced_at', 'jazyk' => 'language', 'preklad_z' => 'translation_of', 'hledani' => 'search_text',
            'odkazy_cas' => 'links_checked_at', 'smazano' => 'deleted_at',
        ],
        'media_slozky' => ['ids' => 'folder_id', 'nazev' => 'name'],
        'media' => [
            'ido' => 'media_id', 'vlastnik' => 'owner_id', 'sekce' => 'folder_id', 'nazev' => 'name', 'popis' => 'description', 'autor' => 'author',
            'obr_poloha' => 'image_path', 'obr_width' => 'image_width', 'obr_height' => 'image_height', 'obr_vel' => 'image_size',
            'nahl_poloha' => 'thumb_path', 'nahl_width' => 'thumb_width', 'nahl_height' => 'thumb_height', 'barva' => 'color', 'ohnisko' => 'focal_point',
            'datum' => 'created_at',
        ],
        'kontrola_ip' => ['idk' => 'check_id', 'ip_adresa' => 'ip', 'typ' => 'type', 'cil' => 'target', 'cas' => 'checked_at'],
        'media_pouziti' => ['ido' => 'media_id', 'idc' => 'news_id'],
        'stitky' => ['ids' => 'tag_id', 'nazev' => 'name', 'seo_link' => 'slug', 'popis' => 'description', 'obrazek' => 'image'],
        'novinky_stitky' => ['idc' => 'news_id', 'ids' => 'tag_id'],
        'novinky_revize' => [
            'idr' => 'revision_id', 'idc' => 'news_id', 'datum' => 'created_at', 'kdo' => 'user_id', 'titulek' => 'title', 'uvod' => 'intro',
        ],
        'stranky' => [
            'ids' => 'page_id', 'seo_link' => 'slug', 'titulek' => 'title', 'popis' => 'description', 'seo_titulek' => 'seo_title', 'obrazek' => 'image',
            'heslo_hash' => 'password_hash', 'zobrazit' => 'visible', 'zverejnit_od' => 'publish_at', 'kod_hlavicky' => 'head_code', 'v_menu' => 'in_menu',
            'poradi' => 'sort_order', 'zmeneno' => 'updated_at', 'jazyk' => 'language', 'preklad_z' => 'translation_of', 'nadrazena' => 'parent_id',
            'stavba' => 'build', 'stavba_koncept' => 'build_draft', 'smazano' => 'deleted_at',
        ],
        'casti' => [
            'typ' => 'type', 'jazyk' => 'language', 'varianta' => 'variant', 'nazev' => 'name', 'stranky' => 'pages', 'stavba' => 'build',
            'stavba_koncept' => 'build_draft', 'zmeneno' => 'updated_at',
        ],
        'stavba_revize' => ['idr' => 'revision_id', 'ids' => 'page_id', 'cast' => 'part', 'datum' => 'created_at', 'kdo' => 'user_id', 'stavba' => 'build'],
        'tridy' => ['nazev' => 'name', 'styl' => 'style', 'zmeneno' => 'updated_at'],
        'presmerovani' => [
            'idp' => 'redirect_id', 'z_adresy' => 'from_path', 'na_adresu' => 'to_path', 'typ' => 'type', 'pocet' => 'hits', 'vytvoreno' => 'created_at',
        ],
        'souhlasy' => ['ids' => 'consent_id', 'id_souhlasu' => 'visitor_token', 'cas' => 'created_at', 'kategorie' => 'categories'],
        'stat_dny' => ['den' => 'day', 'navstevy' => 'visits', 'zobrazeni' => 'views'],
        'stat_navstevnici' => ['den' => 'day', 'otisk' => 'visitor_hash'],
        'stat_novinky' => ['den' => 'day', 'idc' => 'news_id', 'pocet' => 'views'],
        'stat_stranky' => ['den' => 'day', 'cesta' => 'path', 'pocet' => 'views'],
        'stat_kampane' => ['den' => 'day', 'kampan' => 'campaign', 'navstevy' => 'visits'],
        'stat_zarizeni' => ['den' => 'day', 'zarizeni' => 'device', 'navstevy' => 'visits'],
        'stat_konverze' => ['den' => 'day', 'cesta' => 'path', 'typ' => 'type', 'pocet' => 'count'],
        'stat_zdroje' => ['den' => 'day', 'zdroj' => 'source', 'pocet' => 'count'],
        'protokol' => [
            'idp' => 'log_id', 'cas' => 'created_at', 'kdo' => 'user_id', 'jmeno' => 'user_name', 'modul' => 'module', 'akce' => 'action',
            'popis' => 'description', 'duvod' => 'reason',
        ],
        'api_tokeny' => [
            'idt' => 'token_id', 'idu' => 'user_id', 'nazev' => 'name', 'klient' => 'client_id', 'druh' => 'kind', 'expirace' => 'expires_at',
            'otisk' => 'token_hash', 'vytvoren' => 'created_at', 'pouzit' => 'used_at',
        ],
        'uzivatele_klice' => [
            'idk' => 'passkey_id', 'idu' => 'user_id', 'nazev' => 'name', 'otisk_id' => 'credential_hash', 'id_klice' => 'credential_id',
            'verejny' => 'public_key', 'pocitadlo' => 'sign_count', 'vytvoreno' => 'created_at', 'pouzito' => 'used_at',
        ],
        'posta' => [
            'idp' => 'mail_id', 'komu' => 'recipient', 'predmet' => 'subject', 'telo' => 'body', 'vytvoreno' => 'created_at', 'odeslano' => 'sent_at',
            'pokusu' => 'attempts', 'dalsi_pokus' => 'next_attempt_at', 'chyba' => 'error',
        ],
        'nenalezeno' => ['cesta' => 'path', 'pocet' => 'count', 'naposledy' => 'last_seen_at', 'ignorovano' => 'ignored_at'],
        'novinky_koncepty' => ['kdo' => 'user_id', 'idc' => 'news_id', 'cas' => 'saved_at'],
        'odkazy_vadne' => ['ido' => 'link_id', 'idc' => 'target_id', 'stav' => 'status', 'cas' => 'checked_at'],
        'import_mapa' => ['zdroj' => 'source', 'typ' => 'type', 'cizi_id' => 'source_id', 'nase_id' => 'local_id'],
        'poptavky' => [
            'idp' => 'enquiry_id', 'datum' => 'created_at', 'formular' => 'form', 'zdroj' => 'source', 'prvek' => 'element', 'stranka' => 'page',
            'tema' => 'topic', 'vstup' => 'landing_page', 'odkud' => 'referrer', 'kampan' => 'campaign', 'stav' => 'status', 'kategorie' => 'category',
            'priorita' => 'priority', 'navrh_odpovedi' => 'suggested_reply', 'poznamka' => 'note', 'prirazeno' => 'assigned_to',
            'anonymizovano' => 'anonymised_at',
        ],
        'kolekce' => [
            'idk' => 'collection_id', 'nazev' => 'name', 'seo_link' => 'slug', 'pole' => 'fields', 'stavba' => 'build', 'stavba_koncept' => 'build_draft',
            'zmeneno' => 'updated_at',
        ],
        'kolekce_polozky' => [
            'idp' => 'item_id', 'idk' => 'collection_id', 'nazev' => 'name', 'seo_link' => 'slug', 'seo_titulek' => 'seo_title', 'popis' => 'description',
            'obrazek' => 'image', 'poradi' => 'sort_order', 'zobrazit' => 'visible', 'zverejnit_od' => 'publish_at', 'jazyk' => 'language',
            'datum' => 'created_at', 'zmeneno' => 'updated_at', 'smazano' => 'deleted_at',
        ],
        'kolekce_sablony' => ['idk' => 'collection_id', 'jazyk' => 'language', 'stavba' => 'build', 'stavba_koncept' => 'build_draft', 'zmeneno' => 'updated_at'],
        'menu' => ['umisteni' => 'location', 'jazyk' => 'language', 'polozky' => 'items', 'zmeneno' => 'updated_at'],
        'stranky_revize' => ['idr' => 'revision_id', 'ids' => 'page_id', 'datum' => 'created_at', 'kdo' => 'user_id', 'titulek' => 'title'],
        'sekce' => ['idx' => 'section_id', 'nazev' => 'name', 'prvek' => 'element', 'zmeneno' => 'updated_at'],
        'popupy' => [
            'idpp' => 'popup_id', 'nazev' => 'name', 'adresa' => 'slug', 'typ' => 'type', 'spoustec' => 'trigger_type', 'hodnota' => 'value',
            'pravidla' => 'rules', 'cetnost' => 'frequency', 'dni' => 'days', 'aktivni' => 'active', 'poradi' => 'sort_order', 'stavba' => 'build',
            'stavba_koncept' => 'build_draft', 'zobrazeni' => 'impressions', 'zavreni' => 'closes', 'konverze' => 'conversions', 'zmeneno' => 'updated_at',
        ],
        'komponenty' => [
            'idm' => 'component_id', 'nazev' => 'name', 'vlastnosti' => 'properties', 'stavba' => 'build', 'stavba_koncept' => 'build_draft',
            'zmeneno' => 'updated_at',
        ],
        'odberatele' => [
            'ido' => 'subscriber_id', 'stav' => 'status', 'zdroj' => 'source', 'kampan' => 'campaign', 'vstup' => 'landing_page', 'datum' => 'created_at',
            'potvrzeno' => 'confirmed_at', 'sync_chyba' => 'sync_error',
        ],
        'odber_fronta' => [
            'idf' => 'queue_id', 'akce' => 'action', 'pokusy' => 'attempts', 'dalsi' => 'next_attempt_at', 'chyba' => 'error', 'vytvoreno' => 'created_at',
        ],
        'oauth_klienti' => ['idk' => 'id', 'tajemstvi' => 'secret_hash', 'nazev' => 'name', 'presmerovani' => 'redirect_uris', 'vytvoren' => 'created_at'],
        'oauth_kody' => ['otisk' => 'code_hash', 'idu' => 'user_id', 'presmerovani' => 'redirect_uri', 'vyzva' => 'code_challenge', 'expirace' => 'expires_at'],

        // tables that already have English names but kept a Czech column
        'document_versions' => ['idp' => 'item_id'],
        'document_downloads' => ['idp' => 'item_id'],
        'notice_log' => ['idp' => 'item_id'],
        'testimonial_requests' => ['idp' => 'enquiry_id'],
        'social_drafts' => ['idc' => 'news_id'],
        'blueprints' => ['nazev' => 'name'],
    ],

    // Columns that are English already but sit in a Czech-named table: a deliberate "keep", so the inventory can tell them from forgotten ones.
    'keep' => [
        '*' => ['id', 'email', 'url', 'text', 'data', 'ip', 'name', 'type', 'status', 'created_at', 'updated_at', 'faq', 'noindex', 'visible',
            'visit', 'element', 'kind', 'access', 'token', 'source', 'path', 'language', 'count', 'day', 'version', 'summary', 'author',
            'valid_until', 'review_by', 'links_checked', 'detail', 'hidden_redirect', 'preset', 'schema_org', 'kit_key', 'sync', 'intro',
            'css', 'auto_score', 'password', 'admin', 'role', 'register', 'bio', 'via', 'alg', 'client_id', 'triaged_by', 'triaged_at', 'quote', 'target'],
    ],

    // Stored values (not names) that are Czech: column => [Czech value => English value]. Extend while reviewing; the code scan lists more.
    'values' => [
        'api_tokeny.druh' => ['pristup' => 'access', 'obnova' => 'refresh'],
        'import_mapa.typ' => ['clanek' => 'news', 'stranka' => 'page', 'rubrika' => 'category', 'stitek' => 'tag', 'obrazek' => 'image', 'komentar' => 'comment'],
        'menu.umisteni' => ['hlavni' => 'main', 'paticka' => 'footer'],
        'odber_fronta.akce' => ['pridat' => 'add', 'odebrat' => 'remove'],
        'odberatele.sync' => ['ceka' => 'pending', 'chyba' => 'error'],
        'stat_zarizeni.zarizeni' => ['pocitac' => 'computer'],
        'casti.typ' => ['hlavicka' => 'header', 'paticka' => 'footer', 'novinka' => 'news_item', 'vypis' => 'list', 'nenalezeno' => 'not_found'],
    ],

    // Extensions::CATALOG keys (also stored in the setting `extensions` and used by Module::EXTENSION / Element::EXTENSION).
    'extensions' => [
        'novinky' => 'news', 'poptavky' => 'enquiries', 'statistika' => 'stats', 'presmerovani' => 'redirects', 'jazyky' => 'languages', 'asistent' => 'assistant',
    ],

    // Public routes (first segment).
    'routes' => [
        'novinky' => 'news', 'hledani' => 'search', 'formular' => 'form', 'souhlas' => 'consent', 'odber' => 'subscribe', 'ulohy' => 'tasks',
        'konverze' => 'conversion', 'kategorie' => 'category', 'stitek' => 'tag',
    ],
];
