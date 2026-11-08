<?php
// Read-only helpers for the administrator's grading overview.
require_once __DIR__.'/request_validation.php';

function marksOverviewFilters(array $input): array {
    $filters=[];
    foreach(['grade_id','section_id','subject_id','teacher_id'] as $key) {
        $filters[$key]=schoolOptionalRequestId($input[$key]??null);
        if($filters[$key]===null) throw new DomainException('Μη έγκυρο φίλτρο επισκόπησης.');
    }
    $filters['period']=$input['period']??'all';
    if(!is_string($filters['period'])||!in_array($filters['period'],['term1','term2','written','terms','all'],true)) {
        throw new DomainException('Μη έγκυρη περίοδος επισκόπησης.');
    }
    return $filters;
}

function marksOverviewCourses(array $courses,array $teachers,array $filters): array {
    return array_values(array_filter($courses,static function(array $course) use($teachers,$filters): bool {
        foreach(['grade_id','section_id','subject_id'] as $key) {
            if($filters[$key] && (int)$course[$key]!==$filters[$key]) return false;
        }
        return !$filters['teacher_id'] || isset($teachers[$course['id']][$filters['teacher_id']]);
    }));
}

function marksOverviewPeriods(array $periods,string $selection): array {
    $codes=match($selection) {
        'terms'=>['term1','term2'], 'all'=>['term1','term2','written'], default=>[$selection]
    };
    $byCode=array_column($periods,null,'code');
    $result=[];
    foreach($codes as $code) if(isset($byCode[$code])) $result[]=$byCode[$code];
    return $result;
}

function marksOverviewGroups(array $courses,array $rows): array {
    $groups=[]; $courseSections=[];
    foreach($courses as $course) {
        $section=$course['section_id']; $courseSections[$course['id']]=$section;
        $groups[$section]??=['name'=>$course['section_name'],'grade'=>$course['grade_name'],'courses'=>[],'students'=>[]];
        $groups[$section]['courses'][$course['id']]=$course;
    }
    foreach($rows as $row) {
        $section=$courseSections[$row['course_offering_id']]??null;
        if($section===null) continue;
        $student=&$groups[$section]['students'][$row['student_id']];
        if($student===null) $student=['name'=>$row['last_name'].' '.$row['first_name'],'am'=>$row['am'],'courses'=>[]];
        $student['courses'][$row['course_offering_id']]??=[];
        if($row['code']!==null) $student['courses'][$row['course_offering_id']][$row['code']]=$row;
        unset($student);
    }
    foreach($groups as &$group) {
        uasort($group['students'],static fn($a,$b)=>strcmp($a['name'],$b['name']) ?: strnatcmp($a['am'],$b['am']));
    }
    unset($group);
    return $groups;
}
