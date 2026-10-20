<?php

/**
 * Kaleta (the Czech upstream) => Talea: the words a Kaleta site export (obsah.json, format version 2) stores and their Talea names.
 * Read by tools/kaleta/convert.php. The builder part is Kaleta's own MCP vocabulary (Kaleta\Mcp\Vocabulary as of 3.7, the source
 * of the Talea builder names); the rest comes from the hard-fork rename map (tables, columns, stored values) and the classes it
 * renamed (menus, pop-ups, site parts, collections, components, design system). Czech on purpose: tools/kaleta/ is a dictionary.
 */

return [
    /* ---------- builds (Kaleta\Mcp\Vocabulary) ---------- */
    'node' => [
        'typ' => 'type', 'znacka' => 'tag', 'obsah' => 'content', 'styl' => 'style', 'tridy' => 'classes', 'kotva' => 'anchor',
        'popis' => 'label', 'atributy' => 'attributes', 'podminky' => 'conditions', 'zamek' => 'locked', 'deti' => 'children'
    ],
    'conditions' => ['prihlaseni' => 'signed_in', 'od' => 'from', 'do' => 'to', 'jazyky' => 'languages', 'parametr' => 'url_parameter'],
    'condition_values' => ['ano' => 'yes', 'ne' => 'no'],
    'url_parameter' => ['nazev' => 'name', 'hodnota' => 'value'],
    'types' => [
        'sekce' => 'section', 'kontejner' => 'container', 'mrizka' => 'grid', 'nadpis' => 'heading', 'text' => 'text',
        'obrazek' => 'image', 'tlacitko' => 'button', 'seznam' => 'list', 'citat' => 'testimonial', 'faq' => 'faq', 'video' => 'video',
        'oddelovac' => 'divider', 'ikona' => 'icon', 'galerie' => 'gallery', 'zalozky' => 'tabs', 'karusel' => 'carousel', 'mapa' => 'map',
        'vlozeni' => 'embed', 'drobecky' => 'breadcrumbs', 'pocitadlo' => 'counter', 'prubeh' => 'progress_bars', 'hodnoceni' => 'rating',
        'odpocet' => 'countdown', 'socialni' => 'social_links', 'hledani' => 'search', 'novinky' => 'news_list',
        'kolekce' => 'collection_list', 'do_poptavky' => 'enquiry_button', 'pobocky' => 'store_locator', 'formular' => 'form',
        'rezervace' => 'booking', 'newsletter' => 'newsletter_signup', 'komponenta' => 'component', 'html' => 'custom_html',
        'nahoru' => 'back_to_top', 'logo' => 'logo', 'navigace' => 'navigation', 'jazyky' => 'language_switcher',
        'udaje' => 'company_details', 'obsah' => 'page_content', 'cenik' => 'pricing_table', 'pred_po' => 'before_after',
        'hotspoty' => 'hotspots', 'casova_osa' => 'timeline', 'recenze_google' => 'google_reviews'
    ],
    'content' => [
        'sirka' => 'width', 'video' => 'background_video', 'odkaz' => 'link', 'text' => 'text', 'html' => 'html', 'src' => 'src',
        'alt' => 'alt', 'popisek' => 'caption', 'priorita' => 'priority', 'varianta' => 'variant', 'nove_okno' => 'new_window',
        'ikona' => 'icon', 'ikona_vlevo' => 'icon_left', 'polozky' => 'items', 'styl' => 'style', 'autor' => 'author',
        'pozice' => 'position', 'jedna' => 'single_open', 'faq' => 'faq_schema', 'url' => 'url', 'titulek' => 'title',
        'plakat' => 'poster', 'tvar' => 'shape', 'popis' => 'description', 'fotky' => 'photos', 'pomer' => 'ratio', 'karty' => 'tabs',
        'naraz' => 'per_view', 'adresa' => 'address', 'priblizeni' => 'zoom', 'vyska' => 'height', 'cislo' => 'number', 'pred' => 'prefix',
        'za' => 'suffix', 'hodnota' => 'value', 'cil' => 'target', 'konec' => 'end_text', 'nazvy' => 'show_names',
        'napoveda' => 'placeholder', 'tlacitko' => 'button_text', 'pocet' => 'count', 'kategorie' => 'category', 'obrazky' => 'images',
        'kolekce' => 'collection', 'razeni' => 'sort', 'razeni_pole' => 'sort_field', 'filtr_pole' => 'filter_field',
        'filtr_hodnota' => 'filter_value', 'bez_aktualni' => 'exclude_current', 'obdobi' => 'period', 'obdobi_od' => 'period_start_field',
        'obdobi_do' => 'period_end_field', 'filtry' => 'filters', 'kosik' => 'basket_page', 'mnozstvi' => 'quantity',
        'porovnani' => 'compare', 'strankovani' => 'pagination', 'prazdne' => 'empty_text', 'nazev' => 'name', 'pole' => 'fields',
        'dekujeme' => 'thank_you', 'prijemce' => 'recipient', 'dekovna' => 'thank_you_page', 'potvrzeni' => 'confirmation',
        'bez_captcha' => 'no_captcha', 'poslat_soubor' => 'send_file', 'souhlas' => 'consent', 'komponenta' => 'component',
        'hodnoty' => 'values', 'kod' => 'code', 'menu' => 'menu', 'novinky' => 'news_link', 'mobil' => 'phone_menu', 'mega' => 'mega_menu',
        'zvyrazneni' => 'highlight', 'jazyky' => 'language_switcher', 'smer' => 'direction', 'udaj' => 'detail',
        'pri_rolovani' => 'on_scroll', 'text_nahore' => 'text_at_top', 'pole_poloha' => 'location_field', 'hledani' => 'search_box',
        'nejblizsi' => 'nearest', 'mapa' => 'show_map', 'dalsi_kroky' => 'next_steps', 'odpovime_do' => 'reply_within_hours',
        'odpovida' => 'who_replies', 'plany' => 'plans', 'obrazek_pred' => 'before_image', 'alt_pred' => 'before_alt',
        'popisek_pred' => 'before_label', 'obrazek_po' => 'after_image', 'alt_po' => 'after_alt', 'popisek_po' => 'after_label',
        'delic' => 'divider_position', 'body' => 'points', 'udalosti' => 'milestones', 'min_hvezd' => 'min_stars', 'souhrn' => 'summary',
        'sluzba' => 'service', 'osoba' => 'staff_member'
    ],
    'content_by_type' => [
        'logo' => ['nazev' => 'show_name'],
    ],
    'items' => [
        'otazka' => 'question', 'odpoved' => 'answer', 'src' => 'src', 'alt' => 'alt', 'nazev' => 'name', 'obsah' => 'content',
        'hodnota' => 'value', 'popisek' => 'label', 'typ' => 'type', 'povinne' => 'required', 'moznosti' => 'options',
        'moznosti_volby' => 'choices', 'moznosti_zaskrtnuti' => 'checkbox_options', 'cena_za_jednotku' => 'unit_price',
        'zaklad' => 'base_price', 'mena' => 'currency', 'kdyz_pole' => 'show_when_field', 'kdyz_hodnota' => 'show_when_value',
        'cena' => 'price', 'obdobi' => 'period', 'popis' => 'description', 'funkce' => 'features', 'tlacitko' => 'button_text',
        'odkaz' => 'link', 'zvyraznit' => 'highlighted', 'stitek' => 'badge', 'x' => 'x', 'y' => 'y', 'datum' => 'date'
    ],
    'item_values' => [
        'typ' => [
            'text' => 'text', 'email' => 'email', 'tel' => 'tel', 'textarea' => 'textarea', 'vyber' => 'select', 'volba' => 'radio',
            'datum' => 'date', 'cislo' => 'number', 'soubor' => 'file', 'kosik' => 'basket', 'krok' => 'step', 'odhad' => 'estimate',
            'souhlas' => 'checkbox', 'zaskrtnuti' => 'checkboxes', 'skryte' => 'hidden'
        ],
    ],
    'values' => [
        'sirka' => ['obsah' => 'content', 'uzka' => 'narrow', 'plna' => 'full'],
        'varianta' => ['primarni' => 'primary', 'sekundarni' => 'secondary', 'obrys' => 'outline', 'odkaz' => 'link'],
        'ikona' => [
            'fajfka' => 'check', 'fajfka-kruh' => 'check-circle', 'hvezda' => 'star', 'srdce' => 'heart', 'telefon' => 'phone',
            'email' => 'email', 'misto' => 'place', 'hodiny' => 'clock', 'kalendar' => 'calendar', 'clovek' => 'person', 'lide' => 'people',
            'dum' => 'home', 'stit' => 'shield', 'stit-fajfka' => 'shield-check', 'blesk' => 'bolt', 'list' => 'leaf', 'auto' => 'car',
            'klic' => 'key', 'naradi' => 'tools', 'bublina' => 'speech-bubble', 'svet' => 'globe', 'zamek' => 'lock', 'penize' => 'money',
            'graf' => 'chart', 'rust' => 'growth', 'darek' => 'gift', 'fotak' => 'camera', 'dokument' => 'document', 'sipka' => 'arrow',
            'plus' => 'plus', 'info' => 'info', 'pozor' => 'warning', 'medaile' => 'medal', 'kufr' => 'briefcase', 'salek' => 'cup',
            'cil' => 'target', 'raketa' => 'rocket', 'palec' => 'thumbs-up', 'zarovka' => 'bulb', 'github' => 'github',
            'rozvrzeni' => 'layout', 'tokeny' => 'tokens', 'chat' => 'chat', 'clanek' => 'article', 'vrstvy' => 'layers', 'obrazek' => 'image',
            'odeslat' => 'send', 'nastaveni' => 'settings'
        ],
        'styl' => ['odrazky' => 'bullets', 'fajfky' => 'checks', 'bez' => 'none', 'nabidka' => 'dropdown', 'rada' => 'row'],
        'tvar' => ['kruh' => 'circle', 'ctverec' => 'square'],
        'razeni' => ['poradi' => 'order', 'nazev' => 'name', 'nejnovejsi' => 'newest', 'pole' => 'field', 'pole_sestupne' => 'field_descending'],
        'obdobi' => ['' => '', 'nadchazejici' => 'upcoming', 'probihajici' => 'current', 'minule' => 'past'],
        'menu' => ['hlavni' => 'main', 'paticka' => 'footer'],
        'zvyrazneni' => ['pozadi' => 'background', 'podtrzeni' => 'underline'],
        'smer' => ['nahoru' => 'up', 'dolu' => 'down'],
        'pri_rolovani' => ['pruhledna' => 'transparent', 'zmensit' => 'shrink', 'pruhledna-zmensit' => 'transparent_shrink'],
        'text_nahore' => ['svetly' => 'light', 'tmavy' => 'dark'],
        'udaj' => [
            'adresa' => 'address', 'telefon' => 'phone', 'email' => 'email', 'hodiny' => 'hours', 'otevreno' => 'open_now', 'mapa' => 'map',
            'firma' => 'company', 'tiraz' => 'imprint', 'copyright' => 'copyright', 'nazev' => 'name', 'popis' => 'description',
            'text_paticky' => 'footer_text', 'site' => 'social', 'rss' => 'rss'
        ],
    ],
    'states' => [
        'zaklad' => 'base', 'tablet' => 'tablet', 'mobil' => 'mobile', 'hover' => 'hover', 'aktivni' => 'active',
        'hover_tablet' => 'hover_tablet', 'hover_mobil' => 'hover_mobile', 'aktivni_tablet' => 'active_tablet',
        'aktivni_mobil' => 'active_mobile'
    ],
    'style' => [
        'zobrazeni' => 'display', 'smer' => 'direction', 'zalamovani' => 'wrap', 'sloupce' => 'columns', 'radky' => 'rows',
        'oblasti' => 'areas', 'oblast' => 'area', 'rozpeti_sloupcu' => 'column_span', 'rozpeti_radku' => 'row_span', 'mezera' => 'gap',
        'zarovnani' => 'align_items', 'rozmisteni' => 'justify_content', 'vlastni_zarovnani' => 'align_self', 'poradi' => 'order',
        'rust' => 'flex', 'sirka' => 'width', 'max_sirka' => 'max_width', 'vyska' => 'height', 'min_vyska' => 'min_height',
        'pomer_stran' => 'aspect_ratio', 'prizpusobeni' => 'object_fit', 'na_stred' => 'center', 'odsazeni_y' => 'padding_y',
        'odsazeni_x' => 'padding_x', 'okraj_nahore' => 'margin_top', 'okraj_dole' => 'margin_bottom', 'okraj_vlevo' => 'margin_left',
        'okraj_vpravo' => 'margin_right', 'typ_styl' => 'text_style', 'velikost_pisma' => 'font_size', 'tloustka_pisma' => 'font_weight',
        'pismo' => 'font', 'zarovnani_textu' => 'text_align', 'radkovani' => 'line_height', 'velka_pismena' => 'text_transform',
        'proklad' => 'letter_spacing', 'max_radek' => 'line_length', 'barva' => 'color', 'pozadi' => 'background',
        'obrazek_pozadi' => 'background_image', 'prechod' => 'gradient', 'paralaxa' => 'background_attachment', 'prekryv' => 'overlay',
        'ramecek' => 'border', 'barva_ramecku' => 'border_color', 'linka_nahore' => 'border_top', 'linka_dole' => 'border_bottom',
        'zaobleni' => 'radius', 'stin' => 'shadow', 'pruhlednost' => 'opacity', 'orez' => 'overflow', 'pozice' => 'position',
        'odshora' => 'top', 'zdola' => 'bottom', 'zleva' => 'left', 'zprava' => 'right', 'posun' => 'translate', 'meritko' => 'scale',
        'otoceni' => 'rotate', 'plynule' => 'transition', 'vrstva' => 'z_index', 'animace' => 'animation', 'pohyb' => 'scroll_motion',
        'najeti' => 'hover_effect'
    ],
    'colors' => [
        'primarni' => 'primary', 'primarni-jemna' => 'primary-soft', 'na-primarni' => 'on-primary', 'sekundarni' => 'secondary',
        'text' => 'text', 'tlumeny' => 'muted', 'pozadi' => 'background', 'plocha' => 'surface', 'linka' => 'line', 'bila' => 'white',
        'cerna' => 'black'
    ],
    'text_styles' => [
        'titulek' => 'title', 'nadpis-sekce' => 'section-heading', 'podnadpis' => 'subheading', 'perex' => 'lead', 'text' => 'body',
        'drobny' => 'small', 'nadtitulek' => 'eyebrow'
    ],
    'radii' => ['plne' => 'full'],
    'color_properties' => ['barva', 'pozadi', 'prekryv', 'barva_ramecku'],

    /* ---------- tables and columns of obsah.json ---------- */

    // export table => Talea table; tables that are English already map to themselves
    'tables' => [
        'stranky' => 'pages', 'kategorie' => 'categories', 'stitky' => 'tags', 'novinky' => 'news', 'presmerovani' => 'redirects', 'tridy' => 'classes',
        'casti' => 'site_parts', 'komponenty' => 'components', 'sekce' => 'sections', 'menu' => 'menus', 'kolekce' => 'collections',
        'kolekce_sablony' => 'collection_templates', 'kolekce_polozky' => 'collection_items', 'popupy' => 'popups', 'media_slozky' => 'media_folders',
        'media' => 'media', 'document_versions' => 'document_versions', 'facts' => 'facts', 'hours_exceptions' => 'hours_exceptions',
        'booking_services' => 'booking_services', 'booking_staff' => 'booking_staff', 'booking_staff_services' => 'booking_staff_services',
        'booking_hours' => 'booking_hours', 'booking_off' => 'booking_off', 'blueprints' => 'blueprints', 'notebook' => 'notebook', 'notice_log' => 'notice_log',
    ],

    // Talea table => [export column => Talea column]; a column not listed keeps its name
    'columns' => [
        'pages' => [
            'ids' => 'page_id', 'seo_link' => 'slug', 'titulek' => 'title', 'popis' => 'description', 'seo_titulek' => 'seo_title', 'obrazek' => 'image',
            'heslo_hash' => 'password_hash', 'zobrazit' => 'visible', 'zverejnit_od' => 'publish_at', 'kod_hlavicky' => 'head_code', 'v_menu' => 'in_menu',
            'poradi' => 'sort_order', 'zmeneno' => 'updated_at', 'jazyk' => 'language', 'preklad_z' => 'translation_of', 'nadrazena' => 'parent_id',
            'stavba' => 'build', 'stavba_koncept' => 'build_draft', 'smazano' => 'deleted_at',
        ],
        'categories' => ['idt' => 'category_id', 'nazev' => 'name', 'seo_link' => 'slug', 'popis' => 'description', 'hodnost' => 'weight', 'jazyk' => 'language', 'preklad_z' => 'translation_of'],
        'tags' => ['ids' => 'tag_id', 'nazev' => 'name', 'seo_link' => 'slug', 'popis' => 'description', 'obrazek' => 'image'],
        'news' => [
            'idc' => 'news_id', 'seo_link' => 'slug', 'titulek' => 'title', 'uvod' => 'intro', 'obrazek' => 'image', 'obrazek_popis' => 'image_caption',
            'obrazek_autor' => 'image_author', 'tema' => 'category_id', 'datum' => 'published_at', 't_slova' => 'keywords', 'seo_titulek' => 'seo_title',
            'seo_popis' => 'seo_description', 'zmeneno' => 'edited_at', 'aktualizovano' => 'updated_at', 'jazyk' => 'language', 'preklad_z' => 'translation_of',
            'stitky' => 'tags',
        ],
        'redirects' => ['idp' => 'redirect_id', 'z_adresy' => 'from_path', 'na_adresu' => 'to_path', 'typ' => 'type'],
        'classes' => ['nazev' => 'name', 'styl' => 'style'],
        'site_parts' => ['typ' => 'type', 'jazyk' => 'language', 'varianta' => 'variant', 'nazev' => 'name', 'stranky' => 'pages', 'pravidla' => 'rules', 'stavba' => 'build', 'stavba_koncept' => 'build_draft'],
        'components' => ['idm' => 'component_id', 'nazev' => 'name', 'vlastnosti' => 'properties', 'stavba' => 'build', 'stavba_koncept' => 'build_draft'],
        'sections' => ['idx' => 'section_id', 'nazev' => 'name', 'prvek' => 'element'],
        'menus' => ['umisteni' => 'location', 'jazyk' => 'language', 'polozky' => 'items'],
        'collections' => ['idk' => 'collection_id', 'nazev' => 'name', 'seo_link' => 'slug', 'pole' => 'fields', 'stavba' => 'build', 'stavba_koncept' => 'build_draft'],
        'collection_templates' => ['idk' => 'collection_id', 'jazyk' => 'language', 'stavba' => 'build', 'stavba_koncept' => 'build_draft'],
        'collection_items' => [
            'idp' => 'item_id', 'idk' => 'collection_id', 'nazev' => 'name', 'seo_link' => 'slug', 'seo_titulek' => 'seo_title', 'popis' => 'description',
            'obrazek' => 'image', 'poradi' => 'sort_order', 'zobrazit' => 'visible', 'zverejnit_od' => 'publish_at', 'jazyk' => 'language', 'datum' => 'created_at',
        ],
        'popups' => [
            'idpp' => 'popup_id', 'nazev' => 'name', 'adresa' => 'slug', 'typ' => 'type', 'spoustec' => 'trigger_type', 'hodnota' => 'value', 'pravidla' => 'rules',
            'cetnost' => 'frequency', 'dni' => 'days', 'aktivni' => 'active', 'poradi' => 'sort_order', 'stavba' => 'build', 'stavba_koncept' => 'build_draft',
        ],
        'media_folders' => ['ids' => 'folder_id', 'nazev' => 'name'],
        'media' => [
            'ido' => 'media_id', 'sekce' => 'folder_id', 'nazev' => 'name', 'popis' => 'description', 'autor' => 'author', 'obr_poloha' => 'image_path',
            'obr_width' => 'image_width', 'obr_height' => 'image_height', 'obr_vel' => 'image_size', 'nahl_poloha' => 'thumb_path', 'nahl_width' => 'thumb_width',
            'nahl_height' => 'thumb_height', 'barva' => 'color', 'ohnisko' => 'focal_point', 'datum' => 'created_at',
        ],
        'document_versions' => ['idp' => 'item_id'],
        'notice_log' => ['idp' => 'item_id'],
        'blueprints' => ['nazev' => 'name'],
    ],

    /* ---------- stored values ---------- */

    'menu_locations' => ['hlavni' => 'main', 'paticka' => 'footer'],
    'menu_items' => ['typ' => 'type', 'ids' => 'page_id', 'popis' => 'description', 'nove_okno' => 'new_window', 'ikona' => 'icon', 'deti' => 'children'],
    'menu_types' => ['stranka' => 'page', 'odkaz' => 'link', 'novinky' => 'news', 'skupina' => 'group'],

    'site_part_types' => ['hlavicka' => 'header', 'paticka' => 'footer', 'novinka' => 'news_item', 'vypis' => 'list', 'nenalezeno' => 'not_found'],

    'popup_types' => ['okno' => 'window', 'panel' => 'slide_in', 'lista-nahore' => 'top_bar', 'lista-dole' => 'bottom_bar', 'cela' => 'fullscreen'],
    'popup_triggers' => ['cas' => 'time', 'posun' => 'scroll', 'odchod' => 'exit', 'necinnost' => 'idle', 'stranky' => 'pages', 'klik' => 'click'],
    'popup_frequencies' => ['relace' => 'session', 'dni' => 'days', 'zavreni' => 'until_closed', 'odeslani' => 'until_submitted', 'vzdy' => 'always'],
    'popup_rules' => ['kde' => 'where', 'stranky' => 'pages', 'kolekce' => 'collections', 'novinky' => 'news', 'jazyk' => 'language', 'od' => 'from', 'do' => 'to',
        'zarizeni' => 'device', 'utm' => 'campaign', 'odkud' => 'referrer'],
    'popup_rule_values' => ['kde' => ['vse' => 'all', 'vybrane' => 'selected'], 'zarizeni' => ['vse' => 'all', 'pocitac' => 'desktop', 'telefon' => 'phone']],

    // collection fields and component properties ({klic, popisek, typ, kolekce, moznosti, vychozi})
    'fields' => ['klic' => 'key', 'popisek' => 'label', 'typ' => 'type', 'kolekce' => 'collection', 'moznosti' => 'options', 'vychozi' => 'default'],
    'field_types' => ['text' => 'text', 'radky' => 'lines', 'html' => 'html', 'obrazek' => 'image', 'odkaz' => 'link', 'cislo' => 'number', 'datum' => 'date',
        'termin' => 'datetime', 'soubor' => 'file', 'poloha' => 'location', 'volba' => 'radio', 'parametry' => 'parameters', 'varianty' => 'variants', 'polozka' => 'item'],
    // the built-in placeholders of a collection item
    'placeholders' => ['nazev' => 'name', 'datum' => 'date'],

    'design_system' => ['barvy' => 'colors', 'barvy_tmave' => 'colors_dark', 'vlastni_pisma' => 'custom_fonts', 'pismo_titulky' => 'font_heading', 'pismo_text' => 'font_body',
        'zaklad_min' => 'base_min', 'zaklad_max' => 'base_max', 'pomer_min' => 'ratio_min', 'pomer_max' => 'ratio_max', 'sirka' => 'width', 'sirka_textu' => 'text_width',
        'zaobleni' => 'radius', 'typografie' => 'typography'],
    'design_fonts' => ['nazev' => 'name', 'soubor' => 'file', 'tucny' => 'bold'],
    'design_typography' => ['tloustka' => 'weight', 'krok' => 'step'],
    'design_typography_keys' => ['titulek' => 'title', 'nadpis-sekce' => 'section-heading', 'podnadpis' => 'subheading', 'perex' => 'lead', 'drobny' => 'small', 'nadtitulek' => 'eyebrow'],
    'font_choices' => ['moderni' => 'modern', 'klasicke' => 'classic', 'knizni' => 'book', 'grotesk' => 'grotesque', 'zaoblene' => 'rounded', 'strojove' => 'typewriter',
        'patkove' => 'serif', 'elegantni' => 'elegant', 'vychozi' => 'default'],

    'dark_mode' => ['vypnuto' => 'off', 'tmavy' => 'dark'],
    'extensions' => ['novinky' => 'news', 'poptavky' => 'enquiries', 'statistika' => 'stats', 'presmerovani' => 'redirects', 'jazyky' => 'languages', 'asistent' => 'assistant'],

    // CSS custom properties and classes of the site in custom CSS and HTML (longest first is applied by convert.php)
    'css' => [
        '--ka-barva-primarni-jemna' => '--tl-color-primary-soft', '--ka-barva-na-primarni' => '--tl-color-on-primary', '--ka-barva-primarni' => '--tl-color-primary',
        '--ka-barva-sekundarni' => '--tl-color-secondary', '--ka-barva-text-svetle' => '--tl-color-text-light', '--ka-barva-text-tmave' => '--tl-color-text-dark',
        '--ka-barva-text' => '--tl-color-text', '--ka-barva-pozadi' => '--tl-color-background', '--ka-barva-plocha' => '--tl-color-surface',
        '--ka-barva-bila' => '--tl-color-white', '--ka-barva-cerna' => '--tl-color-black', '--ka-barva-tlumeny' => '--tl-color-muted', '--ka-barva-linka' => '--tl-color-line',
        '--ka-akcent' => '--tl-accent', '--ka-pismo-text' => '--tl-font-body', '--ka-pismo-titulky' => '--tl-font-heading', '--ka-sirka-textu' => '--tl-text-width',
        '--ka-sirka' => '--tl-width', '--ka-zaobleni-plne' => '--tl-radius-full', '--ka-zaobleni' => '--tl-radius', '--ka-krok' => '--tl-step', '--ka-mezera' => '--tl-space',
        '--ka-stin' => '--tl-shadow', '--ka-typ-titulek' => '--tl-type-title', '--ka-typ-nadpis-sekce' => '--tl-type-section-heading', '--ka-typ-podnadpis' => '--tl-type-subheading', '--ka-typ-perex' => '--tl-type-lead', '--ka-typ-drobny' => '--tl-type-small', '--ka-typ-nadtitulek' => '--tl-type-eyebrow', '--ka-typ' => '--tl-type',
        '--ka-prekryv' => '--tl-overlay', '--ka-delic' => '--tl-split', '--ka-naraz' => '--tl-per-view', '--ka-osa-x' => '--tl-axis-x', '--ka-okraj' => '--tl-margin',
        'ka-tlacitko--primarni' => 'tl-button--primary', 'ka-tlacitko--sekundarni' => 'tl-button--secondary', 'ka-tlacitko--obrys' => 'tl-button--outline',
        'ka-tlacitko--odkaz' => 'tl-button--link', 'ka-tlacitko' => 'tl-button', 'ka-formular' => 'tl-form', 'ka-hledani' => 'tl-search', 'ka-obal--uzka' => 'tl-wrap--narrow',
        'ka-obal' => 'tl-wrap', 'ka-galerie' => 'tl-gallery', 'ka-ikona' => 'tl-icon', 'ka-nahoru' => 'tl-back-to-top',
    ],
];
