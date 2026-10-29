<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/request_validation.php';
require_once __DIR__ . '/../includes/attendance_security.php';
require_once __DIR__ . '/../includes/attendance_day.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
$user = requireApiLogin();
requirePost();
if ($user['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error'=>'Μόνο οι διαχειριστές μπορούν να επεξεργαστούν όλες τις ώρες.']);
    exit;
}
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || array_is_list($input) || !verifyCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error'=>'Μη έγκυρο αίτημα ασφαλείας.']);
    exit;
}
$db = getDB();
$year = getActiveAcademicYear();
$yearId = (int)($year['id'] ?? 0);
$date = schoolRequestDate($input['date'] ?? null);
$changes = $input['changes'] ?? null;
$sectionId = schoolRequestId($input['section_id'] ?? null);
if ($date===null || $sectionId===null || !is_array($changes) || !array_is_list($changes) || count($changes) < 1 || count($changes) > 5000 || schoolRequestId($input['academic_year_id'] ?? null) !== $yearId) {
    attendanceDenied($db, 400, 'Μη έγκυρα δεδομένα ή αλλαγή σχολικού έτους. Ανανεώστε τη σελίδα.');
}
try {
    $user = beginAttendanceMutation($db, $yearId, ['admin']);
    validateAttendanceSection($db, $sectionId, $yearId, $user);
    $read = $db->prepare('SELECT id,is_absent,absence_type,teacher_id,section_id,updated_at,note FROM attendance WHERE academic_year_id=? AND date=? AND student_id=? AND hour=? FOR UPDATE');
    $write = $db->prepare("INSERT INTO attendance (academic_year_id,date,hour,section_id,student_id,teacher_id,is_absent,absence_type) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE is_absent=VALUES(is_absent),absence_type=VALUES(absence_type),teacher_id=VALUES(teacher_id),section_id=VALUES(section_id),updated_at=NOW()");
    $seen = [];
    foreach ($changes as $change) {
        if (!is_array($change)) attendanceDenied($db,400,'Μη έγκυρη αλλαγή.');
        $studentId = schoolRequestId($change['student_id'] ?? null);
        $hour = schoolRequestHour($change['hour'] ?? null,7);
        $status = $change['status'] ?? null;
        if (!$studentId || !$hour || $hour < 1 || $hour > 7 || !is_string($status) || !in_array($status,['present','absent','expulsion','justified'],true) || !is_string($change['version'] ?? null)) attendanceDenied($db,400,'Μη έγκυρος μαθητής, ώρα ή κατάσταση.');
        $key = $studentId . ':' . $hour;
        if (isset($seen[$key])) attendanceDenied($db,400,'Διπλή καταχώρηση της ίδιας ώρας.');
        $seen[$key] = true;
        validateAttendanceStudent($db,$studentId,$sectionId,$yearId);
        $read->execute([$yearId,$date,$studentId,$hour]);
        $existing = $read->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!hash_equals(attendanceSlotVersion($existing),$change['version'])) attendanceDenied($db,409,'Μια καταχώρηση άλλαξε από άλλον χρήστη. Δεν αποθηκεύτηκε καμία αλλαγή. Ανανεώστε τα δεδομένα πριν συνεχίσετε.');
        $write->execute([$yearId,$date,$hour,$sectionId,$studentId,$user['id'],$status === 'present' ? 0 : 1,$status === 'present' ? 'absent' : $status]);
    }
    $db->commit();
    echo json_encode(['success'=>true,'saved_count'=>count($changes)]);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    http_response_code(500);
    echo json_encode(['error'=>'Σφάλμα αποθήκευσης. Οι αλλαγές δεν αποθηκεύτηκαν.']);
}
