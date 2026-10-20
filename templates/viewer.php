<?php
// The shipped viewer page's own <style> and <body>, re-wrapped (EditorController::page).
// The product's style is confined to #litecore-viewer-root (EditorController::
// scopedStyle; study_LiteCore finding 110): Nextcloud's header and controls keep
// their own look. The height box gives the page's viewport something to fill
// under Nextcloud's header.
?>
<style>@scope (#litecore-viewer-root) {
<?php print_unescaped($_['style']); ?>
}</style>
<?php // A browser without @scope drops the whole block above; the host script
// switches this copy on there (templates/editor.php has the same arrangement,
// study_LiteCore review 2026-10-06 item 8). ?>
<style id="litecore-unscoped" media="not all">
<?php print_unescaped($_['unscoped']); ?>
</style>
<style>
/* The host's own bar: the file's name and a way back to Files. Read-only, so
   nothing about saving (study_LiteCore SPEC-M4-ods-in-nextcloud §3.4). */
#litecore-viewer-bar { display: flex; gap: 12px; align-items: center; padding: 6px 12px; font-size: 14px;
	border-bottom: 1px solid var(--color-border, #ddd); }
#litecore-host-title { font-weight: 650; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
#litecore-host-readonly { color: var(--color-text-maxcontrast, #6b7c8a); font-size: 12px; white-space: nowrap; }
#litecore-host-close { margin-left: auto; font-size: 16px; line-height: 1; padding: 4px 10px; }
/* The page's developer chrome (its header, its file input and close button,
   its status read-outs) is marked data-dev-only (SPEC-M4 §5.1) and hidden
   here, not taken out: a driver can still read the status fields. */
#litecore-viewer-root [data-dev-only] { display: none; }
/* The page lays its viewport out as 60vh for a standalone window; under
   Nextcloud's header it fills what the root leaves after the tab strip. */
#litecore-viewer-root { display: flex; flex-direction: column; height: 100%; width: 100%; }
#litecore-viewer-root main { display: flex; flex-direction: column; flex: 1; min-height: 0; }
#litecore-viewer-root #viewport { flex: 1; min-height: 0; height: auto; }
/* Nextcloud's core/css/server.css resets dialog display and margins (findings
   154 and 155); the viewer page has no dialog today, restated for the day it does. */
#litecore-viewer-root dialog:not([open]) { display: none; }
#litecore-viewer-root dialog[open] { margin: auto; }
</style>
<div id="litecore-viewer-root">
<div id="litecore-viewer-bar"><span id="litecore-host-title"></span><span id="litecore-host-readonly">唯讀</span><button type="button" id="litecore-host-close" title="關閉" aria-label="關閉">✕</button></div>
<?php print_unescaped($_['body']); ?>
</div>
