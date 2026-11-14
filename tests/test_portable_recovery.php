<?php
// Synthetic data only. Disposable databases and private fixture roots; no school config.
if(PHP_SAPI!=='cli')exit;
$portableAppRoot=getenv('TEST_RELEASE_ROOT')?:dirname(__DIR__).'/public_html';
require_once $portableAppRoot.'/includes/lifecycle.php';
set_exception_handler(static function(Throwable $e){fwrite(STDERR,'FAIL: '.get_class($e).' at '.basename($e->getFile()).':'.$e->getLine().' code='.$e->getCode()."\n");exit(1);});
function portableCheck(bool $ok,string $label): void{if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
function portableReject(callable $action,string $label): void{try{$action();}catch(DomainException $e){portableCheck(true,$label);return;}throw new RuntimeException($label);}
$server='mysql:host=localhost;port=3306;charset=utf8mb4';$pass=getenv('TEST_MYSQL_PASSWORD')?:'';$attrs=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];$rootDb=new PDO($server,'root',$pass,$attrs);
$fixture=dirname(__DIR__).'/scratch/portable-fixtures-'.bin2hex(random_bytes(6));mkdir($fixture,0700,true);$databases=[];
function portableSchema(PDO $db,string $prefix): void{
    preg_match_all('/CREATE TABLE IF NOT EXISTS .*?;/s',file_get_contents($GLOBALS['portableAppRoot'].'/schema.sql'),$creates);
    foreach($creates[0] as $sql){$sql=preg_replace('/(CREATE TABLE IF NOT EXISTS|REFERENCES)\s+`?([a-z][a-z0-9_]*)`?/i','$1 `'.$prefix.'$2`',$sql);$db->exec($sql);}
}
function portableMutate(string $input,string $output,string $table,callable $change): string {
    copy($input,$output);$z=new ZipArchive();$z->open($output);$m=json_decode($z->getFromName('manifest.json'),true);$data=$z->getFromName('data/'.$table.'.ndjson');$rows=[];foreach(explode("\n",trim($data)) as $line)if($line!=='')$rows[]=json_decode($line,true);
    $change($m,$rows);$data='';foreach($rows as $row)$data.=json_encode($row,JSON_UNESCAPED_UNICODE)."\n";$m['tables'][$table]['rows']=count($rows);$m['tables'][$table]['bytes']=strlen($data);$m['tables'][$table]['sha256']=hash('sha256',$data);
    $z->addFromString('data/'.$table.'.ndjson',$data);$z->addFromString('manifest.json',json_encode($m,JSON_UNESCAPED_UNICODE));$z->close();return hash_file('sha256',$output);
}
try{
 foreach([['','school_'],['old_','new_']] as $case=>[$sp,$tp]){
    $dbs=[];
    foreach(['source','target'] as $kind){$name='absences_portable_'.$kind.'_'.bin2hex(random_bytes(6));$rootDb->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$databases[]=$name;$dbs[$kind]=[$name,new PDO($server.';dbname='.$name,'root',$pass,$attrs)];}
    [$sourceName,$source]=$dbs['source'];[$targetName,$target]=$dbs['target'];portableSchema($source,$sp);portableSchema($target,$tp);
    $source->exec("INSERT INTO `{$sp}users`(id,name,email,password_hash,role,status,teacher_registry_number) VALUES(1,'Source Admin','source@example.test','SOURCE_LOGIN_SECRET','admin','active','001'),(2,'Teacher','teacher@example.test','TEACHER_LOGIN_SECRET','teacher','active','002'),(3,'Inactive','inactive@example.test','INACTIVE_LOGIN_SECRET','teacher','inactive','003')");
    foreach([$source,$target] as $i=>$db){$p=$i===0?$sp:$tp;$db->exec("INSERT INTO `{$p}academic_years`(id,name,is_active) VALUES(1,'2026-2027',1)");$db->exec("INSERT INTO `{$p}grades`(id,name) VALUES(1,'Α')");$db->exec("INSERT INTO `{$p}sections`(id,grade_id,name) VALUES(1,1,'Α1')");}
    $source->exec("INSERT INTO `{$sp}academic_years`(id,name,is_active) VALUES(2,'2025-2026',0)");
    $source->exec("INSERT INTO `{$sp}settings` VALUES('academic_year_closed_2','1')");
    $target->exec("INSERT INTO `{$tp}settings` VALUES('academic_year_closed_2','0'),('academic_year_closed_999','1')");
    $source->exec("INSERT INTO `{$sp}students`(id,academic_year_id,am,last_name,first_name,guardian_email) VALUES(1,1,'0001','Fixture','Student','guardian@example.test')");$source->exec("INSERT INTO `{$sp}student_sections` VALUES(1,1)");$source->exec("INSERT INTO `{$sp}teacher_sections` VALUES(1,2,1)");
    $source->exec("INSERT INTO `{$sp}attendance`(id,academic_year_id,date,hour,section_id,student_id,teacher_id,note) VALUES(1,1,'2026-11-01',1,1,1,2,'School history')");
    $source->exec("INSERT INTO `{$sp}subjects`(id,source_name,display_name) VALUES(1,'Subject','Subject')");$source->exec("INSERT INTO `{$sp}course_offerings`(id,academic_year_id,section_id,subject_id) VALUES(1,1,1,1)");$source->exec("INSERT INTO `{$sp}assessment_periods`(id,academic_year_id,code,name) VALUES(1,1,'term1','Term')");$source->exec("INSERT INTO `{$sp}course_students`(id,course_offering_id,student_id) VALUES(1,1,1)");$source->exec("INSERT INTO `{$sp}course_teachers`(course_offering_id,teacher_id) VALUES(1,2)");$source->exec("INSERT INTO `{$sp}student_marks`(course_student_id,assessment_period_id,mark_value,updated_by) VALUES(1,1,15,2)");$source->exec("INSERT INTO `{$sp}mark_submissions`(course_offering_id,assessment_period_id,status,submitted_by) VALUES(1,1,'submitted',2)");
    $source->exec("INSERT INTO `{$sp}notification_logs`(channel,student_id,recipient,message,error_message,sent_by) VALUES('email',1,'guardian@example.test','School message','SMTP_ERROR_SECRET',2)");
    $source->exec("INSERT INTO `{$sp}smtp_accounts`(title,from_email,from_name,host,username,password) VALUES('SOURCE_SMTP_ACCOUNT','source@example.test','School','SOURCE_SMTP_HOST','SOURCE_SMTP_USERNAME','SOURCE_SMTP_PASSWORD')");
    foreach(['school_name'=>'Fixture school','school_phone'=>'000','email_template_body'=>'School template','smtp_host'=>'SOURCE_SMTP_SETTINGS','smtp_pass'=>'SOURCE_SMTP_PASS','sms_api_key'=>'SOURCE_SMS_KEY','sms_provider'=>'SOURCE_SMS_PROVIDER','unknown_private_key'=>'UNKNOWN_SECRET','app_master_key'=>'MASTER_KEY_SECRET'] as $k=>$v)$source->prepare("INSERT INTO `{$sp}settings` VALUES(?,?)")->execute([$k,$v]);
    $targetEmail=$case===0?'destination@example.test':'source@example.test';$targetPassword=password_hash('destination-password',PASSWORD_DEFAULT);
    $target->prepare("INSERT INTO `{$tp}users`(id,name,email,password_hash,role,status,auth_version) VALUES(1,'Destination Admin',?,?,'admin','active',42)")->execute([$targetEmail,$targetPassword]);
    foreach(['smtp_pass'=>'DESTINATION_SMTP','sms_api_key'=>'DESTINATION_SMS','private_setting'=>'DESTINATION_PRIVATE'] as $k=>$v)$target->prepare("INSERT INTO `{$tp}settings` VALUES(?,?)")->execute([$k,$v]);
    $target->exec("INSERT INTO `{$tp}smtp_accounts`(title,from_email,from_name,host,password) VALUES('Destination','new@example.test','New','new-host','DESTINATION_SMTP_ACCOUNT_PASSWORD')");
    $app=$fixture.'/source-app-'.$case;$store=$fixture.'/source-store-'.$case;$destApp=$fixture.'/target-app-'.$case;$destStore=$fixture.'/target-store-'.$case;foreach([$app,$store,$destApp,$destStore] as $p)mkdir($p,0700,true);
    mkdir($app.'/config');file_put_contents($app.'/config/private.php','SOURCE_PRIVATE_CONFIG_SECRET');file_put_contents($app.'/master.key','SOURCE_MASTER_KEY_SECRET');file_put_contents($destApp.'/private-test.txt','DESTINATION_PRIVATE_FILE');
    $_SERVER['DOCUMENT_ROOT']=$app;$manager=new AbsencesLifecycle($app,$store,$source,$sourceName,$sp);$manager->write('version.json',['version'=>'1.3.0']);
    $requestLock=fopen($store.'/request.lock','c+b');flock($requestLock,LOCK_SH);$GLOBALS['absencesRequestLock']=$requestLock;
    $export=$manager->createPortableRecovery('1.3.0',1);portableCheck(is_resource($GLOBALS['absencesRequestLock']??null),'web export upgrades own shared request lock and restores it '.$case);
    flock($requestLock,LOCK_UN);fclose($requestLock);unset($GLOBALS['absencesRequestLock']);$path=$store.'/portable-'.$export['id'].'.zip';
    $protected=new ReflectionMethod(AbsencesLifecycle::class,'restoreProtected');portableCheck($protected->invoke($manager,'includes/portable_recovery.php')&&$protected->invoke($manager,'includes/lifecycle_portable_recovery.php'),'recovery engine helpers protected from old file restores '.$case);
    portableCheck(!is_file($store.'/state.json'),'export leaves source operational '.$case);
    $zip=new ZipArchive();$zip->open($path);$all='';for($i=0;$i<$zip->numFiles;$i++)$all.=$zip->getFromIndex($i);$zip->close();
    foreach(['SOURCE_LOGIN_SECRET','TEACHER_LOGIN_SECRET','INACTIVE_LOGIN_SECRET','SOURCE_SMTP','SOURCE_SMS','UNKNOWN_SECRET','MASTER_KEY_SECRET','SMTP_ERROR_SECRET','SOURCE_PRIVATE_CONFIG_SECRET','password_hash','smtp_accounts',$sourceName,$app] as $secret)portableCheck(!str_contains($all,$secret),'export excludes '.$secret.' '.$case);
    portableCheck(str_contains($all,'guardian@example.test')&&str_contains($all,'School history'),'school contacts and history retained '.$case);
    $_SERVER['DOCUMENT_ROOT']=$destApp;$destination=new AbsencesLifecycle($destApp,$destStore,$target,$targetName,$tp);$destination->write('version.json',['version'=>'1.3.0']);$service=new PortableSchoolRecovery($target,$tp);
    $verified=$service->verify($path,$export['sha256'],'1.3.0');portableCheck($verified['counts']['students']===1&&$verified['counts']['student_marks']===1,'preview supports new DB path and prefix '.$case);
    portableReject(fn()=>$service->verify($path,str_repeat('0',64),'1.3.0'),'wrong external digest rejected '.$case);
    $unsafe=$fixture.'/unsafe-'.$case.'.zip';copy($path,$unsafe);$z=new ZipArchive();$z->open($unsafe);$z->addFromString('config/private.php','PRIVATE');$z->close();portableReject(fn()=>$service->verify($unsafe,hash_file('sha256',$unsafe),'1.3.0'),'additional executable/private file rejected '.$case);
    $badSettings=$fixture.'/bad-settings-'.$case.'.zip';$badHash=portableMutate($path,$badSettings,'settings',static function(&$m,&$rows){$rows=[['setting_key'=>'sms_api_key','setting_value'=>'INJECTED_SECRET']];});portableReject(fn()=>$service->verify($badSettings,$badHash,'1.3.0'),'credentials rejected even with recomputed valid hashes '.$case);
    $badUsers=$fixture.'/bad-users-'.$case.'.zip';$badHash=portableMutate($path,$badUsers,'users',static function(&$m,&$rows){$m['tables']['users']['columns'][]='password_hash';foreach($rows as &$row)$row['password_hash']='INJECTED_PASSWORD';});portableReject(fn()=>$service->verify($badUsers,$badHash,'1.3.0'),'user passwords cannot be smuggled into package '.$case);
    $badFK=$fixture.'/bad-fk-'.$case.'.zip';$badHash=portableMutate($path,$badFK,'attendance',static function(&$m,&$rows){$rows[0]['teacher_id']='999999';});$badStage=$destination->stagePortableRecovery($badFK,$badHash,'1.3.0',1);$failed=false;try{$destination->importPortableRecovery($badStage['id'],$badStage['sha256'],'1.3.0',1);}catch(PDOException $e){$failed=true;}
    portableCheck($failed&&(int)$target->query("SELECT COUNT(*) FROM `{$tp}students`")->fetchColumn()===0&&(int)$target->query("SELECT COUNT(*) FROM `{$tp}users`")->fetchColumn()===1,'foreign key error rolls back all school data '.$case);portableCheck(!is_file($destStore.'/state.json'),'rolled-back import does not trap installation in maintenance '.$case);$destination->cancelPortableRecovery($badStage['id'],1);
    $badLength=$fixture.'/bad-length-'.$case.'.zip';$badHash=portableMutate($path,$badLength,'students',static function(&$m,&$rows){$rows[0]['last_name']=str_repeat('X',101);});$badStage=$destination->stagePortableRecovery($badLength,$badHash,'1.3.0',1);$failed=false;try{$destination->importPortableRecovery($badStage['id'],$badStage['sha256'],'1.3.0',1);}catch(PDOException $e){$failed=true;}
    portableCheck($failed&&(int)$target->query("SELECT COUNT(*) FROM `{$tp}students`")->fetchColumn()===0,'strict import rejects silent data truncation '.$case);$destination->cancelPortableRecovery($badStage['id'],1);
    $target->exec("INSERT INTO `{$tp}users`(id,name,email,role,status) VALUES(2,'Extra','extra@example.test','teacher','pending_activation')");portableReject(fn()=>$destination->stagePortableRecovery($path,$export['sha256'],'1.3.0',1),'nonfresh destination rejected '.$case);$target->exec("DELETE FROM `{$tp}users` WHERE id=2");
    $stage=$destination->stagePortableRecovery($path,$export['sha256'],'1.3.0',1);portableCheck((int)$target->query("SELECT COUNT(*) FROM `{$tp}students`")->fetchColumn()===0,'preview performs no student writes '.$case);
    $target->exec("INSERT INTO `{$tp}students`(id,academic_year_id,am,last_name,first_name) VALUES(8,1,'8','New','Change')");portableReject(fn()=>$destination->importPortableRecovery($stage['id'],$stage['sha256'],'1.3.0',1),'destination rechecked after preview '.$case);portableCheck(!is_file($destStore.'/state.json'),'rejected import does not leave maintenance '.$case);$target->exec("DELETE FROM `{$tp}students` WHERE id=8");
    $requestLock=fopen($destStore.'/request.lock','c+b');flock($requestLock,LOCK_SH);$GLOBALS['absencesRequestLock']=$requestLock;
    $result=$destination->importPortableRecovery($stage['id'],$stage['sha256'],'1.3.0',1);
    portableCheck(is_resource($GLOBALS['absencesRequestLock']??null),'web restore upgrades and restores its shared request lock '.$case);
    flock($requestLock,LOCK_UN);fclose($requestLock);unset($GLOBALS['absencesRequestLock']);
    portableCheck((int)$target->query("SELECT COUNT(*) FROM `{$tp}students`")->fetchColumn()===1,'actual portable recovery completes '.$case);
    portableCheck((int)$target->query("SELECT teacher_id FROM `{$tp}attendance` WHERE id=1")->fetchColumn()===2&&(int)$target->query("SELECT updated_by FROM `{$tp}student_marks` WHERE id=1")->fetchColumn()===2&&(int)$target->query("SELECT submitted_by FROM `{$tp}mark_submissions` WHERE id=1")->fetchColumn()===2,'historical authors preserved '.$case);
    portableCheck($target->query("SELECT guardian_email FROM `{$tp}students` WHERE id=1")->fetchColumn()==='guardian@example.test','guardian data restored '.$case);
    $admin=$target->query("SELECT * FROM `{$tp}users` WHERE id=".(int)$result['admin_id'])->fetch();portableCheck($admin['email']===$targetEmail&&$admin['role']==='admin'&&$admin['status']==='active'&&password_verify('destination-password',$admin['password_hash']),'destination administrator credentials preserved '.$case);
    portableCheck($target->query("SELECT password_hash FROM `{$tp}users` WHERE id=2")->fetchColumn()===null&&$target->query("SELECT status FROM `{$tp}users` WHERE id=2")->fetchColumn()==='pending_activation','teachers require new passwords '.$case);
    portableCheck($target->query("SELECT status FROM `{$tp}users` WHERE id=3")->fetchColumn()==='inactive','inactive teachers remain inactive '.$case);
    portableCheck($target->query("SELECT setting_value FROM `{$tp}settings` WHERE setting_key='academic_year_closed_2'")->fetchColumn()==='1'&&$target->query("SELECT setting_value FROM `{$tp}settings` WHERE setting_key='academic_year_closed_999'")->fetchColumn()===false,'closed years survive recovery and stale closure flags are cleared '.$case);
    portableCheck($target->query("SELECT setting_value FROM `{$tp}settings` WHERE setting_key='smtp_pass'")->fetchColumn()==='DESTINATION_SMTP'&&$target->query("SELECT setting_value FROM `{$tp}settings` WHERE setting_key='sms_api_key'")->fetchColumn()==='DESTINATION_SMS','destination private SMTP and SMS settings preserved '.$case);
    portableCheck($target->query("SELECT password FROM `{$tp}smtp_accounts` LIMIT 1")->fetchColumn()==='DESTINATION_SMTP_ACCOUNT_PASSWORD','SMTP accounts not imported or replaced '.$case);
    portableCheck($target->query("SELECT setting_value FROM `{$tp}settings` WHERE setting_key='school_name'")->fetchColumn()==='Fixture school','safe school settings restored '.$case);
    portableCheck(file_get_contents($destApp.'/private-test.txt')==='DESTINATION_PRIVATE_FILE'&&!file_exists($destApp.'/master.key'),'destination files and keys untouched '.$case);
    portableCheck(!is_file($destStore.'/state.json')&&is_file($destStore.'/restore-generation.json'),'maintenance cleared and sessions invalidated '.$case);
    portableCheck($destination->read('version.json')['version']==='1.3.0'&&is_file($destStore.'/'.$result['rescue'].'.zip'),'destination version and rescue backup retained '.$case);
    portableReject(fn()=>$destination->stagePortableRecovery($path,$export['sha256'],'1.3.0',(int)$result['admin_id']),'second import into populated destination rejected '.$case);
 }
}finally{
 foreach($databases as $name)if(preg_match('/^absences_portable_(source|target)_[a-f0-9]{12}$/D',$name))$rootDb->exec('DROP DATABASE `'.$name.'`');
 $resolved=realpath($fixture);$boundary=realpath(dirname(__DIR__).'/scratch');if($resolved&&$boundary&&str_starts_with(strtolower(str_replace('\\','/',$resolved)),strtolower(str_replace('\\','/',$boundary)).'/')&&str_starts_with(basename($resolved),'portable-fixtures-')){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $entry){if($entry->isDir())rmdir($entry->getPathname());else unlink($entry->getPathname());}rmdir($resolved);}
}
echo "All portable recovery fixtures passed; no real school records exported or restored.\n";
