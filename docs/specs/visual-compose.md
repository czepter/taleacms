# Spec: visual composing in the page builder

Status: draft for review. Owner decision: "add a real visual compose with drag and drop etc."

## 1. Problem

The builder already has drag and drop of *structure*: dragging elements and ready-made sections from the left panel onto the
canvas with an insertion line, a move handle on the selected element, drag in the Structure tree, tap-to-move on touch,
inline text editing, undo/redo and copy/paste (`image/builder.js`). Everything about *size, space and layout* still goes
through form fields in the style panel (`Style::PROPERTIES`: Grid columns, Span columns, Gap, Padding…). A non-designer
cannot see what a value does until they type it. Direct manipulation lets them grab the thing and pull.

Goal: **direct manipulation on the canvas** for size, spacing, columns and placement, with output that stays the same
token-based, responsive, semantic CSS. Not pixel-absolute positioning.

## 2. Principles

1. The page stays a tree of elements; every gesture ends as a normal change to `Style` / element `content`. One validator
   (`Build::sanitize`), one renderer (`Build::render`). No second model.
2. Gestures snap to design-system tokens (spacing scale, columns), and show the token name while dragging. A free value is
   possible with a modifier key.
3. Everything is per breakpoint (`zaklad` / `tablet` / `mobil`): the device switcher decides which state a gesture writes.
4. Every gesture has a keyboard route and a touch route (accessibility; HTML5 drag events do not fire on touch).
5. No build step, no npm, no library; vanilla JS under the admin CSP (`script-src 'self'`, no inline handlers).
6. What the editor can do, Claude can do over MCP: new style properties get `Vocabulary` entries and contract records.

## 3. Scope

### Phase A: direct manipulation of the existing model (no schema change)

| # | Feature | Behaviour |
|---|---|---|
| A1 | **Spacing handles** | On the selected element, handles on the padding, margin and gap areas. Drag changes the value, snapping to the spacing tokens, with a live label. Alt = both opposite sides, Shift = all sides. |
| A2 | **Resize handles** | Edge and corner handles set width / max-width (as %, tokens or px with a modifier) and min-height. In a grid cell, the right/bottom handles change the column/row span and snap to grid lines. |
| A3 | **Column dividers** | In a row of columns (flex or `sloupce` grid) a divider between two children is draggable; writes the fr template (for example `2fr 1fr`). Shift = equal. |
| A4 | **Floating toolbar** | Above the selection: select parent, move, duplicate, delete, align (start/center/end), and by type: text (bold, italic, link, size step, alignment), image (replace, alt, focal point), button (link, variant). |
| A5 | **Multi-select** | Shift-click, Ctrl/Cmd-click and marquee on empty canvas. Group move, delete, duplicate, align/distribute, **Group** (wrap in container) and **Ungroup**. |
| A6 | **Guides and snapping** | While dragging: alignment guides to siblings' edges and centres, equal-spacing hints, ghost preview of the dropped element at the target position (the current insertion line stays for the tree). |
| A7 | **Breakpoint editing** | Device switcher (desktop / tablet / phone) renders the canvas at that width and writes to that state. Overridden values are marked; "reset to inherited" per property. |
| A8 | **Keyboard** | Arrow keys move the selection among siblings / grid cells, Alt+arrows resize by one step, Alt-drag duplicates, Ctrl/Cmd+G / Shift+G group / ungroup, Esc cancels a gesture, Tab walks the handles. |
| A9 | **Pointer events** | Gestures use pointer events with capture, so mouse, pen and touch behave the same. HTML5 drag stays only for panel to canvas (with the tap-to-place fallback that already exists). |
| A10 | **Drop files** | Dropping image / video files from the computer onto the canvas uploads them to Media and inserts the element at the drop position. |

### Phase B: free placement ("compose" section)

A section can be switched to **Compose**: a 12-column grid with fixed row unit (tokens). Children are placed by dragging on
the grid and resized by handles. Output is still CSS grid placement, not absolute positioning.

- New style properties (`Style::PROPERTIES`): `grid-column-start`, `grid-column-end`, `grid-row-start`, `grid-row-end`
  (line numbers 1–13 / 1–40), plus `z-index` from a small scale (`below`, `base`, `above`, `top`).
- Overlap is allowed only inside a Compose section (same cells, ordered by z-index).
- Tablet and phone: by default a Compose section stacks its children in source order (reading order = DOM order, so
  accessibility and SEO hold). The editor can give tablet/phone their own placement; on phone a separate order (`poradi`)
  is enough in most cases.
- A "Tidy up" action reflows overlapping or out-of-order elements into a clean column.
- Converting a section to Compose keeps current positions (flex/grid children get initial cells), and back to Stack
  drops the placement properties.

### Phase C: media on the canvas

