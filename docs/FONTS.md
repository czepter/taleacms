# Font library

Talea ships about 28 open-source font families, self-hosted in `image/fonts/<slug>/`. Visitors never contact a font host: the site
emits `@font-face` (with `font-display: swap`) and a preload only for the one or two families chosen in Appearance. The library is
read by `Builder\DesignSystem::libraryFonts()`; a choice is stored as `lib:<slug>` in `font_heading` / `font_body` of the design
system (the MCP tool `update_design_system` also accepts the family name, e.g. `Playfair Display`). System fonts and fonts uploaded
to Media (`custom-1…3`) keep working.

## Layout of a family

```
image/fonts/<slug>/
  <slug>.woff2            variable font (one file, weight range), or <slug>-<weight>.woff2 per static weight
  <slug>-italic.woff2     only when the italic is included
  OFL.txt                 the licence text of the family (kept with the files)
  font.json               name, category (sans|serif|display|mono|handwritten), weights, italic, variable,
                          scripts [latin, latin-ext], files [{file, weight, style}], source, licence, reserved_name (if any)
```

`image/fonts/bricolage-grotesque-*.woff2` and `image/fonts/OFL.txt` (the admin font) stay in the root of the folder and are not part
of the picker.

## Adding a family

The site never downloads fonts. A maintainer does it by hand, once, with `tools/fonts-add.php`:

```
python3 -m venv /tmp/fonttools && /tmp/fonttools/bin/pip install fonttools brotli
TALEA_FONT_TOOLS=/tmp/fonttools/bin php tools/fonts-add.php <slug> <google-fonts-dir> [--category=sans|serif|display|mono|handwritten]
    [--weights=400,700] [--italic] [--name="Family Name"]
```

`<google-fonts-dir>` is the folder under <https://github.com/google/fonts/tree/main/ofl/> (for example `sourcesans3` for the slug
`source-sans-3`). The script downloads `METADATA.pb`, the TTF files and `OFL.txt` from `raw.githubusercontent.com/google/fonts` only,
refuses a family that is not under the OFL, pins every variable axis except weight, subsets to Latin and Latin-extended
(U+0000-00FF, U+0100-024F, U+1E00-1EFF, U+2000-206F, U+20A0-20CF, U+2100-214F, U+2190-21FF, U+2212, U+2215), converts to WOFF2 and writes
the folder. It prints the size and any Reserved Font Name found in the licence.

Then:

1. Check the glyphs of English, German, Czech and Polish render without fallback (the browser test does it for every family).
2. Raise `BUDGET_BYTES` in `tests/Unit/Builder/FontLibraryTest.php` on purpose; the test also checks every `font.json`, licence file and WOFF2.
3. Optionally add a suggested pair to `DesignSystem::PAIRINGS`.
4. Mention a new family in `NOTICE` only if it is not a Google Fonts OFL family.

Cyrillic, Greek, Arabic and CJK subsets are not included (on request).

## Licences

All families are SIL Open Font License 1.1. Subsetting and conversion to WOFF2 do not change the design of the glyphs; the families keep
their original names (as Google Fonts and Fontsource serve them). Where the licence names a Reserved Font Name (see `reserved_name`
in `font.json`), do not publish a *modified* design under that name.

## Size

Total WOFF2 size of the library: 2.37 MB for 28 families (46 files; the largest are Merriweather 241 KB, Lato 247 KB in four static
weights, EB Garamond 182 KB and Inter 183 KB with italics). Only the chosen families reach a visitor: typically 40-100 KB per family.
