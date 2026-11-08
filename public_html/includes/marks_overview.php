<?php
// Included only by the authenticated common marks page. Never expose as an endpoint.
if(!isset($admin,$db,$yearId,$courses,$periods,$h,$displayGrade) || !$admin) { http_response_code(403); exit; }
require __DIR__.'/marks_filters.php'; ?>
<?php
$overviewRows=[];
if($overviewCourses && $overviewPeriods) {
    $ids=implode(',',array_map('intval',array_column($overviewCourses,'id')));
    $periodIds=implode(',',array_map('intval',array_column($overviewPeriods,'id')));
    $q=$db->prepare("SELECT cs.student_id,cs.course_offering_id,s.am,s.last_name,s.first_name,p.code,m.mark_value,m.mark_status FROM course_students cs JOIN course_offerings c ON c.id=cs.course_offering_id JOIN students s ON s.id=cs.student_id LEFT JOIN student_marks m ON m.course_student_id=cs.id AND m.assessment_period_id IN ($periodIds) LEFT JOIN assessment_periods p ON p.id=m.assessment_period_id AND p.academic_year_id=c.academic_year_id WHERE c.academic_year_id=? AND c.id IN ($ids) AND cs.is_active=1 ORDER BY s.last_name,s.first_name,s.am,s.id");
    $q->execute([$yearId]); $overviewRows=$q->fetchAll(PDO::FETCH_ASSOC);
}
$overviewGroups=marksOverviewGroups($overviewCourses,$overviewRows);
$overviewPeriodLabels=['term1'=>'Α΄ τετρ.','term2'=>'Β΄ τετρ.','written'=>'Γραπτά'];
$overviewStatusLabels=['not_submitted'=>'Δ','no_show'=>'-1','absent'=>'Απουσία','exempt'=>'Απαλλαγή'];
?>
<p class="marks-help">Περίοδος: <?= $h($overviewPeriodOptions[$overviewFilters['period']]) ?> · — Χωρίς καταχώριση · Δ Δεν κατατέθηκε βαθμός · -1 Δεν προσήλθε. Σύρετε οριζόντια για τα υπόλοιπα μαθήματα.</p>
<?php if(!$overviewGroups || !$overviewPeriods): ?><p class="marks-notice">Δεν βρέθηκαν αποτελέσματα για τις επιλεγμένες επιλογές.</p><?php endif; ?>
<?php foreach($overviewGroups as $sectionId=>$group): ?>
<section class="card"><h2 id="marks-section-<?= (int)$sectionId ?>">Τμήμα: <?= $h($group['name']) ?> <small class="marks-help"><?= $h($group['grade']) ?> · <?= count($group['students']) ?> μαθητές</small></h2>
<div class="marks-table-wrap marks-overview-scroll" tabindex="0" role="region" aria-labelledby="marks-section-<?= (int)$sectionId ?>">
<table class="marks-table marks-overview-table"><thead><tr><th rowspan="2" scope="col" class="marks-student-column">Ονοματεπώνυμο μαθητή</th>
<?php foreach($group['courses'] as $c): ?><th colspan="<?= count($overviewPeriods) ?>" scope="colgroup" class="marks-subject-group"><?= $h($c['subject_name']) ?><small><?= $h(implode(', ',$overviewTeachers[$c['id']]??[]) ?: 'Χωρίς καθηγητή') ?></small><?php if(!$c['is_active']): ?><small>Ανενεργό</small><?php endif; ?></th><?php endforeach; ?></tr><tr>
<?php foreach($group['courses'] as $c): foreach($overviewPeriods as $index=>$p): ?><th scope="col" class="<?= $index===0?'marks-subject-start':'' ?>"><a href="<?= $h($url(['view'=>'entry','course_id'=>$c['id'],'period_id'=>$p['id']])) ?>" title="<?= $h('Καταχώριση · '.$c['subject_name'].' · '.$p['name']) ?>"><?= $h($overviewPeriodLabels[$p['code']]) ?></a></th><?php endforeach; endforeach; ?></tr></thead><tbody>
<?php foreach($group['students'] as $student): ?><tr><th scope="row" class="marks-student-column"><?= $h($student['name']) ?><small>ΑΜ <?= $h($student['am']) ?></small></th>
<?php foreach($group['courses'] as $c): foreach($overviewPeriods as $index=>$p):
    $m=$student['courses'][$c['id']][$p['code']]??null;
    $text='—'; $title='Χωρίς καταχώριση';
    if(!array_key_exists($c['id'],$student['courses'])) { $text='·'; $title='Δεν συμμετέχει στη διδασκαλία'; }
    elseif($m && $m['mark_status']==='value' && $m['mark_value']!==null) { $text=$displayGrade($m['mark_value']); $title='Βαθμός'; }
    elseif(isset($overviewStatusLabels[$m['mark_status']??''])) { $text=$overviewStatusLabels[$m['mark_status']]; $title=$specialMarkLabels[$m['mark_status']]??$text; }
    elseif($p['code']==='written'&&!$c['has_written_exam']) { $text='Μ/Ε'; $title='Δεν εξετάζεται γραπτώς'; }
?><td class="<?= $index===0?'marks-subject-start':'' ?>" title="<?= $h($title) ?>"><?= $h($text) ?></td><?php endforeach; endforeach; ?></tr><?php endforeach; ?>
<?php if(!$group['students']): ?><tr><td colspan="<?= 1+count($group['courses'])*count($overviewPeriods) ?>">Δεν υπάρχουν ενεργοί μαθητές στις επιλεγμένες διδασκαλίες.</td></tr><?php endif; ?>
</tbody></table></div></section>
<?php endforeach; ?>
