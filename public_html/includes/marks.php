<?php
// SPDX-License-Identifier: GPL-3.0-only
// Grading domain. Integer hundredths avoid binary floating-point step errors.
require_once __DIR__.'/request_validation.php';
require_once __DIR__.'/co_teaching.php';

function marksTables(): array {
    return ['subjects','course_offerings','course_teachers','assessment_periods','course_students','student_marks','mark_submissions','mark_audit_log','mark_teacher_aliases'];
}

function marksReady(PDO $db): bool {
    try { foreach(marksTables() as $table) $db->query('SELECT 1 FROM '.$table.' LIMIT 0'); return true; }
    catch(PDOException $e) { if(in_array((int)($e->errorInfo[1]??0),[1146],true)) return false; throw $e; }
}

function marksInstall(PDO $db): void {
    $sql=file_get_contents(__DIR__.'/../migrations/20261102_marks.sql');
    try {
        foreach(explode(';',$sql) as $statement) if(trim($statement)!=='') $db->exec($statement);
    } catch(PDOException $e) {
        if(in_array((int)($e->errorInfo[1]??0),[1044,1142,1143],true)) throw new DomainException('Ο λογαριασμός της εφαρμογής δεν έχει δικαίωμα δημιουργίας πινάκων. Εκτελέστε το bin/migrate_marks.php με λογαριασμό συντήρησης (MARKS_DB_USER / MARKS_DB_PASS).');
        throw $e;
    }
}

function marksSeedPeriods(PDO $db,int $year): void {
    $q=$db->prepare('INSERT IGNORE INTO assessment_periods(academic_year_id,code,name,mark_step) VALUES(?,?,?,?)');
    foreach([['term1','Α΄ τετράμηνο','1'],['term2','Β΄ τετράμηνο','1'],['written','Γραπτά','0.2']] as $period) $q->execute(array_merge([$year],$period));
}

function marksPeriods(PDO $db,int $year): array {
    $q=$db->prepare("SELECT * FROM assessment_periods WHERE academic_year_id=? ORDER BY FIELD(code,'term1','term2','written'),id");
    $q->execute([$year]); return $q->fetchAll(PDO::FETCH_ASSOC);
}

function marksDecimal(mixed $value): int {
    if(!is_string($value)&&!is_int($value)) throw new DomainException('Μη έγκυρος αριθμός.');
    $s=str_replace(',','.',trim((string)$value));
    if(!preg_match('/^-?(?:0|[1-9][0-9]{0,3})(?:\.[0-9]{1,2})?$/D',$s)) throw new DomainException('Δώστε αριθμό με έως δύο δεκαδικά ψηφία.');
    $negative=str_starts_with($s,'-'); $s=ltrim($s,'-'); $parts=explode('.',$s);
    $n=(int)$parts[0]*100+(int)str_pad($parts[1]??'',2,'0'); return $negative?-$n:$n;
}

function marksNumber(int $n): string { return ($n<0?'-':'').intdiv(abs($n),100).'.'.str_pad((string)(abs($n)%100),2,'0',STR_PAD_LEFT); }

function marksScale(array $period): array {
    $min=marksDecimal((string)$period['min_mark']); $max=marksDecimal((string)$period['max_mark']); $step=marksDecimal((string)$period['mark_step']);
    if($min<0||$max>$min+100000||$max>999999||$max<=$min||$step<=0||($max-$min)%$step!==0) throw new DomainException('Ορίστε μη αρνητικό ελάχιστο, μεγαλύτερο μέγιστο και θετικό βήμα που διαιρεί ακριβώς το εύρος.');
    return [$min,$max,$step];
}

function marksValue(mixed $value,array $period): ?string {
    if($value===null||$value==='') return null;
    $n=marksDecimal($value); [$min,$max,$step]=marksScale($period);
    if($n<$min||$n>$max||($n-$min)%$step!==0) throw new DomainException('Ο βαθμός είναι εκτός ορίων ή δεν ακολουθεί το βήμα της περιόδου.');
    return marksNumber($n);
}

function marksActiveYearLock(PDO $db,int $year): void {
    $q=$db->prepare('SELECT id FROM academic_years WHERE id=? AND is_active=1 FOR UPDATE'); $q->execute([$year]);
    if(!$q->fetchColumn()) throw new DomainException('Το ενεργό σχολικό έτος άλλαξε. Ανανεώστε τη σελίδα.');
}

