<?php
if(!isset($admin,$view,$db,$courses,$periods,$baseFields)||!$admin||$view!=='manage') { http_response_code(403); exit; }
require __DIR__.'/marks_filters.php';
$assignmentUsers=$db->query("SELECT id,name,status FROM users WHERE role IN ('teacher','teacher_admin','admin') ORDER BY name,id")->fetchAll(PDO::FETCH_ASSOC);
$assignmentNames=array_column($assignmentUsers,'name','id');
$teacherOptions=static function() use($assignmentUsers,$h) {
    echo '<option value="">Επιλέξτε καθηγητή</option>';
    $statuses=['active'=>'Ενεργός','pending_activation'=>'Αναμονή ενεργοποίησης','inactive'=>'Ανενεργός'];
    foreach($assignmentUsers as $teacher) echo '<option value="'.(int)$teacher['id'].'">'.$h($teacher['name'].' · '.($statuses[$teacher['status']]??'Μη ενεργός')).'</option>';
};
$assignmentBase=static function($c) use($baseFields) {
    $baseFields();
    echo '<input type="hidden" name="course_id" value="'.(int)$c['id'].'"><input type="hidden" name="course_version" value="'.(int)$c['version'].'">';
};
$bulkPreview=null;
if(($_POST['action']??'')==='bulk_preview' && !$error) {
    $old=schoolRequestId($_POST['old_teacher_id']??null); $new=schoolRequestId($_POST['teacher_id']??null);
    if(!$old||!$new||$old===$new||!isset($assignmentNames[$new])) {
        echo '<p class="marks-notice marks-error" role="alert">Επιλέξτε διαφορετικό παλιό και νέο καθηγητή.</p>';
    } else {
        $targets=[]; $versions=[];
        foreach($overviewCourses as $c) if(isset($overviewTeachers[$c['id']][$old])) { $targets[]=$c; $versions[$c['id']]=(string)$c['version']; }
        if(!$targets||count($targets)>500) echo '<p class="marks-notice marks-error">Επιλέξτε φίλτρα που περιλαμβάνουν από 1 έως 500 διδασκαλίες του παλιού καθηγητή.</p>';
        else {
            $input=['old_teacher_id'=>$old,'teacher_id'=>$new,'course_versions'=>$versions];
            if(isset($_POST['can_edit_marks'])) $input['can_edit_marks']='1';
            $bulkPreview=['year_id'=>$yearId,'created'=>time(),'token'=>bin2hex(random_bytes(24)),'input'=>$input,'targets'=>$targets,'old_name'=>$overviewTeacherNames[$old]??'','new_name'=>$assignmentNames[$new]];
            $_SESSION['marks_bulk']=$bulkPreview;
        }
    }
}
?>
<section class="card"><h2>Διδασκαλίες και καθηγητές</h2>
<div class="marks-table-wrap"><table class="marks-table"><thead><tr><th>Τμήμα</th><th>Μάθημα</th><th>Καθηγητής/ές</th><th>Ενέργεια</th></tr></thead><tbody>
<?php foreach($overviewCourses as $c): ?><tr><td><?= $h($c['section_name']) ?></td><td><?= $h($c['subject_name']) ?><?= !$c['is_active']?' · Ανενεργό':'' ?></td><td><?= $h(implode(', ',$overviewTeachers[$c['id']]??[]) ?: 'Χωρίς καθηγητή') ?></td><td><a class="marks-course-link" href="<?= $h($url(array_merge($_GET,['view'=>'manage','course_id'=>$c['id']])).'#assignment-editor') ?>">Επεξεργασία ανάθεσης</a></td></tr><?php endforeach; ?>
<?php if(!$overviewCourses): ?><tr><td colspan="4">Δεν βρέθηκαν διδασκαλίες για τα επιλεγμένα φίλτρα.</td></tr><?php endif; ?>
</tbody></table></div></section>
<?php if($bulkPreview): ?>
<section class="card"><h2>Επιβεβαίωση μαζικής αντικατάστασης</h2><p><?= $h($bulkPreview['old_name']) ?> → <?= $h($bulkPreview['new_name']) ?>. Ο προηγούμενος χάνει την πρόσβαση στις επιλεγμένες διδασκαλίες. Οι βαθμοί και οι οριστικές υποβολές διατηρούνται.</p>
<p>Καταχώριση βαθμών από τον νέο καθηγητή: <strong><?= isset($bulkPreview['input']['can_edit_marks'])?'Ναι':'Όχι' ?></strong></p>
<form method="post"><?php $baseFields(); ?><input type="hidden" name="action" value="bulk_replace"><input type="hidden" name="bulk_token" value="<?= $h($bulkPreview['token']) ?>">
<?php foreach($bulkPreview['targets'] as $c): ?><p><label><input type="checkbox" name="bulk_selected[]" value="<?= (int)$c['id'] ?>" checked> <?= $h($c['section_name'].' · '.$c['subject_name']) ?></label></p><?php endforeach; ?>
<button class="btn btn-primary">Επιβεβαίωση αντικατάστασης</button> <a href="<?= $h($url(['view'=>'manage'])) ?>">Ακύρωση</a></form></section>
<?php endif; ?>
<section class="card"><details><summary>Μαζική αντικατάσταση καθηγητή</summary><p>Η προεπισκόπηση περιλαμβάνει μόνο τις διδασκαλίες που αντιστοιχούν στα παραπάνω φίλτρα και στον παλιό καθηγητή.</p>
<form method="post" class="marks-inline-form"><?php $baseFields(); ?><input type="hidden" name="action" value="bulk_preview"><label>Παλιός καθηγητής<select class="form-control" name="old_teacher_id" required><option value="">Επιλέξτε καθηγητή</option><?php foreach($overviewTeacherNames as $id=>$name): ?><option value="<?= (int)$id ?>"><?= $h($name) ?></option><?php endforeach; ?></select></label><label>Νέος καθηγητής<select class="form-control" name="teacher_id" required><?php $teacherOptions(); ?></select></label><label><input type="checkbox" name="can_edit_marks" checked> Καταχώριση βαθμών</label><button class="btn btn-secondary">Προεπισκόπηση αλλαγών</button></form></details></section>
<?php if($course): ?>
<section class="card" id="assignment-editor"><h2><?= $h($course['section_name'].' · '.$course['subject_name']) ?></h2>
<p class="marks-help">Οι αλλαγές καταγράφονται στο ιστορικό και δεν μεταβάλλουν βαθμούς ή υποβολές. Χωρίς δικαίωμα καταχώρισης, ο καθηγητής δεν έχει πρόσβαση στη βαθμολογία αυτής της διδασκαλίας.</p>
<?php $q=$db->prepare('SELECT ct.*,u.name FROM course_teachers ct JOIN users u ON u.id=ct.teacher_id WHERE course_offering_id=? ORDER BY u.name'); $q->execute([$courseId]); $assigned=$q->fetchAll(PDO::FETCH_ASSOC); ?>
<?php foreach($assigned as $ct): ?>
<form method="post" class="marks-inline-form" style="margin:16px 0"><?php $assignmentBase($course); ?><input type="hidden" name="action" value="teacher_access"><input type="hidden" name="teacher_id" value="<?= (int)$ct['teacher_id'] ?>"><input type="hidden" name="edit_access_present" value="1"><strong><?= $h($ct['name']) ?></strong><label><input type="checkbox" name="teacher_active" <?= $ct['is_active']?'checked':'' ?>> Ενεργή ανάθεση</label><label><input type="checkbox" name="can_edit_marks" <?= $ct['can_edit_marks']?'checked':'' ?>> Καταχώριση βαθμών</label><button class="btn btn-secondary">Ενημέρωση πρόσβασης</button></form>
<?php if($ct['is_active']): ?><details><summary>Αντικατάσταση <?= $h($ct['name']) ?></summary><form method="post" class="marks-inline-form"><?php $assignmentBase($course); ?><input type="hidden" name="action" value="assignment_replace"><input type="hidden" name="old_teacher_id" value="<?= (int)$ct['teacher_id'] ?>"><label>Νέος καθηγητής<select name="teacher_id" class="form-control" required><?php $teacherOptions(); ?></select></label><label><input type="checkbox" name="can_edit_marks" checked> Καταχώριση βαθμών</label><button class="btn btn-primary">Αντικατάσταση καθηγητή</button></form></details><?php endif; ?>
<?php endforeach; ?>
<p class="marks-help">Η ανάθεση σε καθηγητή σε αναμονή ενεργοποίησης ή ανενεργό δεν ενεργοποιεί τον λογαριασμό του. Η πρόσβαση ρυθμίζεται από τη διαχείριση καθηγητών.</p><h3>Προσθήκη καθηγητή / συνδιδασκαλία</h3><form method="post" class="marks-inline-form"><?php $assignmentBase($course); ?><input type="hidden" name="action" value="assignment_add"><label>Καθηγητής<select name="teacher_id" class="form-control" required><?php $teacherOptions(); ?></select></label><label><input type="checkbox" name="can_edit_marks" checked> Καταχώριση βαθμών</label><button class="btn btn-primary">Προσθήκη ανάθεσης</button></form>
<details style="margin-top:20px"><summary>Ρυθμίσεις μαθήματος και μαθητών</summary>
<form method="post" class="marks-inline-form"><?php $assignmentBase($course); ?><input type="hidden" name="action" value="course_settings"><label><input type="checkbox" name="has_written_exam" <?= $course['has_written_exam']?'checked':'' ?>> Γραπτή εξέταση</label><label><input type="checkbox" name="is_active" <?= $course['is_active']?'checked':'' ?>> Ενεργό μάθημα</label><button class="btn btn-secondary">Αποθήκευση</button></form>
<p>Εκκρεμούν <?= count($diff['add']) ?> προσθήκες και <?= count($diff['remove']) ?> αποχωρήσεις μαθητών.</p><form method="post"><?php $assignmentBase($course); ?><button class="btn btn-secondary" name="action" value="sync">Εφαρμογή αλλαγών λίστας μαθητών</button></form></details>
<p><a href="<?= $h($url(['view'=>'history','course_id'=>$courseId])) ?>">Ιστορικό αλλαγών μαθήματος</a></p></section>
<?php endif; ?>
