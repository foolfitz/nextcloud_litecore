<?php
// The shipped page's own <style> and <body>, re-wrapped (EditorController::editor).
// The height box only gives the product's column layout something to fill under
// Nextcloud's header; the product CSS itself is unchanged.
?>
<style><?php print_unescaped($_['style']); ?></style>
<div id="litecore-editor-root" style="display: flex; flex-direction: column; height: calc(100vh - 50px); width: 100%;">
<?php print_unescaped($_['body']); ?>
</div>
