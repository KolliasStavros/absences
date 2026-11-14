<?php
// Reuse synthetic grading fixtures; no database connection or real records.
require __DIR__.'/test_marks_overview.php';
$view='submissions';
$_GET=['grade_id'=>'1','teacher_id'=>'5','period'=>'terms'];
ob_start(); require __DIR__.'/../public_html/includes/marks_filters.php'; $html=ob_get_clean();
overviewCheck(array_column($overviewCourses,'id')===[10,30],'submissions filters combine grade and teacher');
overviewCheck(array_column($overviewPeriods,'code')===['term1','term2'],'submissions limits displayed period columns');
overviewCheck(str_contains($html,'name="view" value="submissions"')&&str_contains($html,'href="marks.php?view=submissions"'),'submit and reset stay on submissions');

// The caller supplies only courses already authorized for teacher/teacher_admin.
$courses=[$courses[0]];
$db=new OverviewFixtureDB([$teacherRows[0]],[]);
$_GET=[];
ob_start(); require __DIR__.'/../public_html/includes/marks_filters.php'; $html=ob_get_clean();
overviewCheck(array_column($overviewCourses,'id')===[10],'all never expands the authorized course list');
overviewCheck(str_contains($db->queries[0],'c.id IN (10)'),'teacher choices query constrained to authorized courses');
overviewCheck(!str_contains($html,'Other')&&!str_contains($html,'Β1'),'unrelated teacher and section omitted from filters');
$_GET=['teacher_id'=>'6'];
ob_start(); require __DIR__.'/../public_html/includes/marks_filters.php'; ob_end_clean();
overviewCheck($overviewCourses===[],'unassigned teacher filter returns no authorized results');
$_GET=['teacher_id'=>['5']];
ob_start(); require __DIR__.'/../public_html/includes/marks_filters.php'; ob_end_clean();
overviewCheck($overviewCourses===[]&&$overviewError!=='','invalid submissions filters cannot broaden results');