function marksCourse(PDO $db,int $year,int $course,array $user,bool $lock=false): array {
    $q=$db->prepare('SELECT c.*,s.name AS section_name,sub.display_name AS subject_name FROM course_offerings c JOIN sections s ON s.id=c.section_id JOIN subjects sub ON sub.id=c.subject_id WHERE c.id=? AND c.academic_year_id=?'.($lock?' FOR UPDATE':''));
    $q->execute([$course,$year]); $row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row) throw new DomainException('Δεν βρέθηκε προσβάσιμη διδασκαλία.');
    if(($user['role']??'')!=='admin') {
        $q=$db->prepare('SELECT 1 FROM course_teachers WHERE course_offering_id=? AND teacher_id=? AND is_active=1 AND can_edit_marks=1'); $q->execute([$course,$user['id']]);
        if(!$q->fetchColumn()||!(int)$row['is_active']) throw new DomainException('Δεν έχετε πρόσβαση στη βαθμολογία αυτού του μαθήματος.');
    }
    return $row;
}

function marksPeriod(PDO $db,int $year,int $id,bool $lock=false): array {
    $q=$db->prepare('SELECT * FROM assessment_periods WHERE id=? AND academic_year_id=?'.($lock?' FOR UPDATE':'')); $q->execute([$id,$year]);
    $row=$q->fetch(PDO::FETCH_ASSOC); if(!$row) throw new DomainException('Μη έγκυρη περίοδος.'); return $row;
}

function marksCourses(PDO $db,int $year,array $user): array {
    $where=''; $params=[$year];
    if(($user['role']??'')!=='admin') { $where=' AND c.is_active=1 AND EXISTS(SELECT 1 FROM course_teachers ct WHERE ct.course_offering_id=c.id AND ct.teacher_id=? AND ct.is_active=1 AND ct.can_edit_marks=1)'; $params[]=$user['id']; }
    $q=$db->prepare('SELECT c.*,s.grade_id,g.name AS grade_name,s.name AS section_name,sub.display_name AS subject_name,(SELECT COUNT(*) FROM course_students cs WHERE cs.course_offering_id=c.id AND cs.is_active=1) AS student_count FROM course_offerings c JOIN sections s ON s.id=c.section_id LEFT JOIN grades g ON g.id=s.grade_id JOIN subjects sub ON sub.id=c.subject_id WHERE c.academic_year_id=?'.$where.' ORDER BY s.name,sub.display_name,c.id');
    $q->execute($params); return $q->fetchAll(PDO::FETCH_ASSOC);
}

function marksPeriodStatus(string $code): ?string {
    return ['term1'=>'not_submitted','term2'=>'not_submitted','written'=>'no_show'][$code] ?? null;
}

function marksRoster(PDO $db,int $course,int $period): array {
    $q=$db->prepare('SELECT cs.id AS course_student_id,cs.is_active,s.am,s.last_name,s.first_name,m.id AS mark_id,m.mark_value,COALESCE(m.mark_status,\'ungraded\') AS mark_status FROM course_students cs JOIN students s ON s.id=cs.student_id LEFT JOIN student_marks m ON m.course_student_id=cs.id AND m.assessment_period_id=? WHERE cs.course_offering_id=? AND (cs.is_active=1 OR m.id IS NOT NULL) ORDER BY s.last_name,s.first_name,s.am,s.id');
    $q->execute([$period,$course]); return $q->fetchAll(PDO::FETCH_ASSOC);
}

function marksSubmission(PDO $db,int $course,int $period): array {
    $q=$db->prepare('SELECT * FROM mark_submissions WHERE course_offering_id=? AND assessment_period_id=?'); $q->execute([$course,$period]);
    return $q->fetch(PDO::FETCH_ASSOC)?:['id'=>null,'status'=>'draft','version'=>0,'submitted_at'=>null];
}

function marksAudit(PDO $db,int $course,int $actor,string $action,mixed $old,mixed $new,?int $mark=null,?int $submission=null,string $reason=''): void {
    $q=$db->prepare('INSERT INTO mark_audit_log(course_offering_id,changed_by,action,old_value,new_value,mark_id,submission_id,reason) VALUES(?,?,?,?,?,?,?,?)');
    $q->execute([$course,$actor,$action,json_encode($old,JSON_THROW_ON_ERROR),json_encode($new,JSON_THROW_ON_ERROR),$mark,$submission,$reason]);
}

