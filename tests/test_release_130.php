<?php
// Real signed package verification/update, disposable database and fixture roots only.
if(PHP_SAPI!=='cli') { http_response_code(403); exit; }
$project=dirname(__DIR__);
// The already published prepared engine must accept the new signed declaration.
require_once $project.'/release/absences-public-1.2.1/public_html/includes/lifecycle.php';
function release130Check(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo 'PASS: '.$label.PHP_EOL; }
$options=getopt('',['mysql','user:','public-key:']);
if(!isset($options['mysql'],$options['public-key'])) exit("Use --mysql --user=root --public-key=PATH; TEST_MYSQL_PASSWORD only.\n");
$attributes=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
$server='mysql:host=localhost;port=3306;charset=utf8mb4';
$rootDb=new PDO($server,$options['user']??'root',getenv('TEST_MYSQL_PASSWORD')?:'',$attributes);
$database='absences_release130_test_'.bin2hex(random_bytes(6));
$fixtures=$project.'/scratch/release130-fixtures-'.bin2hex(random_bytes(6)); mkdir($fixtures,0700,true);
$tables=[];
try {
    $rootDb->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    foreach(['1.2.1'] as $base) foreach(['','school_'] as $prefix) {
        $db=new PDO($server.';dbname='.$database,$options['user']??'root',getenv('TEST_MYSQL_PASSWORD')?:'',$attributes);
        $db->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) $db->exec('DROP TABLE `'.$table.'`');
        $db->exec('SET FOREIGN_KEY_CHECKS=1');
        preg_match_all('/CREATE TABLE IF NOT EXISTS .*?;/s',file_get_contents($project.'/release/absences-public-1.2.1/public_html/schema.sql'),$creates);
        foreach($creates[0] as $sql) {
            $sql=preg_replace('/(CREATE TABLE IF NOT EXISTS|REFERENCES)\s+`?([a-z][a-z0-9_]*)`?/i','$1 `'.$prefix.'$2`',$sql);
            $db->exec($sql);
        }
        $db->exec("INSERT INTO `{$prefix}users`(id,name,email,role,status) VALUES(1,'Fixture','fixture@example.test','admin','active')");
        $db->exec("INSERT INTO `{$prefix}academic_years`(id,name,is_active) VALUES(1,'2026-2027',1)");
        $db->exec("INSERT INTO `{$prefix}grades`(id,name) VALUES(1,'A')");
        $db->exec("INSERT INTO `{$prefix}sections`(id,grade_id,name) VALUES(1,1,'A1')");
        $db->exec("INSERT INTO `{$prefix}students`(id,academic_year_id,am,last_name,first_name) VALUES(1,1,'001','TEST','STUDENT')");
        $db->exec("INSERT INTO `{$prefix}attendance`(academic_year_id,date,hour,section_id,student_id,teacher_id) VALUES(1,'2026-11-01',1,1,1,1)");
        $before=$db->query('SELECT * FROM `'.$prefix.'attendance`')->fetchAll();
        $app=$fixtures.'/app-'.$base.'-'.$prefix; $store=$fixtures.'/private-'.$base.'-'.$prefix;
        mkdir($app,0700,true); mkdir($store,0700,true); file_put_contents($app.'/index.php','<?php // fixture');
        copy($options['public-key'],$store.'/release-public-key.txt');
        $_SERVER['DOCUMENT_ROOT']=$app;
        $manager=new AbsencesLifecycle($app,$store,$db,$database,$prefix); $manager->write('version.json',['version'=>$base]);
        $package=$project.'/release/absences-update-'.$base.'-to-1.3.0.zip';
        $manifest=$manager->verifyPackage($package,$base);
        release130Check(empty($manifest['new_tables'])&&count($manifest['migrations'])===1&&count($manifest['files'])===25,'published verifier accepts signed registry update '.$base.' '.$prefix);
        $manager->update($package,$base,1);
        release130Check($manager->read('version.json')['version']==='1.3.0'&&!is_file($store.'/state.json'),'real update completes '.$base.' '.$prefix);
        release130Check($db->query('SELECT * FROM `'.$prefix.'attendance`')->fetchAll()===$before,'existing attendance preserved '.$base.' '.$prefix);
        $column=$db->query("SHOW COLUMNS FROM `{$prefix}users` LIKE 'teacher_registry_number'")->fetch(PDO::FETCH_ASSOC);
        release130Check($column && $column['Null']==='YES' && $column['Key']==='UNI','nullable unique teacher registry migration '.$prefix);
        release130Check($db->query("SELECT teacher_registry_number FROM `{$prefix}users` WHERE id=1")->fetchColumn()===null,'existing teacher registry left empty '.$prefix);
        $backup=$manager->createBackup('1.3.0',1); $verified=$manager->verifyRestoreBackup($backup['id']);
        release130Check(count($verified['tables'])===31,'post-update verified backup includes all 31 tables '.$base.' '.$prefix);
        foreach($manifest['files'] as $file=>$hash) if(!hash_equals($hash,hash_file('sha256',$app.'/'.$file))) throw new RuntimeException('Installed file hash mismatch');
        release130Check(true,'installed runtime matches signed file hashes '.$base.' '.$prefix);
    }
} finally {
    if(preg_match('/^absences_release130_test_[a-f0-9]{12}$/D',$database)) $rootDb->exec('DROP DATABASE `'.$database.'`');
    $resolved=realpath($fixtures);
    if($resolved&&str_starts_with(basename($resolved),'release130-fixtures-')) {
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($iterator as $entry) { if($entry->isDir()) rmdir($entry->getPathname()); else unlink($entry->getPathname()); }
        rmdir($resolved);
    }
}
