<?php
// api/get_live_attendance.php - Real-time Live Attendance Updates Endpoint for Admin Dashboard
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/request_validation.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$user = requireApiLogin();

if (!canManageAnyAttendance()) {
    http_response_code(403);
    echo json_encode(['error' => 'Δεν έχετε δικαίωμα πρόσβασης στα ζωντανά δεδομένα απουσιών']);
    exit;
}

$db = getDB();
$activeYear = getActiveAcademicYear();
$activeYearId = $activeYear ? (int)$activeYear['id'] : 1;
$selectedDate=schoolRequestDate($_GET['date'] ?? date('Y-m-d'));
if ($selectedDate===null) schoolJsonResponse(['error'=>'Μη έγκυρη μορφή ημερομηνίας'],400);
$rawHash=$_GET['state_hash'] ?? '';
$statusFilter=$_GET['status_filter'] ?? 'absent_only';
if (!is_string($rawHash) || strlen($rawHash)>128 || !is_string($statusFilter)) schoolJsonResponse(['error'=>'Μη έγκυρα φίλτρα.'],400);
$clientHash=trim($rawHash);$statusFilter=trim($statusFilter);
$filterGradeId=schoolOptionalRequestId($_GET['grade_id'] ?? null);
$filterSectionId=schoolOptionalRequestId($_GET['section_id'] ?? null);
if ($filterGradeId===null || $filterSectionId===null) schoolJsonResponse(['error'=>'Μη έγκυρη τάξη ή τμήμα.'],400);
$allowedFilters = ['all', 'absent_only', 'present_only', 'expulsion_only', 'justified_only'];
if (!in_array($statusFilter, $allowedFilters, true)) {
    $statusFilter = 'absent_only';
}

