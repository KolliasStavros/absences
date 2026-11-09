<?php
// SPDX-License-Identifier: GPL-3.0-only
require_once __DIR__.'/request_validation.php';

function calendarTables(): array {
    return ['calendar_managers','school_calendar_events','calendar_event_readers','calendar_audit_log'];
}
function calendarReady(PDO $db): bool {
    try { foreach(calendarTables() as $table) $db->query('SELECT 1 FROM '.$table.' LIMIT 0'); return true; }
    catch(PDOException $e) { if((int)($e->errorInfo[1]??0)===1146) return false; throw $e; }
}
function calendarCanManage(PDO $db,array $user): bool {
    if(($user['role']??'')==='admin') return true;
    $q=$db->prepare('SELECT 1 FROM calendar_managers WHERE user_id=? AND is_active=1');
    $q->execute([$user['id']]); return (bool)$q->fetchColumn();
}
function calendarCategories(): array { return ['event'=>'Εκδήλωση','trip'=>'Εκδρομή','task'=>'Εργασία / προθεσμία','note'=>'Σημείωση']; }
function calendarStatuses(): array { return ['planned'=>'Προγραμματισμένο','pending'=>'Σε εκκρεμότητα','completed'=>'Ολοκληρωμένο','cancelled'=>'Ακυρωμένο']; }
function calendarVisibilities(): array { return ['private'=>'Μόνο γραμματεία','all'=>'Όλοι οι καθηγητές','selected'=>'Επιλεγμένοι καθηγητές']; }
function calendarText(array $input,string $key,int $max,bool $required=false): string {
    $text=$input[$key]??'';
    if(!is_string($text)||mb_strlen(trim($text))>$max||($required&&trim($text)==='')) throw new DomainException('Ελέγξτε το πεδίο '.(['title'=>'τίτλος','description'=>'περιγραφή','internal_notes'=>'εσωτερικές σημειώσεις'][$key]??$key).'.');
    return trim($text);
}
function calendarInput(array $input): array {
    $data=['title'=>calendarText($input,'title',200,true),'description'=>calendarText($input,'description',10000),'internal_notes'=>calendarText($input,'internal_notes',10000)];
    foreach(['category'=>calendarCategories(),'status'=>calendarStatuses(),'visibility'=>calendarVisibilities()] as $key=>$choices) {
        if(!is_string($input[$key]??null)||!isset($choices[$input[$key]])) throw new DomainException('Μη έγκυρη επιλογή.');
        $data[$key]=$input[$key];
    }
    $data['start_date']=schoolRequestDate($input['start_date']??null);
    $data['end_date']=schoolRequestDate(($input['end_date']??'')===''?($input['start_date']??null):$input['end_date']);
    if(!$data['start_date']||!$data['end_date']||$data['end_date']<$data['start_date']) throw new DomainException('Ορίστε έγκυρες ημερομηνίες με λήξη μετά την έναρξη.');
    foreach(['start_time','end_time'] as $key) {
        $value=$input[$key]??'';
        if(!is_string($value)||($value!==''&&!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$value))) throw new DomainException('Μη έγκυρη ώρα.');
        $data[$key]=$value===''?null:$value;
    }
    if($data['end_time']!==null&&$data['start_time']===null) throw new DomainException('Συμπληρώστε και την ώρα έναρξης.');
    if($data['start_date']===$data['end_date']&&$data['end_time']!==null&&$data['end_time']<$data['start_time']) throw new DomainException('Η ώρα λήξης προηγείται της έναρξης.');
    $readers=$input['readers']??[];
    if(!is_array($readers)||count($readers)>2000) throw new DomainException('Μη έγκυρη λίστα καθηγητών.');
    $data['readers']=[];
    foreach($readers as $reader) { $id=schoolRequestId($reader); if(!$id) throw new DomainException('Μη έγκυρος καθηγητής.'); $data['readers'][$id]=$id; }
    $data['readers']=$data['visibility']==='selected'?array_values($data['readers']):[];
    if($data['visibility']==='selected'&&!$data['readers']) throw new DomainException('Επιλέξτε τουλάχιστον έναν καθηγητή.');
    return $data;
}
// The same predicate protects month, agenda, reminders and direct event URLs.
function calendarAccess(bool $manager,array $user,array &$params): string {
    if($manager) return '';
    $params[]=(int)$user['id'];
    return " AND (e.visibility='all' OR (e.visibility='selected' AND EXISTS (SELECT 1 FROM calendar_event_readers r WHERE r.event_id=e.id AND r.user_id=? AND r.is_active=1)))";
}
function calendarFields(bool $manager): string {
    $fields='e.id,e.academic_year_id,e.title,e.description,e.category,e.status,e.start_date,e.end_date,e.start_time,e.end_time';
    return $manager?$fields.',e.internal_notes,e.visibility,e.version,e.created_by,e.updated_by,e.created_at,e.updated_at':$fields;
}
function calendarEvents(PDO $db,int $year,array $user,string $from,string $to,bool $outstanding=false): array {
    $manager=calendarCanManage($db,$user);
    $params=[$year]; $access=calendarAccess($manager,$user,$params);
    $dates=$outstanding?" AND e.status IN ('planned','pending') AND (e.end_date>=? OR e.category='task' OR e.status='pending')":" AND e.start_date<=? AND e.end_date>=?";
    if($outstanding) $params[]=$from; else { $params[]=$to; $params[]=$from; }
    $q=$db->prepare('SELECT '.calendarFields($manager).' FROM school_calendar_events e WHERE e.academic_year_id=? AND e.is_deleted=0'.$access.$dates.' ORDER BY e.start_date, e.start_time,e.id');
    $q->execute($params); return $q->fetchAll(PDO::FETCH_ASSOC);
}
function calendarEvent(PDO $db,int $year,int $id,array $user): array {
    $manager=calendarCanManage($db,$user);
    $params=[$year,$id]; $access=calendarAccess($manager,$user,$params);
    $q=$db->prepare('SELECT '.calendarFields($manager).' FROM school_calendar_events e WHERE e.academic_year_id=? AND e.id=? AND e.is_deleted=0'.$access);
    $q->execute($params); $row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row) throw new DomainException('Η καταχώριση δεν είναι διαθέσιμη.');
    if($manager) {
        $q=$db->prepare('SELECT user_id FROM calendar_event_readers WHERE event_id=? AND is_active=1'); $q->execute([$id]);
        $row['readers']=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
    }
    return $row;
}
function calendarAudit(PDO $db,?int $event,int $actor,string $action,array $details): void {
    $q=$db->prepare('INSERT INTO calendar_audit_log(event_id,changed_by,action,details) VALUES(?,?,?,?)');
    $q->execute([$event,$actor,$action,json_encode($details,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
}
// Serialize edits, permission changes and school-year switches in year -> actor -> event order.
function calendarWriteLock(PDO $db,int $year,array $user,bool $adminOnly=false): void {
    $q=$db->prepare('SELECT id FROM academic_years WHERE id=? AND is_active=1 FOR UPDATE'); $q->execute([$year]);
    if(!$q->fetchColumn()) throw new DomainException('Το ενεργό σχολικό έτος άλλαξε. Ανανεώστε τη σελίδα.');
    $q=$db->prepare("SELECT id,role FROM users WHERE id=? AND status='active' FOR UPDATE"); $q->execute([$user['id']]); $actor=$q->fetch(PDO::FETCH_ASSOC);
    if(!$actor||($adminOnly?$actor['role']!=='admin':!calendarCanManage($db,$actor))) throw new DomainException('Δεν έχετε δικαίωμα διαχείρισης ημερολογίου.');
}
function calendarSave(PDO $db,int $year,array $user,array $input): int {
    $data=calendarInput($input); $id=schoolOptionalRequestId($input['id']??null);
    if($id===null) throw new DomainException('Μη έγκυρη καταχώριση.');
    $db->beginTransaction();
    try {
        calendarWriteLock($db,$year,$user);
        foreach($data['readers'] as $reader) {
            $q=$db->prepare("SELECT id FROM users WHERE id=? AND role IN ('teacher','teacher_admin') AND status='active'"); $q->execute([$reader]);
            if(!$q->fetchColumn()) throw new DomainException('Ένας από τους επιλεγμένους καθηγητές δεν είναι ενεργός.');
        }
        $columns=['title','description','internal_notes','category','status','visibility','start_date','end_date','start_time','end_time'];
        $values=array_map(fn($key)=>$data[$key],$columns); $before=null;
        if($id) {
            $q=$db->prepare('SELECT * FROM school_calendar_events WHERE id=? AND academic_year_id=? AND is_deleted=0 FOR UPDATE'); $q->execute([$id,$year]); $before=$q->fetch(PDO::FETCH_ASSOC);
            if(!$before||schoolRequestId($input['version']??null)!==(int)$before['version']) throw new DomainException('Η καταχώριση άλλαξε ή διαγράφηκε. Ανοίξτε την ξανά πριν αποθηκεύσετε.');
            $q=$db->prepare('UPDATE school_calendar_events SET '.implode(',',array_map(fn($key)=>$key.'=?',$columns)).',updated_by=?,version=version+1 WHERE id=?');
            $q->execute([...$values,$user['id'],$id]);
        } else {
            $q=$db->prepare('INSERT INTO school_calendar_events ('.implode(',',$columns).',academic_year_id,created_by,updated_by) VALUES('.implode(',',array_fill(0,13,'?')).')');
            $q->execute([...$values,$year,$user['id'],$user['id']]); $id=(int)$db->lastInsertId();
        }
        // Soft membership changes work with the restricted runtime's INSERT/UPDATE privileges.
        $q=$db->prepare('UPDATE calendar_event_readers SET is_active=0 WHERE event_id=?'); $q->execute([$id]);
        $q=$db->prepare('INSERT INTO calendar_event_readers(event_id,user_id,is_active) VALUES(?,?,1) ON DUPLICATE KEY UPDATE is_active=1');
        foreach($data['readers'] as $reader) $q->execute([$id,$reader]);
        calendarAudit($db,$id,(int)$user['id'],$before?'update':'create',['before'=>$before,'after'=>$data]);
        $db->commit(); return $id;
    } catch(Throwable $e) { if($db->inTransaction()) $db->rollBack(); throw $e; }
}
function calendarDelete(PDO $db,int $year,array $user,int $id,int $version): void {
    $db->beginTransaction();
    try {
        calendarWriteLock($db,$year,$user);
        $q=$db->prepare('UPDATE school_calendar_events SET is_deleted=1,version=version+1,updated_by=? WHERE id=? AND academic_year_id=? AND version=? AND is_deleted=0');
        $q->execute([$user['id'],$id,$year,$version]);
        if($q->rowCount()!==1) throw new DomainException('Η καταχώριση άλλαξε ή διαγράφηκε. Ανανεώστε τη σελίδα.');
        calendarAudit($db,$id,(int)$user['id'],'delete',['version'=>$version]); $db->commit();
    } catch(Throwable $e) { if($db->inTransaction()) $db->rollBack(); throw $e; }
}
function calendarSetManager(PDO $db,int $year,array $user,int $target,bool $enabled): void {
    $db->beginTransaction();
    try {
        calendarWriteLock($db,$year,$user,true);
        $q=$db->prepare("SELECT id FROM users WHERE id=? AND role IN ('teacher','teacher_admin') AND status='active' FOR UPDATE"); $q->execute([$target]);
        if(!$q->fetchColumn()) throw new DomainException('Επιλέξτε ενεργό λογαριασμό καθηγητή.');
        $q=$db->prepare('INSERT INTO calendar_managers(user_id,is_active,granted_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE is_active=VALUES(is_active),granted_by=VALUES(granted_by)');
        $q->execute([$target,(int)$enabled,$user['id']]);
        calendarAudit($db,null,(int)$user['id'],'manager_access',['user_id'=>$target,'enabled'=>$enabled]); $db->commit();
    } catch(Throwable $e) { if($db->inTransaction()) $db->rollBack(); throw $e; }
}
