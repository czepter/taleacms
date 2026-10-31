# Spec: visual composing in the page builder

Status: draft for review. Owner decision: "add a real visual compose with drag and drop etc."

Status of phase A (#22): built, M1–M4 (`image/builder/overlay.js`, `handles.js`, `keys.js`; since #229 the builder is ES modules, see section 4). Deviations from the text below:
resize handles are on the right edge, the bottom edge and the bottom-right corner only (the other edges would not move the element's
visible edge in a top-left flowing layout); padding handles write `padding_y` / `padding_x` (the model has no per-side padding);
"distribute" and equal-spacing hints are not built; the move gesture is a toolbar grip (pointer events) in place of the old ⠿ handle.

Status of phase B (#23): built (`image/builder/compose.js`; style properties `grid_column_start`, `grid_column_end`, `grid_row_start`, `grid_row_end`, `layer`;
Section fields `layout`, `stack_from`). Deviations from the text above: the layer scale is stored by name and renders `z-index` -1 / 0 / 1 / 2;
rows are `minmax(var(--tl-space-l), auto)` (the unit is fixed, a row still grows with its text, so a paragraph never overflows its cell);
Stack to Compose reads the positions from the desktop canvas (on tablet or phone it writes one clean column); a tablet or phone placement is possible
with `stack_from` = phone only. Phase C (crop mode, canvas background, replace by drop) is built, see its status below.

## 1. Problem

The builder already has drag and drop of *structure*: dragging elements and ready-made sections from the left panel onto the
canvas with an insertion line, a move handle on the selected element, drag in the Structure tree, tap-to-move on touch,
inline text editing, undo/redo and copy/paste (`image/builder/`). Everything about *size, space and layout* still goes
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

Status: built (M6 data, M7 crop mode C1 in `image/builder/media.js`, M8 Background popover C2 in `image/builder/background.js` and replace by drop C3 in
`media.js` + `overlay.js`). Deviations from the text below: the popover is a `popover="manual"` element drawn again by every `C.render` (a saved draft swaps the
canvas), closed by Esc, a press outside or the button; its title names the screen size as the device switcher does (Desktop / Tablet / Mobile). The overlay chips
write `overlay` = `rgb(0 0 0 / 0.3)` (Darken) or `rgb(0 0 0 / 0.55)` (Darken more), or the `gradient` option "darken at the bottom (over a photo)"; for that
`Style::css` now puts a `gradient` of the same state as a layer over the background image (before it, the photo's declaration won and the gradient was lost).
"None" and "Remove image" can only remove what the current screen size sets itself: a colour or overlay passed on from a wider size is switched off with
`transparent`, an inherited image or darkening gradient is removed on the wider size (the button says so). The focal point of a background is not offered
with `background-attachment: fixed`, and the crop frame is the element's border box (a section with a border is off by the border width). The contrast line
under the tabs is the builder's own check (`contrastCheck`, panels.js), run once per canvas, so a colour change shows its warning when the saved draft has
swapped the canvas; it skips elements on a photo and never blocks. Replace by drop: an image file over an image or over any element inside a
section / container that shows a background image in the current screen size (the nearest such element wins), a video over a section with a background video;
Media keeps an image's alt in its name, so a file picked from Media (toolbar Replace, popover Choose) brings its name as the alt when it has one, while a file
just uploaded or dropped has only a file name: the old alt stays and the status line says to check it (an empty alt takes the name); both end in `C.replaceImage`.
The screen size of a drop or of a popover upload is the one at the moment of the drop or the choice, not of the moment the upload ends. Replacing a background
removes `background_focal` from that size and from every narrower size that takes the new photo from it. "Darken at the bottom" on a size that only inherits the
photo writes the photo (and its point) into that size too, and Remove image takes an own darkening gradient with it. Phase C is about the picture inside a box, not the box: what part of a photo shows, in what
shape, and what sits behind a section. Every write is a normal `Style` / `content` change of the current breakpoint, one
`applyChange` per gesture, like phases A and B.

**What exists today (the base Phase C builds on):**

- A Media file has its own focal point (`media.focal_point`, `"x% y%"`, set by a tap in Media → edit, `Admin\Modules\Media::actionSave`).
  `Front\ImageHtml::complete` adds it as an inline `object-position` to every `<img>` of that file on the site: builder pages, news
  text, collections, galleries. So the old open question 5 is settled: the per-file focal point already reaches every image.
  It is not reachable over MCP (`update_media` has no `focal_point`), and background images ignore it (`Style::css` writes
  `background-position: center`).
- Style properties `aspect_ratio` (choice: 1, 4/3, 3/2, 16/9, 21/9, 3/4) and `object_fit` (cover | contain), per breakpoint,
  form fields only.
- Background: style `background` (colour token), `background_image`, `overlay` (`--tl-overlay`), `gradient`; the Section
  content field `background_video` (MP4/WebM from Media, not per breakpoint). Form fields only.
- The toolbar of an image has Replace (Media picker) and alt. A file dropped on the canvas is always inserted as a new element.
- Known gap: an image with a caption or a link renders `<figure>` / `<a>` around the `<img>` and the element's style lands on
  the wrapper, so `aspect_ratio` / `object_fit` crop the wrapper, not the photo.

#### C1. Crop mode: focal point and ratio of an image

| | |
|---|---|
| Opened by | Toolbar button **Crop** on a selected image (crosshair icon); double-click on an image; Enter on a selected image. |
| Canvas | The whole photo appears around the element box at its cover scale; the part outside the box is dimmed (the box is the crop frame and does not move). A crosshair marks the focal point. The rest of the overlay (handles, other toolbar buttons) hides. |
| Pointer | Press anywhere on the photo (also on the dimmed part) and drag: the point stays under the pointer. While dragging, the photo holds still and the bright window slides over it to show what will be visible; on release the photo settles so the window is back on the element box. Snaps to 0 / 50 / 100 % on each axis within 3 %; Alt = no snap. |
| Ratio | A strip in the toolbar: **Original**, 1:1, 4:3, 3:2, 16:9, 21:9, 3:4 (the existing `aspect_ratio` options). A ratio writes `aspect_ratio` and, if not set, `object_fit: cover`; Original removes `aspect_ratio`. |
| Keyboard | Arrows move the point by 5 %, Shift+arrows by 1 %, Home = centre, 1–7 pick a ratio, Enter = done, Esc = cancel (restores the state before crop mode). Live value in `aria-live`: "Focal point 30 %, 70 %". |
| Done | **Done** writes one `applyChange` (focal point and ratio together = one undo step). **Reset** removes `focal_point` (and `aspect_ratio`) in the current breakpoint. |
| Breakpoints | Writes the device state the switcher shows. A phone ratio of 3:4 under a desktop 16:9 is the common case. The toolbar shows a dot when the state overrides an inherited value (as A7). |
| Not offered | Locked elements, images inside components, `contain` fit (no crop: the button shows, disabled, "Shows the whole image"), SVG. |

Precedence of the point: placement in this breakpoint → placement inherited from a wider breakpoint → the file's own focal point
in Media → centre. The crop never cuts the file; Media keeps one original and its variants.

#### C2. Background from the canvas

A **Background** button in the toolbar of a section and of every container element (`TYPY[type].container`) opens a popover
(Popover API, inside the overlay layer, anchored to the button, closes on Esc / outside click):

| Tab | Controls | Writes |
|---|---|---|
| Colour | Swatches of the design-system colour tokens (read from the canvas, never recomputed), "none". A free colour stays in the panel. | `background` |
| Image | Choose from Media, Upload, the current image with **Set focal point** (crop mode on the background, no ratio strip), Overlay: none / darken / darken more / darken at the bottom. | `background_image`, `background_focal`, `overlay` or `gradient` |
| Video (section only) | Choose from Media (MP4/WebM), remove. Note: "Same video on every screen size. Hidden for visitors who ask for less motion; the image is shown instead." | content `background_video` |

Each control is one undo step. Style values go to the current breakpoint; the popover title says which ("Background · phone").
When the section's text colour fails contrast against the chosen colour (the builder check already computes this), the popover
shows the check's warning line; it never blocks.

#### C3. Replace by drop

Dragging files over the canvas keeps today's insertion line, with two exceptions:

| Drop target | Highlight | Result |
|---|---|---|
| One image file over an image element | The whole image outlined, label "Replace image · Shift: insert" | Upload, `content.src` = new file, placement `focal_point` removed in all states (it was for the old photo), ratio kept. |
| One image file over a section / container that has a `background_image` in the current state | The element outlined, label "Replace background · Shift: insert" | Upload, `background_image` of the current state = new file, `background_focal` removed. |
| One video file over a section that has a `background_video` | as above | `background_video` = new file. |
| Shift held, several files, locked element, other cases | insertion line | Insert, as today (A10). |

Alt text on replace (drop and the Replace button end in the same function): Media keeps an image's alt in its name (the description is a caption).
A file picked from Media takes its name as the alt when it has one, otherwise the old alt stays. A file just uploaded or dropped has only its file name,
so the old alt stays and the status line says "Image replaced. Check that the description still fits."; an empty alt is filled from the name. One drop =
one undo step; a failed upload changes nothing.

#### C4. Data model

| Property | Type | CSS | Allowed on | States |
|---|---|---|---|---|
| `focal_point` (new) | `focal`: `"x% y%"`, integers 0–100 | `--tl-focal` + `object-position` | image elements | base, tablet, mobile |
| `background_focal` (new) | `focal` | `background-position` (replaces the fixed `center`) and the section video's `object-position` | elements with a `background_image` or `background_video` | base, tablet, mobile |

- `Build::sanitize` drops them (reported) on other types and in hover/active states, and drops values outside 0–100.
- `ImageHtml::complete` writes the file's point as `object-position: var(--tl-focal, x% y%)`, so a placement wins over the file
  and an inline style no longer beats the layered CSS. `Image::baseCss` lets the crop group (`aspect_ratio`, `object_fit`,
  `focal_point`) reach the `<img>` inside a `<figure>` / `<a>` (the known gap above).
- MCP: both properties reach `save_build` through `Style::PROPERTIES` and `ElementSchema` (builder style properties are not recorded in
  `tools/contracts`); `update_media` gains `focal_point` (`"x% y%"` or `""`, checked by `Style::focal`), recorded in the tool contract and
  the admin Media field's MCP counterpart (ParityTest).
- No migration: style is JSON in the build; `media.focal_point` exists.

#### C5. Non-goals of phase C

Zoom or free-rectangle crop (it would need scaled images or server-side crops), cutting or re-encoding files, per-item focal
points inside Gallery / Carousel / Before-after (the file's point applies there), writing the Media file's point from the canvas
(that change would be live at once, outside the draft), backgrounds on non-container elements, video poster editing.

### Non-goals

Absolute pixel positioning outside Compose; free rotation / skew; z-index outside Compose; vector drawing; real-time
multi-user editing; custom CSS editing on the canvas (stays in the panel).

## 4. Technical notes

- **Where the code goes:** the builder is native ES modules in `image/builder/` (#229, no bundler): the compose work is
  `overlay.js`, `handles.js`, `keys.js` and `compose.js`, which import what they use (`applyChange`, `find`, `state` from `core.js`,
  `previewDoc` from `canvas.js`) and share the toolkit `C` that `overlay.js` exports. `window.taleaBuilder` remains only as a handle
  for the browser test (`tools/test-browser.mjs`); no module reads it. Phase C adds `media.js` (crop mode, background popover,
  replace by drop): its toolbar buttons go through `C.extras`, the drop target through `overlay.js`'s `fileDrop`.
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
6. `Build::sanitize` rejects out-of-range grid lines and unknown z-index values; `tests/Unit/Builder/ComposeTest.php` has cases for
   each new property; the Vocabulary round-trip test passes; `php tools/contracts.php` shows only additions.
7. The saved page's output weight does not grow for pages that do not use Compose (Lighthouse budget in
   `tools/test-lighthouse.sh` still 99–100 on the starter sites).
8. Claude over MCP can create the same Compose placement (`save_build` / `edit_build` with the new properties).

Phase C:

9. With the mouse only: give a hero image a 16:9 crop on desktop and 3:4 on phone with the subject kept in view, put a photo
   behind a section with a darkening overlay and move its focal point, and replace an image by dropping a file on it. Each is
   one undo step; reloading shows the same result; the desktop crop is unchanged by the phone edit.
10. The same with keyboard only (crop mode arrows, ratio digits; the background popover by Tab) and in touch emulation.
11. Precedence: a placement point beats the Media file's point, which beats centre; an image with a caption or a link crops
    the photo, not the figure (`tests/Unit/Builder/StyleTest.php`, `tests/Site/` for `ImageHtml`).
12. `Build::sanitize` drops `focal_point` outside images, in hover states and out of range; `background_focal` without a
    background; `tests/Unit/Builder/ComposeTest.php` (or a new `MediaOnCanvasTest.php`) has a case for each.
13. Over MCP, `save_build` with `focal_point` / `background_focal` and `update_media` with `focal_point` give the same output
    as the editor; `php tools/contracts.php` shows only additions.
14. Pages that use none of it get no new per-element CSS; the Image base CSS gains one rule and a linked image without a caption carries `tl-linked`.

## 7. Milestones

| Milestone | Contents | Size |
|---|---|---|
| M1 | A9 pointer-event base, overlay layer, A7 breakpoint switcher with override marks | M |
| M2 | A1 spacing handles, A2 resize handles, A3 column dividers | L |
| M3 | A4 floating toolbar, A5 multi-select / group, A6 guides | L |
| M4 | A8 keyboard, A10 file drop, browser tests for acceptance 1–3, 5 | M |
| M5 | Phase B Compose section: style properties, sanitizer, vocabulary, contracts, editor placement, tidy-up | XL |
| M6 | Phase C data: `focal_point`, `background_focal`, sanitize, CSS, `ImageHtml` precedence, figure fix, Vocabulary, contracts, `update_media` | S |
| M7 | C1 crop mode (pointer, keyboard, ratio strip), browser tests 9–10 for crop | M |
| M8 | C2 background popover, C3 replace by drop, browser tests 9–10 for both (built) | M |

M1–M4 ship without any data-model change and can be released incrementally.

## 8. Open questions

1. Should Compose allow overlap at all (decorative images over text), or only free placement without overlap? Overlap is
   what makes pages look designed, and what makes mobile hard.
2. Row unit in Compose: fixed height per row (predictable) or content-sized rows with free spans?
3. Does multi-select group move apply across different parents, or only among siblings?
4. Hold the 12-column grid, or let the design system set the column count (also for the snap points)?
5. ~~Should phase C focal point also apply to existing images in news text and collections?~~ Settled: the Media file's
   point already applies everywhere (`ImageHtml::complete`); phase C adds a per-placement point for builder images only.
6. Crop mode on a Gallery / Carousel item: open it on the item and write the Media file's point (live, outside the draft), or
   leave galleries to Media → edit? The spec leaves them out (C5).
7. Should "Replace image" also offer "Replace everywhere" (`Media::actionReplace`, swaps the file under every use)? It is
   live at once, so it would need its own confirmation.
