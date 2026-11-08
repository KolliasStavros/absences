<?php
// SPDX-License-Identifier: GPL-3.0-only
require_once __DIR__.'/marks.php';

function marksNameKey(string $name): string {
    $name=mb_strtoupper(trim($name),'UTF-8');
    $name=strtr($name,['Ά'=>'Α','Έ'=>'Ε','Ή'=>'Η','Ί'=>'Ι','Ό'=>'Ο','Ύ'=>'Υ','Ώ'=>'Ω','Ϊ'=>'Ι','Ϋ'=>'Υ',"\u{0301}"=>'']);
    return preg_replace('/\s+/u',' ',$name);
}

// Only names, section, subject, hours, assignment and year are read. Column D
// (tax identifier), father's name and other employee data never enter the preview.
function marksReadAssignments(string $path): array {
    if(!is_file($path)||filesize($path)>2097152) throw new DomainException('Επιλέξτε αναφορά MySchool XLS/XLSX έως 2 MB.');
    require_once __DIR__.'/spreadsheet/vendor/autoload.php';
    $magic=file_get_contents($path,false,null,0,8);
    if($magic==="\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") $reader=new \PhpOffice\PhpSpreadsheet\Reader\Xls();
    elseif(str_starts_with($magic,"PK\x03\x04")) {
        $zip=new ZipArchive(); if($zip->open($path)!==true) throw new DomainException('Μη έγκυρο XLSX.');
        try {
            if($zip->numFiles>500) throw new DomainException('Υπερβολικά σύνθετο XLSX.');
            $total=0;
            for($i=0;$i<$zip->numFiles;$i++) {
                $s=$zip->statIndex($i); $total+=$s['size'];
                if($s['size']>4194304||$total>16777216||$s['size']/max(1,$s['comp_size'])>100||preg_match('~vbaProject|externalLinks|embeddings~i',$s['name'])) throw new DomainException('Το XLSX υπερβαίνει τα όρια ή περιέχει μη υποστηριζόμενα στοιχεία.');
            }
        } finally { $zip->close(); }
        $reader=new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
    } else throw new DomainException('Χρειάζεται η αναφορά αναθέσεων MySchool σε πραγματικό XLS ή XLSX.');
    $reader->setReadDataOnly(true);
    $info=$reader->listWorksheetInfo($path);
    if(count($info)!==1||$info[0]['totalRows']>5000||$info[0]['totalColumns']>30) throw new DomainException('Χρειάζεται μία αναφορά έως 5.000 γραμμές και 30 στήλες.');
    $reader->setReadFilter(new class implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
        public function readCell(string $columnAddress,int $row,string $worksheetName=''): bool {
            return $row<=5000&&in_array($columnAddress,['A','B','H','J','L','M','N'],true);
        }
    });
    $book=$reader->load($path);
    try {
        $sheet=$book->getSheet(0); $header=0; $year='';
        for($i=1;$i<=min(50,$sheet->getHighestDataRow());$i++) {
            if(marksNameKey((string)$sheet->getCell('A'.$i)->getValue())==='ΕΠΩΝΥΜΟ'&&marksNameKey((string)$sheet->getCell('H'.$i)->getValue())==='ΤΜΗΜΑ'&&marksNameKey((string)$sheet->getCell('J'.$i)->getValue())==='ΟΝΟΜΑΣΙΑ ΜΑΘΗΜΑΤΟΣ') $header=$i;
            $v=trim((string)$sheet->getCell('M'.$i)->getValue()); if(preg_match('/^20[0-9]{2}-20[0-9]{2}$/D',$v)) $year=$v;
        }
        if(!$header||!$year) throw new DomainException('Δεν αναγνωρίστηκε η αναφορά «Αναθέσεις εκπαιδευτικών μονάδας» και το σχολικό έτος.');
        $rows=[]; $last=''; $first='';
        for($i=$header+1;$i<=$sheet->getHighestDataRow();$i++) {
            $a=trim((string)$sheet->getCell('A'.$i)->getValue()); $b=trim((string)$sheet->getCell('B'.$i)->getValue());
            if($a!==''||$b!=='') { $last=$a; $first=$b; }
            $section=trim((string)$sheet->getCell('H'.$i)->getValue()); $subject=trim((string)$sheet->getCell('J'.$i)->getValue());
            if($section===''&&$subject==='') continue;
            if($section===''||$subject===''||$last===''||$first==='') throw new DomainException('Ελλιπής ανάθεση στη γραμμή '.$i.'.');
            foreach([$last,$first,$subject] as $v) if(mb_strlen($v)>255) throw new DomainException('Υπερβολικά μεγάλο πεδίο στη γραμμή '.$i.'.');
            if(mb_strlen($section)>100) throw new DomainException('Υπερβολικά μεγάλο όνομα τμήματος.');
            $key=marksNameKey($last.' '.$first);
            if(mb_strlen($last.' '.$first)>150) throw new DomainException('Το ονοματεπώνυμο υπερβαίνει το όριο των λογαριασμών στη γραμμή '.$i.'.');
            if(mb_strlen($key)>255) throw new DomainException('Υπερβολικά μεγάλο όνομα εκπαιδευτικού.');
            $hours=trim((string)$sheet->getCell('L'.$i)->getValue());
            if($hours!=='') { $n=marksDecimal($hours); if($n<0||$n>10000) throw new DomainException('Μη έγκυρες ώρες στη γραμμή '.$i.'.'); $hours=marksNumber($n); }
            $assignment=trim((string)$sheet->getCell('N'.$i)->getValue()); if(mb_strlen($assignment)>100) throw new DomainException('Μη έγκυρο είδος ανάθεσης.');
            $rows[]=['line'=>$i,'teacher_key'=>$key,'teacher_name'=>$last.' '.$first,'section'=>$section,'subject'=>$subject,'hours'=>$hours===''?null:$hours,'assignment'=>$assignment];
        }
        if(!$rows) throw new DomainException('Δεν βρέθηκαν αναθέσεις.');
        return ['year_name'=>$year,'rows'=>$rows];
    } finally { $book->disconnectWorksheets(); }
}

