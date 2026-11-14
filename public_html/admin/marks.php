<?php
require_once __DIR__.'/../config/config.php';
requireAdmin();
if (!(require __DIR__.'/../includes/marks_enabled.php')) { http_response_code(503); exit('Η Βαθμολογία είναι προσωρινά απενεργοποιημένη.'); }
$marksAdminLayout = true;
require __DIR__.'/../includes/marks_page.php';
