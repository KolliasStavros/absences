<?php
// Include only on authenticated pages, after auth bootstrap.
?>
<script id="technicalReporter" src="../assets/js/technical-errors.js?v=1" data-csrf="<?= htmlspecialchars(getCsrfToken(),ENT_QUOTES,'UTF-8') ?>"></script>
