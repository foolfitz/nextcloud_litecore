<?php
// The shipped page's own <style> and <body>, re-wrapped (EditorController::editor).
// The height box only gives the product's column layout something to fill under
// Nextcloud's header; the product CSS itself is unchanged.
?>
<style><?php print_unescaped($_['style']); ?></style>
<style>
#litecore-host-bar { display: flex; gap: 12px; align-items: center; padding: 6px 12px; font-size: 14px;
	background: var(--color-warning, #eca700); color: var(--color-warning-text, #000); }
#litecore-host-bar[hidden] { display: none; }
#litecore-host-bar button { margin-left: auto; }
</style>
<div id="litecore-editor-root" style="display: flex; flex-direction: column; height: calc(100vh - 50px); width: 100%;">
<div id="litecore-host-bar" role="status" hidden><span id="litecore-host-notice"></span><button type="button" id="litecore-host-download" hidden>下載這份內容</button></div>
<?php print_unescaped($_['body']); ?>
</div>
