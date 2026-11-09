<?php
// SPDX-License-Identifier: GPL-3.0-only
// Additive local installation. No credentials in arguments or output, no role/data changes.
if(PHP_SAPI!=='cli') { http_response_code(403); exit; }
require_once __DIR__.'/../config/database.php';
if(is_file(__DIR__.'/../config/maintenance.php')) require_once __DIR__.'/../config/maintenance.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/school_calendar.php';
$calendarDbUser=getenv('CALENDAR_DB_USER')?: (defined('APP_MAINTENANCE_DB_USER')?APP_MAINTENANCE_DB_USER:DB_USER);
$calendarDbPass=getenv('CALENDAR_DB_USER')?(getenv('CALENDAR_DB_PASS')?:''):(defined('APP_MAINTENANCE_DB_USER')?APP_MAINTENANCE_DB_PASS:DB_PASS);
try {
    $db=new AppPDO('mysql:host='.DB_HOST.';port='.(defined('DB_PORT')?DB_PORT:3306).';dbname='.DB_NAME.';charset=utf8mb4',$calendarDbUser,$calendarDbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    foreach(explode(';',file_get_contents(__DIR__.'/../migrations/20261109_school_calendar.sql')) as $statement) if(trim($statement)!=='') $db->exec($statement);
    if(!calendarReady($db)) throw new RuntimeException('Missing calendar tables');
    echo "Το ημερολόγιο σχολείου ενεργοποιήθηκε. Δεν προστέθηκαν καταχωρίσεις ή αναθέσεις δικαιωμάτων.\n";
} catch(Throwable $e) {
    fwrite(STDERR,'Η ενεργοποίηση απέτυχε (SQLSTATE '.$e->getCode().'). Ελέγξτε τη σύνδεση και τα δικαιώματα CREATE / REFERENCES του λογαριασμού συντήρησης.'.PHP_EOL); exit(1);
}
