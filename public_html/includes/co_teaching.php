<?php
// SPDX-License-Identifier: GPL-3.0-only
// Composition metadata lives in settings and is included in existing backups.
function coTeachingGroups(PDO $db): array {
    $q=$db->prepare('SELECT setting_value FROM settings WHERE setting_key=?');$q->execute(['co_teaching_sections']);
    $raw=$q->fetchColumn();if($raw===false)return [];
    $groups=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    if(!is_array($groups))throw new RuntimeException('Invalid co-teaching settings');
    $out=[];
    foreach($groups as $id=>$sources){
        if(!ctype_digit((string)$id)||(int)$id<1||!is_array($sources))throw new RuntimeException('Invalid co-teaching composition');
        $ids=[];foreach($sources as $source){if(!is_int($source)||$source<1)throw new RuntimeException('Invalid source section');$ids[]=$source;}
        $out[(int)$id]=array_values(array_unique($ids));
    }
    return $out;
}
function coTeachingMembershipSql(array $groups): string {
    if(!$groups)return 'student_sections';
    $targets=implode(',',array_map('intval',array_keys($groups)));$edges=[];
    foreach($groups as $target=>$sources)foreach($sources as $source)$edges[]='SELECT '.(int)$target.' AS target_id, '.(int)$source.' AS source_id';
    if(!$edges)throw new RuntimeException('Empty co-teaching composition');
    return '(SELECT student_id, section_id FROM student_sections WHERE section_id NOT IN ('.$targets.') UNION SELECT ss.student_id, c.target_id AS section_id FROM student_sections ss JOIN ('.implode(' UNION ALL ',$edges).') c ON c.source_id=ss.section_id)';
}
function coTeachingSave(PDO $db,int $year,int $id,int $grade,string $name,array $sources): int {
    $name=trim($name);$sources=array_values(array_unique($sources));
    if($grade<1||$name===''||mb_strlen($name)>100||count($sources)<2||count($sources)>100)throw new DomainException('Ορίστε όνομα έως 100 χαρακτήρες, τάξη και τουλάχιστον δύο διαφορετικά τμήματα.');
    foreach($sources as $source)if(!is_int($source)||$source<1||$source===$id)throw new DomainException('Μη έγκυρη επιλογή τμημάτων.');
    $db->beginTransaction();
    try {
        $q=$db->prepare('SELECT id FROM academic_years WHERE id=? AND is_active=1 FOR UPDATE');$q->execute([$year]);if(!$q->fetchColumn())throw new DomainException('Το ενεργό έτος άλλαξε. Ανανεώστε τη σελίδα.');
        $db->prepare('INSERT IGNORE INTO settings(setting_key,setting_value) VALUES(?,?)')->execute(['co_teaching_sections','{}']);
        $q=$db->prepare('SELECT setting_value FROM settings WHERE setting_key=? FOR UPDATE');$q->execute(['co_teaching_sections']);$groups=coTeachingGroups($db);
        if($id && !isset($groups[$id]))throw new DomainException('Δεν βρέθηκε η συνδιδασκαλία.');
        if(!$id && count($groups)>=100)throw new DomainException('Έχει συμπληρωθεί το όριο 100 συνδιδασκαλιών.');
        foreach($sources as $source)if(isset($groups[$source]))throw new DomainException('Επιλέξτε απλά τμήματα, όχι άλλες συνδιδασκαλίες.');
        $q=$db->prepare('SELECT id FROM sections WHERE grade_id=? AND id IN ('.implode(',',array_fill(0,count($sources),'?')).') FOR UPDATE');$q->execute(array_merge([$grade],$sources));
        if(count($q->fetchAll(PDO::FETCH_COLUMN))!==count($sources))throw new DomainException('Όλα τα τμήματα πρέπει να ανήκουν στην επιλεγμένη τάξη.');
        if($id){
            $q=$db->prepare('SELECT grade_id FROM sections WHERE id=? FOR UPDATE');$q->execute([$id]);$oldGrade=(int)$q->fetchColumn();
            $old=$groups[$id];sort($old);$next=$sources;sort($next);
            if($old!==$next||$oldGrade!==$grade){$q=$db->prepare('SELECT COUNT(*) FROM attendance WHERE section_id=?');$q->execute([$id]);if($q->fetchColumn())throw new DomainException('Υπάρχουν καταχωρίσεις παρουσιολογίου. Επιτρέπεται μόνο αλλαγή ονόματος· δημιουργήστε νέα συνδιδασκαλία για άλλη σύνθεση.');}
            $db->prepare('UPDATE sections SET name=?,grade_id=? WHERE id=?')->execute([$name,$grade,$id]);
        }else{$db->prepare('INSERT INTO sections(grade_id,name) VALUES(?,?)')->execute([$grade,$name]);$id=(int)$db->lastInsertId();}
        $groups[$id]=$sources;$db->prepare('UPDATE settings SET setting_value=? WHERE setting_key=?')->execute([json_encode((object)$groups,JSON_THROW_ON_ERROR),'co_teaching_sections']);
        $db->commit();return $id;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}

function coTeachingDelete(PDO $db,int $year,int $id): void {
    $db->beginTransaction();
    try {
        $q=$db->prepare('SELECT id FROM academic_years WHERE id=? AND is_active=1 FOR UPDATE');$q->execute([$year]);if(!$q->fetchColumn())throw new DomainException('Το ενεργό έτος άλλαξε.');
        $db->prepare('INSERT IGNORE INTO settings(setting_key,setting_value) VALUES(?,?)')->execute(['co_teaching_sections','{}']);
        $q=$db->prepare('SELECT setting_value FROM settings WHERE setting_key=? FOR UPDATE');$q->execute(['co_teaching_sections']);$groups=coTeachingGroups($db);
        foreach($groups as $sources)if(in_array($id,$sources,true))throw new DomainException('Το τμήμα συμμετέχει σε συνδιδασκαλία. Αλλάξτε πρώτα τη σύνθεσή της ή διαγράψτε την, εφόσον δεν έχει καταχωρίσεις.');
        $q=$db->prepare('SELECT COUNT(*) FROM attendance WHERE section_id=?');$q->execute([$id]);if($q->fetchColumn())throw new DomainException('Δεν επιτρέπεται διαγραφή τμήματος με καταχωρίσεις παρουσιολογίου. Το ιστορικό προστατεύεται.');
        $q=$db->prepare('SELECT COUNT(*) FROM student_sections WHERE section_id=?');$q->execute([$id]);if($q->fetchColumn())throw new DomainException('Αποσυνδέστε πρώτα τους εγγεγραμμένους μαθητές.');
        unset($groups[$id]);$db->prepare('UPDATE settings SET setting_value=? WHERE setting_key=?')->execute([json_encode((object)$groups,JSON_THROW_ON_ERROR),'co_teaching_sections']);
        $db->prepare('DELETE FROM sections WHERE id=?')->execute([$id]);$db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
