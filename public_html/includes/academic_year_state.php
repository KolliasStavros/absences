<?php
// Year closure lives in existing settings, so enabling this feature needs no DDL.
function academicYearClosed(PDO $db,int $year): bool {
    $q=$db->prepare('SELECT setting_value FROM settings WHERE setting_key=?');
    $q->execute(['academic_year_closed_'.$year]);
    return $q->fetchColumn()==='1';
}

function academicYearAssertWritable(PDO $db,int $year): void {
    $q=$db->prepare('SELECT id FROM academic_years WHERE id=? AND is_active=1 FOR UPDATE');
    $q->execute([$year]);
    if(!$q->fetchColumn()||academicYearClosed($db,$year))throw new DomainException('Το έτος είναι κλειστό ή δεν είναι πλέον ενεργό. Ανανεώστε τη σελίδα.');
}

// Historical browsing is per administrator session; it never switches the school's active year.
function academicYearRequestGuard(): void {
    if(PHP_SAPI==='cli'||empty($_SESSION['user_id']))return;
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    if(in_array($method,['GET','HEAD','OPTIONS'],true))return;
    $root=realpath(dirname(__DIR__));$script=realpath($_SERVER['SCRIPT_FILENAME']??'');
    $safe=['admin/academic_years.php','admin/settings.php','admin/maintenance.php','admin/portable_recovery.php','logout.php','index.php'];
    foreach($safe as $path)if($script===realpath($root.'/'.$path))return;
    $year=getActiveAcademicYear();
    if((int)$year['is_active']===1&&!academicYearClosed(getDB(),(int)$year['id']))return;
    http_response_code(409);
    $message='Προβολή ιστορικού: δεν επιτρέπονται αλλαγές ή αποστολές. Επιστρέψτε στο ενεργό έτος από τα Σχολικά έτη.';
    if(str_contains(str_replace('\\','/',$_SERVER['SCRIPT_NAME']??''),'/api/')){
        header('Content-Type: application/json; charset=utf-8');echo json_encode(['error'=>$message],JSON_UNESCAPED_UNICODE);
    }else{header('Content-Type: text/html; charset=utf-8');echo htmlspecialchars($message,ENT_QUOTES,'UTF-8').' <a href="'.htmlspecialchars(BASE_URL,ENT_QUOTES,'UTF-8').'/admin/academic_years.php">Σχολικά έτη</a>';}
    exit;
}
