<?php
// SPDX-License-Identifier: GPL-3.0-only
if(PHP_SAPI!=='cli') { http_response_code(403); exit; }
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../includes/marks.php';
// Deployment credentials are ephemeral environment variables, never persisted.
$db=getenv('MARKS_DB_USER')
    ?new AppPDO('mysql:host='.DB_HOST.';port='.(defined('DB_PORT')?DB_PORT:3306).';dbname='.DB_NAME.';charset=utf8mb4',getenv('MARKS_DB_USER'),getenv('MARKS_DB_PASS')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false])
    :getDB();
marksInstall($db);
$year=$db->query('SELECT id FROM academic_years WHERE is_active=1 LIMIT 1')->fetchColumn();
if(!$year) throw new RuntimeException('Δεν υπάρχει ενεργό σχολικό έτος.');
marksSeedPeriods($db,(int)$year);
echo "Η ενότητα Βαθμολογία είναι έτοιμη. Δεν μεταβλήθηκαν δεδομένα παρουσιολογίου.\n";
