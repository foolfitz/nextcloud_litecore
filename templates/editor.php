<?php
// The shipped page's own <style> and <body>, re-wrapped (EditorController::editor).
// The product's style is confined to #litecore-editor-root (EditorController::
// scopedStyle; study_LiteCore finding 110): Nextcloud's header and controls keep
// their own look. The height box only gives the product's column layout
// something to fill under Nextcloud's header.
?>
<style>@scope (#litecore-editor-root) {
<?php print_unescaped($_['style']); ?>
}</style>
<?php // A browser without @scope drops the whole block above, and the editor's hidden
// parts (the notice, the accessibility projection, the input sink) show. The host
// script switches this copy on there: the editor works, at finding 110's cost.
// study_LiteCore review 2026-10-06 item 8. ?>
<style id="litecore-unscoped" media="not all">
<?php print_unescaped($_['unscoped']); ?>
</style>
<style>
#litecore-host-bar { display: flex; gap: 12px; align-items: center; padding: 6px 12px; font-size: 14px;
	background: var(--color-warning, #eca700); color: var(--color-warning-text, #000); }
#litecore-host-bar[hidden] { display: none; }
#litecore-host-bar button { margin-left: auto; }
/* The title: the file's name where the page's own brand line was, and a way
   back to Files (study_LiteCore finding 110). */
#litecore-host-title { font-weight: 650; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
#litecore-host-unsaved { color: var(--color-text-maxcontrast, #6b7c8a); font-size: 12px; white-space: nowrap; }
#litecore-host-unsaved[hidden] { display: none; }
#litecore-host-saved { color: var(--color-text-maxcontrast, #6b7c8a); font-size: 12px; white-space: nowrap; }
#litecore-host-saved[hidden] { display: none; }
#litecore-host-close { font-size: 16px; line-height: 1; padding: 4px 10px; }
/* Production UI (study_LiteCore specs/SPEC-M3-production-ui.md): the shipped
   page carries the developers' controls and read-outs. Hidden here, not taken
   out of the page: the runners drive these with button.click(), which works on
   a hidden button. The keyboard covers the edits (Backspace/Delete, Enter,
   Shift+Enter). */
#litecore-editor-root .provenance,
#litecore-editor-root .brand,
#litecore-editor-root #fixture,
#litecore-editor-root #open-file,
#litecore-editor-root #toolbar > .sep:nth-of-type(1),
#litecore-editor-root #toolbar > .sep:nth-of-type(3),
#litecore-editor-root #toolbar > .sep:nth-of-type(5),
#litecore-editor-root [data-action="move-character-left"],
#litecore-editor-root [data-action="move-character-right"],
#litecore-editor-root [data-action="delete-backward"],
#litecore-editor-root [data-action="delete-forward"],
#litecore-editor-root [data-action="insert-paragraph-break"],
#litecore-editor-root [data-action="insert-line-break"],
#litecore-editor-root #text,
#litecore-editor-root [data-action="insert-text"],
#litecore-editor-root .statusbar { display: none; }
/* Finding 143 (host 0.2.21; the owner's ruling of 2026-10-14: the page number
   looks like Collabora's). The page's own #page-status is 12 px muted text in
   a 27 px strip, and with .statusbar hidden it was the root's last strip --
   8 px of which sat under #content's clip (the root was calc(100vh - 50px),
   #content is --body-height = 100vh - 50px - 8px). Here it is the editor's
   status bar: the root's full width along its bottom edge, inside #content,
   32 px tall, the page number its leftmost text in the host's own text size
   and colour. `display` is set, so `hidden` is restated: the page hides the
   element until the first page-rectangle answer and while no document is open.
   Collabora draws the number as a badge and opens Go to Page on click; the
   badge needs a span in the page markup and the click an action -- neither is
   in this release (study_LiteCore findings/143, 裁決). */
#litecore-editor-root #page-status {
	display: flex; align-items: center; box-sizing: border-box;
	width: 100%; min-height: 32px; padding: 0 12px; margin: 0;
	font-size: 13px; line-height: 1.5; text-align: left;
	color: var(--color-main-text); background: var(--color-main-background);
	border-top: 1px solid var(--color-border);
}
#litecore-editor-root #page-status[hidden] { display: none; }
/* Finding 154 (host 0.2.26): Nextcloud's core/css/server.css resets
   `article, aside, dialog, ... { display: block }`, an author rule that beats
   the browser's own `dialog:not([open]) { display: none }`. On 0.2.25 the page's
   closed Markdown dialog therefore sat over the editor's top left and took the
   clicks meant for the document. Only the closed state is restated; an open
   dialog keeps its own display (study_LiteCore findings/154, ruling 3). */
#litecore-editor-root dialog:not([open]) { display: none; }
/* Finding 155 (host 0.2.28): the same reset sets `dialog { margin: 0 }`, an
   author rule that beats the browser's own `margin: auto`. A modal dialog is
   `position: fixed; inset: 0`, so on 0.2.27 the open Markdown dialog sat at the
   viewport's top left. The open state gets its centring back; the page already
   sets the dialog's padding and border itself (study_LiteCore findings/155,
   ruling 5 item 19). */
#litecore-editor-root dialog[open] { margin: auto; }
</style>
<div id="litecore-editor-root" style="display: flex; flex-direction: column; height: 100%; width: 100%;">
<div id="litecore-host-bar" role="status" hidden><span id="litecore-host-notice"></span><button type="button" id="litecore-host-download" hidden>下載這份內容</button></div>
<?php print_unescaped($_['body']); ?>
</div>
