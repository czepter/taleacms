# Media library

The design of Admin → Media and of the image picker in the editor. The approved mock-ups are the canvas "Media library
rework" (Claude design artifact `3E1cZn7Q77LgfNy5CNn2xF`); this file is the written contract the code follows. It builds on
`docs/specs/admin-lists.md` (page head, toolbar, selection bar, row menu) and on the admin design system (tokens in
`image/admin.css`).

## Problems it solves

- Every tile carries a description field, a checkbox, a "description" link and a usage line: the grid is noise.
- Editing an image reloads the page and opens a form under the whole grid.
- The upload box takes the top of the screen whether you upload or not.
- Folders are one flat list; a photographer with one folder per story ends up with 30 long names in the sidebar.
- Pagination is `[1] 2 3`; "Filter" is a button; there is no type filter and no table view.
- "Move to folder" deleted files (`bulk=presun` never matched `move`): fixed separately, before this work.

## Levels: folders on top, files below

Folders can hold folders (`media_folders.parent_id`). Every level of the library shows two sections:

1. **Folders** – a card per folder of this level (a mosaic of its three newest images, the name, "4 folders · 69 files",
   "Changed …" from its newest file, "12 without description" in `--warning-text` when images in it lack one, a "⋯" menu:
   Rename, Move, Delete) and a dashed "New folder" card.
2. **Files** – the files directly in this level: "Not in a folder" at the root ("All media"), "Files in <folder>" inside a
   folder.

Counts are direct: a folder's files are the files whose `folder_id` is that folder, not its subfolders'. The breadcrumb
("All media / Weddings / Castle wedding") shows the path; every part but the last is a link.

The views that cut across folders (Recently uploaded, Missing description, Unused, Waiting for approval, a search) show
only the files section, over the whole library.

## Screen

- **Page head:** `<h1>` Media, the guide link, "New folder" (secondary, opens a popover form; the folder is created in
  the current level), "Upload" (primary).
- **Left menu** (`nav.media-rail`): All media (total), Recently uploaded (last 7 days), a rule, Missing description (images
  with an empty name, `--warning-text` dot), Unused, Waiting for approval (only when there are any, `--handover-text`
  dot), Clean up; then the heading "Folders" with a "+" button, a "Find a folder" field (filters the tree in the browser)
  and the folder tree: the branch of the current folder is open, every other folder with subfolders shows a closed
  arrow; a folder link shows its direct file count.
- **Toolbar:** search (submits on Enter, no Filter button), type chips (Images, Documents, Video, Audio – one at a time,
  links that toggle `type`), sort select (submits on change), view switch Grid | Table.
- **Grid tile:** the thumbnail is the link to the details (`item=`); a checkbox top left; flags on the image: "No
  description" (image with empty name), "Unused", "Waiting for approval"; below: the name (or the file name in weak
  italics when the name is empty) and "2000×1444 · 231 kB · used 2×".
