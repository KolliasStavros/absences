<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../includes/myschool_import.php';
try {
$db=getenv('MARKS_DB_USER')
    ?new AppPDO('mysql:host='.DB_HOST.';port='.(defined('DB_PORT')?DB_PORT:3306).';dbname='.DB_NAME.';charset=utf8mb4',getenv('MARKS_DB_USER'),getenv('MARKS_DB_PASS')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION])
    :getDB();
myschoolInstallRegistry($db);
echo "Έτοιμη η αποθήκευση ΑΜ καθηγητών. Δεν εισήχθησαν μαθητές ή καθηγητές.\n";
} catch(Throwable $e){fwrite(STDERR,'Η προετοιμασία δεν ολοκληρώθηκε: '.safeError($e)."\n");exit(1);}
