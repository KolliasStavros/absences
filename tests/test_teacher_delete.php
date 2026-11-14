<?php
// Actual archive helper, disposable database only, no school configuration.
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/../public_html/includes/teacher_lifecycle.php';
$server=new PDO('mysql:host=localhost;port=3306;charset=utf8mb4','root',getenv('TEST_MYSQL_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name='absences_teacher_delete_test_'.bin2hex(random_bytes(6));
try {
    $server->exec('CREATE DATABASE `'.$name.'`');
    $server->exec('USE `'.$name.'`');
    $server->exec("CREATE TABLE users(id INT PRIMARY KEY,role VARCHAR(30),status VARCHAR(30),auth_version INT) ENGINE=InnoDB");
    $server->exec('CREATE TABLE teacher_sections(teacher_id INT,academic_year_id INT,FOREIGN KEY(teacher_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB');
    $server->exec('CREATE TABLE history(teacher_id INT,FOREIGN KEY(teacher_id) REFERENCES users(id) ON DELETE RESTRICT) ENGINE=InnoDB');
    $server->exec('CREATE TABLE password_resets(user_id INT,used_at DATETIME NULL) ENGINE=InnoDB');
    foreach ([false,true] as $linked) {
        $id=$linked?2:1;
        $server->exec("INSERT INTO users VALUES($id,'teacher','active',1)");
        $server->exec("INSERT INTO teacher_sections VALUES($id,1),($id,2)");
        if ($linked) $server->exec("INSERT INTO history VALUES($id)");
        $server->beginTransaction();
        archiveTeacher($server,$id,1,['id'=>99,'role'=>'admin']);
        $server->commit();
        $row=$server->query('SELECT status,auth_version FROM users WHERE id='.$id)->fetch(PDO::FETCH_ASSOC);
        if ($row['status']!=='inactive'||(int)$row['auth_version']!==2||(int)$server->query('SELECT COUNT(*) FROM teacher_sections WHERE teacher_id='.$id.' AND academic_year_id=1')->fetchColumn()!==0||(int)$server->query('SELECT COUNT(*) FROM teacher_sections WHERE teacher_id='.$id.' AND academic_year_id=2')->fetchColumn()!==1) throw new RuntimeException('Archive did not preserve other years');
        if($linked&&(int)$server->query('SELECT COUNT(*) FROM history')->fetchColumn()!==1)throw new RuntimeException('History not preserved');
    }
    echo "PASS: archive works with or without historical FKs and without grading tables; other years preserved\n";
} finally {
    if ($server->inTransaction()) $server->rollBack();
    if (preg_match('/^absences_teacher_delete_test_[a-f0-9]{12}$/D',$name)) $server->exec('DROP DATABASE `'.$name.'`');
}