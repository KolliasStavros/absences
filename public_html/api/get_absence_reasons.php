<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/request_validation.php';
header('Content-Type: application/json; charset=utf-8');
$user = requireApiLogin();
if ($user['role'] !== 'admin') schoolJsonResponse(['error'=>'Δεν επιτρέπεται η πρόσβαση.'],403);
$id = schoolRequestId($_GET['student_id'] ?? null);
$date = schoolRequestDate($_GET['date'] ?? null);
if ($id===null || $date===null) schoolJsonResponse(['error'=>'Μη έγκυρος μαθητής ή ημερομηνία.'],400);
try {
    $year = (int)(getActiveAcademicYear()['id'] ?? 0);
    $db = getDB();
    $q = $db->prepare('SELECT id FROM students WHERE id=? AND academic_year_id=?'); $q->execute([$id,$year]);
    if (!$q->fetchColumn()) schoolJsonResponse(['error'=>'Ο μαθητής δεν βρέθηκε.'],404);
    $q = $db->prepare('SELECT unjustified_reason,justification_reason,protocol FROM absence_day_reasons WHERE academic_year_id=? AND student_id=? AND date=?');
    $q->execute([$year,$id,$date]);
    schoolJsonResponse(['reasons'=>$q->fetch(PDO::FETCH_ASSOC) ?: null]);
} catch (Throwable $e) {
    schoolJsonResponse(['error'=>safeError($e)],500);
}
