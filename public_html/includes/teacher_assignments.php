<?php
// Shared attendance assignments: explicit exceptions plus current teaching assignments.
// Never copy derived grants into teacher_sections; revocation then follows the course.
function teacherAttendanceAssignmentsSql(PDO $db): string {
    static $ready;
    $ready ??= new WeakMap();
    if(!isset($ready[$db])) {
        try {
            $db->query('SELECT 1 FROM course_offerings LIMIT 0');
            $db->query('SELECT 1 FROM course_teachers LIMIT 0');
            $ready[$db]=true;
        } catch(PDOException $e) {
            if((int)($e->errorInfo[1]??0)!==1146) throw $e;
            $ready[$db]=false;
        }
    }
    if(!$ready[$db]) return 'teacher_sections';
    return '(SELECT academic_year_id,teacher_id,section_id FROM teacher_sections UNION SELECT c.academic_year_id,ct.teacher_id,c.section_id FROM course_offerings c JOIN course_teachers ct ON ct.course_offering_id=c.id WHERE c.is_active=1 AND ct.is_active=1)';
}
