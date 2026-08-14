# Controlled Book Editor behavioral parity gate

Baseline: `44df02b9`

This is a behavior freeze, not a source-byte freeze. The gate executes the
baseline and current editor JavaScript in Chromium against identical,
deterministic editor/API fixtures. Every named row has an explicit expected
assertion; normalized observations and relevant API payloads must then match.
There are no retries or skips, and any missing scenario, assertion, browser
error, failed expectation, or baseline/current difference fails closed.

Run from the repository root:

`php tests/controlled_book_editor_44df02b9_parity_gate_check.php`

| Feature | Required baseline behavior | Current contract | State | Named regression test |
|---|---|---|---|---|
| Toolbar structure | Controls retain rendered order | Executable behavioral parity | Required | `toolbar.structure_and_order` |
| Toolbar commands | Commands target active source selection/block | Executable behavioral parity | Required | `toolbar.all_commands_target_source` |
| Menus and popovers | Menus expose the same actionable options | Executable behavioral parity | Required | `toolbar.menus_and_popovers` |
| Paragraph typing | One live source paragraph mutates and saves | Executable behavioral parity | Required | `paragraph.typing_single_source_object` |
| Paragraph Enter | Browser Enter mutates source paragraph structure | Executable behavioral parity | Required | `paragraph.enter` |
| Paragraph Shift+Enter | Browser soft break remains in one paragraph | Executable behavioral parity | Required | `paragraph.shift_enter` |
| Paragraph across pages | One paragraph, selection, and history identity | Executable behavioral parity | Required | `paragraph.cross_page_identity` |
| Heading editing | Heading field edits through original save path | Executable behavioral parity | Required | `heading.edit_and_save` |
| Heading Enter | Browser Enter mutates the heading source field | Executable behavioral parity | Required | `heading.enter` |
| Heading numbering | Style change refreshes numbering and payload | Executable behavioral parity | Required | `heading.numbering` |
| Text selection | Selection range belongs to one source block | Executable behavioral parity | Required | `selection.cross_page_source_range` |
| Copy | Selected source text is copied without mutation | Executable behavioral parity | Required | `clipboard.copy` |
| Paste | Pasted text mutates and saves the source | Executable behavioral parity | Required | `clipboard.paste` |
| Text formatting | Formatting targets selection and persists | Executable behavioral parity | Required | `formatting.all_text_commands` |
| Undo | Source snapshot is restored | Executable behavioral parity | Required | `history.undo` |
| Redo | Undone source snapshot is reapplied | Executable behavioral parity | Required | `history.redo` |
| Bullet list display | One `<ul>` owns multiple source items | Executable behavioral parity | Required | `list.bullet_visual_identity` |
| Numbered list display | One `<ol>` owns numbering and items | Executable behavioral parity | Required | `list.numbered_visual_identity` |
| List Enter | Browser Enter splits within the same list | Executable behavioral parity | Required | `list.enter` |
| Empty-item exit | Empty item exits into continuation source | Executable behavioral parity | Required | `list.empty_item_exit` |
| List Shift+Enter | Soft exit preserves one source list block | Executable behavioral parity | Required | `list.shift_enter` |
| List indent | Selected item level increments and saves | Executable behavioral parity | Required | `list.indent` |
| List outdent | Selected item level decrements and saves | Executable behavioral parity | Required | `list.outdent` |
| Nested lists | Item hierarchy persists in source payload | Executable behavioral parity | Required | `list.nested_structure` |
| Ordered-list start | Start value mutates DOM and payload | Executable behavioral parity | Required | `list.ordered_start` |
| List copy/paste | Multi-item content remains one source list | Executable behavioral parity | Required | `list.copy_paste_multiple_items` |
| List block actions | One action chrome belongs to source list | Executable behavioral parity | Required | `list.single_block_actions` |
| Insert block | API insertion, placement, and focus agree | Executable behavioral parity | Required | `block.insert` |
| Delete block | Source block and API identity are deleted | Executable behavioral parity | Required | `block.delete` |
| Move block up | Up direction and source identity persist | Executable behavioral parity | Required | `block.move_up` |
| Move block down | Down direction and source identity persist | Executable behavioral parity | Required | `block.move_down` |
| Insert paragraph below | New block follows source and receives focus | Executable behavioral parity | Required | `block.insert_paragraph_below` |
| Table identity | One source table owns rows, columns, and tools | Executable behavioral parity | Required | `table.single_source_object` |
| Table cell editing | Cell edit persists through table payload | Executable behavioral parity | Required | `table.cell_edit` |
| Add row | Complete source table gains and saves a row | Executable behavioral parity | Required | `table.add_row` |
| Delete row | Selected row is removed and payload shrinks | Executable behavioral parity | Required | `table.delete_row` |
| Add column | Colgroup, header, rows, and payload expand | Executable behavioral parity | Required | `table.add_column` |
| Delete column | Colgroup, header, rows, and payload shrink | Executable behavioral parity | Required | `table.delete_column` |
| Column resize | Drag changes colgroup and saved widths | Executable behavioral parity | Required | `table.column_resize` |
| Title row | Title-row control and persistence agree | Executable behavioral parity | Required | `table.title_row` |
| Header row | Header edit remains one persisted header row | Executable behavioral parity | Required | `table.header_row` |
| Repeated header | Presentation does not duplicate source header | Executable behavioral parity | Required | `table.repeated_header_is_presentation_only` |
| Merged cells | Merge changes DOM span and payload span | Executable behavioral parity | Required | `table.merged_and_spanned_cells` |
| Table formatting | Selected cell formatting persists | Executable behavioral parity | Required | `table.formatting` |
| Table cell copy/paste | Tabular paste updates cells and payload | Executable behavioral parity | Required | `table.cell_copy_paste` |
| Table undo/redo | Table snapshot removes and restores mutation | Executable behavioral parity | Required | `table.undo_redo` |
| Callout identity | One editable source callout exists | Executable behavioral parity | Required | `callout.single_source_object` |
| Callout editing | Title/text edits persist together | Executable behavioral parity | Required | `callout.edit_and_save` |
| Callout type | Type switch mutates class, type, and payload | Executable behavioral parity | Required | `callout.type_switch` |
| Image upload | Browser file upload inserts returned source block | Executable behavioral parity | Required | `image.upload` |
| Image resize | Drag changes figure width and payload | Executable behavioral parity | Required | `image.resize` |
| Image rotate | Control rotates DOM and payload by 90 degrees | Executable behavioral parity | Required | `image.rotate` |
| Image caption | Caption edit persists as image alt payload | Executable behavioral parity | Required | `image.caption` |
| Figure controls | One figure exposes rotate, resize, caption | Executable behavioral parity | Required | `figure.all_controls` |
| Fields | Settings and variable binding persist | Executable behavioral parity | Required | `field.all_controls` |
| Cover tools | Cover field edit uses cover save path | Executable behavioral parity | Required | `special.cover` |
| TOC tools | Regeneration operates on rendered TOC | Executable behavioral parity | Required | `special.toc` |
| LEP tools | LEP edit/save and approval state agree | Executable behavioral parity | Required | `special.lep` |
| Part 0 tools | Structured field edit persists page/headings | Executable behavioral parity | Required | `special.part0` |
| Annex tools | Annex navigation loads selected section | Executable behavioral parity | Required | `special.annex` |
| Save payloads | Complete logical source payload is serialized | Executable behavioral parity | Required | `save.identical_payloads` |
| Save timing | One 700 ms debounce updates status and saves | Executable behavioral parity | Required | `save.timing_and_status` |
| Focus retention | Debounced save retains active source field | Executable behavioral parity | Required | `editing.focus_retention` |
| Scroll retention | Debounced save retains real scroll offset | Executable behavioral parity | Required | `editing.scroll_retention` |
| Manual page break | Additive break leaves one edited source | Executable behavioral parity | Required | `pagination.manual_break_only_addition` |
| Page furniture | Furniture is present and non-editable | Executable behavioral parity | Required | `pagination.page_furniture_only_addition` |
| Presentation copies | No duplicate semantic IDs reach payload | Executable behavioral parity | Required | `pagination.presentation_copies_excluded` |

## Scope and architecture

The harness renders the baseline/current editor shell extracted fail-closed from
the PHP template, loads each variant's real editor CSS and JavaScript, and
asserts rendered control order, mode visibility, and relevant computed layout.
The PHP template itself is not executed: its editor-root HTML region is
extracted, PHP tags are removed, dynamic IDs are normalized, and the generated
list options/form-only controls are injected deterministically. PHP
service/database internals are also not executed. Editor API responses are
mocked deterministically, while actual baseline/current client requests and
payloads are recorded, normalized, and compared. Server-side API behavior still
requires separate PHP integration contracts.

All four editor files may change after Phase A:

- `public/assets/controlled_book_editor.js`
- `public/assets/controlled_book_editor.css`
- `public/admin/compliance/controlled_book_editor.php`
- `public/admin/api/controlled_book_editor_api.php`

Their former architecture byte-hash assertions were removed. JavaScript,
rendered shell behavior, CSS layout, and client API payloads are guarded by this
behavioral gate; server-side PHP API execution remains covered only by separate
integration contracts.
