<?php
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../public_html/includes/teacher_registry_migration.php';
$db=new PDO('mysql:host=localhost;port=3306;charset=utf8mb4','root',getenv('TEST_MYSQL_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name='absences_registry_fixture_'.bin2hex(random_bytes(6));
function registryCheck(bool $ok,string $label): void{if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
try{
    $db->exec('CREATE DATABASE `'.$name.'`');$db->exec('USE `'.$name.'`');
    foreach(['users','school_users'] as $table){
        $db->exec('CREATE TABLE `'.$table.'` (id INT PRIMARY KEY) ENGINE=InnoDB');
        $sql='ALTER TABLE `'.$table.'` ADD COLUMN teacher_registry_number VARCHAR(30) DEFAULT NULL, ADD UNIQUE KEY unique_teacher_registry_number (teacher_registry_number);';
        registryCheck(teacherRegistryMigrationPlan($db,$table,$sql)===[$sql],'missing column executes original migration '.$table);
        $db->exec('ALTER TABLE `'.$table.'` ADD COLUMN teacher_registry_number VARCHAR(30) DEFAULT NULL');
        registryCheck(count(teacherRegistryMigrationPlan($db,$table,$sql))===1,'partial schema adds missing index only '.$table);
        $db->exec('INSERT INTO `'.$table.'` VALUES(1,\'001\'),(2,\'001\')');$blocked=false;
        try{teacherRegistryMigrationPlan($db,$table,$sql);}catch(DomainException $e){$blocked=true;}
        registryCheck($blocked,'duplicate registries rejected before DDL '.$table);
        $db->exec('DELETE FROM `'.$table.'` WHERE id=2');foreach(teacherRegistryMigrationPlan($db,$table,$sql) as $statement)$db->exec($statement);
        registryCheck(teacherRegistryMigrationPlan($db,$table,$sql)===[],'matching existing schema skips safely '.$table);
        registryCheck($db->query('SELECT teacher_registry_number FROM `'.$table.'` WHERE id=1')->fetchColumn()==='001','existing registry data preserved '.$table);
        $db->exec('ALTER TABLE `'.$table.'` MODIFY teacher_registry_number VARCHAR(20) DEFAULT NULL');$blocked=false;
        try{teacherRegistryMigrationPlan($db,$table,$sql);}catch(DomainException $e){$blocked=true;}
        registryCheck($blocked,'incompatible column rejected before maintenance '.$table);
        registryCheck(teacherRegistryMigrationPlan($db,$table,'ALTER TABLE `'.$table.'` ADD other_field INT;')===null,'other migrations never suppressed '.$table);
    }
}finally{if(preg_match('/^absences_registry_fixture_[a-f0-9]{12}$/D',$name))$db->exec('DROP DATABASE `'.$name.'`');}
