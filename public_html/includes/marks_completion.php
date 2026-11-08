<?php
// Read-only completion calculations over already authorized courses and periods.
function marksCompletion(array $course,array $period,array $stats): array {
    $total=(int)$course['student_count'];
    $complete=min($total,max(0,(int)($stats['complete']??0)));
    $required=(bool)$course['is_active'] && $total>0 && ($period['code']!=='written'||$course['has_written_exam']);
    $missing=max(0,$total-$complete);
    $submitted=($stats['status']??'draft')==='submitted';
    return ['required'=>$required,'complete'=>$complete,'missing'=>$missing,
        'empty'=>$required&&$complete===0,'incomplete'=>$required&&$missing>0,
        'draft'=>$required&&$missing===0&&!$submitted,
        'submitted'=>$required&&$submitted,'pending'=>$required&&($missing>0||!$submitted)];
}

function marksCompletionMatches(array $course,array $periods,array $stats,string $state): bool {
    if($state==='all') return true;
    foreach($periods as $period) {
        $result=marksCompletion($course,$period,$stats[$period['id']]??[]);
        if($result[$state]??false) return true;
    }
    return false;
}