function marksImportMatches(PDO $db,array $preview): array {
    $users=$db->query("SELECT id,name,email,status FROM users WHERE role IN ('teacher','teacher_admin','admin') ORDER BY name,id")->fetchAll(PDO::FETCH_ASSOC);
    $sections=$db->query('SELECT id,name FROM sections ORDER BY name,id')->fetchAll(PDO::FETCH_ASSOC);
    $aliases=$db->query('SELECT source_key,teacher_id FROM mark_teacher_aliases')->fetchAll(PDO::FETCH_KEY_PAIR);
    $teachers=[]; $sectionMatches=[];
    foreach($preview['rows'] as $row) {
        $key=$row['teacher_key'];
        if(!isset($teachers[$key])) {
            $ids=[];
            foreach($users as $u) {
                $tokens=explode(' ',marksNameKey($u['name'])); sort($tokens); $sourceTokens=explode(' ',$key); sort($sourceTokens);
                if($tokens===$sourceTokens) $ids[]=(int)$u['id'];
            }
            $alias=(int)($aliases[$key]??0); $available=array_map('intval',array_column($users,'id'));
            $teachers[$key]=['name'=>$row['teacher_name'],'id'=>$alias&&in_array($alias,$available,true)?$alias:(count($ids)===1?$ids[0]:(count($ids)===0?'new':0))];
        }
        $name=$row['section']; if(!isset($sectionMatches[$name])) {
            $ids=[]; foreach($sections as $s) if(marksNameKey($s['name'])===marksNameKey($name)) $ids[]=(int)$s['id'];
            $sectionMatches[$name]=count($ids)===1?$ids[0]:0;
        }
    }
    return ['users'=>$users,'sections'=>$sections,'teachers'=>$teachers,'section_matches'=>$sectionMatches];
}

