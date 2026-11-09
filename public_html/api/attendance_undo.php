<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../includes/attendance_security.php';
$actor=requireApiLogin();
if(!in_array($actor['role'],['admin','teacher_admin'],true)) schoolJsonResponse(['error'=>'Δεν επιτρέπεται η πρόσβαση.'],403);
$db=getDB(); $year=(int)getActiveAcademicYear()['id'];
if(!attendanceUndoReady($db)) schoolJsonResponse(['error'=>'Το ιστορικό αλλαγών δεν έχει ενεργοποιηθεί.'],503);
try {
    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET') {
        $date=$_GET['date']??'';
        if($date!==''&&!schoolRequestDate($date)) throw new DomainException('Μη έγκυρη ημερομηνία.');
        $before=schoolOptionalRequestId($_GET['before']??null);
        if($before===null) throw new DomainException('Μη έγκυρη σελίδα.');
        $params=[$year]; $where='';
        if($actor['role']!=='admin') { $where.=' AND b.actor_id=?'; $params[]=$actor['id']; }
        if($date!=='') { $where.=' AND b.target_date=?'; $params[]=$date; }
        if($before) { $where.=' AND b.id<?'; $params[]=$before; }
        $q=$db->prepare('SELECT b.id,b.target_date,b.started_at,b.last_at,b.status,u.name AS actor_name,(SELECT COUNT(DISTINCT c.student_id) FROM attendance_edit_changes c WHERE c.batch_id=b.id) AS students,(SELECT COUNT(*) FROM attendance_edit_changes c WHERE c.batch_id=b.id) AS changes FROM attendance_edit_batches b JOIN users u ON u.id=b.actor_id WHERE b.academic_year_id=?'.$where.' ORDER BY b.id DESC LIMIT 51');
        $q->execute($params); $rows=$q->fetchAll(PDO::FETCH_ASSOC); $more=count($rows)>50; $rows=array_slice($rows,0,50);
        schoolJsonResponse(['batches'=>$rows,'next'=>$more?(int)end($rows)['id']:null]);
    }
    requirePost();
    $input=json_decode(file_get_contents('php://input'),true);
    if(!is_array($input)||!verifyCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN']??'')) schoolJsonResponse(['error'=>'Μη έγκυρο αίτημα ασφαλείας.'],403);
    if(schoolRequestId($input['year_id']??null)!==$year) throw new DomainException('Το σχολικό έτος άλλαξε. Ανανεώστε τη σελίδα.');
    $id=schoolRequestId($input['batch_id']??null);
    if(!$id) throw new DomainException('Μη έγκυρη ομάδα αλλαγών.');
    if(($input['action']??'')==='preview') {
        $actor=beginAttendanceMutation($db,$year,['admin','teacher_admin']);
        $plan=attendanceUndoPlan($db,$year,$id,$actor); $db->commit(); unset($plan['slots']);
        schoolJsonResponse($plan);
    }
    if(($input['action']??'')==='apply'&&is_string($input['hash']??null)&&preg_match('/^[a-f0-9]{64}$/D',$input['hash'])) {
        schoolJsonResponse(['success'=>true]+attendanceUndoApply($db,$year,$id,$actor,$input['hash']));
    }
    throw new DomainException('Μη έγκυρη ενέργεια.');
} catch(DomainException $e) { if($db->inTransaction()) $db->rollBack(); schoolJsonResponse(['error'=>$e->getMessage()],409); }
catch(Throwable $e) { if($db->inTransaction()) $db->rollBack(); schoolJsonResponse(['error'=>'Το ιστορικό δεν είναι προσωρινά διαθέσιμο. Δεν επιβεβαιώθηκε αναίρεση· ανανεώστε τα δεδομένα πριν συνεχίσετε.'],500); }
