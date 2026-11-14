<?php
// Recognize only the exact additive registry migration; never suppress general SQL errors.
function teacherRegistryMigrationPlan(PDO $db,string $table,string $sql): ?array {
    if(!preg_match('/^[a-zA-Z0-9_]+$/D',$table))throw new InvalidArgumentException('Invalid table');
    $expected='ALTER TABLE `'.$table.'` ADD COLUMN teacher_registry_number VARCHAR(30) DEFAULT NULL, ADD UNIQUE KEY unique_teacher_registry_number (teacher_registry_number);';
    if(trim($sql)!==$expected)return null;
    $q=$db->prepare('SELECT COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$q->execute([$table,'teacher_registry_number']);$column=$q->fetch(PDO::FETCH_ASSOC);
    $q=$db->prepare('SELECT INDEX_NAME,NON_UNIQUE,COLUMN_NAME,SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX');$q->execute([$table]);$indexes=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$indexes[$row['INDEX_NAME']][]=$row;
    $unique=false;
    foreach($indexes as $rows)if(count($rows)===1&&(int)$rows[0]['NON_UNIQUE']===0&&$rows[0]['COLUMN_NAME']==='teacher_registry_number'&&$rows[0]['SUB_PART']===null)$unique=true;
    if(!$column){if(isset($indexes['unique_teacher_registry_number']))throw new DomainException('Ο δείκτης ΑΜ χρησιμοποιείται ήδη με διαφορετική μορφή. Απαιτείται έλεγχος σχήματος πριν την ενημέρωση.');return [$expected];}
    if(strtolower($column['COLUMN_TYPE'])!=='varchar(30)'||$column['IS_NULLABLE']!=='YES'||$column['COLUMN_DEFAULT']!==null||$column['EXTRA']!=='')throw new DomainException('Η υπάρχουσα στήλη ΑΜ δεν έχει την απαιτούμενη μορφή VARCHAR(30), προαιρετική, με προεπιλογή NULL. Η ενημέρωση σταμάτησε πριν από αλλαγές.');
    if($unique)return [];
    if(isset($indexes['unique_teacher_registry_number']))throw new DomainException('Ο δείκτης ΑΜ έχει ασύμβατη μορφή. Απαιτείται έλεγχος σχήματος.');
    if($db->query('SELECT 1 FROM `'.$table.'` WHERE teacher_registry_number IS NOT NULL GROUP BY teacher_registry_number HAVING COUNT(*)>1 LIMIT 1')->fetchColumn()!==false)throw new DomainException('Υπάρχουν διπλοί ΑΜ καθηγητών. Διορθώστε τους πριν από την ενημέρωση.');
    return ['ALTER TABLE `'.$table.'` ADD UNIQUE KEY unique_teacher_registry_number (teacher_registry_number);'];
}
