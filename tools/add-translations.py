#!/usr/bin/env python3
"""Doplní překlady do slovníku Talea a správně je escapuje (apostrof v překladu jinak rozbije PHP soubor).

Použití: tools/add-translations.py system/languages/admin-en.php < radky    (řádek = "česky|překlad")
         tools/add-translations.py image/languages/admin-en.js < radky       (slovník skriptů administrace, funkce T())
         tools/add-translations.py --sort system/languages/de.php            (sorts the whole dictionary by key)
Existující klíče přeskočí; položky shodné s češtinou nezapisuje.
A dictionary is sorted by key and every new entry goes to its sorted place, so two pull requests that add texts touch
different lines and merge without a conflict. Every entry ends with a comma (JS too), so an insert never edits its neighbour.
"""
import bisect
import json
import re
import sys


def php(text: str) -> str:
    return "'" + text.replace('\\', '\\\\').replace("'", "\\'") + "'"


def js(text: str) -> str:
    return json.dumps(text, ensure_ascii=False)


# one entry: the key literal, then the value up to its comma and line end (a value may span lines)
ENTRY = {
    False: re.compile(r"    ('(?:[^'\\]|\\.)*') => '(?:[^'\\]|\\.)*',\n"),
    True: re.compile(r'\t("(?:[^"\\]|\\.)*"): "(?:[^"\\]|\\.)*",?\n'),
}


def key(literal: str, skript: bool) -> str:
    """The text of a key literal; entries are sorted by it."""
    return json.loads(literal) if skript else re.sub(r"\\([\\'])", r'\1', literal[1:-1])


sort_only = sys.argv[1] == '--sort'
cesta = sys.argv[-1]
skript = cesta.endswith('.js')
obsah = open(cesta, encoding='utf-8').read()
# the frame: everything up to the line that opens the array/object, and from the line that closes it
start = re.search(r'^.*\{\n?' if skript else r'^return \[\n?', obsah, re.M).end()
end = obsah.rindex('}' if skript else ']')
head, body, tail = obsah[:start].rstrip('\n') + '\n', obsah[start:end], obsah[end:]
polozky = []
pos = 0
while pos < len(body):
    m = ENTRY[skript].match(body, pos)
    if not m:
        sys.exit(f'{cesta}: not a dictionary entry: {body[pos:pos + 80]!r}')
    text = m.group(0) if m.group(0).endswith(',\n') else m.group(0)[:-1] + ',\n'
    polozky.append((key(m.group(1), skript), text))
    pos = m.end()
if sort_only:
    polozky.sort()
    nove = []
else:
    known = {k for k, _ in polozky}
    nove = []
    for radek in sys.stdin.read().splitlines():
        if '|' not in radek:
            continue
        cesky, preklad = radek.split('|', 1)
        if cesky == preklad or cesky in known:
            continue
        known.add(cesky)
        nove.append(cesky)
        bisect.insort(polozky, (cesky, '\t' + js(cesky) + ': ' + js(preklad) + ',\n' if skript else '    ' + php(cesky) + ' => ' + php(preklad) + ',\n'))
open(cesta, 'w', encoding='utf-8').write(head + ''.join(text for _, text in polozky) + tail)
print(f'{cesta}: sorted' if sort_only else f'{cesta}: doplněno {len(nove)} položek')