- **Table view:** checkbox, a 56 × 42 preview, file name + folder, the description as an input that saves on change and on
  Enter (then focuses the next row's input; the existing `data-description-media` script), Used, Size, a chevron that
  opens the details. "Missing description" opens in the table view by default.
- **Show more** instead of pages: "Showing 40 of 69" and a link that raises `show` by 40 (max 1000).
- **Selection bar:** the shared one of `admin-lists.md` (CSS `:has()` and counters): "3 selected", Move to folder (a
  select of the tree, names indented by level, plus the button), Delete (red, `data-confirm`, used files are skipped as
  today), Clear selection.
- **Details panel** (`?item=<public id>`, server-rendered, an `<aside>`; the grid stays usable; from 1400 px it sits beside the
  files as a third column, between 861 and 1399 px below them): file
  name, previous/next (within the current list), close; the preview with the focal point (existing `data-focal`);
  Replace file (JPG/PNG/WebP, not while waiting for approval), Download (`<a download>`), Copy address (`data-copy`);
  Description (alt), Caption, Image credit, Folder (select); "Used in N places" (the existing usage finder; each place
  with its kind); file info (file, dimensions, size, uploaded, address); Save; Delete – disabled with "The file is used in
  N places. Remove it there first to delete it." while used.
- **Phone width (up to 860 px):** one column in the order files, details panel, left menu - the files come first.

## Upload

- Dropping files anywhere on the Media screen uploads them into the current folder; while files are dragged over the
  page an overlay says "Drop to upload to <folder>" with the formats line. Dropping on a folder card uploads into that
  folder. The Upload button opens the file chooser. Without JavaScript the hidden form still posts.
- An upload tray (bottom right; minimise, close) lists each file: uploaded (✓), progress, waiting, skipped ("Already in
  Media as <file> · Open it" – same size and sha1 as an existing file), failed (the reason). Files go one at a time
  through `upload?format=json`. When at least one image arrived: "Describe the new images" opens Recently uploaded in the
  table view.

## Drag and drop into folders

- Tiles (the selection, or the one tile dragged when it is not selected) and folder cards are draggable.
- Drop targets: folder cards, folders in the left tree, the breadcrumb links (a level up). While dragging, targets get a
  dashed outline; the one under the pointer is outlined in ink with "Move N files here". Esc cancels.
- A folder cannot be dropped on itself or into its own subfolders (refused by the server too). Moving a folder (drop, the Move
  form, `move_media_folder`, a `parent` in `save_media_folder`) is for editors and administrators; creating and renaming stay open.
  A move whose target folder no longer exists is refused, never taken for the top level.
- A file is dragged by its tile (in the table by its picture and name), a folder by the picture and name of its card - never by
  the forms of its menu, whose fields must stay selectable.
- After the drop the page reloads with a message "3 files moved to Castle wedding." and an Undo button (moves them back
  to the folder they came from - only those still in the folder the move put them in, so a later change is not overwritten). A
  move in which nothing could be moved says "Nothing was moved." The keyboard way stays: select, then Move to folder in the selection bar.

## Picker in the editor

The image picker (`image/editor.js`, `media/listing`) gets the same left menu (All media, Recently uploaded, Used on
this page is out of scope, the folder tree), search, the grid, and a side column with the selected image and its
description field: an image without a description asks for one, and it is saved to Media before inserting.

## Data and server

- Migration: `media_folders.parent_id` (nullable, foreign key to `media_folders.folder_id`, `ON DELETE SET NULL`).
- `Core\MediaFolders` (new; `Core\MediaFolder` is the owner's directory and stays as it is): the tree (`all()` with
  `parent_id`, direct file count, missing-description count, newest file date), `thumbnails()` (the three newest thumbnails of a
  level's folders in one query), `path()`
  (breadcrumb), `descendants()`, `move()` (refuses a cycle; the check and the update run in one transaction under a lock, and it can rename in
  the same step), `delete()` (its files and subfolders move to its parent, then
  the row goes).
- URL parameters (English): `folder` (public id; `0` = not in a folder; replaces `section`), `view=table`, `type`
  (`image|document|video|audio`), `recent=1`, `missing=1`, `unused=1`, `waiting=1`, `search`, `sort`, `show`, `item`.
- Actions: `folder` (create/rename, takes `parent`), `folder_move`, `folder_delete`, `bulk` (`move`/`delete`;
  `format=json` for drag and drop), `undo_move`, `upload` (unchanged, plus the duplicate check), `save`, `save_caption`,
  `replace`.
- MCP: `list_media_folders` returns `parent` and `path`; `save_media_folder` takes `parent`; `delete_media_folder`
  moves the content up; a new `move_media_folder`. `save_media_folder` with an `id` moves first (a `parent` that is given, empty = top
  level, null = leave) and renames in the same step. Contract `tools/contracts/mcp-tools.json`; the parity map in
  `tests/Unit/Mcp/ParityTest.php` lists every new admin action.

## Phases (one stacked PR each)

1. `fix/media-bulk-move` – the bulk Move button sends `move`, the controller accepts only `move` and `delete`.
2. `feat/media-folder-tree` – migration, `Core\MediaFolders`, admin folder actions with `parent`, MCP tools, tests.
3. `feat/media-library-rework` – the screen above (levels, menu, toolbar, grid, table, details panel, selection bar,
   show more), CSS in `image/admin.css`, translations, site tests.
4. `feat/media-upload-drag` – the drop overlay, upload tray, duplicate check, drag and drop into folders with Undo.
5. `feat/media-picker` – the editor's picker.

Out of scope: bulk download as ZIP, crop/rotate, upload from a web address, linking "Used in" rows to their editors.
