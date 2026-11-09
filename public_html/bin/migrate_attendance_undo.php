<?php
if(PHP_SAPI!=='cli') { http_response_code(403); exit; }
require_once __DIR__.'/../config/database.php';
if(is_file(__DIR__.'/../config/maintenance.php')) require_once __DIR__.'/../config/maintenance.php';
require_once __DIR__.'/../includes/db.php';
try {
    $journalDbUser=getenv('UNDO_DB_USER')?: (defined('APP_MAINTENANCE_DB_USER')?APP_MAINTENANCE_DB_USER:DB_USER);
    $journalDbPass=getenv('UNDO_DB_USER')?(getenv('UNDO_DB_PASS')?:''):(defined('APP_MAINTENANCE_DB_USER')?APP_MAINTENANCE_DB_PASS:DB_PASS);
    $db=new AppPDO('mysql:host='.DB_HOST.';port='.(defined('DB_PORT')?DB_PORT:3306).';dbname='.DB_NAME.';charset=utf8mb4',$journalDbUser,$journalDbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    foreach(explode(';',file_get_contents(__DIR__.'/../migrations/20261109_attendance_undo.sql')) as $statement) if(trim($statement)!=='') $db->exec($statement);
    echo "Ενεργοποιήθηκε το ιστορικό νέων αλλαγών απουσιών. Δεν μεταβλήθηκαν υπάρχουσες απουσίες.\n";
} catch(Throwable $e) { fwrite(STDERR,'Αποτυχία ενεργοποίησης ιστορικού (SQLSTATE '.$e->getCode().').'.PHP_EOL); exit(1); }