function marksCommitImport(PDO $db,int $year,array $user,array $preview,array $teacherMap,array $sectionMap,string $assignmentPolicy='preserve'): int {
    if(($user['role']??'')!=='admin') throw new DomainException('Απαιτείται διαχειριστής.');
    if(!in_array($assignmentPolicy,['preserve','replace'],true)) throw new DomainException('Επιλέξτε τρόπο εισαγωγής αναθέσεων.');
    if(!isset($preview['rows'])||count($preview['rows'])>5000) throw new DomainException('Μη έγκυρη προεπισκόπηση.');
    $db->beginTransaction();
    try {
        marksActiveYearLock($db,$year);
        $q=$db->prepare('SELECT name FROM academic_years WHERE id=?'); $q->execute([$year]);
        if($q->fetchColumn()!==$preview['year_name']) throw new DomainException('Το έτος της αναφοράς δεν συμφωνεί με το ενεργό έτος.');
        $n=0; $synced=[]; $duplicates=[]; $createdTeachers=[]; $assignmentDecisions=[];
        foreach($preview['rows'] as $row) {
            $selection=$teacherMap[$row['teacher_key']]??null;
            if($selection==='new') {
                if(!isset($createdTeachers[$row['teacher_key']])) {
                    $tokens=explode(' ',$row['teacher_key']); sort($tokens);
                    foreach($db->query('SELECT name FROM users')->fetchAll(PDO::FETCH_COLUMN) as $name) {
                        $existingTokens=explode(' ',marksNameKey($name)); sort($existingTokens);
                        if($tokens===$existingTokens) throw new DomainException('Υπάρχει ήδη λογαριασμός με αυτό το όνομα. Επιλέξτε τον στην αντιστοίχιση αντί δημιουργίας νέου.');
                    }
                    $q=$db->prepare("INSERT INTO users(name,email,role,status) VALUES(?,?,'teacher','pending_activation')");
                    $q->execute([$row['teacher_name'],'pending.'.bin2hex(random_bytes(12)).'@myschool.local']);
                    $createdTeachers[$row['teacher_key']]=(int)$db->lastInsertId();
                }
                $selection=$createdTeachers[$row['teacher_key']];
            }
            $teacher=schoolRequestId($selection); $section=schoolRequestId($sectionMap[$row['section']]??null);
            if(!$teacher||!$section) throw new DomainException('Αντιστοιχίστε όλους τους καθηγητές και τα τμήματα.');
            $q=$db->prepare("SELECT id FROM users WHERE id=? AND role IN ('teacher','teacher_admin','admin')"); $q->execute([$teacher]); if(!$q->fetchColumn()) throw new DomainException('Μη διαθέσιμος λογαριασμός εκπαιδευτικού.');
            $q=$db->prepare('SELECT id FROM sections WHERE id=?'); $q->execute([$section]); if(!$q->fetchColumn()) throw new DomainException('Μη έγκυρο τμήμα.');
            $db->prepare('INSERT INTO mark_teacher_aliases(source_key,teacher_id) VALUES(?,?) ON DUPLICATE KEY UPDATE teacher_id=VALUES(teacher_id)')->execute([$row['teacher_key'],$teacher]);
            $db->prepare('INSERT IGNORE INTO subjects(source_name,display_name) VALUES(?,?)')->execute([$row['subject'],$row['subject']]);
            $q=$db->prepare('SELECT id FROM subjects WHERE source_name=?'); $q->execute([$row['subject']]); $subject=(int)$q->fetchColumn();
            $duplicate=$section.':'.$subject.':'.$teacher;
            if(isset($duplicates[$duplicate])) throw new DomainException('Η αντιστοίχιση δημιουργεί διπλή ανάθεση. Ελέγξτε τις γραμμές '.$duplicates[$duplicate].' και '.$row['line'].'.');
            $duplicates[$duplicate]=$row['line'];
            $db->prepare('INSERT INTO course_offerings(academic_year_id,section_id,subject_id,weekly_hours) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE weekly_hours=VALUES(weekly_hours)')->execute([$year,$section,$subject,$row['hours']]);
            $q=$db->prepare('SELECT * FROM course_offerings WHERE academic_year_id=? AND section_id=? AND subject_id=? FOR UPDATE'); $q->execute([$year,$section,$subject]); $course=$q->fetch(PDO::FETCH_ASSOC);
            if(!array_key_exists($course['id'],$assignmentDecisions)) {
                $q=$db->prepare("SELECT action FROM mark_audit_log WHERE course_offering_id=? AND action IN ('teacher_access','assignment_manual','assignment_reset') ORDER BY id DESC LIMIT 1");
                $q->execute([$course['id']]);
                $manual=in_array($q->fetchColumn(),['teacher_access','assignment_manual'],true);
                $assignmentDecisions[$course['id']]=$assignmentPolicy==='replace'||!$manual;
                if($assignmentPolicy==='replace') {
                    $before=marksAssignmentSnapshot($db,(int)$course['id']);
                    $db->prepare('UPDATE course_teachers SET is_active=0 WHERE course_offering_id=?')->execute([$course['id']]);
                    marksAudit($db,(int)$course['id'],(int)$user['id'],'assignment_reset',$before,['source'=>'myschool']);
                }
            }
            if($assignmentDecisions[$course['id']]) {
                $q=$db->prepare('SELECT assignment_type,is_active,can_edit_marks FROM course_teachers WHERE course_offering_id=? AND teacher_id=?'); $q->execute([$course['id'],$teacher]); $oldAssignment=$q->fetch(PDO::FETCH_ASSOC)?:null;
                $db->prepare('INSERT INTO course_teachers(course_offering_id,teacher_id,assignment_type) VALUES(?,?,?) ON DUPLICATE KEY UPDATE assignment_type=VALUES(assignment_type),is_active=1,can_edit_marks=1')->execute([$course['id'],$teacher,$row['assignment']]);
                marksAudit($db,(int)$course['id'],(int)$user['id'],'assignment_import',$oldAssignment,['teacher_id'=>$teacher,'assignment_type'=>$row['assignment'],'is_active'=>1,'can_edit_marks'=>1]);
            }
            if(!isset($synced[$course['id']])) {
                $diff=marksSyncRoster($db,$course); $synced[$course['id']]=true;
                marksAudit($db,(int)$course['id'],(int)$user['id'],'import',null,['subject_id'=>$subject,'roster_added'=>count($diff['add']),'roster_removed'=>count($diff['remove'])]);
                $db->prepare('UPDATE course_offerings SET version=version+1 WHERE id=?')->execute([$course['id']]);
            }
            $n++;
        }
        $db->commit(); return $n;
    } catch(Throwable $e) { if($db->inTransaction()) $db->rollBack(); throw $e; }
}

