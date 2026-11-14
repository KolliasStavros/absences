<?php
require_once __DIR__.'/marks_import.php';
// Caller holds the existing account locks and transaction. Historical actors are never deleted.
function archiveTeacher(PDO $db,int $teacher,int $year,array $actor): void {
    if(($actor['role']??'')!=='admin'||!$db->inTransaction())throw new DomainException('Απαιτείται διαχειριστής και συναλλαγή.');
    $q=$db->prepare("SELECT id FROM users WHERE id=? AND role IN ('teacher','teacher_admin') FOR UPDATE");$q->execute([$teacher]);if(!$q->fetchColumn())throw new DomainException('Δεν βρέθηκε καθηγητής προς αποχώρηση.');
    $db->prepare("UPDATE users SET status='inactive',auth_version=auth_version+1 WHERE id=?")->execute([$teacher]);
    $db->prepare('UPDATE password_resets SET used_at=CURRENT_TIMESTAMP WHERE user_id=? AND used_at IS NULL')->execute([$teacher]);
    $db->prepare('DELETE FROM teacher_sections WHERE teacher_id=? AND academic_year_id=?')->execute([$teacher,$year]);
    if(marksReady($db)){
        $q=$db->prepare('SELECT c.* FROM course_offerings c JOIN course_teachers ct ON ct.course_offering_id=c.id WHERE ct.teacher_id=? AND ct.is_active=1 AND c.academic_year_id=? ORDER BY c.id FOR UPDATE');$q->execute([$teacher,$year]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $course){
            $before=marksAssignmentSnapshot($db,(int)$course['id']);
            $db->prepare('UPDATE course_teachers SET is_active=0 WHERE course_offering_id=? AND teacher_id=?')->execute([$course['id'],$teacher]);
            $db->prepare('UPDATE course_offerings SET version=version+1 WHERE id=?')->execute([$course['id']]);
            marksAudit($db,(int)$course['id'],(int)$actor['id'],'assignment_manual',$before,marksAssignmentSnapshot($db,(int)$course['id']),null,null,'Αποχώρηση καθηγητή');
        }
    }
}
