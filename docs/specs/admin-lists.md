# Admin lists

The design every admin overview follows (pages, news, enquiries, collections, components, pop-ups, redirects, users,
bookings and the rest). The approved mock-ups are the canvas "Seitenübersicht Redesign" (Claude design artifact
`QENHvGKpeTQaVHNiTH6uQn`); this file is the written contract the code follows.

## Building blocks

1. **Page head.** The layout's `<h1>` with a weak count next to it ("19 pages"). On the right: quiet links to related
   screens (Categories, Roles, Fields and settings), at most one secondary button (Import, Export) and exactly one primary
   button (New …). Lists never print their own `navigation-row` above the table any more.
2. **Toolbar.** Left: status tabs as a segmented control, every tab with its count; the trash is a tab, not a separate
   link. Right: at most two filter selects and a search field with a magnifier icon. Search submits on Enter; there is no
   "Filter" button (a visually hidden submit button stays for keyboard and screen-reader users). Selects submit on change.
3. **Row.** Optional grip (only where order matters), optional checkbox, the **main cell** (title on one line, cut with an
   ellipsis, full text in `title`; a weak sub-line below: address, category, e-mail, field summary), a few meta columns,
   the status cell, the actions cell. Row height 64 px, no zebra stripes, no coloured bar on the left.
4. **Status.** Always a dot plus a word, never colour alone. A note in a second line under it when something needs
   attention ("Changes not published", "2 broken links", "check by 01.11."). Vocabulary:

   | Kind      | Dot colour token     | Used for                                         |
   |-----------|----------------------|--------------------------------------------------|
   | `live`    | `--ok`               | published, visible, confirmed, on                |
   | `hidden`  | `--warning-text`     | hidden, requested, needs a check                 |
   | `draft`   | `--text-weak`        | draft                                            |
   | `planned` | `--planned` (new)    | scheduled                                        |
   | `review`  | `--handover-text`    | awaiting publication                             |
   | `error`   | `--error`            | failed, broken                                   |
   | `off`     | `--line-field`       | cancelled, inactive, switched off                |

   Unread items get a `--signal` dot in front of the name, not a status. Pills (`.badge`) remain only for exceptions
   ("Text" on a page without the builder, "automatic" on a redirect the site created).
5. **Row actions.** At most two visible icon buttons (36 × 36): the pencil (opens the main editor; for builder content the
   builder) and the "⋯" button that opens a menu (Popover API). One row type may replace the pencil's neighbour with a
   one-click action that is the point of the list (✓ resolve an enquiry, ✓ confirm a booking request: filled when it waits
   for the user). The menu holds everything else; destructive items come last, after a separator, in `--error`, and keep
   their `data-confirm`. Where an item cannot be deleted (the home page) the menu says why instead.
6. **Selection bar.** Appears only while at least one row is selected, sticks to the bottom of the viewport, shows
   "3 selected" and one button per bulk action (the primary one filled, destructive in red) plus "Clear selection". It
   replaces "With selected: [select] Apply". Built with CSS (`:has()`, counters), the existing select-all script stays.
7. **Attention box.** Above the list, only when something waits for the user (404 addresses with a suggestion, items
   to approve). One row per item with its one-click action; disappears when empty.
8. **Empty state.** The existing `admin/empty` partial. A search with no result says "Nothing matches" with
   "Clear filter".

## Rules

- Dates younger than seven days are relative ("2 hours ago", "yesterday", "3 days ago"), older ones `format_date()`.
- The title link opens the same target as the pencil.
- A switch (`role="switch"` on a `<button>` inside a POST form) replaces "Turn on/Turn off" links (pop-ups, add-ons).
- Wide tables scroll inside their box; on phones (≤ 640 px) the existing card layout of `table.listing` stays.
- Nothing changes for MCP or the POST contracts: actions keep their URLs, field names and confirmations.

## Out of scope (own follow-ups)

- Drag-and-drop ordering (grips in the mock-ups for collection items and categories): needs reorder actions and MCP parity.
- A "changed" column for news (its `updated_at` means "substantially updated", not "edited").