function marksAssignmentSnapshot(PDO $db,int $id): array {
    $q=$db->prepare('SELECT teacher_id,assignment_type,is_active,can_edit_marks FROM course_teachers WHERE course_offering_id=? ORDER BY teacher_id');
    $q->execute([$id]); return $q->fetchAll(PDO::FETCH_ASSOC);
}

// Caller holds active-year and course locks in an administrator transaction.
function marksChangeAssignment(PDO $db,array $course,array $user,array $input): void {
    if(($user['role']??'')!=='admin') throw new DomainException('Απαιτείται διαχειριστής.');
    $id=(int)$course['id']; $action=$input['action']??'';
    if(!in_array($action,['teacher_access','assignment_add','assignment_replace'],true)) throw new DomainException('Μη έγκυρη ενέργεια ανάθεσης.');
    $teacher=schoolRequestId($input['teacher_id']??null);
    if(!$teacher) throw new DomainException('Επιλέξτε καθηγητή.');
    $before=marksAssignmentSnapshot($db,$id); $byTeacher=array_column($before,null,'teacher_id');
    if($action==='teacher_access') {
        if(!isset($byTeacher[$teacher])) throw new DomainException('Δεν βρέθηκε ανάθεση.');
        $active=isset($input['teacher_active'])?1:0;
        $edit=isset($input['edit_access_present'])?(isset($input['can_edit_marks'])?1:0):(int)$byTeacher[$teacher]['can_edit_marks'];
        $db->prepare('UPDATE course_teachers SET is_active=?,can_edit_marks=? WHERE course_offering_id=? AND teacher_id=?')->execute([$active,$edit,$id,$teacher]);
    } else {
        $q=$db->prepare("SELECT id FROM users WHERE id=? AND role IN ('teacher','teacher_admin','admin')");
        $q->execute([$teacher]); if(!$q->fetchColumn()) throw new DomainException('Επιλέξτε έγκυρο λογαριασμό εκπαιδευτικού.');
        if($action==='assignment_replace') {
            $old=schoolRequestId($input['old_teacher_id']??null);
            if(!$old||$old===$teacher||empty($byTeacher[$old]['is_active'])) throw new DomainException('Επιλέξτε διαφορετικό καθηγητή και έγκυρη ενεργή ανάθεση προς αντικατάσταση.');
            $db->prepare('UPDATE course_teachers SET is_active=0 WHERE course_offering_id=? AND teacher_id=?')->execute([$id,$old]);
        } elseif(!empty($byTeacher[$teacher]['is_active'])) throw new DomainException('Ο καθηγητής έχει ήδη ενεργή ανάθεση. Χρησιμοποιήστε την ενημέρωση πρόσβασης.');
        $edit=isset($input['can_edit_marks'])?1:0;
        $db->prepare("INSERT INTO course_teachers(course_offering_id,teacher_id,assignment_type,is_active,can_edit_marks) VALUES(?,?,'Χειροκίνητη',1,?) ON DUPLICATE KEY UPDATE is_active=1,can_edit_marks=VALUES(can_edit_marks)")->execute([$id,$teacher,$edit]);
    }
    $db->prepare('UPDATE course_offerings SET version=version+1 WHERE id=?')->execute([$id]);
    marksAudit($db,$id,(int)$user['id'],'assignment_manual',$before,marksAssignmentSnapshot($db,$id));
}

