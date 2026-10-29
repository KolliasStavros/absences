<?php
// api/delete_attendance_hour.php - Delete / clear attendance for a section and hour with ownership validation
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/request_validation.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
require_once __DIR__ . '/../includes/attendance_security.php';

$user = requireApiLogin();
requirePost();

$user = getCurrentUser();
$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input) || !$input || array_is_list($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Μη έγκυρα δεδομένα']);
    exit;
}

// CSRF Protection
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? '');
if (!verifyCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Μη έγκυρο αίτημα ασφαλείας (CSRF Token mismatch). Παρακαλώ ανανεώστε τη σελίδα.']);
    exit;
}

$sectionId = schoolRequestId($input['section_id'] ?? null);
$date = schoolRequestDate($input['date'] ?? date('Y-m-d'));
$hour = schoolRequestHour($input['hour'] ?? 1);
if ($date===null) schoolJsonResponse(['error'=>'Μη έγκυρη μορφή ημερομηνίας'],400);
if ($sectionId===null || $hour===null) schoolJsonResponse(['error'=>'Μη έγκυρο τμήμα ή διδακτική ώρα'],400);

$db = getDB();
$activeYear = getActiveAcademicYear();
$activeYearId = $activeYear ? (int)$activeYear['id'] : 1;
$user = beginAttendanceMutation($db, $activeYearId);
validateAttendanceSection($db, $sectionId, $activeYearId, $user);

// If user is ordinary teacher, verify assignment to section for active academic year
if ($user['role'] === 'teacher') {
    $stmtSec = $db->prepare("SELECT 1 FROM teacher_sections WHERE teacher_id = ? AND section_id = ? AND academic_year_id = ?");
    $stmtSec->execute([$user['id'], $sectionId, $activeYearId]);
    if (!$stmtSec->fetch()) {
        attendanceDenied($db, 403, 'Δεν έχετε ανάθεση σε αυτό το τμήμα για το τρέχον διδακτικό έτος.');
    }
}

// Fetch all existing attendance rows for this hour
$stmtExisting = $db->prepare("
    SELECT id, student_id, teacher_id, absence_type, is_absent 
    FROM attendance 
    WHERE date = ? AND hour = ? AND section_id = ? AND academic_year_id = ?
");
$stmtExisting->execute([$date, $hour, $sectionId, $activeYearId]);
$existingRows = $stmtExisting->fetchAll(PDO::FETCH_ASSOC);

if (empty($existingRows)) {
    if ($db->inTransaction()) $db->commit();
    echo json_encode(['success' => true, 'message' => 'Δεν υπάρχουν καταχωρημένες απουσίες για διαγραφή.']);
    exit;
}

// Ownership check: If any row was recorded by another teacher and caller cannot manage all attendance
if (!canManageAnyAttendance()) {
    $otherTeacherRows = array_filter($existingRows, function($r) use ($user) {
        return (int)$r['teacher_id'] !== (int)$user['id'];
    });
    if (!empty($otherTeacherRows)) {
        attendanceDenied($db, 403, 'Δεν έχετε δικαίωμα διαγραφής απουσιών που έχουν καταχωρηθεί από άλλο συνάδελφο. Μόνο ο ίδιος ή Καθηγητής-Διαχειριστής μπορεί να τις διαγράψει.');
    }
}

// Protected absence types: Justified absences can only be deleted/managed by Admin (canJustifyAbsences)
$justifiedRows = array_filter($existingRows, function($r) {
    return $r['absence_type'] === 'justified' && (int)$r['is_absent'] === 1;
});

try {
    // Transaction already holds the year mutation lock.

    if (!empty($justifiedRows) && !canJustifyAbsences()) {
        // If all existing rows are justified, block deletion
        if (count($justifiedRows) === count($existingRows)) {
            $db->rollBack();
            http_response_code(403);
            echo json_encode(['error' => 'Η ώρα περιέχει μόνο δικαιολογημένες απουσίες, οι οποίες μπορούν να τροποποιηθούν ή να διαγραφούν μόνο από τη διεύθυνση του σχολείου.']);
            exit;
        }

        // Delete only non-justified rows, preserving justified ones
        $stmtDelete = $db->prepare("
            DELETE FROM attendance 
            WHERE date = ? AND hour = ? AND section_id = ? AND academic_year_id = ? AND absence_type != 'justified'
        ");
        $stmtDelete->execute([$date, $hour, $sectionId, $activeYearId]);
        $deletedCount = $stmtDelete->rowCount();

        $db->commit();
        echo json_encode([
            'success' => true, 
            'message' => "Οι απουσίες της {$hour}ης ώρας διαγράφηκαν επιτυχώς. Οι δικαιολογημένες απουσίες διατηρήθηκαν ακέραιες.",
            'deleted_count' => $deletedCount,
            'preserved_justified' => count($justifiedRows)
        ]);
    } else {
        // Admin or hour without justified absences: delete all records for this hour
        $stmtDelete = $db->prepare("
            DELETE FROM attendance 
            WHERE date = ? AND hour = ? AND section_id = ? AND academic_year_id = ?
        ");
        $stmtDelete->execute([$date, $hour, $sectionId, $activeYearId]);
        $deletedCount = $stmtDelete->rowCount();

        $db->commit();
        echo json_encode([
            'success' => true, 
            'message' => "Οι καταχωρημένες απουσίες της {$hour}ης ώρας διαγράφηκαν επιτυχώς.",
            'deleted_count' => $deletedCount
        ]);
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Σφάλμα διαγραφής: ' . safeError($e)]);
}