// 1. Check quick state signature
$stmtState = $db->prepare("
    SELECT 
        MAX(updated_at) as max_updated, 
        COUNT(*) as total_records 
    FROM attendance 
    WHERE date = ? AND academic_year_id = ?
");
$stmtState->execute([$selectedDate, $activeYearId]);
$stateInfo = $stmtState->fetch(PDO::FETCH_ASSOC);

$stmtNotesState = $db->prepare("
    SELECT 
        MAX(updated_at) as max_note_updated, 
        COUNT(*) as total_notes 
    FROM attendance_notes 
    WHERE date = ? AND academic_year_id = ?
");
$stmtNotesState->execute([$selectedDate, $activeYearId]);
$notesStateInfo = $stmtNotesState->fetch(PDO::FETCH_ASSOC);

$maxUpdated = ($stateInfo['max_updated'] ?? 'none') . '_' . ($notesStateInfo['max_note_updated'] ?? 'none');
$totalRecords = (int)($stateInfo['total_records'] ?? 0) . '_' . (int)($notesStateInfo['total_notes'] ?? 0);
$serverStateHash = md5($maxUpdated . '_' . $totalRecords . '_' . $filterGradeId . '_' . $filterSectionId . '_' . $statusFilter);

if (!empty($clientHash) && $clientHash === $serverStateHash) {
    // Zero changes, exit immediately with minimal network overhead
    echo json_encode([
        'has_updates' => false,
        'state_hash' => $serverStateHash
    ]);
    exit;
}

// 2. State has changed or first load: fetch all data for this date
$stmtAtt = $db->prepare("
    SELECT 
        a.id as attendance_id,
        a.student_id,
        a.hour,
        a.section_id,
        a.is_absent,
        a.absence_type,
        a.note,
        a.email_notified,
        a.updated_at,
        (UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP(a.updated_at)) as seconds_ago,
        sec.name as section_name,
        sec.grade_id,
        g.name as grade_name,
        u.name as teacher_name,
        s.last_name,
        s.first_name,
        s.gender
    FROM attendance a
    JOIN students s ON a.student_id = s.id
    JOIN sections sec ON a.section_id = sec.id
    JOIN grades g ON sec.grade_id = g.id
    JOIN users u ON a.teacher_id = u.id
    WHERE a.date = ? AND a.academic_year_id = ? AND a.is_absent = 1
    ORDER BY a.hour ASC
");
$stmtAtt->execute([$selectedDate, $activeYearId]);
$attRows = $stmtAtt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all independent notes for this date
$stmtAllNotes = $db->prepare("
    SELECT student_id, hour, note 
    FROM attendance_notes 
    WHERE date = ? AND academic_year_id = ?
");
$stmtAllNotes->execute([$selectedDate, $activeYearId]);
$allNotesRows = $stmtAllNotes->fetchAll(PDO::FETCH_ASSOC);
$studentNotes = [];
foreach ($allNotesRows as $nr) {
    $sid = (int)$nr['student_id'];
    $studentNotes[$sid][(int)$nr['hour']] = $nr['note'];
}

// Group absences by student
$studentAttendances = [];
$totalAbsentStudents = 0;
$totalAbsenceHours = 0;
$totalExpulsions = 0;
$totalJustified = 0;
$totalPendingEmails = 0;

$studentsWithAbsences = [];
$recentNotifications = [];

foreach ($attRows as $ar) {
    $sid = (int)$ar['student_id'];
    $studentsWithAbsences[$sid] = true;
    $totalAbsenceHours++;

    if ($ar['absence_type'] === 'expulsion') {
        $totalExpulsions++;
    } elseif ($ar['absence_type'] === 'justified') {
        $totalJustified++;
    }
    if (!$ar['email_notified']) {
        $totalPendingEmails++;
    }

    // Check if updated in the last 15 seconds (using DB server clock)
    $secAgo = isset($ar['seconds_ago']) ? (int)$ar['seconds_ago'] : 999;
    if ($secAgo >= 0 && $secAgo <= 15) {
        $recentNotifications[] = [
            'attendance_id' => (int)$ar['attendance_id'],
            'student_id' => $sid,
            'student_name' => $ar['last_name'] . ' ' . $ar['first_name'],
            'gender' => $ar['gender'],
            'hour' => (int)$ar['hour'],
            'section_name' => $ar['section_name'],
            'teacher_name' => $ar['teacher_name'],
            'absence_type' => $ar['absence_type'] ?: 'absent',
            'note' => $ar['note'] ?? null,
            'updated_at' => $ar['updated_at']
        ];
    }

    $studentAttendances[$sid][] = [
        'id' => (int)$ar['attendance_id'],
        'hour' => (int)$ar['hour'],
        'section_id' => (int)$ar['section_id'],
        'section_name' => $ar['section_name'],
        'teacher_name' => $ar['teacher_name'],
        'absence_type' => $ar['absence_type'] ?: 'absent',
        'note' => $studentNotes[$sid][(int)$ar['hour']] ?? null,
        'email_notified' => (int)$ar['email_notified']
    ];
}

$totalAbsentStudents = count($studentsWithAbsences);

// Fetch all students who match grade & section filter to accurately report displayed count
$sqlStudents = "
    SELECT s.id as student_id
    FROM students s
    LEFT JOIN student_sections ss ON s.id = ss.student_id
    LEFT JOIN sections sec ON ss.section_id = sec.id
    LEFT JOIN grades g ON sec.grade_id = g.id
    WHERE s.academic_year_id = ?
";
$params = [$activeYearId];
if ($filterGradeId > 0) {
    $sqlStudents .= " AND g.id = ? ";
    $params[] = $filterGradeId;
}
if ($filterSectionId > 0) {
    $sqlStudents .= " AND sec.id = ? ";
    $params[] = $filterSectionId;
}
$sqlStudents .= " GROUP BY s.id";
$stmtS = $db->prepare($sqlStudents);
$stmtS->execute($params);
$allFilteredStudentIds = $stmtS->fetchAll(PDO::FETCH_COLUMN);

// Calculate how many are displayed according to statusFilter
$displayedCount = 0;
foreach ($allFilteredStudentIds as $sId) {
    $hasAbs = isset($studentsWithAbsences[$sId]);
    if ($statusFilter === 'all') {
        $displayedCount++;
    } elseif ($statusFilter === 'absent_only' && $hasAbs) {
        $displayedCount++;
    } elseif ($statusFilter === 'present_only' && !$hasAbs) {
        $displayedCount++;
    } elseif ($statusFilter === 'expulsion_only') {
        $atts = $studentAttendances[$sId] ?? [];
        foreach ($atts as $a) {
            if ($a['absence_type'] === 'expulsion') {
                $displayedCount++;
                break;
            }
        }
    } elseif ($statusFilter === 'justified_only') {
        $atts = $studentAttendances[$sId] ?? [];
        foreach ($atts as $a) {
            if ($a['absence_type'] === 'justified') {
                $displayedCount++;
                break;
            }
        }
    }
}

echo json_encode([
    'has_updates' => true,
    'state_hash' => $serverStateHash,
    'server_time' => date('Y-m-d H:i:s'),
    'kpis' => [
        'displayed_count' => $displayedCount,
        'total_absent_students' => $totalAbsentStudents,
        'total_absence_hours' => $totalAbsenceHours,
        'total_expulsions' => $totalExpulsions,
        'total_justified' => $totalJustified,
        'total_pending_emails' => $totalPendingEmails
    ],
    'student_attendances' => $studentAttendances,
    'student_notes' => (object)$studentNotes,
    'recent_notifications' => $recentNotifications
], JSON_UNESCAPED_UNICODE);