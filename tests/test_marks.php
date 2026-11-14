<?php
// SPDX-License-Identifier: GPL-3.0-only
// All tables used by --mysql are connection-local TEMPORARY fixtures shadowing
// application tables. No actual users, grades, enrollments or settings are changed.
if(PHP_SAPI!=='cli') { http_response_code(403); exit; }
ob_start();
require_once __DIR__.'/../public_html/includes/marks_import.php';
function marksCheck(bool $ok,string $label): void { if(!$ok) { fwrite(STDERR,"FAIL: $label\n"); exit(1); } echo "PASS: $label\n"; }
function marksReject(callable $f,string $label): void { try { $f(); $rejected=false; } catch(DomainException $e) { $rejected=true; } marksCheck($rejected,$label); }
$term=['min_mark'=>'0','max_mark'=>'20','mark_step'=>'1']; $written=$term; $written['mark_step']='0,2';
marksCheck(marksValue('0',$term)==='0.00'&&marksValue('20',$term)==='20.00','zero and maximum remain numeric');
marksCheck(marksValue('15,6',$written)==='15.60'&&marksValue('15.6',$written)==='15.60','comma and decimal point');
marksCheck(marksValue('',$term)===null,'blank is distinct from zero');
foreach(['15.6','20.01','-1',true,[],1.2,'1e1','NaN','1.234','1,2.3'] as $value) marksReject(fn()=>marksValue($value,$term),'invalid number/range/step rejected');
marksReject(fn()=>marksValue('15.5',$written),'written step 0.2 enforced');
marksReject(fn()=>marksScale(['min_mark'=>'0','max_mark'=>'20','mark_step'=>'0']),'zero step rejected');
marksReject(fn()=>marksScale(['min_mark'=>'20','max_mark'=>'0','mark_step'=>'1']),'reversed range rejected');
marksReject(fn()=>marksScale(['min_mark'=>'0','max_mark'=>'20','mark_step'=>'0.3']),'unreachable slider maximum rejected');
marksCheck(marksNameKey('  Άλφα   βήτα ')==='ΑΛΦΑ ΒΗΤΑ','Greek name normalization');
if(!in_array('--mysql',$argv,true)) { echo "Use --mysql for isolated database fixtures.\n"; exit; }
set_exception_handler(static function(Throwable $e) { fwrite(STDERR,'FAIL: '.get_class($e).' at '.basename($e->getFile()).':'.$e->getLine().' SQLSTATE '.$e->getCode().' driver '.($e instanceof PDOException?($e->errorInfo[1]??'unknown'):'n/a')."\n"); exit(1); });
// CLI tests need database constants only, never web sessions or maintenance writes.
require_once __DIR__.'/../public_html/config/database.php';
require_once __DIR__.'/../public_html/includes/db.php';
$db=new AppPDO('mysql:host='.DB_HOST.';port='.(defined('DB_PORT')?DB_PORT:3306).';dbname='.DB_NAME.';charset=utf8mb4',getenv('MARKS_DB_USER')?:DB_USER,getenv('MARKS_DB_USER')?getenv('MARKS_DB_PASS'):DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach([
    'academic_years'=>'id INT PRIMARY KEY,name VARCHAR(50),is_active INT',
    'users'=>'id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(150),email VARCHAR(150) UNIQUE,role VARCHAR(30),status VARCHAR(30)',
    'grades'=>'id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(50)',
    'sections'=>'id INT PRIMARY KEY,grade_id INT,name VARCHAR(100)',
    'students'=>'id INT PRIMARY KEY,academic_year_id INT,am VARCHAR(50),last_name VARCHAR(100),first_name VARCHAR(100)',
    'student_sections'=>'student_id INT,section_id INT,PRIMARY KEY(student_id,section_id)',
    'teacher_sections'=>'academic_year_id INT,teacher_id INT,section_id INT,PRIMARY KEY(academic_year_id,teacher_id,section_id)',
    'settings'=>'setting_key VARCHAR(100) PRIMARY KEY,setting_value TEXT',
] as $name=>$columns) $db->exec('CREATE TEMPORARY TABLE '.$name.' ('.$columns.') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$schema=file_get_contents(__DIR__.'/../public_html/migrations/20261102_marks.sql');
$schema=preg_replace('/^\s*FOREIGN KEY .*\n/m','',$schema);
$schema=preg_replace('/,\s*\)/',"\n)",$schema);
$schema=str_replace('CREATE TABLE IF NOT EXISTS','CREATE TEMPORARY TABLE',$schema);
foreach(explode(';',$schema) as $sql) if(trim($sql)!=='') $db->exec($sql);
$db->exec("INSERT INTO academic_years VALUES(1,'2026-2027',1),(2,'2025-2026',0)");
$db->exec("INSERT INTO users VALUES(1,'ΔΙΑΧΕΙΡΙΣΤΗΣ','admin@example.test','admin','active'),(2,'ΑΛΦΑ ΚΑΘΗΓΗΤΗΣ','teacher@example.test','teacher','active'),(3,'ΒΗΤΑ ΚΑΘΗΓΗΤΗΣ','manager@example.test','teacher_admin','active')");
$db->exec("INSERT INTO sections VALUES(1,1,'Α-1'),(2,1,'Α-1 Γαλλικά')");
$db->exec("INSERT INTO students VALUES(1,1,'001','ΑΛΦΑ','ΜΑΘΗΤΗΣ'),(2,1,'002','ΒΗΤΑ','ΜΑΘΗΤΗΣ'),(3,2,'003','ΠΑΛΙΟΣ','ΜΑΘΗΤΗΣ'),(4,1,'004','ΓΑΜΑ','ΜΑΘΗΤΗΣ')");
$db->exec('INSERT INTO student_sections VALUES(1,1),(2,1),(3,1),(1,2)');
marksSeedPeriods($db,1); marksSeedPeriods($db,1); $periods=marksPeriods($db,1);
marksCheck(count($periods)===3&&(float)$periods[2]['mark_step']===0.2,'idempotent period defaults');
foreach(marksTables() as $table) marksCheck(str_contains(AppPDO::prefixIdentifiers('SELECT * FROM '.$table,'test_'),'test_'.$table),'prefix registry '.$table);
marksCheck(AppPDO::prefixIdentifiers("SELECT 'student_marks' FROM student_marks",'x_')==="SELECT 'student_marks' FROM x_student_marks",'prefix leaves literals intact');
$admin=['id'=>1,'role'=>'admin']; $teacher=['id'=>2,'role'=>'teacher']; $manager=['id'=>3,'role'=>'teacher_admin'];
$preview=['year_name'=>'2026-2027','rows'=>[
 ['line'=>15,'teacher_key'=>'ΑΛΦΑ ΚΑΘΗΓΗΤΗΣ','teacher_name'=>'ΑΛΦΑ ΚΑΘΗΓΗΤΗΣ','section'=>'Α-1','subject'=>'ΓΠ-ΛΥΚ-Φυσική','hours'=>'2.00','assignment'=>'ΠΡΩΤΗ ΑΝΑΘΕΣΗ'],
 ['line'=>16,'teacher_key'=>'ΑΛΦΑ ΚΑΘΗΓΗΤΗΣ','teacher_name'=>'ΑΛΦΑ ΚΑΘΗΓΗΤΗΣ','section'=>'Α-1 Γαλλικά','subject'=>'ΞΓ-ΛΥΚ-Γαλλικά','hours'=>'2.00','assignment'=>'ΠΡΩΤΗ ΑΝΑΘΕΣΗ'],
]];
$teacherMap=['ΑΛΦΑ ΚΑΘΗΓΗΤΗΣ'=>2]; $sectionMap=['Α-1'=>1,'Α-1 Γαλλικά'=>2];
marksCommitImport($db,1,$admin,$preview,$teacherMap,$sectionMap);
marksCommitImport($db,1,$admin,$preview,$teacherMap,$sectionMap);
marksCheck((int)$db->query('SELECT COUNT(*) FROM course_offerings')->fetchColumn()===2,'reimport has no duplicate courses');
marksCheck((int)$db->query('SELECT COUNT(*) FROM course_teachers')->fetchColumn()===2,'reimport has no duplicate assignments');
marksCheck((int)$db->query('SELECT COUNT(*) FROM course_students')->fetchColumn()===3,'rosters use special sections and active-year students only');
marksCheck(marksImportMatches($db,$preview)['teachers']['ΑΛΦΑ ΚΑΘΗΓΗΤΗΣ']['id']===2,'confirmed name alias reused');
$courses=marksCourses($db,1,$teacher); $course=(int)$courses[0]['id']; $other=(int)$courses[1]['id']; $period=(int)$periods[0]['id'];
marksCheck(count($courses)===2&&marksCourses($db,1,$manager)===[],'teacher_admin does not inherit grade access');
marksReject(fn()=>marksCourse($db,1,$course,$manager),'unassigned read denied');
marksReject(fn()=>marksCourse($db,2,$course,$teacher),'cross-year course denied');
$roster=marksRoster($db,$course,$period); marksCheck(count($roster)===2,'roster correct for general section');
$payload=static function(array $values) use($db,$course,$period,$teacher): array {
    $c=marksCourse($db,1,$course,$teacher); $p=marksPeriod($db,1,$period); $s=marksSubmission($db,$course,$period);
    $rows=marksRoster($db,$course,$period); $marks=[];
    foreach($rows as $i=>$r) if($r['is_active']) $marks[]=['id'=>(int)$r['course_student_id'],'status'=>$values[$i]===null?'ungraded':'value','value'=>$values[$i]];
    return ['course_version'=>(string)$c['version'],'period_version'=>(string)$p['version'],'version'=>(string)$s['version'],'action'=>'save','marks'=>$marks];
};
$initial=$payload(['0','20']); marksSave($db,1,$course,$period,$teacher,$initial);
marksCheck((int)$db->query('SELECT COUNT(*) FROM student_marks')->fetchColumn()===2,'save numeric zero and maximum');
marksReject(fn()=>marksSave($db,1,$course,$period,$teacher,$initial),'stale submission version rejected');
marksReject(fn()=>marksSave($db,1,$course,$period,$manager,$payload(['1','2'])),'unassigned write denied');
$incomplete=$payload(['1','2']); array_pop($incomplete['marks']);
marksReject(fn()=>marksSave($db,1,$course,$period,$teacher,$incomplete),'partial roster rejected');
$duplicate=$payload(['1','2']); $duplicate['marks'][1]=$duplicate['marks'][0];
marksReject(fn()=>marksSave($db,1,$course,$period,$teacher,$duplicate),'duplicate student rejected');
$outsider=$payload(['1','2']); $outsider['marks'][1]['id']=(int)marksRoster($db,$other,$period)[0]['course_student_id'];
marksReject(fn()=>marksSave($db,1,$course,$period,$teacher,$outsider),'foreign enrollment rejected');
$invalid=$payload(['3','15.5']);
marksReject(fn()=>marksSave($db,1,$course,$period,$teacher,$invalid),'invalid term step rejected atomically');
marksCheck(marksRoster($db,$course,$period)[0]['mark_value']==='0.00','invalid batch did not partially change first grade');
$blank=$payload([null,'20']); $blank['action']='submit';
marksReject(fn()=>marksSave($db,1,$course,$period,$teacher,$blank),'incomplete final submission rejected');
$final=$payload(['0','20']); $final['action']='submit'; marksSave($db,1,$course,$period,$teacher,$final);
marksCheck(marksSubmission($db,$course,$period)['status']==='submitted','final submission locks sheet');
$db->exec('INSERT INTO student_sections VALUES(4,1)');
$lockedCourse=marksCourse($db,1,$course,$admin);
marksReject(fn()=>marksManageCourse($db,1,$course,$admin,['action'=>'sync','course_version'=>(string)$lockedCourse['version']]),'submitted sheet blocks roster changes');
$db->exec('DELETE FROM student_sections WHERE student_id=4 AND section_id=1');
marksReject(fn()=>marksSave($db,1,$course,$period,$teacher,$payload(['1','2'])),'submitted marks cannot be overwritten');
$submission=marksSubmission($db,$course,$period);
marksReject(fn()=>marksReopen($db,1,$course,$period,$teacher,'Διόρθωση',(int)$submission['version']),'teacher cannot reopen');
marksReject(fn()=>marksReopen($db,1,$course,$period,$admin,'',(int)$submission['version']),'reopen requires reason');
marksReopen($db,1,$course,$period,$admin,'Διόρθωση',(int)$submission['version']);
marksCheck(marksSubmission($db,$course,$period)['status']==='draft','admin reopens with reason');
$writtenPeriod=(int)$periods[2]['id'];
$writtenInput=$payload(['15,6','20']); $writtenInput['period_version']=(string)$periods[2]['version']; $writtenInput['version']='0';
marksSave($db,1,$course,$writtenPeriod,$teacher,$writtenInput);
marksCheck(marksRoster($db,$course,$writtenPeriod)[0]['mark_value']==='15.60','written decimal saved without rounding');
$invalidWritten=$writtenInput; $invalidWritten['version']='1'; $invalidWritten['marks'][0]['value']='15,5';
marksReject(fn()=>marksSave($db,1,$course,$writtenPeriod,$teacher,$invalidWritten),'server rejects written grade off the 0.2 step');
$settings=[]; foreach(marksPeriods($db,1) as $p) $settings[$p['id']]=['version'=>(string)$p['version'],'min_mark'=>$p['min_mark'],'max_mark'=>$p['max_mark'],'mark_step'=>$p['mark_step'],'is_open'=>1];
$badSettings=$settings; $badSettings[$period]['min_mark']='1';
marksReject(fn()=>marksSaveSettings($db,1,$badSettings),'settings cannot invalidate existing zero');
marksCheck(marksPeriod($db,1,$period)['min_mark']==='0.00','rejected settings rolled back');
$beforeSettings=$payload(['0','20']); marksSaveSettings($db,1,$settings);
marksReject(fn()=>marksSave($db,1,$course,$period,$teacher,$beforeSettings),'stale period version rejected');
$closed=$settings; foreach($closed as &$p) { $p['version']=(string)((int)$p['version']+1); unset($p['is_open']); } unset($p);
marksSaveSettings($db,1,$closed);
marksReject(fn()=>marksSave($db,1,$course,$period,$teacher,$payload(['1','2'])),'closed period rejects server writes');
foreach(marksPeriods($db,1) as $p) { $settings[$p['id']]['version']=(string)$p['version']; } marksSaveSettings($db,1,$settings);
$staleRoster=$payload(['0','20']); $db->exec('INSERT INTO student_sections VALUES(4,1)');
$c=marksCourse($db,1,$course,$admin); marksManageCourse($db,1,$course,$admin,['action'=>'sync','course_version'=>(string)$c['version']]);
marksCheck(count(marksRoster($db,$course,$period))===3,'explicit roster sync adds new member');
marksReject(fn()=>marksSave($db,1,$course,$period,$teacher,$staleRoster),'roster revision prevents stale overwrites');
$q=$db->prepare('SELECT COUNT(*) FROM mark_audit_log WHERE action=? AND reason=?'); $q->execute(['reopen','Διόρθωση']); marksCheck((int)$q->fetchColumn()===1,'reopen reason audited');
$c=marksCourse($db,1,$course,$admin); marksManageCourse($db,1,$course,$admin,['action'=>'teacher_access','course_version'=>(string)$c['version'],'teacher_id'=>'2']);
marksReject(fn()=>marksCourse($db,1,$course,$teacher),'revoked assignment stops reads');
marksCheck(count(marksCourses($db,1,$teacher))===1,'revoked course disappears from teacher menu');
$newPreview=$preview;
foreach($newPreview['rows'] as &$row) { $row['teacher_key']='ΓΑΜΑ ΚΑΘΗΓΗΤΗΣ'; $row['teacher_name']='ΓΑΜΑ ΚΑΘΗΓΗΤΗΣ'; } unset($row);
marksCommitImport($db,1,$admin,$newPreview,['ΓΑΜΑ ΚΑΘΗΓΗΤΗΣ'=>'new'],$sectionMap);
$q=$db->prepare('SELECT id,status,email FROM users WHERE name=?'); $q->execute(['ΓΑΜΑ ΚΑΘΗΓΗΤΗΣ']); $newTeacher=$q->fetch(PDO::FETCH_ASSOC);
marksCheck($newTeacher&&$newTeacher['status']==='pending_activation'&&str_starts_with($newTeacher['email'],'pending.'),'missing teacher created once without credentials or tax identifier');
marksCheck(marksImportMatches($db,$newPreview)['teachers']['ΓΑΜΑ ΚΑΘΗΓΗΤΗΣ']['id']===(int)$newTeacher['id'],'new teacher reused by confirmed alias');
marksReject(fn()=>marksCommitImport($db,1,$admin,$newPreview,['ΓΑΜΑ ΚΑΘΗΓΗΤΗΣ'=>'new'],$sectionMap),'duplicate teacher creation rejected');
require_once __DIR__.'/../public_html/includes/excel_reader.php';
ExcelReader::importMySchoolTeachers([14=>[1=>'Επώνυμο',2=>'Όνομα',4=>'ΑΦΜ',8=>'Τμήμα'],15=>[1=>'☀',2=>'☁',4=>'DO_NOT_RETAIN',8=>'Α-1']],$db,1);
$q=$db->prepare('SELECT email FROM users WHERE name=?'); $q->execute(['☀ ☁']);
marksCheck(!str_contains($q->fetchColumn(),'DO_NOT_RETAIN'),'legacy teacher importer no longer derives fallback email from tax identifier');
// A fresh workbook fixture proves grouping and filtering without real personal data.
require_once __DIR__.'/../public_html/includes/spreadsheet/vendor/autoload.php';
$book=new \PhpOffice\PhpSpreadsheet\Spreadsheet(); $sheet=$book->getActiveSheet();
$sheet->setCellValue('M9','2026-2027'); $sheet->setCellValue('A14','Επώνυμο'); $sheet->setCellValue('H14','Τμήμα'); $sheet->setCellValue('J14','Ονομασία μαθήματος');
$sheet->setCellValue('A15','ΑΛΦΑ'); $sheet->setCellValue('B15','ΚΑΘΗΓΗΤΗΣ'); $sheet->setCellValue('D15','DO_NOT_RETAIN');
$sheet->setCellValue('H15','Α-1'); $sheet->setCellValue('J15','Φυσική'); $sheet->setCellValue('L15','2');
$sheet->setCellValue('H16','Α-1 Γαλλικά'); $sheet->setCellValue('J16','Γαλλικά');
$temp=tempnam(sys_get_temp_dir(),'marks-test-');
try {
    (new \PhpOffice\PhpSpreadsheet\Writer\Xls($book))->save($temp); $parsed=marksReadAssignments($temp);
    marksCheck(count($parsed['rows'])===2&&$parsed['rows'][1]['teacher_key']==='ΑΛΦΑ ΚΑΘΗΓΗΤΗΣ','binary XLS grouped teacher names parsed');
    marksCheck(!str_contains(json_encode($parsed),'DO_NOT_RETAIN'),'excluded tax column never retained in preview');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($temp); $parsed=marksReadAssignments($temp);
    marksCheck(count($parsed['rows'])===2,'XLSX assignment report supported');
} finally { unlink($temp); $book->disconnectWorksheets(); }
// Manual assignments and import policy: use only the connection-local fixtures above.
$recordsBefore=$db->query('SELECT * FROM student_marks ORDER BY id')->fetchAll();
$submissionsBefore=$db->query('SELECT * FROM mark_submissions ORDER BY id')->fetchAll();
marksCommitImport($db,1,$admin,$preview,$teacherMap,$sectionMap,'replace');
$c=marksCourse($db,1,$course,$admin);
marksManageCourse($db,1,$course,$admin,['action'=>'assignment_replace','course_version'=>(string)$c['version'],'old_teacher_id'=>'2','teacher_id'=>'3','can_edit_marks'=>'1']);
marksReject(fn()=>marksCourse($db,1,$course,$teacher),'replaced teacher loses access');
marksCheck(marksCourse($db,1,$course,$manager)['id']==$course,'replacement teacher_admin has only explicit course access');
marksCommitImport($db,1,$admin,$preview,$teacherMap,$sectionMap,'preserve');
marksCheck(marksCourse($db,1,$course,$manager)['id']==$course,'preserve import keeps manual replacement');
marksReject(fn()=>marksCourse($db,1,$course,$teacher),'preserve never restores revoked imported teacher');
marksCommitImport($db,1,$admin,$preview,$teacherMap,$sectionMap,'replace');
marksReject(fn()=>marksCourse($db,1,$course,$manager),'replace import revokes manual teacher');
marksCheck(marksCourse($db,1,$course,$teacher)['id']==$course,'replace import restores report assignments');
$c=marksCourse($db,1,$course,$admin);
marksManageCourse($db,1,$course,$admin,['action'=>'assignment_add','course_version'=>(string)$c['version'],'teacher_id'=>'3','can_edit_marks'=>'1']);
marksCheck(count(array_filter(marksAssignmentSnapshot($db,$course),fn($a)=>(int)$a['is_active']===1))===2,'manual co-teacher added');
$c=marksCourse($db,1,$course,$admin);
marksManageCourse($db,1,$course,$admin,['action'=>'teacher_access','course_version'=>(string)$c['version'],'teacher_id'=>'3','teacher_active'=>'1','edit_access_present'=>'1']);
marksReject(fn()=>marksCourse($db,1,$course,$manager),'editing permission can be revoked independently');
marksCommitImport($db,1,$admin,$preview,$teacherMap,$sectionMap,'preserve');
marksReject(fn()=>marksCourse($db,1,$course,$manager),'preserve keeps manual permission restrictions');
// Assignment must not activate accounts or require prior activation.
foreach(['pending_activation','inactive'] as $accountStatus) {
    $db->prepare('UPDATE users SET status=? WHERE id=?')->execute([$accountStatus,3]);
    $c=marksCourse($db,1,$course,$admin);
    marksManageCourse($db,1,$course,$admin,['action'=>'assignment_replace','course_version'=>(string)$c['version'],'old_teacher_id'=>'2','teacher_id'=>'3','can_edit_marks'=>'1']);
    $q=$db->prepare('SELECT status FROM users WHERE id=?');$q->execute([3]);
    marksCheck($q->fetchColumn()===$accountStatus,'assignment preserves account status '.$accountStatus);
    marksCommitImport($db,1,$admin,$preview,$teacherMap,$sectionMap,'replace');
}
$db->exec("UPDATE users SET status='active' WHERE id=3");
$versions=[];foreach([$course,$other] as $id) $versions[$id]=(string)marksCourse($db,1,$id,$admin)['version'];
$badVersions=$versions;$badVersions[max(array_keys($badVersions))]='0';
$beforeBulk=marksAssignmentSnapshot($db,$course);
marksReject(fn()=>marksBulkReplace($db,1,$admin,['course_versions'=>$badVersions,'old_teacher_id'=>'2','teacher_id'=>'3','can_edit_marks'=>'1']),'stale bulk replacement rejected');
marksCheck(marksAssignmentSnapshot($db,$course)===$beforeBulk,'bulk failure rolls back earlier changes');
marksBulkReplace($db,1,$admin,['course_versions'=>$versions,'old_teacher_id'=>'2','teacher_id'=>'3','can_edit_marks'=>'1']);
foreach([$course,$other] as $id) {
    marksCheck(marksCourse($db,1,$id,$manager)['id']==$id,'bulk grants selected replacement');
    marksReject(fn()=>marksCourse($db,1,$id,$teacher),'bulk revokes previous teacher');
}
marksReject(fn()=>marksBulkReplace($db,1,$manager,['course_versions'=>$versions,'old_teacher_id'=>'3','teacher_id'=>'2']),'teacher_admin cannot manage assignments');
marksCheck($db->query('SELECT * FROM student_marks ORDER BY id')->fetchAll()===$recordsBefore,'assignment changes and policies preserve all marks');
marksCheck($db->query('SELECT * FROM mark_submissions ORDER BY id')->fetchAll()===$submissionsBefore,'assignment changes and policies preserve submission locks');
marksCheck(marksReady($db),'all grading tables available');

require_once __DIR__.'/../public_html/includes/teacher_assignments.php';
$attendanceRelation=teacherAttendanceAssignmentsSql($db);
$attendanceSections=static function(int $teacher,int $year=1) use($db,$attendanceRelation): array {
    $q=$db->prepare('SELECT section_id FROM '.$attendanceRelation.' ta WHERE teacher_id=? AND academic_year_id=? ORDER BY section_id');
    $q->execute([$teacher,$year]); return array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
};
marksCheck($attendanceSections(3)===[1,2]&&$attendanceSections(2)===[],'teaching replacement grants new attendance sections and revokes old automatic access');
$db->exec('INSERT INTO teacher_sections VALUES(1,2,1),(2,2,2),(1,3,1)');
marksCheck($attendanceSections(3)===[1,2],'manual and automatic assignments deduplicate per section');
marksCheck($attendanceSections(2)===[1]&&$attendanceSections(2,2)===[2],'manual exceptions preserved and school years isolated');
$db->exec('UPDATE course_teachers SET can_edit_marks=0 WHERE teacher_id=3');
marksCheck($attendanceSections(3)===[1,2],'attendance does not depend on permission to edit marks');
$db->exec('UPDATE course_offerings SET is_active=0');
marksCheck($attendanceSections(3)===[1],'inactive courses revoke derived access but preserve manual exception');
$db->exec('UPDATE course_offerings SET is_active=1');
$db->exec('UPDATE course_teachers SET is_active=0 WHERE teacher_id=3');
marksCheck($attendanceSections(3)===[1],'inactive teaching assignment does not grant attendance');
$db->beginTransaction();
$q=$db->prepare('SELECT 1 FROM '.$attendanceRelation.' ta WHERE teacher_id=? AND section_id=? AND academic_year_id=? LOCK IN SHARE MODE');
$q->execute([2,1,1]); marksCheck((bool)$q->fetchColumn(),'effective authorization relation supports attendance mutation locking');
$db->rollBack();
marksCheck(str_contains(AppPDO::prefixIdentifiers($attendanceRelation,'school_'),'school_course_teachers')&&str_contains(AppPDO::prefixIdentifiers($attendanceRelation,'school_'),'school_teacher_sections'),'shared assignment relation supports DB_PREFIX');
echo "All grading fixtures passed; production data unchanged.\n";
