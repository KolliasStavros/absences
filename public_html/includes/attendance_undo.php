<?php
// Transactional before/after journal. Call only under the attendance year mutex.
require_once __DIR__.'/request_validation.php';
function attendanceUndoReady(PDO $db): bool {
    try { $db->query('SELECT 1 FROM attendance_edit_batches LIMIT 0'); $db->query('SELECT 1 FROM attendance_edit_changes LIMIT 0'); return true; }
    catch(PDOException $e) { if((int)($e->errorInfo[1]??0)===1146) return false; throw $e; }
}
function attendanceUndoJson(mixed $value): string { return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); }
function attendanceUndoState(PDO $db,int $year,string $date,array $students): array {
    $students=array_values(array_unique(array_map('intval',$students))); $states=[];
    if(!$students) return [];
    $in=implode(',',array_fill(0,count($students),'?')); $params=[$year,$date,...$students];
    foreach($students as $id) for($h=1;$h<=8;$h++) $states[$id.':'.$h]=['attendance'=>null,'note'=>null,'reason'=>null];
    foreach(['attendance'=>'attendance','attendance_notes'=>'note'] as $table=>$key) {
        $q=$db->prepare('SELECT * FROM '.$table.' WHERE academic_year_id=? AND date=? AND student_id IN ('.$in.') ORDER BY student_id,hour'.($db->inTransaction()?' FOR UPDATE':'')); $q->execute($params);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row) if(isset($states[$row['student_id'].':'.$row['hour']])) $states[$row['student_id'].':'.$row['hour']][$key]=$row;
    }
    $q=$db->prepare('SELECT * FROM absence_day_reasons WHERE academic_year_id=? AND date=? AND student_id IN ('.$in.')'.($db->inTransaction()?' FOR UPDATE':'')); $q->execute($params);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row) for($h=1;$h<=8;$h++) $states[$row['student_id'].':'.$h]['reason']=$row;
    return $states;
}
function attendanceUndoCapture(PDO $db,int $year,string $date,array $students): ?array {
    if(!attendanceUndoReady($db)) return null;
    if(!$db->inTransaction()) throw new LogicException('Attendance journal requires transaction');
    return ['year'=>$year,'date'=>$date,'students'=>$students,'states'=>attendanceUndoState($db,$year,$date,$students)];
}
function attendanceUndoKeys(): ?array {
    $tab=$_SERVER['HTTP_X_ATTENDANCE_TAB']??$_POST['undo_tab']??'';
    $run=$_SERVER['HTTP_X_ATTENDANCE_RUN']??$_POST['undo_run']??'';
    if(!is_string($tab)||!is_string($run)||!preg_match('/^[a-f0-9]{32,64}$/D',$tab)||!preg_match('/^[a-f0-9]{32,64}$/D',$run)) return null;
    return [$tab,$run];
}
function attendanceUndoRecord(PDO $db,?array $capture,array $actor,bool $group=false,?array $keys=null): ?int {
    if($capture===null) return null;
    if(!$db->inTransaction()) throw new LogicException('Attendance journal requires transaction');
    $after=attendanceUndoState($db,$capture['year'],$capture['date'],$capture['students']); $changes=[];
    foreach($capture['states'] as $key=>$before) if(attendanceUndoJson($before)!==attendanceUndoJson($after[$key])) $changes[$key]=[$before,$after[$key]];
    if(!$changes) return null;
    $batch=null; $keys=$keys??attendanceUndoKeys();
    if($group&&$keys&&in_array($actor['role'],['admin','teacher_admin'],true)) {
        [$tab,$run]=$keys;
        $q=$db->prepare("SELECT *,last_at>=DATE_SUB(NOW(6),INTERVAL 15 MINUTE) AS recent FROM attendance_edit_batches WHERE academic_year_id=? AND actor_id=? AND tab_key=? ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $q->execute([$capture['year'],$actor['id'],$tab]); $last=$q->fetch(PDO::FETCH_ASSOC);
        if($last&&$last['status']==='open'&&(int)$last['recent']&&$last['target_date']===$capture['date']&&$last['run_key']===$run) {
            $batch=(int)$last['id'];
            $db->prepare('UPDATE attendance_edit_batches SET last_at=NOW(6) WHERE id=?')->execute([$batch]);
        } else {
            if($last&&$last['status']==='open') $db->prepare("UPDATE attendance_edit_batches SET status='closed' WHERE id=?")->execute([$last['id']]);
            $db->prepare('INSERT INTO attendance_edit_batches(academic_year_id,actor_id,tab_key,run_key,target_date) VALUES(?,?,?,?,?)')->execute([$capture['year'],$actor['id'],$tab,$run,$capture['date']]);
            $batch=(int)$db->lastInsertId();
        }
    }
    $q=$db->prepare('INSERT INTO attendance_edit_changes(batch_id,academic_year_id,actor_id,student_id,target_date,hour,before_state,after_state) VALUES(?,?,?,?,?,?,?,?)');
    foreach($changes as $key=>[$before,$state]) { [$student,$hour]=explode(':',$key); $q->execute([$batch,$capture['year'],$actor['id'],$student,$capture['date'],$hour,attendanceUndoJson($before),attendanceUndoJson($state)]); }
    return $batch;
}
function attendanceUndoBatch(PDO $db,int $year,int $id,array $actor): array {
    if(!in_array($actor['role']??'', ['admin','teacher_admin'],true)) throw new DomainException('Δεν επιτρέπεται η πρόσβαση.');
    $q=$db->prepare('SELECT b.*,u.name AS actor_name FROM attendance_edit_batches b JOIN users u ON u.id=b.actor_id WHERE b.id=? AND b.academic_year_id=?'); $q->execute([$id,$year]); $batch=$q->fetch(PDO::FETCH_ASSOC);
    if(!$batch||($actor['role']!=='admin'&&(int)$batch['actor_id']!==(int)$actor['id'])) throw new DomainException('Η ομάδα αλλαγών δεν είναι διαθέσιμη.');
    return $batch;
}
function attendanceUndoLabel(array $state): string {
    $a=$state['attendance'];
    $label=(!$a||!(int)$a['is_absent'])?'Παρουσία':(['absent'=>'Απουσία','leave'=>'Με άδεια','justified'=>'Δικαιολογημένη','expulsion'=>'Αποβολή'][$a['absence_type']]??'Απουσία');
    $note=$state['note']['note']??$a['note']??'';
    return $label.($note!==''?' · Σημείωση: '.$note:'');
}
function attendanceUndoPlan(PDO $db,int $year,int $id,array $actor): array {
    $batch=attendanceUndoBatch($db,$year,$id,$actor);
    $q=$db->prepare('SELECT * FROM attendance_edit_changes WHERE batch_id=? ORDER BY id'); $q->execute([$id]); $slots=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $entry) {
        $key=$entry['student_id'].':'.$entry['hour'];
        if(!isset($slots[$key])) $slots[$key]=['first'=>(int)$entry['id'],'student_id'=>(int)$entry['student_id'],'hour'=>(int)$entry['hour'],'before'=>json_decode($entry['before_state'],true,512,JSON_THROW_ON_ERROR)];
        $slots[$key]['after']=json_decode($entry['after_state'],true,512,JSON_THROW_ON_ERROR);
    }
    $current=attendanceUndoState($db,$year,$batch['target_date'],array_column($slots,'student_id'));
    $rows=[]; $signature=[]; $eligible=0;
    foreach($slots as $key=>$slot) {
        $q=$db->prepare('SELECT MAX(id) FROM attendance_edit_changes WHERE academic_year_id=? AND student_id=? AND target_date=? AND hour=? AND id>? AND (batch_id IS NULL OR batch_id<>?)');
        $q->execute([$year,$slot['student_id'],$batch['target_date'],$slot['hour'],$slot['first'],$id]); $intervening=$q->fetchColumn();
        $reason='';
        if(!in_array($batch['status'],['open','closed'],true)) $reason='Έχει ήδη αναιρεθεί.';
        elseif($intervening||attendanceUndoJson($current[$key])!==attendanceUndoJson($slot['after'])) $reason='Μεσολάβησε άλλη αλλαγή. Θα διατηρηθεί.';
        elseif(attendanceUndoJson($slot['before'])===attendanceUndoJson($slot['after'])) $reason='Δεν υπάρχει καθαρή αλλαγή.';
        elseif($actor['role']!=='admin'&&((in_array($slot['before']['attendance']['absence_type']??'', ['leave','justified'],true))||(in_array($slot['after']['attendance']['absence_type']??'', ['leave','justified'],true)))) $reason='Απαιτείται διαχειριστής για άδεια ή δικαιολόγηση.';
        $q=$db->prepare('SELECT first_name,last_name FROM students WHERE id=? AND academic_year_id=?'); $q->execute([$slot['student_id'],$year]); $student=$q->fetch(PDO::FETCH_ASSOC);
        if(!$student) $reason='Ο μαθητής δεν είναι διαθέσιμος στο έτος.';
        $q=$db->prepare("SELECT COUNT(*) FROM notification_logs WHERE student_id=? AND status='sent' AND created_at>=?"); $q->execute([$slot['student_id'],substr($batch['started_at'],0,19)]); $sent=(int)$q->fetchColumn();
        $rows[]=['student_id'=>$slot['student_id'],'name'=>$student?trim($student['last_name'].' '.$student['first_name']):'Μη διαθέσιμος μαθητής','hour'=>$slot['hour'],'from'=>attendanceUndoLabel($slot['after']),'to'=>attendanceUndoLabel($slot['before']),'eligible'=>$reason==='','reason'=>$reason,'sent'=>$sent>0];
        $signature[]=[$key,$slot,$current[$key],$intervening,$sent,$reason];
        if($reason==='') $eligible++;
    }
    return ['batch'=>$batch,'rows'=>$rows,'eligible'=>$eligible,'hash'=>hash('sha256',attendanceUndoJson([$batch['status'],$signature])),'slots'=>$slots];
}
function attendanceUndoApply(PDO $db,int $year,int $id,array $actor,string $hash): array {
    $db->beginTransaction();
    try {
        $q=$db->prepare('SELECT id FROM academic_years WHERE id=? AND is_active=1 FOR UPDATE'); $q->execute([$year]);
        if(!$q->fetchColumn()) throw new DomainException('Το ενεργό σχολικό έτος άλλαξε.');
        $q=$db->prepare("SELECT id,role FROM users WHERE id=? AND status='active' FOR UPDATE"); $q->execute([$actor['id']]); $fresh=$q->fetch(PDO::FETCH_ASSOC);
        if(!$fresh) throw new DomainException('Απαιτείται ενεργός χρήστης.');
        $plan=attendanceUndoPlan($db,$year,$id,$fresh);
        if(!hash_equals($plan['hash'],$hash)) throw new DomainException('Τα δεδομένα άλλαξαν. Ανοίξτε ξανά την προεπισκόπηση.');
        if(!$plan['eligible']) throw new DomainException('Δεν υπάρχουν ασφαλείς αλλαγές για αναίρεση.');
        $capture=attendanceUndoCapture($db,$year,$plan['batch']['target_date'],array_column($plan['rows'],'student_id'));
        foreach($plan['rows'] as $row) {
            if(!$row['eligible']) continue;
            $before=$plan['slots'][$row['student_id'].':'.$row['hour']]['before'];
            foreach(['attendance'=>'attendance','attendance_notes'=>'note'] as $table=>$key) {
                $db->prepare('DELETE FROM '.$table.' WHERE academic_year_id=? AND date=? AND student_id=? AND hour=?')->execute([$year,$plan['batch']['target_date'],$row['student_id'],$row['hour']]);
                if($before[$key]!==null) {
                    $record=$before[$key]; unset($record['updated_at']);
                    $columns=array_keys($record);
                    $sql='INSERT INTO '.$table.' (`'.implode('`,`',$columns).'`,updated_at) VALUES ('.implode(',',array_fill(0,count($columns),'?')).',NOW())';
                    $db->prepare($sql)->execute(array_values($record));
                }
            }
        }
        attendanceUndoRecord($db,$capture,$fresh);
        $status=$plan['eligible']===count($plan['rows'])?'undone':'partial';
        $db->prepare('UPDATE attendance_edit_batches SET status=?,undone_by=?,undone_at=NOW(6) WHERE id=?')->execute([$status,$fresh['id'],$id]);
        $db->commit(); return ['restored'=>$plan['eligible'],'skipped'=>count($plan['rows'])-$plan['eligible']];
    } catch(Throwable $e) { if($db->inTransaction()) $db->rollBack(); throw $e; }
}
