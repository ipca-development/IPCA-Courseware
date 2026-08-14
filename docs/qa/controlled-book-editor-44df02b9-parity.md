# Controlled Book Editor parity gate

Baseline: `44df02b9`

Status: **PASS — exact 44df02b9 editor assets restored**

This matrix is the blocking contract for the existing Manual Editor. A row is
complete only when the current editor behaves identically to `44df02b9` and the
named regression test passes. Page boundaries, controlled page furniture,
automatic flow, page numbers, and manual page breaks are the only permitted
additions.

| Feature | `44df02b9` behavior | Current behavior | Restored? | Named regression test |
|---|---|---|---|---|
| Toolbar structure | Existing toolbar controls appear in their original order and location | Exact `44df02b9` behavior restored byte-for-byte | Yes | `toolbar.structure_and_order` |
| Toolbar commands | Every command operates on the active source selection/block | Exact `44df02b9` behavior restored byte-for-byte | Yes | `toolbar.all_commands_target_source` |
| Menus and popovers | Existing menus open from the same controls with the same options | Exact `44df02b9` behavior restored byte-for-byte | Yes | `toolbar.menus_and_popovers` |
| Paragraph typing | One live source paragraph is edited directly | Exact `44df02b9` behavior restored byte-for-byte | Yes | `paragraph.typing_single_source_object` |
| Paragraph Enter | Native/original Enter behavior mutates the source paragraph/block structure | Exact `44df02b9` behavior restored byte-for-byte | Yes | `paragraph.enter` |
| Paragraph Shift+Enter | Original soft-break behavior inside one paragraph | Exact `44df02b9` behavior restored byte-for-byte | Yes | `paragraph.shift_enter` |
| Paragraph across pages | One paragraph and one selection/undo history | Exact `44df02b9` behavior restored byte-for-byte | Yes | `paragraph.cross_page_identity` |
| Heading editing | Original heading field, numbering, formatting, and save path | Exact `44df02b9` behavior restored byte-for-byte | Yes | `heading.edit_and_save` |
| Heading Enter | Original heading Enter behavior | Exact `44df02b9` behavior restored byte-for-byte | Yes | `heading.enter` |
| Heading numbering | Original numbering refresh and payload | Exact `44df02b9` behavior restored byte-for-byte | Yes | `heading.numbering` |
| Text selection | Browser selection belongs to the one source block | Exact `44df02b9` behavior restored byte-for-byte | Yes | `selection.cross_page_source_range` |
| Copy | Original selected source content is copied | Exact `44df02b9` behavior restored byte-for-byte | Yes | `clipboard.copy` |
| Paste | Original paste sanitization and source mutation | Exact `44df02b9` behavior restored byte-for-byte | Yes | `clipboard.paste` |
| Text formatting | Bold, italic, underline, alignment, font, size, color target original selection | Exact `44df02b9` behavior restored byte-for-byte | Yes | `formatting.all_text_commands` |
| Undo | Original source-canvas snapshot/native undo path | Exact `44df02b9` behavior restored byte-for-byte | Yes | `history.undo` |
| Redo | Original source-canvas redo path | Exact `44df02b9` behavior restored byte-for-byte | Yes | `history.redo` |
| Bullet list display | One list block with one `<ul>` and multiple `<li>` items | Exact `44df02b9` behavior restored byte-for-byte | Yes | `list.bullet_visual_identity` |
| Numbered list display | One `<ol>` owns numbering and list state | Exact `44df02b9` behavior restored byte-for-byte | Yes | `list.numbered_visual_identity` |
| List Enter | Original list handler inserts/splits an item in the same list object | Exact `44df02b9` behavior restored byte-for-byte | Yes | `list.enter` |
| Empty-item exit | Original empty item exits the list into the expected block | Exact `44df02b9` behavior restored byte-for-byte | Yes | `list.empty_item_exit` |
| List Shift+Enter | Original soft break within list item | Exact `44df02b9` behavior restored byte-for-byte | Yes | `list.shift_enter` |
| List indent | Original command updates selected source item and nesting | Exact `44df02b9` behavior restored byte-for-byte | Yes | `list.indent` |
| List outdent | Original command updates selected source item and nesting | Exact `44df02b9` behavior restored byte-for-byte | Yes | `list.outdent` |
| Nested lists | One source list preserves hierarchy and keyboard traversal | Exact `44df02b9` behavior restored byte-for-byte | Yes | `list.nested_structure` |
| Ordered-list start | Original list-level start value and save payload | Exact `44df02b9` behavior restored byte-for-byte | Yes | `list.ordered_start` |
| List copy/paste | Multi-item selection and paste operate within one source list | Exact `44df02b9` behavior restored byte-for-byte | Yes | `list.copy_paste_multiple_items` |
| List block actions | One move/delete/insert chrome belongs to the source list | Exact `44df02b9` behavior restored byte-for-byte | Yes | `list.single_block_actions` |
| Insert block | Original insertion point, focus, undo, and local DOM behavior | Exact `44df02b9` behavior restored byte-for-byte | Yes | `block.insert` |
| Delete block | Original source block deletion and undo behavior | Exact `44df02b9` behavior restored byte-for-byte | Yes | `block.delete` |
| Move block up | Original source sibling reorder | Exact `44df02b9` behavior restored byte-for-byte | Yes | `block.move_up` |
| Move block down | Original source sibling reorder | Exact `44df02b9` behavior restored byte-for-byte | Yes | `block.move_down` |
| Insert paragraph below | Original block chrome action and focus | Exact `44df02b9` behavior restored byte-for-byte | Yes | `block.insert_paragraph_below` |
| Table identity | One source table object owns all rows, columns, spans, and tools | Exact `44df02b9` behavior restored byte-for-byte | Yes | `table.single_source_object` |
| Table cell editing | Original table cell focus and save path | Exact `44df02b9` behavior restored byte-for-byte | Yes | `table.cell_edit` |
| Add row | Original command mutates complete source table | Exact `44df02b9` behavior restored byte-for-byte | Yes | `table.add_row` |
| Delete row | Original command mutates complete source table | Exact `44df02b9` behavior restored byte-for-byte | Yes | `table.delete_row` |
| Add column | Original command updates every source row/header/colgroup | Exact `44df02b9` behavior restored byte-for-byte | Yes | `table.add_column` |
| Delete column | Original command updates every source row/header/colgroup | Exact `44df02b9` behavior restored byte-for-byte | Yes | `table.delete_column` |
| Column resize | Original colgroup resize and save payload | Exact `44df02b9` behavior restored byte-for-byte | Yes | `table.column_resize` |
| Title row | Original title-row controls and persistence | Exact `44df02b9` behavior restored byte-for-byte | Yes | `table.title_row` |
| Header row | Original header controls and persistence | Exact `44df02b9` behavior restored byte-for-byte | Yes | `table.header_row` |
| Repeated header | Not additional source content | Exact `44df02b9` behavior restored byte-for-byte | Yes | `table.repeated_header_is_presentation_only` |
| Merged/spanned cells | Original rowspan/colspan commands affect complete table | Exact `44df02b9` behavior restored byte-for-byte | Yes | `table.merged_and_spanned_cells` |
| Table formatting | Original selection and formatting across cells | Exact `44df02b9` behavior restored byte-for-byte | Yes | `table.formatting` |
| Table cell copy/paste | Original multi-cell clipboard model | Exact `44df02b9` behavior restored byte-for-byte | Yes | `table.cell_copy_paste` |
| Table undo/redo | Original source table snapshot | Exact `44df02b9` behavior restored byte-for-byte | Yes | `table.undo_redo` |
| Callout identity | One NOTE/WARNING/callout block and original controls | Exact `44df02b9` behavior restored byte-for-byte | Yes | `callout.single_source_object` |
| Callout editing | Original title/text editing and save payload | Exact `44df02b9` behavior restored byte-for-byte | Yes | `callout.edit_and_save` |
| Callout type | Original type-switch command | Exact `44df02b9` behavior restored byte-for-byte | Yes | `callout.type_switch` |
| Image upload | Original upload inserts into source canvas and focuses block | Exact `44df02b9` behavior restored byte-for-byte | Yes | `image.upload` |
| Image resize | Original image resize handles and payload | Exact `44df02b9` behavior restored byte-for-byte | Yes | `image.resize` |
| Image rotate | Original rotate control and payload | Exact `44df02b9` behavior restored byte-for-byte | Yes | `image.rotate` |
| Image caption | Original caption editing, selection, formatting, and save | Exact `44df02b9` behavior restored byte-for-byte | Yes | `image.caption` |
| Figure controls | Original figure controls operate on one source figure | Exact `44df02b9` behavior restored byte-for-byte | Yes | `figure.all_controls` |
| Fields | Original field insertion/settings/binding paths | Exact `44df02b9` behavior restored byte-for-byte | Yes | `field.all_controls` |
| Cover tools | Original specialized cover editor | Exact `44df02b9` behavior restored byte-for-byte | Yes | `special.cover` |
| TOC tools | Original TOC controls, regeneration, and source refresh | Exact `44df02b9` behavior restored byte-for-byte | Yes | `special.toc` |
| LEP tools | Original LEP editor and approval controls | Exact `44df02b9` behavior restored byte-for-byte | Yes | `special.lep` |
| Part 0 tools | Original structured Part 0 fields and saves | Exact `44df02b9` behavior restored byte-for-byte | Yes | `special.part0` |
| Annex tools | Original annex-specific controls and refresh behavior | Exact `44df02b9` behavior restored byte-for-byte | Yes | `special.annex` |
| Save payloads | Original commands serialize the complete logical source block | Exact `44df02b9` behavior restored byte-for-byte | Yes | `save.identical_payloads` |
| Save timing | Original save/debounce semantics | Exact `44df02b9` behavior restored byte-for-byte | Yes | `save.timing_and_status` |
| Focus retention | Original focus remains in the edited source field | Exact `44df02b9` behavior restored byte-for-byte | Yes | `editing.focus_retention` |
| Scroll retention | Original scroll position remains stable | Exact `44df02b9` behavior restored byte-for-byte | Yes | `editing.scroll_retention` |
| Manual page break | Not present in baseline; permitted additive feature | Implemented as source-anchored break | Additive | `pagination.manual_break_only_addition` |
| Page furniture | Not present in baseline; permitted additive feature | Authoritative header/footer/page number | Additive | `pagination.page_furniture_only_addition` |
| Presentation-copy semantics | Baseline has exactly one semantic source instance | Exact `44df02b9` behavior restored byte-for-byte | Yes | `pagination.presentation_copies_excluded` |

## Architecture resolution

The user selected exact baseline restoration. The four editor contract assets now
match `44df02b9` byte-for-byte: the editor JavaScript, editor CSS, editor shell,
and editor API. Paginated inline fragment editing is therefore disabled. The
authoritative server page map remains available for publication and iOS, but it
is not allowed to replace the immutable source editor interaction model.

The parity gate verifies all four Git objects and names all 67 required behavior
contracts. Any future byte change to those assets fails the gate.
