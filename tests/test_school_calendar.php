<?php
// SPDX-License-Identifier: GPL-3.0-only
// Fixtures only: random disposable database, never loads school config or credentials.
if(PHP_SAPI!=='cli') { http_response_code(403); exit; }
require_once __DIR__.'/../public_html/includes/db.php';
require_once __DIR__.'/../public_html/includes/school_calendar.php';
function calCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo 'PASS: '.$label.PHP_EOL; }
function calReject(callable $fn,string $label): void { try { $fn(); } catch(DomainException $e) { calCheck(true,$label); return; } throw new RuntimeException('Not rejected: '.$label); }
// Render the actual shared page against fixtures without a web session or school config.
function getCurrentUser() { return $GLOBALS['calendarFixtureUser']; }
function getSchoolSetting($key,$default='') { return 'Σχολείο δοκιμής'; }
function getCsrfToken() { return 'fixture-csrf-token'; }
function csrfField() { return '<input type="hidden" name="csrf_token" value="fixture-csrf-token">'; }
function verifyCsrfToken($value=null) { return $value==='fixture-csrf-token'; }
function logoutForm($class='') { return ''; }
function safeError($error) { return 'Σφάλμα δοκιμής'; }
function calRender(PDO $fixtureDb,array $fixtureUser,array $get,array $post=[]): string {
    $GLOBALS['calendarFixtureDb']=$fixtureDb; $GLOBALS['calendarFixtureUser']=$fixtureUser;
    $_GET=$get; $_POST=$post; $_SESSION=[]; $_SERVER['REQUEST_METHOD']=$post?'POST':'GET'; $_SERVER['PHP_SELF']='/teacher/calendar.php';
    $calendarAdminLayout=false; ob_start(); require __DIR__.'/../public_html/includes/school_calendar_page.php'; return ob_get_clean();
}
define('APP_VERSION','test');
$sample=['title'=>'Εκδρομή <δοκιμής>','description'=>'Περιγραφή','internal_notes'=>'ΕΣΩΤΕΡΙΚΟ ΜΥΣΤΙΚΟ ΔΟΚΙΜΗΣ','category'=>'trip','status'=>'planned','visibility'=>'private','start_date'=>'2026-11-09','end_date'=>'2026-11-10','start_time'=>'08:00','end_time'=>'14:00'];
calCheck(calendarInput($sample)['readers']===[],'private default has no readers');
foreach([
    ['title'=>''],['title'=>[]],['description'=>str_repeat('α',10001)],['start_date'=>'2026-02-30'],['end_date'=>'2026-11-08'],['start_time'=>'25:00'],['start_time'=>'','end_time'=>'12:00'],['start_time'=>'12:00','end_time'=>'11:00','end_date'=>'2026-11-09'],['visibility'=>'unknown'],['status'=>[]],['category'=>'invalid'],['readers'=>'2'],['readers'=>['2x']],['visibility'=>'selected','readers'=>[]]
] as $invalid) calReject(fn()=>calendarInput(array_replace($sample,$invalid)),'invalid input '.implode(',',array_keys($invalid)));
$params=[1]; calCheck(str_contains(calendarAccess(false,['id'=>3],$params),'r.user_id=?')&&$params===[1,3],'viewer predicate binds identity');
calCheck(!str_contains(calendarFields(false),'internal_notes')&&!str_contains(calendarFields(false),'created_by'),'private fields excluded from viewer SELECT');
foreach(calendarTables() as $table) calCheck(str_contains(AppPDO::prefixIdentifiers('SELECT * FROM '.$table,'school_'),'school_'.$table),'prefix '.$table);
$options=getopt('',['mysql','host:','port:','user:']);
if(!isset($options['mysql'])) exit("Use --mysql for disposable integration fixtures.\n");
$server='mysql:host='.($options['host']??'localhost').';port='.($options['port']??3306).';charset=utf8mb4';
$pdoOptions=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$fixtureAdmin=new PDO($server,$options['user']??'root',getenv('TEST_MYSQL_PASSWORD')?:'',$pdoOptions);
$fixtureName='absences_calendar_test_'.bin2hex(random_bytes(6));
try {
    $fixtureAdmin->exec('CREATE DATABASE `'.$fixtureName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    define('DB_HOST',$options['host']??'localhost'); define('DB_PORT',$options['port']??3306);
    define('DB_NAME',$fixtureName); define('DB_CHARSET','utf8mb4');
    define('DB_USER',$options['user']??'root'); define('DB_PASS',getenv('TEST_MYSQL_PASSWORD')?:'');
    foreach(['','school_'] as $prefix) {
        AppPDO::setPrefix($prefix);
        $db=new AppPDO($server.';dbname='.$fixtureName,$options['user']??'root',getenv('TEST_MYSQL_PASSWORD')?:'',$pdoOptions);
        $db->exec('CREATE TABLE users(id INT PRIMARY KEY,name VARCHAR(100),role VARCHAR(30),status VARCHAR(30)) ENGINE=InnoDB');
        $db->exec('CREATE TABLE academic_years(id INT PRIMARY KEY,name VARCHAR(30),is_active INT) ENGINE=InnoDB');
        $db->exec('CREATE TABLE settings(setting_key VARCHAR(100) PRIMARY KEY,setting_value TEXT) ENGINE=InnoDB');
        $db->exec("INSERT INTO users VALUES(1,'Διαχειριστής','admin','active'),(2,'Αλφα','teacher','active'),(3,'Βητα','teacher_admin','active'),(4,'Γραμματεία','teacher','active'),(5,'Ανενεργός','teacher','inactive')");
        $db->exec("INSERT INTO academic_years VALUES(1,'2026-2027',1),(2,'2025-2026',0)");
        calCheck(!calendarReady($db),'missing module detected '.$prefix);
        for($i=0;$i<2;$i++) foreach(explode(';',file_get_contents(__DIR__.'/../migrations/20261109_school_calendar.sql')) as $sql) if(trim($sql)!=='') $db->exec($sql);
        calCheck(calendarReady($db),'idempotent migration '.$prefix);
        $admin=['id'=>1,'role'=>'admin']; $teacher=['id'=>2,'role'=>'teacher']; $teacherAdmin=['id'=>3,'role'=>'teacher_admin']; $staff=['id'=>4,'role'=>'teacher'];
        calCheck(calendarCanManage($db,$admin)&&!calendarCanManage($db,$teacherAdmin),'teacher_admin has no implicit management');
        $private=calendarSave($db,1,$admin,$sample);
        $all=calendarSave($db,1,$admin,array_replace($sample,['visibility'=>'all','title'=>'Κοινό <σενάριο>']));
        $selected=calendarSave($db,1,$admin,array_replace($sample,['visibility'=>'selected','readers'=>['2']]));
        calCheck(count(calendarEvents($db,1,$teacher,'2026-11-01','2026-11-30'))===2,'teacher sees all + selected only');
        calCheck(count(calendarEvents($db,1,$teacherAdmin,'2026-11-01','2026-11-30'))===1,'teacher_admin sees only public event');
        calReject(fn()=>calendarEvent($db,1,$private,$teacher),'direct private ID denied');
        calReject(fn()=>calendarEvent($db,1,$selected,$teacherAdmin),'direct selected ID denied');
        $public=calendarEvent($db,1,$all,$teacher);
        calCheck(!isset($public['internal_notes'],$public['readers'],$public['visibility'],$public['created_by']),'viewer projection omits confidential metadata');
        calCheck(!str_contains(json_encode(calendarEvents($db,1,$teacher,'2026-11-01','2026-11-30')),'ΕΣΩΤΕΡΙΚΟ'),'no internal notes in month results');
        calCheck(count(calendarEvents($db,1,$admin,'2026-11-10','2026-11-10'))===3,'multi-day events overlap range');
        $html=calRender($db,$teacher,['view'=>'month','month'=>'2026-11']);
        calCheck(str_contains($html,'Κοινό &lt;σενάριο&gt;')&&!str_contains($html,'ΕΣΩΤΕΡΙΚΟ ΜΥΣΤΙΚΟ'),'month HTML escapes titles and omits private notes');
        calCheck(!str_contains($html,'cal-day-add'),'viewer has no day creation controls');
        $html=calRender($db,$admin,['view'=>'month','month'=>'2026-11']);
        calCheck(substr_count($html,'class="cal-day-add"')===30&&str_contains($html,'date=2026-11-10'),'each day has authorized dated add control');
        $html=calRender($db,$admin,['view'=>'edit','date'=>'2026-11-23']);
        calCheck(str_contains($html,'cal-editor')&&str_contains($html,'name="start_date" required value="2026-11-23"')&&str_contains($html,'name="end_date" required value="2026-11-23"'),'modal creation prefills clicked day');
        $html=calRender($db,$teacher,['view'=>'detail','event'=>$all]);
        calCheck(!str_contains($html,'Εσωτερικές σημειώσεις')&&!str_contains($html,'Επεξεργασία'),'teacher detail is read-only with no internal fields');
        $html=calRender($db,$teacher,['view'=>'detail','event'=>$private]);
        calCheck(str_contains($html,'δεν είναι διαθέσιμη')&&!str_contains($html,'ΕΣΩΤΕΡΙΚΟ ΜΥΣΤΙΚΟ'),'private direct page denies content');
        $html=calRender($db,$admin,['view'=>'edit','event'=>$private]);
        calCheck(str_contains($html,'ΕΣΩΤΕΡΙΚΟ ΜΥΣΤΙΚΟ')&&str_contains($html,'name="version" value="1"'),'manager form includes private notes and version');
        $html=calRender($db,$admin,['view'=>'edit'],array_replace($sample,['action'=>'save','year_id'=>'1','csrf_token'=>'invalid']));
        calCheck(str_contains($html,'Μη έγκυρο αίτημα ασφαλείας')&&(int)$db->query('SELECT COUNT(*) FROM school_calendar_events')->fetchColumn()===3,'page rejects invalid CSRF without mutation');
        $html=calRender($db,$admin,['view'=>'edit'],array_replace($sample,['action'=>'save','year_id'=>'2','csrf_token'=>'fixture-csrf-token']));
        calCheck(str_contains($html,'Η αλλαγή δεν επιτρέπεται')&&(int)$db->query('SELECT COUNT(*) FROM school_calendar_events')->fetchColumn()===3,'page rejects stale year without mutation');
        calReject(fn()=>calendarSave($db,1,$teacherAdmin,$sample),'teacher_admin write denied');
        calReject(fn()=>calendarSave($db,1,$teacher,array_replace($sample,['id'=>$all,'version'=>1])),'viewer edit denied');
        calReject(fn()=>calendarDelete($db,1,$teacher,$all,1),'viewer deletion denied');
        calReject(fn()=>calendarSetManager($db,1,$teacherAdmin,4,true),'non-admin grant denied');
        calReject(fn()=>calendarSetManager($db,1,$admin,5,true),'inactive manager denied');
        calendarSetManager($db,1,$admin,4,true);
        calCheck(calendarCanManage($db,$staff)&&count(calendarEvents($db,1,$staff,'2026-11-01','2026-11-30'))===3,'delegated staff sees private entries');
        $staffEntry=calendarSave($db,1,$staff,array_replace($sample,['category'=>'task','status'=>'pending','start_date'=>'2026-10-01','end_date'=>'2026-10-01']));
        calCheck(count(calendarEvents($db,1,$staff,'2026-11-11','2026-11-30',true))===1,'overdue task remains in agenda');
        calendarSetManager($db,1,$admin,4,false);
        calReject(fn()=>calendarEvent($db,1,$private,$staff),'revocation removes private read access');
        calReject(fn()=>calendarSave($db,1,$staff,$sample),'revocation removes write access');
        $row=calendarEvent($db,1,$selected,$admin);
        $row['start_time']=substr($row['start_time'],0,5); $row['end_time']=substr($row['end_time'],0,5); $row['readers']=['3'];
        calendarSave($db,1,$admin,$row);
        calReject(fn()=>calendarEvent($db,1,$selected,$teacher),'removed selected reader denied immediately');
        calCheck(calendarEvent($db,1,$selected,$teacherAdmin)['id']==$selected,'new selected reader allowed');
        calReject(fn()=>calendarSave($db,1,$admin,$row),'stale edit rejected');
        calReject(fn()=>calendarDelete($db,1,$admin,$selected,1),'stale deletion rejected');
        calReject(fn()=>calendarSave($db,2,$admin,$sample),'inactive year write denied');
        calReject(fn()=>calendarEvent($db,2,$selected,$admin),'cross-year ID denied');
        calReject(fn()=>calendarSave($db,1,$admin,array_replace($sample,['visibility'=>'selected','readers'=>[5]])),'inactive recipient rejected');
        calCheck((int)$db->query('SELECT COUNT(*) FROM school_calendar_events')->fetchColumn()===4,'failed writes roll back');
        calendarDelete($db,1,$admin,$all,1);
        calReject(fn()=>calendarEvent($db,1,$all,$teacher),'deleted event inaccessible');
        calCheck((int)$db->query('SELECT COUNT(*) FROM school_calendar_events')->fetchColumn()===4,'deletion retains archive');
        $audit=$db->query('SELECT action FROM calendar_audit_log ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        calCheck(count($audit)===8&&end($audit)==='delete','atomic event and permission audit trail');
        $db->exec("UPDATE school_calendar_events SET status='completed' WHERE id=".$staffEntry);
        calCheck(calendarEvents($db,1,$admin,'2026-11-11','2026-11-30',true)===[],'completed task leaves reminders');
        require_once __DIR__.'/../public_html/includes/school_calendar_print_data.php';
        $december=calendarSave($db,1,$admin,array_replace($sample,['title'=>'Δεκέμβριος','visibility'=>'all','start_date'=>'2026-12-01','end_date'=>'2026-12-01']));
        $finishedDecember=calendarSave($db,1,$admin,array_replace($sample,['title'=>'Ολοκληρωμένο Δεκεμβρίου','visibility'=>'all','status'=>'completed','start_date'=>'2026-12-02','end_date'=>'2026-12-02']));
        $privateDecember=calendarSave($db,1,$admin,array_replace($sample,['title'=>'Ιδιωτικό Δεκεμβρίου','start_date'=>'2026-12-01','end_date'=>'2026-12-01']));
        $print=calendarPrintSelection($db,1,$teacher,[],'2026-11-30');
        calCheck(array_map('intval',array_column($print['events'],'id'))===[$december],'November 30 print includes December without month boundary');
        $print=calendarPrintSelection($db,1,$teacher,['scope'=>'year']);
        calCheck(in_array($finishedDecember,array_map('intval',array_column($print['events'],'id')),true),'full year print includes completed entries');
        calCheck(!in_array($privateDecember,array_map('intval',array_column($print['events'],'id')),true),'print excludes inaccessible private events');
        $print=calendarPrintSelection($db,1,$teacher,['scope'=>'range','from'=>'2026-12-02','to'=>'2026-12-02']);
        calCheck(array_map('intval',array_column($print['events'],'id'))===[$finishedDecember],'explicit print range includes matching past or completed entries');
        calReject(fn()=>calendarPrintSelection($db,1,$teacher,['scope'=>'range','from'=>'2026-12-02','to'=>'2026-12-01']),'reversed print range rejected');
        calReject(fn()=>calendarPrintSelection($db,1,$teacher,['scope'=>[]]),'invalid print scope rejected');
        $db->exec("INSERT INTO settings VALUES('academic_year_closed_1','1')");
        calReject(fn()=>calendarSave($db,1,$admin,$sample),'closed active year refuses calendar writes');
        $db->exec("DELETE FROM settings WHERE setting_key='academic_year_closed_1'");
        $db->exec('UPDATE academic_years SET is_active=0 WHERE id=1');
        calCheck(calendarEvent($db,1,$private,$admin)['id']==$private,'archived year readable');
        calReject(fn()=>calendarDelete($db,1,$admin,$private,1),'year switch prevents old form mutation');
        calCheck(!$db->inTransaction(),'failed mutations release transaction');
    }
} finally {
    if(preg_match('/^absences_calendar_test_[a-f0-9]{12}$/D',$fixtureName)) $fixtureAdmin->exec('DROP DATABASE `'.$fixtureName.'`');
    AppPDO::setPrefix(defined('DB_PREFIX')?DB_PREFIX:'');
}
echo "Calendar fixtures completed; no school data changed.\n";
