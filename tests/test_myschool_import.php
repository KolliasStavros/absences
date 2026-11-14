<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/../public_html/includes/myschool_import.php';
require_once __DIR__.'/../public_html/includes/teacher_lifecycle.php';
set_exception_handler(static function(Throwable $e){fwrite(STDERR,'FAIL: '.get_class($e).' at '.basename($e->getFile()).':'.$e->getLine().' code='.$e->getCode()."\n");exit(1);});
function schoolCheck(bool $ok,string $label): void{if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
function schoolReject(callable $call,string $label): void{try{$call();}catch(DomainException $e){schoolCheck(true,$label);return;}throw new RuntimeException($label);}
schoolCheck(myschoolNumber('001234')==='001234','registry preserves leading zeroes');
schoolReject(fn()=>myschoolNumber('abc'),'registry rejects non digits');
schoolCheck(myschoolNameKey('Άλφα Καθηγητής')===myschoolNameKey('ΚΑΘΗΓΗΤΗΣ ΑΛΦΑ'),'name matching ignores order, accents and case');
require_once __DIR__.'/../public_html/includes/spreadsheet/vendor/autoload.php';
$tmp=tempnam(sys_get_temp_dir(),'school_fixture_');
try{
    $book=new \PhpOffice\PhpSpreadsheet\Spreadsheet();$sheet=$book->getActiveSheet();
    foreach(['B1'=>'Αριθμός μητρώου','C1'=>'Επώνυμο μαθητή','D1'=>'Όνομα μαθητή','G1'=>'Τμήματα','B2'=>'001','C2'=>'ΔΟΚΙΜΗ','D2'=>'ΜΑΘΗΤΗΣ','G2'=>'Α1, ΑΞΕΝ1','E2'=>'EXCLUDED_PRIVATE_FIELD'] as $cell=>$value)$sheet->setCellValueExplicit($cell,$value,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    (new \PhpOffice\PhpSpreadsheet\Writer\Xls($book))->save($tmp);
    $students=myschoolReadPeople($tmp,'students');
    schoolCheck(count($students)===1&&$students[0]['am']==='001'&&$students[0]['grade']==='Α'&&count($students[0]['sections'])===2,'binary XLS student registry and section parsing');
    schoolCheck(!str_contains(json_encode($students),'EXCLUDED_PRIVATE_FIELD'),'non allowlisted student columns never enter preview');
    schoolReject(fn()=>myschoolReadPeople($tmp,'teachers'),'student report cannot be mistaken for teacher registry');
    $sheet->setCellValue('C1','Επώνυμο');$sheet->setCellValue('D1','Όνομα');$sheet->setCellValue('G2','EXCLUDED_PRIVATE_FIELD');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xls($book))->save($tmp);$teachers=myschoolReadPeople($tmp,'teachers');
    schoolCheck(!str_contains(json_encode($teachers),'EXCLUDED_PRIVATE_FIELD')&&!isset($teachers[0]['sections']),'teacher reader excludes every non registry/name column');
    $sheet->setCellValueExplicit('B3','001',\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);$sheet->setCellValue('C3','ΑΛΛΟΣ');$sheet->setCellValue('D3','ΚΑΘΗΓΗΤΗΣ');(new \PhpOffice\PhpSpreadsheet\Writer\Xls($book))->save($tmp);
    schoolReject(fn()=>myschoolReadPeople($tmp,'teachers'),'duplicate registry rejected');
    $sheet->setCellValue('B3','');$sheet->setCellValue('C2','=1+1');(new \PhpOffice\PhpSpreadsheet\Writer\Xls($book))->save($tmp);
    schoolReject(fn()=>myschoolReadPeople($tmp,'teachers'),'formula cells rejected');
    $book->disconnectWorksheets();
}finally{if(is_file($tmp))unlink($tmp);}
$teachers=[['am'=>'00101','name'=>'ΑΛΦΑ ΚΑΘΗΓΗΤΗΣ','last_name'=>'ΑΛΦΑ','first_name'=>'ΚΑΘΗΓΗΤΗΣ'],['am'=>'00202','name'=>'ΒΗΤΑ ΚΑΘΗΓΗΤΗΣ','last_name'=>'ΒΗΤΑ','first_name'=>'ΚΑΘΗΓΗΤΗΣ']];
$students=[['am'=>'001','name'=>'ΔΟΚΙΜΗ ΜΑΘΗΤΗΣ','last_name'=>'ΔΟΚΙΜΗ','first_name'=>'ΜΑΘΗΤΗΣ','sections'=>['Α1'],'grade'=>'Α'],['am'=>'002','name'=>'ΝΕΟΣ ΜΑΘΗΤΗΣ','last_name'=>'ΝΕΟΣ','first_name'=>'ΜΑΘΗΤΗΣ','sections'=>['Α1'],'grade'=>'Α']];
$assignments=['year_name'=>'2026-2027','rows'=>[['teacher_key'=>'ΑΛΦΑ ΚΑΘΗΓΗΤΗΣ','teacher_name'=>'ΑΛΦΑ ΚΑΘΗΓΗΤΗΣ','section'=>'Α1','subject'=>'ΔΟΚΙΜΑΣΤΙΚΟ ΜΑΘΗΜΑ','hours'=>'2.00','assignment'=>'Α','line'=>1]]];
$preview=myschoolPreview($students,$teachers,$assignments);
schoolCheck($preview['links']['ΑΛΦΑ ΚΑΘΗΓΗΤΗΣ']['am']==='00101','assignment name joins unique teacher registry');
$ambiguous=$teachers;$ambiguous[1]['name']=$ambiguous[0]['name'];$p=myschoolPreview($students,$ambiguous,$assignments);
schoolCheck($p['links']['ΑΛΦΑ ΚΑΘΗΓΗΤΗΣ']['am']==='','ambiguous names require manual selection');
if(!in_array('--mysql',$argv,true)){echo "Use --mysql for disposable MySQL fixtures.\n";exit;}
require_once __DIR__.'/../public_html/config/database.php';require_once __DIR__.'/../public_html/includes/db.php';
$host='mysql:host='.DB_HOST.';port='.(defined('DB_PORT')?DB_PORT:3306).';charset=utf8mb4';
$fixtureUser=getenv('MARKS_DB_USER')?:DB_USER;$fixturePassword=getenv('MARKS_DB_USER')?(getenv('MARKS_DB_PASS')?:''):DB_PASS;
$maintenance=new PDO($host,$fixtureUser,$fixturePassword,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
foreach(['','school_'] as $prefix){
    $fixture='absences_myschool_fixture_'.bin2hex(random_bytes(6));
    if(!preg_match('/^absences_myschool_fixture_[a-f0-9]{12}$/D',$fixture))throw new RuntimeException('Unsafe fixture name');
    $maintenance->exec('CREATE DATABASE `'.$fixture.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    try{
        $db=new AppPDO($host.';dbname='.$fixture,$fixtureUser,$fixturePassword,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);AppPDO::setPrefix($prefix);
        foreach([
            'users'=>"id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(150),email VARCHAR(150) UNIQUE,role VARCHAR(30),status VARCHAR(30),auth_version INT DEFAULT 1",
            'academic_years'=>'id INT PRIMARY KEY,name VARCHAR(50),is_active INT',
            'grades'=>'id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(50) UNIQUE',
            'sections'=>'id INT AUTO_INCREMENT PRIMARY KEY,grade_id INT NOT NULL,name VARCHAR(100),UNIQUE(grade_id,name),FOREIGN KEY(grade_id) REFERENCES grades(id)',
            'students'=>"id INT AUTO_INCREMENT PRIMARY KEY,academic_year_id INT,am VARCHAR(50),last_name VARCHAR(100),first_name VARCHAR(100),gender VARCHAR(10),guardian_email VARCHAR(150),guardian_mobile VARCHAR(30),UNIQUE(academic_year_id,am)",
            'student_sections'=>'student_id INT,section_id INT,PRIMARY KEY(student_id,section_id),FOREIGN KEY(student_id) REFERENCES students(id),FOREIGN KEY(section_id) REFERENCES sections(id)',
            'teacher_sections'=>'academic_year_id INT,teacher_id INT,section_id INT,PRIMARY KEY(academic_year_id,teacher_id,section_id),FOREIGN KEY(teacher_id) REFERENCES users(id)',
            'password_resets'=>'id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,used_at DATETIME NULL',
            'attendance'=>'id INT AUTO_INCREMENT PRIMARY KEY,teacher_id INT,student_id INT,FOREIGN KEY(teacher_id) REFERENCES users(id) ON DELETE RESTRICT',
            'settings'=>'setting_key VARCHAR(100) PRIMARY KEY,setting_value TEXT',
        ] as $name=>$columns)$db->exec('CREATE TABLE '.$name.' ('.$columns.') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        marksInstall($db);myschoolInstallRegistry($db);myschoolInstallRegistry($db);
        schoolCheck(myschoolRegistryReady($db),'registry migration idempotent '.$prefix);
        $db->exec("INSERT INTO users(id,name,email,role,status) VALUES(1,'ΔΙΑΧΕΙΡΙΣΤΗΣ','admin@example.test','admin','active'),(2,'ΑΛΦΑ ΚΑΘΗΓΗΤΗΣ','teacher@example.test','teacher','active')");
        $db->exec("INSERT INTO academic_years VALUES(1,'2026-2027',1),(2,'2025-2026',0)");$db->exec("INSERT INTO grades VALUES(1,'Α')");$db->exec("INSERT INTO sections VALUES(1,1,'Α1')");
        $db->exec("INSERT INTO students(id,academic_year_id,am,last_name,first_name,gender,guardian_email,guardian_mobile) VALUES(1,1,'001','ΠΑΛΙΟ','ΟΝΟΜΑ','female','guardian@example.test','preserved'),(9,2,'001','ΠΑΛΙΟ','ΕΤΟΣ','male',NULL,NULL)");$db->exec('INSERT INTO student_sections VALUES(1,1)');
        marksSeedPeriods($db,1);$actor=['id'=>1,'role'=>'admin'];$p=$preview;$p['snapshot']=myschoolState($db,1);
        $accounts=['00101'=>'2','00202'=>'new'];$links=[hash('sha256','ΑΛΦΑ ΚΑΘΗΓΗΤΗΣ')=>'00101'];$map=[hash('sha256','Α1')=>'1'];$grades=[hash('sha256','Α1')=>'Α'];
        $result=myschoolCommit($db,1,$actor,$p,$accounts,$links,$map,$grades,'preserve');
        schoolCheck($result['students_new']===1&&$result['students_updated']===1&&$result['teachers_new']===1,'combined import commits students registry and courses '.$prefix);
        $s=$db->query('SELECT * FROM students WHERE id=1')->fetch();schoolCheck($s['guardian_email']==='guardian@example.test'&&$s['guardian_mobile']==='preserved'&&$s['gender']==='female','contacts and existing gender preserved '.$prefix);
        schoolCheck($db->query('SELECT last_name FROM students WHERE id=9')->fetchColumn()==='ΠΑΛΙΟ','other school year untouched '.$prefix);
        schoolCheck($db->query('SELECT email FROM users WHERE id=2')->fetchColumn()==='teacher@example.test','existing teacher email preserved '.$prefix);
        $newTeacher=(int)$db->query("SELECT id FROM users WHERE teacher_registry_number='00202'")->fetchColumn();$accounts['00202']=(string)$newTeacher;
        schoolCheck($db->query('SELECT status FROM users WHERE id='.$newTeacher)->fetchColumn()==='pending_activation','new teachers not automatically activated '.$prefix);
        if(in_array('--render',$argv,true)&&$prefix==='school_'){
            $source=file_get_contents(__DIR__.'/../public_html/admin/myschool_import.php');$start=strpos($source,"?>\n<link");$markup=substr($source,$start);$markup=str_replace("require __DIR__.'/footer.php';",'',$markup);
            $ready=true;$error=$message='';$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');$year=['id'=>1,'name'=>'2026-2027'];$yearId=1;$_SESSION['myschool_preview']=$preview;$_SESSION['myschool_preview']['token']='synthetic-preview';
            $base=static function(){echo '<input name="csrf_token" type="hidden" value="synthetic"><input name="year_id" type="hidden" value="1">';};
            ob_start();eval($markup);$html=ob_get_clean();file_put_contents(__DIR__.'/../scratch/myschool-import-fixture.html','<!doctype html><html lang="el"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="../assets/css/style.css"></head><body class="admin-layout"><main style="max-width:1400px;margin:auto;padding:20px">'.$html.'</main></body></html>');
            unset($_SESSION['myschool_preview']);
        }
        $course=(int)$db->query('SELECT id FROM course_offerings LIMIT 1')->fetchColumn();$period=(int)$db->query('SELECT id FROM assessment_periods LIMIT 1')->fetchColumn();
        schoolCheck((int)$db->query('SELECT COUNT(*) FROM course_students WHERE is_active=1')->fetchColumn()===2,'course rosters derive from imported memberships '.$prefix);
        $p['snapshot']=myschoolState($db,1);myschoolCommit($db,1,$actor,$p,$accounts,$links,$map,$grades,'preserve');
        schoolCheck((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()===3&&(int)$db->query('SELECT COUNT(*) FROM students')->fetchColumn()===3&&(int)$db->query('SELECT COUNT(*) FROM course_offerings')->fetchColumn()===1,'reimport idempotent '.$prefix);
        schoolReject(fn()=>myschoolCommit($db,1,$actor,$p,$accounts,$links,$map,$grades,'preserve'),'stale preview rejected '.$prefix);
        $p['snapshot']=myschoolState($db,1);$p['students'][0]['last_name']='ROLLBACK';$bad=$accounts;$bad['00202']='2';
        schoolReject(fn()=>myschoolCommit($db,1,$actor,$p,$bad,$links,$map,$grades,'preserve'),'invalid teacher mapping rolls entire import back '.$prefix);
        schoolCheck($db->query('SELECT last_name FROM students WHERE id=1')->fetchColumn()==='ΔΟΚΙΜΗ','student write rolled back with teacher error '.$prefix);
        $p=$preview;$p['snapshot']=myschoolState($db,1);$p['students'][]=['am'=>'003','name'=>'ΝΕΟΣ ΤΡΙΤΟΣ','last_name'=>'ΝΕΟΣ','first_name'=>'ΤΡΙΤΟΣ','sections'=>['Α1'],'grade'=>'Α'];
        $db->prepare("INSERT INTO mark_submissions(course_offering_id,assessment_period_id,status,submitted_by) VALUES(?,?,'submitted',2)")->execute([$course,$period]);$p['snapshot']=myschoolState($db,1);
        schoolReject(fn()=>myschoolCommit($db,1,$actor,$p,$accounts,$links,$map,$grades,'replace'),'final submissions block roster change atomically '.$prefix);
        schoolCheck((int)$db->query('SELECT COUNT(*) FROM students')->fetchColumn()===3,'blocked roster leaves no new student '.$prefix);
        $cs=(int)$db->query('SELECT id FROM course_students LIMIT 1')->fetchColumn();$db->prepare("INSERT INTO student_marks(course_student_id,assessment_period_id,mark_value,updated_by) VALUES(?,?,15,2)")->execute([$cs,$period]);
        $db->exec('INSERT INTO attendance(teacher_id,student_id) VALUES(2,1)');$db->exec('INSERT INTO teacher_sections VALUES(1,2,1),(2,2,1)');$db->exec('INSERT INTO password_resets(user_id) VALUES(2)');
        $courseRow=$db->query('SELECT * FROM course_offerings WHERE id='.$course)->fetch();$db->beginTransaction();marksChangeAssignment($db,$courseRow,$actor,['action'=>'assignment_replace','old_teacher_id'=>'2','teacher_id'=>(string)$newTeacher,'can_edit_marks'=>'1']);$db->commit();
        schoolCheck((int)$db->query('SELECT updated_by FROM student_marks LIMIT 1')->fetchColumn()===2,'replacement preserves original grade editor '.$prefix);
        $db->beginTransaction();archiveTeacher($db,2,1,$actor);$db->commit();
        schoolCheck($db->query('SELECT status FROM users WHERE id=2')->fetchColumn()==='inactive'&&(int)$db->query('SELECT auth_version FROM users WHERE id=2')->fetchColumn()===2,'archive revokes active sessions '.$prefix);
        schoolCheck((int)$db->query('SELECT teacher_id FROM attendance LIMIT 1')->fetchColumn()===2&&(int)$db->query('SELECT updated_by FROM student_marks LIMIT 1')->fetchColumn()===2&&(int)$db->query('SELECT submitted_by FROM mark_submissions LIMIT 1')->fetchColumn()===2,'archive preserves grade absence and submission actors with real FKs '.$prefix);
        schoolCheck((int)$db->query('SELECT COUNT(*) FROM teacher_sections WHERE academic_year_id=1')->fetchColumn()===0&&(int)$db->query('SELECT COUNT(*) FROM teacher_sections WHERE academic_year_id=2')->fetchColumn()===1,'archive removes current manual grants only '.$prefix);
        schoolCheck((int)$db->query('SELECT COUNT(*) FROM password_resets WHERE used_at IS NULL')->fetchColumn()===0,'archive cancels outstanding invitations '.$prefix);
        $p=$preview;$p['snapshot']=myschoolState($db,1);myschoolCommit($db,1,$actor,$p,$accounts,$links,$map,$grades,'preserve');
        schoolCheck($db->query('SELECT status FROM users WHERE id=2')->fetchColumn()==='inactive','reimport does not reactivate archived account '.$prefix);
        schoolCheck((int)$db->query('SELECT is_active FROM course_teachers WHERE teacher_id='.$newTeacher.' AND course_offering_id='.$course)->fetchColumn()===1,'preserve import retains replacement '.$prefix);
    }finally{$db=null;AppPDO::setPrefix('');$maintenance->exec('DROP DATABASE `'.$fixture.'`');}
}
echo "All MySchool fixtures passed; actual school data unchanged.\n";
