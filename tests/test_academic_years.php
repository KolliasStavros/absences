<?php
// Real FKs on disposable databases. Never load the school configuration or write school rows.
if(PHP_SAPI!=='cli')exit;
require_once __DIR__.'/../public_html/includes/academic_year_archives.php';
require_once __DIR__.'/../public_html/includes/academic_year_state.php';
require_once __DIR__.'/../public_html/includes/marks.php';
function getCurrentUser(){return $GLOBALS['annualContextActor']??null;}
$source=file_get_contents(__DIR__.'/../public_html/includes/attendance_security.php');$a=strpos($source,'function beginAttendanceMutation(');$b=strpos($source,'function attendanceDenied(',$a);
eval('namespace AnnualScopeFixture; use \\PDO; function getCurrentUser(){return ["id"=>1,"role"=>"admin"];} function attendanceDenied(PDO $db,int $status,string $message): never {if($db->inTransaction())$db->rollBack();throw new \\DomainException($message);} '.substr($source,$a,$b-$a));
$source=file_get_contents(__DIR__.'/../public_html/includes/db.php');$a=strpos($source,'function getActiveAcademicYear(');$b=strpos($source,'function getAllAcademicYearsWithStats(',$a);
eval('namespace AnnualContextFixture; use \\PDO; function getDB(){return $GLOBALS["annualContextDB"];} function getCurrentUser(){return $GLOBALS["annualContextActor"];} '.substr($source,$a,$b-$a));
set_exception_handler(static function(Throwable $e){fwrite(STDERR,'FAIL '.get_class($e).' '.basename($e->getFile()).':'.$e->getLine().' code '.$e->getCode().' '.$e->getMessage()."\n");exit(1);});
function yc(bool $ok,string $label): void {if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function yr(callable $fn,string $label): void {try{$fn();}catch(DomainException $e){yc(true,$label);return;}throw new RuntimeException($label);}
function ys(PDO $db,string $prefix): void {preg_match_all('/CREATE TABLE IF NOT EXISTS .*?;/s',file_get_contents(__DIR__.'/../public_html/schema.sql'),$matches);foreach($matches[0] as $sql)$db->exec(preg_replace('/(CREATE TABLE IF NOT EXISTS|REFERENCES)\s+`?([a-z][a-z0-9_]*)`?/i','$1 `'.$prefix.'$2`',$sql));}
function ym(string $in,string $out,string $table,callable $fn): string {
    copy($in,$out);$z=new ZipArchive();$z->open($out);$m=json_decode($z->getFromName('manifest.json'),true);$raw=$z->getFromName('data/'.$table.'.ndjson');$rows=[];foreach(explode("\n",trim($raw)) as $line)if($line!=='')$rows[]=json_decode($line,true);$fn($rows);$raw='';foreach($rows as $r)$raw.=json_encode($r,JSON_UNESCAPED_UNICODE)."\n";$m['tables'][$table]=['rows'=>count($rows),'bytes'=>strlen($raw),'sha256'=>hash('sha256',$raw)];$z->addFromString('data/'.$table.'.ndjson',$raw);$z->addFromString('manifest.json',json_encode($m,JSON_UNESCAPED_UNICODE));$z->close();return hash_file('sha256',$out);
}
$attrs=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];$dsn='mysql:host=localhost;port=3306;charset=utf8mb4';$pass=getenv('TEST_MYSQL_PASSWORD')?:'';$root=new PDO($dsn,'root',$pass,$attrs);$dbNames=[];
$fixture=dirname(__DIR__).'/scratch/year-fixture-'.bin2hex(random_bytes(6));mkdir($fixture,0700,true);
try{
 foreach(['','school_'] as $case=>$p){
    $name='absences_year_fixture_'.bin2hex(random_bytes(6));$root->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$dbNames[]=$name;$db=new PDO($dsn.';dbname='.$name,'root',$pass,$attrs);ys($db,$p);
    $db->exec("INSERT INTO `{$p}users`(id,name,email,teacher_registry_number,role,status,password_hash) VALUES(1,'Admin','admin@example.test','0001','admin','active','ADMIN_SECRET'),(2,'Teacher','teacher@example.test','0002','teacher','active','TEACHER_SECRET'),(3,'Retired','retired@example.test','0003','teacher','inactive',NULL),(4,'Unrelated','unrelated@example.test','0004','teacher','active','OTHER_SECRET')");
    $db->exec("INSERT INTO `{$p}academic_years`(id,name,is_active) VALUES(1,'2025-2026',0),(2,'2026-2027',1)");$db->exec("INSERT INTO `{$p}grades`(id,name) VALUES(1,'A')");$db->exec("INSERT INTO `{$p}sections`(id,grade_id,name) VALUES(1,1,'A1'),(2,1,'A2')");$db->exec("INSERT INTO `{$p}subjects`(id,source_name,display_name) VALUES(1,'Maths','Maths')");
    foreach([1,2] as $y){
        $db->exec("INSERT INTO `{$p}students`(id,academic_year_id,am,last_name,first_name) VALUES($y,$y,'0001','Student$y','Fixture')");$db->exec("INSERT INTO `{$p}student_sections` VALUES($y,$y)");$db->exec("INSERT INTO `{$p}teacher_sections` VALUES($y,2,$y)");
        $db->exec("INSERT INTO `{$p}attendance`(id,academic_year_id,date,hour,section_id,student_id,teacher_id,note) VALUES($y,$y,'2026-01-01',1,$y,$y,3,'History$y')");$db->exec("INSERT INTO `{$p}attendance_notes`(academic_year_id,student_id,date,hour,note,teacher_id) VALUES($y,$y,'2026-01-01',1,'Note$y',2)");$db->exec("INSERT INTO `{$p}absence_day_reasons`(academic_year_id,student_id,date,justification_reason,justified_by) VALUES($y,$y,'2026-01-01','Reason$y',1)");
        $db->exec("INSERT INTO `{$p}course_offerings`(id,academic_year_id,section_id,subject_id) VALUES($y,$y,$y,1)");$db->exec("INSERT INTO `{$p}course_teachers`(course_offering_id,teacher_id) VALUES($y,2)");$db->exec("INSERT INTO `{$p}course_students`(id,course_offering_id,student_id) VALUES($y,$y,$y)");$db->exec("INSERT INTO `{$p}assessment_periods`(id,academic_year_id,code,name) VALUES($y,$y,'term1','Term')");$db->exec("INSERT INTO `{$p}student_marks`(id,course_student_id,assessment_period_id,mark_value,updated_by) VALUES($y,$y,$y,15,2)");$db->exec("INSERT INTO `{$p}mark_submissions`(id,course_offering_id,assessment_period_id,status,submitted_by) VALUES($y,$y,$y,'submitted',2)");$db->exec("INSERT INTO `{$p}mark_audit_log`(mark_id,submission_id,course_offering_id,action,changed_by) VALUES($y,$y,$y,'save',2)");
        $db->exec("INSERT INTO `{$p}school_calendar_events`(id,academic_year_id,title,start_date,end_date,created_by,updated_by) VALUES($y,$y,'Event$y','2026-01-01','2026-01-01',1,1)");$db->exec("INSERT INTO `{$p}calendar_event_readers` VALUES($y,2,1)");$db->exec("INSERT INTO `{$p}calendar_audit_log`(event_id,changed_by,action,details) VALUES($y,1,'create','{}')");$db->exec("INSERT INTO `{$p}notification_logs`(channel,student_id,recipient,message,error_message,sent_by) VALUES('email',$y,'guardian@example.test','Message$y','SMTP_SECRET',1)");$db->exec("INSERT INTO `{$p}attendance_edit_batches`(id,academic_year_id,actor_id,tab_key,run_key,target_date) VALUES($y,$y,1,'tab','run','2026-01-01')");$db->exec("INSERT INTO `{$p}attendance_edit_changes`(batch_id,academic_year_id,actor_id,student_id,target_date,hour,before_state,after_state) VALUES($y,$y,1,$y,'2026-01-01',1,'{}','{}')");
    }
    $db->exec("INSERT INTO `{$p}settings` VALUES('smtp_pass','PRIVATE_SMTP'),('sms_api_key','PRIVATE_SMS')");
    $store=$fixture.'/private-'.$case;mkdir($store,0700);$archive=new AcademicYearArchives($db,$p,$store,1);$s=$archive->years;
    $guard=fopen($store.'/request.lock','c+b');flock($guard,LOCK_SH);$GLOBALS['absencesRequestLock']=$guard;
    yc($archive->exclusive(fn()=>42)===42&&is_resource($GLOBALS['absencesRequestLock']),'web shared request lock upgrades and remains held after action '.$case);
    flock($guard,LOCK_UN);fclose($guard);unset($GLOBALS['absencesRequestLock']);
    yr(fn()=>$s->activate(9999),'invalid activation keeps current active year '.$case);yc((int)$db->query("SELECT id FROM `{$p}academic_years` WHERE is_active=1")->fetchColumn()===2,'active year survived invalid switch '.$case);
    yr(fn()=>$s->create('2027-2029',true),'nonconsecutive year rejected '.$case);$new=$archive->exclusive(fn()=>$s->create('2027-2028',true));yc($s->snapshot($new)['tables']['students']===[]&&$s->snapshot($new)['tables']['course_offerings']===[]&&$s->snapshot($new)['tables']['student_marks']===[],'new year empty including assignments and marks '.$case);
    yc((int)$db->query("SELECT COUNT(*) FROM `{$p}users`")->fetchColumn()===4&&(int)$db->query("SELECT COUNT(*) FROM `{$p}students`")->fetchColumn()===2,'shared accounts and old pupils preserved '.$case);
    if($p===''){
        $GLOBALS['annualContextDB']=$db;$GLOBALS['annualContextActor']=['role'=>'admin'];$_SESSION=['academic_year_view'=>1];$view=AnnualContextFixture\getActiveAcademicYear(true);
        yc((int)$view['id']===1&&(int)$view['is_active']===0&&(int)$db->query('SELECT id FROM academic_years WHERE is_active=1')->fetchColumn()===$new,'admin historical view does not switch active year');
        $GLOBALS['annualContextActor']=['role'=>'teacher'];yc(AnnualContextFixture\getActiveAcademicYear(true)['id']===$new,'teacher cannot select historical context via session');$_SESSION=[];
    }
    yr(fn()=>$s->setClosed($new,true),'active year cannot close '.$case);$s->setClosed(1,true);yr(fn()=>$s->activate(1),'closed year cannot activate '.$case);
    $other=$s->snapshot(2);$meta=$archive->export(1,'1.3.0');$path=$store.'/academic-year-'.$meta['id'].'.zip';$verified=$s->verify($path,$meta['sha256'],'1.3.0');
    yc($verified['counts']['students']===1&&$verified['counts']['student_marks']===1&&$verified['counts']['calendar_audit_log']===1,'export contains only chosen year plus full grading/calendar history '.$case);
    $z=new ZipArchive();$z->open($path);$raw='';for($i=0;$i<$z->numFiles;$i++)$raw.=$z->getFromIndex($i);$z->close();foreach(['ADMIN_SECRET','TEACHER_SECRET','OTHER_SECRET','SMTP_SECRET','PRIVATE_SMTP','PRIVATE_SMS','unrelated@example.test','Student2','History2','password_hash','attendance_edit_changes'] as $secret)yc(!str_contains($raw,$secret),'annual archive excludes '.$secret.' '.$case);
    yr(fn()=>$s->verify($path,str_repeat('0',64),'1.3.0'),'wrong external checksum rejected '.$case);yr(fn()=>$s->preview($path,$meta['sha256'],'1.3.0'),'existing year restore refused '.$case);
    $bad=$fixture.'/bad-'.$case.'.zip';$sha=ym($path,$bad,'students',static function(&$rows){$rows[0]['academic_year_id']=2;});yr(fn()=>$s->verify($bad,$sha,'1.3.0'),'cross-year data rejected despite recomputed hashes '.$case);
    $sha=ym($path,$bad,'attendance',static function(&$rows){$rows[0]['student_id']=2;});yr(fn()=>$s->verify($bad,$sha,'1.3.0'),'foreign pupil reference rejected '.$case);
    $sha=ym($path,$bad,'users',static function(&$rows){$rows[0]['password_hash']='SECRET';});yr(fn()=>$s->verify($bad,$sha,'1.3.0'),'password column smuggling rejected '.$case);
    yr(fn()=>$archive->delete(1,$meta['id'],'1.3.0'),'delete requires both downloads '.$case);$archive->download($meta['id'],false);yr(fn()=>$archive->delete(1,$meta['id'],'1.3.0'),'ZIP download alone insufficient '.$case);$archive->download($meta['id'],true);
    $db->exec("UPDATE `{$p}attendance` SET note='Changed' WHERE academic_year_id=1");yr(fn()=>$archive->delete(1,$meta['id'],'1.3.0'),'stale backup cannot authorize delete '.$case);$db->exec("UPDATE `{$p}attendance` SET note='History1' WHERE academic_year_id=1");
    // Updating a timestamp is itself a data change. Export the fresh state again.
    $meta=$archive->export(1,'1.3.0');$path=$store.'/academic-year-'.$meta['id'].'.zip';$archive->download($meta['id'],false);$archive->download($meta['id'],true);
    $db->exec("CREATE TABLE `{$p}external_dependency`(student_id INT,FOREIGN KEY(student_id) REFERENCES `{$p}students`(id) ON DELETE CASCADE) ENGINE=InnoDB");yr(fn()=>$archive->delete(1,$meta['id'],'1.3.0'),'unknown extension dependency blocks deletion before cascade '.$case);$db->exec("DROP TABLE `{$p}external_dependency`");
    $archive->delete(1,$meta['id'],'1.3.0');yc($s->snapshot(2)===$other,'delete preserves all data of other year '.$case);yc((int)$db->query("SELECT COUNT(*) FROM `{$p}attendance_edit_changes` WHERE academic_year_id=1")->fetchColumn()===0,'year undo ledger removed child first '.$case);yc((int)$db->query("SELECT COUNT(*) FROM `{$p}users`")->fetchColumn()===4,'year deletion retains authors and accounts '.$case);
    $stage=$archive->stage($path,$meta['sha256'],'1.3.0');yc(!($db->query("SELECT id FROM `{$p}academic_years` WHERE name='2025-2026'")->fetchColumn()),'preview makes no data changes '.$case);
    $restored=$archive->restore($stage['id'],'1.3.0');yc($restored!==1&&$s->closed($restored)&&(int)$s->year($restored)['is_active']===0,'restored year has fresh ID and is closed/inactive '.$case);yc($s->snapshot(2)===$other,'restore preserves other year byte-for-byte '.$case);
    $q=$db->query("SELECT a.teacher_id,m.updated_by,m.mark_value FROM `{$p}attendance` a JOIN `{$p}course_students` cs ON cs.student_id=a.student_id JOIN `{$p}student_marks` m ON m.course_student_id=cs.id WHERE a.academic_year_id=$restored")->fetch();yc((int)$q['teacher_id']===3&&(int)$q['updated_by']===2&&(float)$q['mark_value']===15.0,'IDs remapped while historical authors and marks survive '.$case);
    yc($db->query("SELECT status FROM `{$p}users` WHERE id=3")->fetchColumn()==='inactive'&&$db->query("SELECT password_hash FROM `{$p}users` WHERE id=1")->fetchColumn()==='ADMIN_SECRET','restore never changes account status/passwords '.$case);
    yc($db->query("SELECT setting_value FROM `{$p}settings` WHERE setting_key='smtp_pass'")->fetchColumn()==='PRIVATE_SMTP','SMTP settings preserved '.$case);
    $s->setClosed($restored,false);$s->activate($restored);yc((int)$s->year($restored)['is_active']===1,'explicit reopen then activate works '.$case);
    // Simulate a closed-active inconsistent state: domain write locks still reject it.
    $db->prepare("INSERT INTO `{$p}settings` VALUES(?, '1') ON DUPLICATE KEY UPDATE setting_value='1'")->execute(['academic_year_closed_'.$restored]);
    if($p===''){
        yr(fn()=>marksActiveYearLock($db,$restored),'grading domain rejects closed active year');if($db->inTransaction())$db->rollBack();
        $_SESSION=['user_id'=>1];yr(fn()=>AnnualScopeFixture\beginAttendanceMutation($db,$restored),'attendance domain rejects closed active year');yc(!$db->inTransaction(),'attendance closure rejection rolls back transaction');$_SESSION=[];
    }
    yc(!is_file($store.'/state.json'),'no maintenance state remains after success/failure '.$case);
    // Separate destination, conflicting IDs, missing historic teacher and different table prefix.
    $destName='absences_year_fixture_'.bin2hex(random_bytes(6));$root->exec('CREATE DATABASE `'.$destName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$dbNames[]=$destName;$dest=new PDO($dsn.';dbname='.$destName,'root',$pass,$attrs);ys($dest,'dest_');
    $dest->exec("INSERT INTO dest_users(id,name,email,teacher_registry_number,role,status,password_hash) VALUES(10,'Destination Admin','admin@example.test','0001','admin','active','DEST_SECRET'),(20,'Existing Teacher','changed@example.test','0002','teacher','active','EXISTING_SECRET')");$dest->exec("INSERT INTO dest_academic_years(id,name,is_active) VALUES(1,'2026-2027',1)");
    $target=new AcademicYears($dest,'dest_');$restored=$target->restore($path,$meta['sha256'],'1.3.0');yc($target->closed($restored),'restore accepts different database/prefix and remaps IDs '.$case);
    $retired=$dest->query("SELECT * FROM dest_users WHERE teacher_registry_number='0003'")->fetch();yc($retired['status']==='inactive'&&$retired['password_hash']===null&&$retired['role']==='teacher','missing historical teacher imported inactive without password '.$case);
    yc($dest->query("SELECT email FROM dest_users WHERE id=20")->fetchColumn()==='changed@example.test'&&$dest->query("SELECT password_hash FROM dest_users WHERE id=10")->fetchColumn()==='DEST_SECRET','AM identity matches without overwriting email or admin credentials '.$case);
    // Rollback on strict data error after shared rows and year row were inserted.
    $target->setClosed($restored,true);$temp=$fixture.'/long-'.$case.'.zip';$sha=ym($path,$temp,'students',static function(&$rows){$rows[0]['last_name']=str_repeat('x',101);});
    $emptyName='absences_year_fixture_'.bin2hex(random_bytes(6));$root->exec('CREATE DATABASE `'.$emptyName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$dbNames[]=$emptyName;$empty=new PDO($dsn.';dbname='.$emptyName,'root',$pass,$attrs);ys($empty,'');$empty->exec("INSERT INTO academic_years(name,is_active) VALUES('2026-2027',1)");$service=new AcademicYears($empty);
    $failed=false;try{$service->restore($temp,$sha,'1.3.0');}catch(PDOException $e){$failed=true;}yc($failed&&(int)$empty->query('SELECT COUNT(*) FROM academic_years')->fetchColumn()===1&&(int)$empty->query('SELECT COUNT(*) FROM users')->fetchColumn()===0,'strict failure rolls back year and every shared/child row '.$case);
    $forbidden=new AcademicYearArchives($db,$p,$store,2);yr(fn()=>$forbidden->export(2,'1.3.0'),'teacher cannot use archive workflow '.$case);
    $db->exec("UPDATE `{$p}users` SET role='admin' WHERE id=4");$otherAdmin=new AcademicYearArchives($db,$p,$store,4);yr(fn()=>$otherAdmin->meta($meta['id']),'archive metadata is restricted to creating administrator '.$case);
 }
}finally{
    foreach($dbNames as $name)if(preg_match('/^absences_year_fixture_[a-f0-9]{12}$/D',$name))$root->exec('DROP DATABASE `'.$name.'`');
    $base=realpath(dirname(__DIR__).'/scratch');$real=realpath($fixture);if($real&&str_starts_with(strtolower(str_replace('\\','/',$real)),strtolower(str_replace('\\','/',$base)).'/year-fixture-')){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($real);}
}
echo "Annual year fixtures complete.\n";
