<?php
// api/save_attendance.php - Save or update attendance records for section & hour
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
require_once __DIR__.'/../includes/co_teaching.php';
$activeYear = getActiveAcademicYear();
$activeYearId = $activeYear ? (int)$activeYear['id'] : 1;

try {
    $user = beginAttendanceMutation($db, $activeYearId);
    $sectionMembershipSql=coTeachingMembershipSql(coTeachingGroups($db));
    validateAttendanceSection($db, $sectionId, $activeYearId, $user);

    // Ownership check: If attendance already exists for this hour, only the creator or admin/teacher_admin can modify it
    $stmtExisting = $db->prepare("
        SELECT teacher_id 
        FROM attendance 
        WHERE date = ? AND hour = ? AND student_id IN (SELECT student_id FROM {$sectionMembershipSql} AS memberships WHERE section_id = ?) AND academic_year_id = ?
        AND teacher_id != ?
        LIMIT 1
    ");
    $stmtExisting->execute([$date, $hour, $sectionId, $activeYearId, $user['id']]);
    $existingTeacherId = $stmtExisting->fetchColumn();

    if ($existingTeacherId && $existingTeacherId != $user['id'] && !canManageAnyAttendance()) {
        attendanceDenied($db, 403, 'Δεν έχετε δικαίωμα τροποποίησης απουσιών που έχουν καταχωρηθεί από άλλο συνάδελφο. Μόνο ο ίδιος ή Καθηγητής-Διαχειριστής μπορεί να τις αλλάξει.');
    }

    // Identify existing justified absences so they cannot be accidentally removed by non-admins
    $stmtJustified = $db->prepare("
        SELECT student_id 
        FROM attendance 
        WHERE date = ? AND hour = ? AND student_id IN (SELECT student_id FROM {$sectionMembershipSql} AS memberships WHERE section_id = ?) AND academic_year_id = ? AND absence_type = 'justified' AND is_absent = 1
    ");
    $stmtJustified->execute([$date, $hour, $sectionId, $activeYearId]);
    $alreadyJustifiedStudentIds = $stmtJustified->fetchAll(PDO::FETCH_COLUMN);

    // Fetch all students in this section for the active academic year
    $stmt = $db->prepare("
        SELECT ss.student_id 
        FROM {$sectionMembershipSql} ss
        JOIN students s ON ss.student_id = s.id
        WHERE ss.section_id = ? AND s.academic_year_id = ?
        FOR UPDATE
    ");
    $stmt->execute([$sectionId, $activeYearId]);
    $sectionStudents = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Statuses can be passed as statuses map { student_id: 'present'|'absent'|'expulsion' }
    // Or absent_student_ids array (fallback)
    $statuses = $input['statuses'] ?? null;
    if (($statuses !== null && !is_array($statuses)) || (isset($input['absent_student_ids']) && !is_array($input['absent_student_ids']))) {
        attendanceDenied($db, 400, 'Μη έγκυρος πίνακας καταστάσεων.');
    }
    foreach (($statuses ?? []) as $status) {
        if (!in_array($status,['present','absent','expulsion','justified'],true)) attendanceDenied($db, 400, 'Μη έγκυρη κατάσταση παρουσίας.');
    }
    foreach (array_keys(is_array($statuses) ? $statuses : []) as $submittedId) validateAttendanceStudent($db, (int)$submittedId, $sectionId, $activeYearId);
    foreach (($input['absent_student_ids'] ?? []) as $submittedId) validateAttendanceStudent($db, (int)$submittedId, $sectionId, $activeYearId);
    $absentStudentIds = is_array($input['absent_student_ids'] ?? null) ? $input['absent_student_ids'] : [];

    $stmtUpsert = $db->prepare("
        INSERT INTO attendance (academic_year_id, date, hour, section_id, student_id, teacher_id, is_absent, absence_type)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            academic_year_id = VALUES(academic_year_id),
            is_absent = VALUES(is_absent),
            absence_type = VALUES(absence_type),
            teacher_id = VALUES(teacher_id),
            section_id = VALUES(section_id),
            updated_at = NOW()
    ");

    $absentCount = 0;
    $expulsionCount = 0;
    $justifiedCount = 0;

    foreach ($sectionStudents as $sId) {
        $st = 'present';
        if (is_array($statuses) && isset($statuses[$sId])) {
            $st = $statuses[$sId];
        } elseif (in_array($sId, $absentStudentIds)) {
            $st = 'absent';
        }

        // If this absence was already justified by an admin and caller is not admin, preserve justified
        if (in_array($sId, $alreadyJustifiedStudentIds) && !canJustifyAbsences()) {
            $isAbsent = 1;
            $absenceType = 'justified';
            $justifiedCount++;
            continue; // Preserve the complete protected record, including owner and section.
        } elseif ($st === 'justified') {
            if (!canJustifyAbsences()) {
                attendanceDenied($db, 403, 'Μόνο οι διαχειριστές του σχολείου έχουν δικαίωμα να καταχωρούν δικαιολογημένες απουσίες.');
            }
            $isAbsent = 1;
            $absenceType = 'justified';
            $justifiedCount++;
        } elseif ($st === 'expulsion') {
            $isAbsent = 1;
            $absenceType = 'expulsion';
            $expulsionCount++;
        } elseif ($st === 'absent') {
            $isAbsent = 1;
            $absenceType = 'absent';
            $absentCount++;
        } else {
            $isAbsent = 0;
            $absenceType = 'absent';
        }

        $stmtUpsert->execute([$activeYearId, $date, $hour, $sectionId, $sId, $user['id'], $isAbsent, $absenceType]);
    }

    $db->commit();
    echo json_encode([
        'success' => true, 
        'absent_count' => $absentCount, 
        'expulsion_count' => $expulsionCount,
        'justified_count' => $justifiedCount,
        'total_missing' => ($absentCount + $expulsionCount + $justifiedCount)
    ]);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Σφάλμα αποθήκευσης: ' . safeError($e)]);
}