- **Focal point** for images (click a point; stored as `object-position` percentages) and crop-to-ratio (`pomer_stran`).
- Section background (colour, image, video) editable from the canvas toolbar.
- Replace image by drop onto it.

### Non-goals

Absolute pixel positioning outside Compose; free rotation / skew; z-index outside Compose; vector drawing; real-time
multi-user editing; custom CSS editing on the canvas (stays in the panel).

## 4. Technical notes

- **Where the code goes:** `builder.js` is 1,861 lines in one IIFE. New work goes into separate files loaded by the editor
  page (`image/builder-handles.js`, `builder-compose.js`), sharing a small explicit interface exposed by `builder.js`
  (`applyChange`, `find`, `state`, `previewDoc`). Native `<script>` files, no bundler.
- **Overlay layer:** handles, guides and the marquee live in one overlay element inside the canvas iframe (like
  `#tl-bd-grip` and `#tl-bd-place` today), `pointer-events` only on the handles. It is never part of the saved page and
  never rendered on the public site (the canvas is `?build=draft&editor=1`).
- **Writes:** during a gesture the iframe is updated live by setting inline custom properties / styles on the node; on
  release one `applyChange(fn, key)` writes the real `Style` and removes the temporary inline values. One gesture = one
  undo step; the autosave (`stavba_uloz`) and the server's cleaned tree are taken over as today.
- **Style model:** Phase A needs none. Phase B adds the five properties above to `Style::PROPERTIES`, `Style::sanitize`,
  `Style` CSS generation, `Mcp\Vocabulary`, `tools/contracts` (builder style properties), `stavba_schema` overview, and the
  Section element's `layout` field (`stack` | `compose`).
- **Tokens:** spacing and column snapping read the design system's resolved scale from the canvas document (computed
  custom properties), so a changed design system changes the snap points. No duplicated token maths in JS (CLAUDE.md rule).
- **Locked elements** (`data-tl-lock`), elements inside shared components and PARTS_ONLY elements keep their current
  rules: no handles on locked content.
- **Performance:** one `requestAnimationFrame` loop per gesture, rect reads batched; no layout thrash on pages with 500
  elements.

## 5. Accessibility and internationalisation

- Every handle is a focusable control with an accessible name and live value announcement (`aria-live=polite`); every
  gesture has the keyboard route in A8.
- Visible focus, `prefers-reduced-motion` turns off animated guides, contrast of overlay colours checked in light and dark
  admin themes.
- New UI strings via the admin dictionaries (`T()` / `image/languages/admin-<code>.js`, `tools/check-english.php --js` stays clean).

## 6. Acceptance criteria

1. On a starter-site page, a user with no CSS knowledge can, with the mouse only: change the space between two sections,
   make a three-column row 2:1:1, resize an image to half the column width, and move a button to the right column. All
   four end as valid builds; reloading shows the same result.
2. The same four operations work with keyboard only and on a touch device (Playwright mobile emulation + `tools/test-browser.mjs`).
3. A phone-width edit does not change the desktop layout, and the reverse; "reset to inherited" removes the override.
4. In a Compose section, two elements can overlap on desktop; at phone width they stack in source order with no overlap.
5. Undo reverts one gesture at a time; a failed save mid-gesture never corrupts the tree.
6. `Build::sanitize` rejects out-of-range grid lines and unknown z-index values; `php tools/unit-tests.php` has cases for
   each new property; the Vocabulary round-trip test passes; `php tools/contracts.php` shows only additions.
7. The saved page's output weight does not grow for pages that do not use Compose (Lighthouse budget in
   `tools/test-lighthouse.sh` still 99–100 on the starter sites).
8. Claude over MCP can create the same Compose placement (`save_build` / `edit_build` with the new properties).

## 7. Milestones

| Milestone | Contents | Size |
|---|---|---|
| M1 | A9 pointer-event base, overlay layer, A7 breakpoint switcher with override marks | M |
| M2 | A1 spacing handles, A2 resize handles, A3 column dividers | L |
| M3 | A4 floating toolbar, A5 multi-select / group, A6 guides | L |
| M4 | A8 keyboard, A10 file drop, browser tests for acceptance 1–3, 5 | M |
| M5 | Phase B Compose section: style properties, sanitizer, vocabulary, contracts, editor placement, tidy-up | XL |
| M6 | Phase C focal point, canvas background editing | M |

M1–M4 ship without any data-model change and can be released incrementally.

## 8. Open questions

1. Should Compose allow overlap at all (decorative images over text), or only free placement without overlap? Overlap is
   what makes pages look designed, and what makes mobile hard.
2. Row unit in Compose: fixed height per row (predictable) or content-sized rows with free spans?
3. Does multi-select group move apply across different parents, or only among siblings?
4. Hold the 12-column grid, or let the design system set the column count (also for the snap points)?
5. Should phase C focal point also apply to existing images in news text and collections (affects `Images` and
   `srcset`), or only builder images?