function marksSave(PDO $db,int $year,int $courseId,int $periodId,array $user,array $input): void {
    $db->beginTransaction();
    try {
        marksActiveYearLock($db,$year);
        $period=marksPeriod($db,$year,$periodId,true); $course=marksCourse($db,$year,$courseId,$user,true);
        if(!(int)$period['is_open']||!(int)$course['is_active']||($period['code']==='written'&&!(int)$course['has_written_exam'])) throw new DomainException('Η καταχώριση δεν είναι ανοικτή για αυτό το μάθημα και την περίοδο.');
        $submission=marksSubmission($db,$courseId,$periodId);
        foreach(['course_version'=>$course['version'],'period_version'=>$period['version'],'version'=>$submission['version']] as $key=>$version) {
            if(!isset($input[$key])||!is_scalar($input[$key])||(string)$input[$key]!== (string)$version) throw new DomainException('Τα δεδομένα άλλαξαν από άλλη ενέργεια. Ανανεώστε πριν αποθηκεύσετε.');
        }
        if($submission['status']==='submitted') throw new DomainException('Η υποβολή είναι οριστική. Απαιτείται επανάνοιγμα από διαχειριστή.');
        $rows=$input['marks']??null;
        if(!is_array($rows)||count($rows)>1000) throw new DomainException('Μη έγκυρη λίστα βαθμών.');
        $roster=marksRoster($db,$courseId,$periodId); $active=[];
        foreach($roster as $row) if((int)$row['is_active']) $active[(string)$row['course_student_id']]=$row;
        if(!$active) throw new DomainException('Δεν υπάρχουν ενεργοί μαθητές σε αυτό το μάθημα.');
        $seen=[]; $validated=[];
        foreach($rows as $row) {
            if(!is_array($row)) throw new DomainException('Μη έγκυρη καταχώριση.');
            $id=schoolRequestId($row['id']??null);
            if(!$id||!isset($active[(string)$id])||isset($seen[$id])) throw new DomainException('Η λίστα περιέχει διπλό ή μη εξουσιοδοτημένο μαθητή.');
            $seen[$id]=true; $status=$row['status']??'value';
            $allowedStatuses = ['ungraded','value','exempt',marksPeriodStatus($period['code'])];
            // Preserve existing absence records when the rest of the sheet is saved.
            if ($active[(string)$id]['mark_status'] === 'absent') $allowedStatuses[] = 'absent';
            if (!is_string($status) || !in_array($status,$allowedStatuses,true)) {
                throw new DomainException('Η κατάσταση βαθμού δεν επιτρέπεται για αυτή την περίοδο.');
            }
            $value=marksValue($row['value']??null,$period);
            if($status==='value'&&$value===null) $status='ungraded';
            if($status!=='value'&&$value!==null) throw new DomainException('Η ειδική κατάσταση δεν επιτρέπεται να έχει αριθμητικό βαθμό.');
            $validated[$id]=[$status,$value];
        }
        if(count($seen)!==count($active)) throw new DomainException('Η λίστα μαθητών άλλαξε ή είναι ελλιπής. Ανανεώστε τη σελίδα.');
        $submit=($input['action']??'save')==='submit';
        if($submit) foreach($validated as [$status,$value]) if($status==='ungraded') throw new DomainException('Συμπληρώστε βαθμό ή ειδική κατάσταση για όλους τους ενεργούς μαθητές πριν από την υποβολή.');
        foreach($validated as $id=>[$status,$value]) {
            $old=$active[(string)$id];
            if($old['mark_status']===$status&&$old['mark_value']===$value) continue;
            if($old['mark_id']) {
                $q=$db->prepare('UPDATE student_marks SET mark_value=?,mark_status=?,updated_by=?,updated_at=CURRENT_TIMESTAMP,version=version+1 WHERE id=?'); $q->execute([$value,$status,$user['id'],$old['mark_id']]); $mark=(int)$old['mark_id'];
            } else {
                $q=$db->prepare('INSERT INTO student_marks(course_student_id,assessment_period_id,mark_value,mark_status,updated_by) VALUES(?,?,?,?,?)'); $q->execute([$id,$periodId,$value,$status,$user['id']]); $mark=(int)$db->lastInsertId();
            }
            marksAudit($db,$courseId,(int)$user['id'],'mark',[$old['mark_status'],$old['mark_value']],[$status,$value],$mark);
        }
        $q=$db->prepare('INSERT INTO mark_submissions(course_offering_id,assessment_period_id,status,submitted_by,submitted_at,version) VALUES(?,?,?,?,?,1) ON DUPLICATE KEY UPDATE status=VALUES(status),submitted_by=VALUES(submitted_by),submitted_at=VALUES(submitted_at),version=version+1');
        $q->execute([$courseId,$periodId,$submit?'submitted':'draft',$submit?$user['id']:null,$submit?date('Y-m-d H:i:s'):null]);
        $next=marksSubmission($db,$courseId,$periodId);
        marksAudit($db,$courseId,(int)$user['id'],$submit?'submit':'save',$submission['status'],$next['status'],null,(int)$next['id']);
        $db->commit();
    } catch(Throwable $e) { if($db->inTransaction()) $db->rollBack(); throw $e; }
}

