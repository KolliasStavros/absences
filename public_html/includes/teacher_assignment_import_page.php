<?php
// Compatibility link: all MySchool reports now share one admin-only page.
require_once __DIR__.'/../config/config.php';
requireAdmin();
header('Location: '.BASE_URL.'/admin/myschool_import.php',true,303);
exit;
