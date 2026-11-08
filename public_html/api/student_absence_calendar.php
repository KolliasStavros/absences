<?php
// Read-only annual absence calendar for users of the administrative daily dashboard.
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../includes/request_validation.php';
$user=requireApiLogin();
if(!in_array($user['role'],['admin','teacher_admin'],true)) schoolJsonResponse(['error'=>'Δεν επιτρέπεται η πρόσβαση.'],403);
$id=schoolRequestId($_GET['student_id']??null);
if(!$id) schoolJsonResponse(['error'=>'Μη έγκυρος μαθητής.'],400);
try {
    $db=getDB(); $year=getActiveAcademicYear();
    $q=$db->prepare('SELECT first_name,last_name FROM students WHERE id=? AND academic_year_id=?');
    $q->execute([$id,(int)$year['id']]); $student=$q->fetch(PDO::FETCH_ASSOC);
    if(!$student) schoolJsonResponse(['error'=>'Ο μαθητής δεν ανήκει στο ενεργό σχολικό έτος.'],404);
    $q=$db->prepare("SELECT date,COUNT(*) AS total,SUM(CASE WHEN absence_type='justified' THEN 1 ELSE 0 END) AS justified FROM attendance WHERE student_id=? AND academic_year_id=? AND is_absent=1 GROUP BY date ORDER BY date");
    $q->execute([$id,(int)$year['id']]); $days=[]; $total=0;
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $n=(int)$row['total']; $total+=$n;
        $days[$row['date']]=['total'=>$n,'justified'=>(int)$row['justified'],'unjustified'=>$n-(int)$row['justified']];
    }
    schoolJsonResponse(['name'=>$student['last_name'].' '.$student['first_name'],'year'=>$year['name'],'days'=>(object)$days,'total'=>$total]);
} catch(Throwable $e) { schoolJsonResponse(['error'=>safeError($e)],500); }