function marksReopen(PDO $db,int $year,int $course,int $period,array $user,string $reason,int $version): void {
    if(($user['role']??'')!=='admin') throw new DomainException('Η επανάνοιξη επιτρέπεται μόνο στον διαχειριστή.');
    $reason=trim($reason); if($reason===''||mb_strlen($reason)>255) throw new DomainException('Συμπληρώστε αιτιολογία έως 255 χαρακτήρες.');
    $db->beginTransaction();
    try {
        marksActiveYearLock($db,$year); marksPeriod($db,$year,$period,true); marksCourse($db,$year,$course,$user,true);
        $s=marksSubmission($db,$course,$period);
        if($s['status']!=='submitted'||(int)$s['version']!==$version) throw new DomainException('Η υποβολή άλλαξε. Ανανεώστε τη σελίδα.');
        $db->prepare("UPDATE mark_submissions SET status='draft',submitted_by=NULL,submitted_at=NULL,version=version+1 WHERE id=?")->execute([$s['id']]);
        marksAudit($db,$course,(int)$user['id'],'reopen','submitted','draft',null,(int)$s['id'],$reason); $db->commit();
    } catch(Throwable $e) { if($db->inTransaction()) $db->rollBack(); throw $e; }
}

function marksSaveSettings(PDO $db,int $year,array $rows): void {
    $db->beginTransaction();
    try {
        marksActiveYearLock($db,$year); $periods=marksPeriods($db,$year);
        if(count($rows)!==count($periods)) throw new DomainException('Ελλιπείς ρυθμίσεις περιόδων.');
        foreach($periods as $period) {
            $row=$rows[$period['id']]??null;
            if(!is_array($row)||(string)($row['version']??'')!==(string)$period['version']) throw new DomainException('Οι ρυθμίσεις άλλαξαν. Ανανεώστε τη σελίδα.');
            [$min,$max,$step]=marksScale($row);
            $q=$db->prepare('SELECT m.mark_value FROM student_marks m WHERE m.assessment_period_id=? AND m.mark_value IS NOT NULL'); $q->execute([$period['id']]);
            while(($v=$q->fetchColumn())!==false) marksValue((string)$v,$row);
            $db->prepare('UPDATE assessment_periods SET min_mark=?,max_mark=?,mark_step=?,is_open=?,version=version+1 WHERE id=?')->execute([marksNumber($min),marksNumber($max),marksNumber($step),isset($row['is_open'])?1:0,$period['id']]);
        }
        $db->commit();
    } catch(Throwable $e) { if($db->inTransaction()) $db->rollBack(); throw $e; }
}

function marksRosterDiff(PDO $db,array $course): array {
    $membership=coTeachingMembershipSql(coTeachingGroups($db));
    $q=$db->prepare('SELECT DISTINCT s.id FROM students s JOIN '.$membership.' ss ON ss.student_id=s.id WHERE ss.section_id=? AND s.academic_year_id=?'); $q->execute([$course['section_id'],$course['academic_year_id']]);
    $desired=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
    $q=$db->prepare('SELECT student_id FROM course_students WHERE course_offering_id=? AND is_active=1'); $q->execute([$course['id']]); $current=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
    return ['add'=>array_values(array_diff($desired,$current)),'remove'=>array_values(array_diff($current,$desired))];
}

// Caller holds the academic-year and course locks. Never deletes historical enrollments.
function marksSyncRoster(PDO $db,array $course): array {
    $diff=marksRosterDiff($db,$course);
    if(!$diff['add']&&!$diff['remove']) return $diff;
    $q=$db->prepare("SELECT COUNT(*) FROM mark_submissions WHERE course_offering_id=? AND status='submitted'"); $q->execute([$course['id']]);
    if((int)$q->fetchColumn()) throw new DomainException('Ανοίξτε ξανά τις οριστικές υποβολές πριν αλλάξετε τη λίστα μαθητών.');
    $q=$db->prepare('INSERT INTO course_students(course_offering_id,student_id,is_active) VALUES(?,?,1) ON DUPLICATE KEY UPDATE is_active=1');
    foreach($diff['add'] as $id) $q->execute([$course['id'],$id]);
    $q=$db->prepare('UPDATE course_students SET is_active=0 WHERE course_offering_id=? AND student_id=?'); foreach($diff['remove'] as $id) $q->execute([$course['id'],$id]);
    $db->prepare('UPDATE course_offerings SET version=version+1 WHERE id=?')->execute([$course['id']]); return $diff;
}
