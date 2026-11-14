<?php
if(PHP_SAPI!=='cli')exit;
$project=dirname(__DIR__);$public=$project.'/release/absences-public-1.4.0/public_html';$options=getopt('',['public-key:','unprepared']);
require_once (isset($options['unprepared'])?$project.'/release/absences-public-1.3.0/public_html':$public).'/includes/lifecycle.php';
set_exception_handler(static function(Throwable $e){fwrite(STDERR,'FAIL '.get_class($e).' '.basename($e->getFile()).':'.$e->getLine().' '.$e->getCode()."\n");exit(1);});
function r140(bool $ok,string $label): void {if(!$ok)throw new RuntimeException($label);echo 'PASS '.$label."\n";}
if(!isset($options['public-key']))exit("Use --public-key=PATH\n");
$attrs=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];$dsn='mysql:host=localhost;port=3306;charset=utf8mb4';$pass=getenv('TEST_MYSQL_PASSWORD')?:'';$server=new PDO($dsn,'root',$pass,$attrs);
$database='absences_release140_'.bin2hex(random_bytes(6));$fixture=$project.'/scratch/release140-'.bin2hex(random_bytes(6));mkdir($fixture,0700,true);$server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try{
 foreach(['','school_'] as $p){
    // An unprefixed school_calendar_events legitimately starts with school_.
    // Use an empty fixture database for each installation, as production inventory requires.
    if($p!==''){
        if(!preg_match('/^absences_release140_[a-f0-9]{12}$/D',$database))throw new RuntimeException('Unsafe fixture name.');
        $server->exec('DROP DATABASE `'.$database.'`');$server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }
    $db=new PDO($dsn.';dbname='.$database,'root',$pass,$attrs);
    preg_match_all('/CREATE TABLE IF NOT EXISTS .*?;/s',file_get_contents($project.'/release/absences-public-1.3.0/public_html/schema.sql'),$tables);
    foreach($tables[0] as $sql)$db->exec(preg_replace('/(CREATE TABLE IF NOT EXISTS|REFERENCES)\s+`?([a-z][a-z0-9_]*)`?/i','$1 `'.$p.'$2`',$sql));
    $db->exec("INSERT INTO `{$p}users`(id,name,email,role,status,password_hash) VALUES(1,'Fixture','fixture@example.test','admin','active','FIXTURE_PASSWORD_HASH')");
    $db->exec("INSERT INTO `{$p}academic_years`(id,name,is_active) VALUES(1,'2026-2027',1)");$db->exec("INSERT INTO `{$p}grades` VALUES(1,'A')");$db->exec("INSERT INTO `{$p}sections` VALUES(1,1,'A1')");$db->exec("INSERT INTO `{$p}students`(id,academic_year_id,am,last_name,first_name) VALUES(1,1,'0001','Fixture','Student')");$db->exec("INSERT INTO `{$p}attendance`(academic_year_id,date,hour,section_id,student_id,teacher_id) VALUES(1,'2026-11-15',1,1,1,1)");$before=$db->query("SELECT * FROM `{$p}attendance`")->fetchAll();
    $app=$fixture.'/app-'.$p;$store=$fixture.'/store-'.$p;mkdir($app,0700);mkdir($store,0700);mkdir($app.'/includes');mkdir($app.'/config');file_put_contents($app.'/index.php','<?php // original fixture');file_put_contents($app.'/config/private.php','PRIVATE_FIXTURE_CONFIG');
    // Manual preparation has installed the five reviewed protected files already.
    foreach(['lifecycle.php','lifecycle_restore.php','lifecycle_portable_recovery.php','portable_recovery.php','teacher_registry_migration.php'] as $name)copy($public.'/includes/'.$name,$app.'/includes/'.$name);
    copy($options['public-key'],$store.'/release-public-key.txt');$_SERVER['DOCUMENT_ROOT']=$app;
    $m=new AbsencesLifecycle($app,$store,$db,$database,$p);$m->write('version.json',['version'=>'1.3.0']);$zip=$project.'/release/absences-update-1.3.0-to-1.4.0.zip';
    if(isset($options['unprepared'])){
        $refused=false;try{$m->verifyPackage($zip,'1.3.0');}catch(DomainException $e){$refused=true;}
        r140($refused&&!is_file($store.'/state.json')&&$m->read('version.json')['version']==='1.3.0','unprepared engine refuses before maintenance/data changes '.$p);continue;
    }
    $manifest=$m->verifyPackage($zip,'1.3.0');
    r140(count($manifest['files'])===22&&count($manifest['migrations'])===1&&!$manifest['new_tables']&&!$manifest['delete'],'signature, preparation requirement and no-DDL manifest '.$p);
    $backup=$m->update($zip,'1.3.0',1);r140($m->read('version.json')['version']==='1.4.0'&&!is_file($store.'/state.json'),'actual signed update completes '.$p);
    r140($db->query("SELECT * FROM `{$p}attendance`")->fetchAll()===$before,'attendance preserved byte-for-byte '.$p);
    r140($db->query("SELECT password_hash FROM `{$p}users` WHERE id=1")->fetchColumn()==='FIXTURE_PASSWORD_HASH'&&file_get_contents($app.'/config/private.php')==='PRIVATE_FIXTURE_CONFIG','credentials and private config preserved '.$p);
    foreach($manifest['files'] as $rel=>$sha)r140(is_file($app.'/'.$rel)&&hash_equals($sha,hash_file('sha256',$app.'/'.$rel)),'installed hash '.$rel.' '.$p);
    r140(is_file($store.'/'.$backup['id'].'.zip'),'rescue backup retained '.$p);
    r140(hash_file('sha256',$app.'/includes/lifecycle.php')===hash_file('sha256',$public.'/includes/lifecycle.php'),'protected prepared engine preserved '.$p);
 }
}finally{
 if(preg_match('/^absences_release140_[a-f0-9]{12}$/D',$database))$server->exec('DROP DATABASE `'.$database.'`');
 $base=realpath($project.'/scratch');$real=realpath($fixture);if($real&&str_starts_with(strtolower(str_replace('\\','/',$real)),strtolower(str_replace('\\','/',$base)).'/release140-')){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($real);}
}
echo "Release1.4.0 signed installer fixtures passed.\n";