function marksBulkReplace(PDO $db,int $year,array $user,array $input): void {
    if(($user['role']??'')!=='admin') throw new DomainException('Απαιτείται διαχειριστής.');
    $versions=$input['course_versions']??null;
    if(!is_array($versions)||!$versions||count($versions)>500) throw new DomainException('Επιλέξτε από 1 έως 500 διδασκαλίες.');
    ksort($versions,SORT_NUMERIC);
    $db->beginTransaction();
    try {
        marksActiveYearLock($db,$year);
        foreach($versions as $id=>$version) {
            $id=schoolRequestId($id);
            if(!$id||!is_scalar($version)) throw new DomainException('Μη έγκυρη επιλογή διδασκαλίας.');
            $course=marksCourse($db,$year,$id,$user,true);
            if((string)$version!==(string)$course['version']) throw new DomainException('Κάποια ανάθεση άλλαξε. Ελέγξτε ξανά την προεπισκόπηση.');
            marksChangeAssignment($db,$course,$user,array_merge($input,['action'=>'assignment_replace']));
        }
        $db->commit();
    } catch(Throwable $e) { if($db->inTransaction()) $db->rollBack(); throw $e; }
}

function marksManageCourse(PDO $db,int $year,int $id,array $user,array $input): void {
    if(($user['role']??'')!=='admin') throw new DomainException('Απαιτείται διαχειριστής.');
    $db->beginTransaction();
    try {
        marksActiveYearLock($db,$year); $course=marksCourse($db,$year,$id,$user,true);
        if((string)($input['course_version']??'')!==(string)$course['version']) throw new DomainException('Η διδασκαλία άλλαξε. Ανανεώστε τη σελίδα.');
        if(($input['action']??'')==='sync') {
            $diff=marksSyncRoster($db,$course); marksAudit($db,$id,(int)$user['id'],'roster_sync',null,$diff);
        } elseif(($input['action']??'')==='course_settings') {
            $written=isset($input['has_written_exam'])?1:0; $active=isset($input['is_active'])?1:0;
            if(!$written&&(int)$course['has_written_exam']) {
                $q=$db->prepare("SELECT COUNT(*) FROM student_marks m JOIN course_students cs ON cs.id=m.course_student_id JOIN assessment_periods p ON p.id=m.assessment_period_id WHERE cs.course_offering_id=? AND p.code='written' AND m.mark_status<>'ungraded'"); $q->execute([$id]);
                if((int)$q->fetchColumn()) throw new DomainException('Υπάρχουν βαθμοί γραπτών. Δεν επιτρέπεται απενεργοποίηση της εξέτασης.');
            }
            $db->prepare('UPDATE course_offerings SET has_written_exam=?,is_active=?,version=version+1 WHERE id=?')->execute([$written,$active,$id]);
            marksAudit($db,$id,(int)$user['id'],'course_settings',[$course['has_written_exam'],$course['is_active']],[$written,$active]);
        } elseif(in_array($input['action']??'', ['teacher_access','assignment_add','assignment_replace'],true)) {
            marksChangeAssignment($db,$course,$user,$input);
        } else throw new DomainException('Μη υποστηριζόμενη ενέργεια.');
        $db->commit();
    } catch(Throwable $e) { if($db->inTransaction()) $db->rollBack(); throw $e; }
}
