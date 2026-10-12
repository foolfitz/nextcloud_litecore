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
</style>
<div id="litecore-editor-root" style="display: flex; flex-direction: column; height: calc(100vh - 50px); width: 100%;">
<div id="litecore-host-bar" role="status" hidden><span id="litecore-host-notice"></span><button type="button" id="litecore-host-download" hidden>下載這份內容</button></div>
<?php print_unescaped($_['body']); ?>
</div>
