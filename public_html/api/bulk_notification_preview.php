<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../includes/request_validation.php';
require_once __DIR__.'/../includes/greek_helper.php';
require_once __DIR__.'/../includes/sms.php';
require_once __DIR__.'/../includes/bulk_notification_preview.php';
$user = requireApiLogin();
requirePost();
if (!in_array($user['role'], ['admin','teacher_admin'], true)) schoolJsonResponse(['error'=>'Δεν επιτρέπεται η πρόσβαση.'],403);
if (!verifyCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) schoolJsonResponse(['error'=>'Μη έγκυρο αίτημα ασφαλείας.'],403);
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) schoolJsonResponse(['error'=>'Μη έγκυρο αίτημα.'],400);
$date = schoolRequestDate($input['date'] ?? null);
$channel = $input['channel'] ?? null;
$ids = $input['student_ids'] ?? null;
if (!$date || !in_array($channel,['email','sms'],true) || !is_array($ids) || !array_is_list($ids) || !$ids || count($ids)>1000) schoolJsonResponse(['error'=>'Μη έγκυρη επιλογή μαθητών ή ημερομηνία.'],400);
$validIds=[];
foreach($ids as $id) {
    $valid=schoolRequestId($id);
    if ($valid===null) schoolJsonResponse(['error'=>'Μη έγκυρος μαθητής.'],400);
    $validIds[$valid]=$valid;
}
try {
    $year=(int)(getActiveAcademicYear()['id'] ?? 0);
    $db=getDB(); $items=[];
    $students=$db->prepare('SELECT * FROM students WHERE id=? AND academic_year_id=?');
    $attendance=$db->prepare('SELECT id,hour,absence_type,email_notified,sms_notified FROM attendance WHERE student_id=? AND academic_year_id=? AND date=? AND is_absent=1 ORDER BY hour');
    foreach($validIds as $id) {
        $students->execute([$id,$year]); $student=$students->fetch(PDO::FETCH_ASSOC);
        if (!$student) schoolJsonResponse(['error'=>'Κάποιος μαθητής δεν ανήκει στο ενεργό σχολικό έτος.'],404);
        $attendance->execute([$id,$year,$date]); $rows=$attendance->fetchAll(PDO::FETCH_ASSOC);
        if ($channel==='sms') $rows=array_values(array_filter($rows,fn($r)=>!$r['sms_notified']));
        $contacts=$channel==='email' ? [$student['guardian_email'],$student['guardian_email_2']] : [$student['guardian_mobile'],$student['guardian_mobile_2']];
        $warnings=[]; $recipients=[];
        foreach($contacts as $i=>$contact) {
            if (!$contact) { $warnings[]='Δεν υπάρχει '.($channel==='email'?'email':'κινητό').' '.($i+1).'ου κηδεμόνα.'; continue; }
            $recipients[]=$contact;
            if ($channel==='email' ? !filter_var($contact,FILTER_VALIDATE_EMAIL) : !normalizeGreekMobileNumber($contact)['valid']) $warnings[]='Ελέγξτε το στοιχείο επικοινωνίας: '.$contact;
        }
        if ($channel==='sms') $recipients=array_values(array_unique($recipients));
        $skipped='';
        if (!$rows) $skipped='Δεν υπάρχουν απουσίες προς ειδοποίηση για την ημέρα.';
        if (!$recipients) $skipped='Δεν υπάρχουν παραλήπτες.';
        if ($channel==='sms' && getSchoolSetting('sms_send_mode','fallback_no_email')==='fallback_no_email' && ($student['guardian_email'] || $student['guardian_email_2'])) $skipped='Παράλειψη SMS: υπάρχει email κηδεμόνα, σύμφωνα με τις ρυθμίσεις.';
        if ($channel==='sms' && getSchoolSetting('sms_enabled','0')!=='1') $skipped='Η αποστολή SMS είναι απενεργοποιημένη.';
        $message=$channel==='email' ? buildBulkEmailPreview($student,$rows,$date) : ['subject'=>'Ενημέρωση Απουσιών','body'=>buildStudentSmsMessage($student,count($rows),date('d/m/Y',strtotime($date)),getSchoolSetting('school_name','Παρουσιολόγιο Σχολείου'))];
        $items[]=['id'=>$id,'name'=>$student['last_name'].' '.$student['first_name'],'am'=>$student['am'],'recipients'=>$recipients,'warnings'=>$warnings,'skipped'=>$skipped,'subject'=>$message['subject'],'body'=>$message['body']];
    }
    schoolJsonResponse(['items'=>$items,'date'=>$date,'channel'=>$channel]);
} catch(Throwable $e) { schoolJsonResponse(['error'=>safeError($e)],500); }
