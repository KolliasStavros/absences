<?php
// Called only after the submissions page has scoped courses, periods and statistics.
if(!isset($view,$courses,$periods,$stats,$overviewTeachers,$completionState,$h) || $view!=='submissions') { http_response_code(403); exit; }
require_once __DIR__.'/marks_completion.php';
$courses=array_values(array_filter($courses,static fn($c)=>marksCompletionMatches($c,$periods,$stats[$c['id']]??[],$completionState)));
$pendingTeachers=[];
foreach($courses as $c) foreach($periods as $p) {
    $completion=marksCompletion($c,$p,$stats[$c['id']][$p['id']]??[]);
    if(!$completion['pending']) continue;
    foreach(($overviewTeachers[$c['id']]??[]) ?: [0=>'Χωρίς καθηγητή'] as $teacherId=>$name) {
        if(!empty($overviewFilters['teacher_id']) && (int)$teacherId!==$overviewFilters['teacher_id']) continue;
        $pendingTeachers[$teacherId]??=['name'=>$name,'items'=>[]];
        $pendingTeachers[$teacherId]['items'][]=['course'=>$c,'period'=>$p,'completion'=>$completion];
    }
}
?>
<section class="card"><h2>Εκκρεμότητες ανά καθηγητή</h2>
<p class="marks-help">Για τα επιλεγμένα φίλτρα και τις περιόδους. Εμφανίζονται ενεργές διδασκαλίες με μαθητές. Οι ενδείξεις Δ, -1 και απαλλαγής μετρούν ως καταχωρίσεις. Στις συνδιδασκαλίες η εκκρεμότητα εμφανίζεται σε κάθε ανατεθειμένο καθηγητή.</p>
<?php if(!$pendingTeachers): ?><p>Δεν υπάρχουν εκκρεμότητες για τις επιλεγμένες επιλογές.</p><?php else: ?>
<div class="marks-table-wrap"><table class="marks-table"><thead><tr><th>Καθηγητής</th><th>Τμήμα / μάθημα</th><th>Περίοδος</th><th>Εκκρεμότητα</th></tr></thead><tbody>
<?php foreach($pendingTeachers as $teacher): foreach($teacher['items'] as $index=>$item): $c=$item['course']; $p=$item['period']; $completion=$item['completion']; ?>
<tr><?php if($index===0): ?><th scope="rowgroup" rowspan="<?= count($teacher['items']) ?>"><?= $h($teacher['name']) ?></th><?php endif; ?>
<td><strong><?= $h($c['section_name']) ?></strong><br><?= $h($c['subject_name']) ?></td><td><?= $h($p['name']) ?></td>
<td><a class="marks-course-link" href="<?= $h($url(['view'=>'entry','course_id'=>$c['id'],'period_id'=>$p['id']])) ?>"><?= $completion['complete'] ?>/<?= (int)$c['student_count'] ?></a><br><?= $completion['empty']?'Δεν έχουν καταχωριστεί βαθμοί':($completion['incomplete']?'Λείπουν '.$completion['missing'].' βαθμοί':'Πλήρης · εκκρεμεί οριστική υποβολή') ?></td></tr>
<?php endforeach; endforeach; ?></tbody></table></div><?php endif; ?></section>
