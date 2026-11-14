<?php
// Run the production attendance validators with denial converted to an exception for assertions.
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../public_html/includes/teacher_assignments.php';require __DIR__.'/../public_html/includes/co_teaching.php';
$source=file_get_contents(__DIR__.'/../public_html/includes/attendance_security.php');$start=strpos($source,'function validateAttendanceSection(');$end=strpos($source,'function assertMutableAttendanceHours(',$start);$functions=substr($source,$start,$end-$start);$functions=str_replace('__DIR__',var_export(realpath(__DIR__.'/../includes'),true),$functions);
eval('namespace TeacherScopeFixture; use \PDO; function teacherAttendanceAssignmentsSql(\PDO $db): string { return \teacherAttendanceAssignmentsSql($db); } function coTeachingGroups(\PDO $db): array { return \coTeachingGroups($db); } function coTeachingMembershipSql(array $groups): string { return \coTeachingMembershipSql($groups); } function attendanceDenied(\PDO $db,int $status,string $message): never { throw new \DomainException($message,$status); } '.$functions);
function scopeCheck(bool $ok,string $label): void{if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
function scopeDeny(callable $action,int $code,string $label): void{try{$action();}catch(DomainException $e){scopeCheck($e->getCode()===$code,$label);return;}throw new RuntimeException($label);}
$db=new PDO('mysql:host=localhost;port=3306;charset=utf8mb4','root',getenv('TEST_MYSQL_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$name='absences_scope_fixture_'.bin2hex(random_bytes(6));
try{
 $db->exec('CREATE DATABASE `'.$name.'`');$db->exec('USE `'.$name.'`');
 foreach(['sections'=>'id INT PRIMARY KEY','students'=>'id INT PRIMARY KEY,academic_year_id INT','student_sections'=>'student_id INT,section_id INT','teacher_sections'=>'academic_year_id INT,teacher_id INT,section_id INT','course_offerings'=>'id INT PRIMARY KEY,academic_year_id INT,section_id INT,is_active INT','course_teachers'=>'course_offering_id INT,teacher_id INT,is_active INT,can_edit_marks INT','settings'=>'setting_key VARCHAR(100),setting_value TEXT'] as $table=>$columns)$db->exec('CREATE TABLE '.$table.'('.$columns.') ENGINE=InnoDB');
 $db->exec('INSERT INTO sections VALUES(1),(2),(3)');$db->exec('INSERT INTO students VALUES(1,1),(2,1),(3,2)');$db->exec('INSERT INTO student_sections VALUES(1,1),(2,2),(3,1)');$db->exec('INSERT INTO teacher_sections VALUES(1,10,1),(2,10,3),(1,20,2)');$db->exec('INSERT INTO course_offerings VALUES(1,1,2,1),(2,1,3,0),(3,2,3,1)');$db->exec('INSERT INTO course_teachers VALUES(1,10,1,0),(2,10,1,1),(3,10,1,1)');
 $teacher=['id'=>10,'role'=>'teacher'];
 \TeacherScopeFixture\validateAttendanceSection($db,1,1,$teacher);scopeCheck(true,'teacher manual section allowed');
 \TeacherScopeFixture\validateAttendanceSection($db,2,1,$teacher);scopeCheck(true,'teacher active course grants attendance independently of marks permission');
 scopeDeny(fn()=>\TeacherScopeFixture\validateAttendanceSection($db,3,1,$teacher),403,'inactive course and other-year assignments do not grant current attendance');
 scopeDeny(fn()=>\TeacherScopeFixture\validateAttendanceSection($db,1,1,['id'=>20,'role'=>'teacher']),403,'forged other section ID denied');
 scopeDeny(fn()=>\TeacherScopeFixture\validateAttendanceSection($db,999,1,$teacher),400,'nonexistent section denied');
 \TeacherScopeFixture\validateAttendanceStudent($db,1,1,1);scopeCheck(true,'student in assigned section and current year allowed');
 scopeDeny(fn()=>\TeacherScopeFixture\validateAttendanceStudent($db,2,1,1),400,'forged student from another section denied');
 scopeDeny(fn()=>\TeacherScopeFixture\validateAttendanceStudent($db,3,1,1),400,'student from another school year denied');
 \TeacherScopeFixture\validateAttendanceSection($db,3,1,['id'=>30,'role'=>'teacher_admin']);scopeCheck(true,'teacher_admin has intentional broad attendance permissions');
 $db->exec('UPDATE course_teachers SET is_active=0 WHERE course_offering_id=1');
 scopeDeny(fn()=>\TeacherScopeFixture\validateAttendanceSection($db,2,1,$teacher),403,'revoked teaching assignment immediately denies attendance');
}finally{if(preg_match('/^absences_scope_fixture_[a-f0-9]{12}$/D',$name))$db->exec('DROP DATABASE `'.$name.'`');}
