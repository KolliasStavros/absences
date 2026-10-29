<?php
// A single year mutex serializes every attendance mutation, including empty slots.
// Row locks alone cannot protect two concurrent inserts into an empty slot.
function beginAttendanceMutation(PDO $db, int $yearId, array $roles = ['admin','teacher_admin','teacher']): array {
    $db->beginTransaction();
    $stmt = $db->prepare('SELECT id FROM academic_years WHERE id = ? AND is_active = 1 FOR UPDATE');
    $stmt->execute([$yearId]);
    if (!$stmt->fetchColumn()) attendanceDenied($db, 409, 'Το ενεργό σχολικό έτος άλλαξε. Ανανεώστε τη σελίδα.');
    $actor=$db->prepare('SELECT id FROM users WHERE id=? LOCK IN SHARE MODE');
    $actor->execute([$_SESSION['user_id'] ?? 0]);
    $user=getCurrentUser();
    if (!$user) attendanceDenied($db,401,'Απαιτείται νέα σύνδεση.');
    if (!in_array($user['role'],$roles,true)) attendanceDenied($db,403,'Δεν επιτρέπεται η ενέργεια.');
    return $user;
}

function attendanceDenied(PDO $db, int $status, string $message): never {
    if ($db->inTransaction()) $db->rollBack();
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function validateAttendanceSection(PDO $db, int $sectionId, int $yearId, array $user): void {
    $stmt = $db->prepare('SELECT id FROM sections WHERE id = ?');
    $stmt->execute([$sectionId]);
    if (!$stmt->fetchColumn()) attendanceDenied($db, 400, 'Μη έγκυρο τμήμα.');
    if ($user['role'] === 'teacher') {
        $stmt = $db->prepare('SELECT 1 FROM teacher_sections WHERE teacher_id = ? AND section_id = ? AND academic_year_id = ? LOCK IN SHARE MODE');
        $stmt->execute([$user['id'], $sectionId, $yearId]);
        if (!$stmt->fetchColumn()) attendanceDenied($db, 403, 'Δεν έχετε ανάθεση στο τμήμα.');
    } elseif (!in_array($user['role'], ['admin', 'teacher_admin'], true)) {
        attendanceDenied($db, 403, 'Δεν επιτρέπεται η ενέργεια.');
    }
}

function validateAttendanceStudent(PDO $db, int $studentId, int $sectionId, int $yearId): void {
    $stmt = $db->prepare('SELECT s.id FROM students s JOIN student_sections ss ON ss.student_id=s.id WHERE s.id=? AND s.academic_year_id=? AND ss.section_id=? FOR UPDATE');
    $stmt->execute([$studentId, $yearId, $sectionId]);
    if (!$stmt->fetchColumn()) attendanceDenied($db, 400, 'Ο μαθητής δεν ανήκει στο ενεργό έτος και τμήμα.');
}

function assertMutableAttendanceHours(PDO $db, int $studentId, string $date, int $yearId, array $hours): void {
    $stmt=$db->prepare("SELECT hour FROM attendance WHERE student_id=? AND date=? AND academic_year_id=? AND is_absent=1 AND absence_type='justified' FOR UPDATE");
    $stmt->execute([$studentId,$date,$yearId]);
    if (!canJustifyAbsences() && array_intersect($hours,array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN)))) {
        attendanceDenied($db,403,'Οι δικαιολογημένες απουσίες προστατεύονται.');
    }
}
