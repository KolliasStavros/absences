<?php
require_once __DIR__.'/../public_html/includes/marks_completion.php';
function completionCheck(bool $ok,string $label): void {
    if(!$ok) throw new RuntimeException($label);
    echo "PASS: $label\n";
}
$course=['id'=>10,'student_count'=>3,'is_active'=>1,'has_written_exam'=>0,'section_name'=>'Α1','subject_name'=>'Μάθημα'];
$term=['id'=>1,'code'=>'term1','name'=>'Α΄ τετράμηνο'];
$written=['id'=>3,'code'=>'written','name'=>'Γραπτά'];
$empty=marksCompletion($course,$term,[]);
completionCheck($empty['empty']&&$empty['missing']===3&&$empty['pending'],'missing records mean no grades entered');
$partial=marksCompletion($course,$term,['complete'=>2]);
completionCheck($partial['incomplete']&&$partial['missing']===1&&!$partial['empty'],'partially entered grades counted');
$draft=marksCompletion($course,$term,['complete'=>3]);
completionCheck($draft['draft']&&!$draft['incomplete']&&$draft['pending'],'full draft separate from missing grades');
$submitted=marksCompletion($course,$term,['complete'=>3,'status'=>'submitted']);
completionCheck(!$submitted['pending']&&$submitted['submitted'],'full final submission has no pending work');
completionCheck(!marksCompletion($course,$written,[])['pending'],'non-written course excluded from written obligations');
completionCheck(!marksCompletion(array_replace($course,['is_active'=>0]),$term,[])['pending'],'inactive courses excluded');
completionCheck(!marksCompletion(array_replace($course,['student_count'=>0]),$term,[])['pending'],'empty rosters excluded');
completionCheck(marksCompletionMatches($course,[$term,$written],[1=>['complete'=>2]],'incomplete'),'filter any selected applicable period');
completionCheck(!marksCompletionMatches($course,[$written],[],'empty'),'written-only filter excludes non-exam courses');
$view='submissions'; $courses=[$course]; $periods=[$term,$written]; $stats=[];
$overviewTeachers=[10=>[5=>'Teacher A',6=>'Teacher B']]; $completionState='pending';
$overviewFilters=['teacher_id'=>5];
$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$url=static fn($v)=>'marks.php?'.http_build_query($v);
ob_start(); require __DIR__.'/../public_html/includes/marks_completion_summary.php'; $html=ob_get_clean();
completionCheck(str_contains($html,'Teacher A')&&!str_contains($html,'Teacher B'),'teacher filter respected with co-teachers');
completionCheck(str_contains($html,'Δεν έχουν καταχωριστεί βαθμοί')&&str_contains($html,'Α1'),'pending section and empty status visible');
completionCheck(count($pendingTeachers[5]['items'])===1,'summary ignores inapplicable written period');
$stats=[10=>[1=>['complete'=>3,'status'=>'submitted']]];
ob_start(); require __DIR__.'/../public_html/includes/marks_completion_summary.php'; $html=ob_get_clean();
completionCheck($courses===[]&&str_contains($html,'Δεν υπάρχουν εκκρεμότητες'),'pending-only excludes completed submissions');
