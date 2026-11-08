<?php
// Shared read-only grading filters over the caller's authorized course list.
if(!isset($db,$yearId,$courses,$periods,$h,$url)) { http_response_code(403); exit; }
require_once __DIR__.'/marks_overview_data.php';
$marksFilterView=$view??'overview';
$overviewVisibleIds=implode(',',array_map('intval',array_column($courses,'id'))) ?: '0';
$overviewTeachers=[]; $overviewTeacherNames=[];
$q=$db->prepare("SELECT ct.course_offering_id,ct.teacher_id,u.name FROM course_teachers ct JOIN course_offerings c ON c.id=ct.course_offering_id JOIN users u ON u.id=ct.teacher_id WHERE c.academic_year_id=? AND ct.is_active=1 AND c.id IN ($overviewVisibleIds) ORDER BY u.name,u.id");
$q->execute([$yearId]);
foreach($q->fetchAll(PDO::FETCH_ASSOC) as $teacher) {
    $overviewTeachers[$teacher['course_offering_id']][$teacher['teacher_id']]=$teacher['name'];
    $overviewTeacherNames[$teacher['teacher_id']]=$teacher['name'];
}
$completionStates=['all'=>'Όλες','pending'=>'Όλες οι εκκρεμότητες','incomplete'=>'Λείπουν βαθμοί','empty'=>'Χωρίς καμία καταχώριση','draft'=>'Πλήρεις χωρίς οριστική υποβολή','submitted'=>'Οριστικά υποβληθείσες'];
$completionState=$_GET['completion']??'all';
$completionInvalid=$marksFilterView==='submissions'&&(!is_string($completionState)||!isset($completionStates[$completionState]));
if($completionInvalid) $completionState='all';
$overviewError='';
try { $overviewFilters=marksOverviewFilters($_GET); }
catch(DomainException $e) { $overviewError=$e->getMessage(); $overviewFilters=marksOverviewFilters([]); }
if($completionInvalid) $overviewError='Μη έγκυρο φίλτρο πληρότητας.';
$overviewCourses=$overviewError?[]:marksOverviewCourses($courses,$overviewTeachers,$overviewFilters);
$overviewPeriods=marksOverviewPeriods($periods,$overviewFilters['period']);
$overviewGrades=[]; $overviewSections=[]; $overviewSubjects=[]; $overviewCatalog=[];
foreach($courses as $item) {
    $overviewGrades[$item['grade_id']]=$item['grade_name'];
    $overviewSections[$item['section_id']]=['name'=>$item['section_name'],'grade_id'=>$item['grade_id']];
    $overviewSubjects[$item['subject_id']]=$item['subject_name'];
    $overviewCatalog[]=['grade_id'=>(string)$item['grade_id'],'section_id'=>(string)$item['section_id'],'subject_id'=>(string)$item['subject_id'],'teachers'=>array_map('strval',array_keys($overviewTeachers[$item['id']]??[]))];
}
asort($overviewGrades,SORT_NATURAL); asort($overviewSubjects,SORT_NATURAL);
$overviewPeriodOptions=['term1'=>'Α΄ τετράμηνο','term2'=>'Β΄ τετράμηνο','written'=>'Γραπτά','terms'=>'Α΄ και Β΄ τετράμηνα','all'=>'Όλα'];
?>
<section class="card">
<form method="get" class="marks-filters" data-marks-overview>
<input type="hidden" name="view" value="<?= $h($marksFilterView) ?>">
<label>Τάξη<select name="grade_id" class="form-control"><option value="">Όλες οι τάξεις</option><?php foreach($overviewGrades as $id=>$name): ?><option value="<?= (int)$id ?>" <?= $overviewFilters['grade_id']==$id?'selected':'' ?>><?= $h($name) ?></option><?php endforeach; ?></select></label>
<label>Τμήμα<select name="section_id" class="form-control"><option value="">Όλα τα τμήματα</option><?php foreach($overviewSections as $id=>$item): ?><option value="<?= (int)$id ?>" <?= $overviewFilters['section_id']==$id?'selected':'' ?>><?= $h($item['name']) ?></option><?php endforeach; ?></select></label>
<?php if($marksFilterView!=='manage'): ?><label>Περίοδος<select name="period" class="form-control"><?php foreach($overviewPeriodOptions as $code=>$name): ?><option value="<?= $code ?>" <?= $overviewFilters['period']===$code?'selected':'' ?>><?= $h($name) ?></option><?php endforeach; ?></select></label><?php endif; ?>
<label>Μάθημα<select name="subject_id" class="form-control"><option value="">Όλα τα μαθήματα</option><?php foreach($overviewSubjects as $id=>$name): ?><option value="<?= (int)$id ?>" <?= $overviewFilters['subject_id']==$id?'selected':'' ?>><?= $h($name) ?></option><?php endforeach; ?></select></label>
<label>Καθηγητής<select name="teacher_id" class="form-control"><option value="">Όλοι οι καθηγητές</option><?php foreach($overviewTeacherNames as $id=>$name): ?><option value="<?= (int)$id ?>" <?= $overviewFilters['teacher_id']==$id?'selected':'' ?>><?= $h($name) ?></option><?php endforeach; ?></select></label>
<?php if($marksFilterView==='submissions'): ?><label>Πληρότητα<select name="completion" class="form-control"><?php foreach($completionStates as $key=>$label): ?><option value="<?= $key ?>" <?= $completionState===$key?'selected':'' ?>><?= $h($label) ?></option><?php endforeach; ?></select></label><?php endif; ?>
<button class="btn btn-secondary">Προβολή</button>
<a href="<?= $h($url(['view'=>$marksFilterView])) ?>" class="marks-help">Καθαρισμός φίλτρων</a>
</form>
<script type="application/json" id="marks-overview-catalog"><?= json_encode($overviewCatalog,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?></script>
</section>
<?php if($overviewError): ?><p class="marks-notice marks-error" role="alert"><?= $h($overviewError) ?></p><?php endif; ?>
