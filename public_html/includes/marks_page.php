<?php
// SPDX-License-Identifier: GPL-3.0-only
// Shared grading UI, reached only through the authenticated admin/teacher wrappers.
require_once __DIR__.'/marks_import.php';
$db=getDB(); $user=getCurrentUser(); $admin=$user['role']==='admin';
$year=getActiveAcademicYear(); $yearId=(int)$year['id'];
if(isset($_SESSION['marks_import'])&&(!is_array($_SESSION['marks_import'])||time()-(int)($_SESSION['marks_import']['created']??0)>1800||($_SESSION['marks_import']['year_id']??null)!==$yearId)) unset($_SESSION['marks_import']);
$view=is_string($_GET['view']??null)?$_GET['view']:($admin?'overview':'entry');
if($admin&&$view==='import'&&($_SERVER['REQUEST_METHOD']??'GET')==='GET') { header('Location: '.BASE_URL.'/admin/teachers.php?view=import',true,302); exit; }
$allowed=$admin?['courses','entry','submissions','overview','manage','settings','history']:['courses','entry','submissions'];
if(!in_array($view,$allowed,true)) { http_response_code(403); exit('Δεν επιτρέπεται η πρόσβαση σε αυτή τη σελίδα.'); }
$error=''; $message=$_SESSION['marks_flash']??''; unset($_SESSION['marks_flash']);
$ready=marksReady($db);
$h=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');
$displayGrade = static function ($value): string {
    if ($value === null) return '';
    [$whole, $fraction] = array_pad(explode('.', (string)$value, 2), 2, '');
    $fraction = rtrim($fraction, '0');
    return str_pad($whole, 2, '0', STR_PAD_LEFT).($fraction === '' ? '' : ','.$fraction);
};
$specialMarkLabels = [
    'not_submitted' => 'Δ · Δεν κατατέθηκε βαθμός',
    'no_show' => '-1 · Δεν προσήλθε',
    'absent' => 'Απουσία εξέτασης',
    'exempt' => 'Απαλλαγή',
];
$url=static fn(array $params)=>'marks.php?'.http_build_query($params);
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    try {
        if(!$admin) throw new DomainException('Η ενέργεια επιτρέπεται μόνο σε διαχειριστή.');
        if(!verifyCsrfToken($_POST['csrf_token']??'')) throw new DomainException('Μη έγκυρο αίτημα ασφαλείας. Ανανεώστε τη σελίδα.');
        if(schoolRequestId($_POST['year_id']??null)!==$yearId) throw new DomainException('Το σχολικό έτος άλλαξε. Ανανεώστε τη σελίδα.');
        $action=$_POST['action']??'';
        if($action==='install') {
            marksInstall($db); marksSeedPeriods($db,$yearId); $message='Η ενότητα Βαθμολογία είναι έτοιμη.';
        } elseif(!$ready) throw new DomainException('Χρειάζεται πρώτα ενεργοποίηση της ενότητας.');
        elseif($action==='initialize') {
            marksSeedPeriods($db,$yearId); $message='Δημιουργήθηκαν οι τρεις περίοδοι για το ενεργό έτος.';
        } elseif($action==='settings') {
            if(!is_array($_POST['periods']??null)) throw new DomainException('Μη έγκυρες ρυθμίσεις.');
            marksSaveSettings($db,$yearId,$_POST['periods']); $message='Οι ρυθμίσεις αποθηκεύτηκαν χωρίς μεταβολή των υπαρχόντων βαθμών.';
        } elseif($action==='reopen') {
            $course=schoolRequestId($_POST['course_id']??null); $period=schoolRequestId($_POST['period_id']??null);
            if(!$course||!$period||!is_string($_POST['reason']??null)||!ctype_digit((string)($_POST['version']??''))) throw new DomainException('Μη έγκυρο αίτημα.');
            marksReopen($db,$yearId,$course,$period,$user,$_POST['reason'],(int)$_POST['version']); $message='Η υποβολή άνοιξε ξανά. Η αιτιολογία καταγράφηκε.';
        } elseif($action==='bulk_preview') {
            // Read-only preview is rendered below after the same CSRF/year/admin checks.
        } elseif($action==='bulk_replace') {
            $bulk=$_SESSION['marks_bulk']??null;
            if(!is_array($bulk)||$bulk['year_id']!==$yearId||time()-$bulk['created']>600||!is_string($_POST['bulk_token']??null)||!hash_equals($bulk['token'],$_POST['bulk_token'])) throw new DomainException('Η προεπισκόπηση έληξε. Δημιουργήστε νέα.');
            $selected=$_POST['bulk_selected']??[];
            if(!is_array($selected)||!$selected) throw new DomainException('Επιλέξτε διδασκαλίες προς αντικατάσταση.');
            $input=$bulk['input']; $input['course_versions']=[];
            foreach($selected as $id) {
                $id=schoolRequestId($id);
                if(!$id||!isset($bulk['input']['course_versions'][$id])) throw new DomainException('Μη έγκυρη επιλογή διδασκαλίας.');
                $input['course_versions'][$id]=$bulk['input']['course_versions'][$id];
            }
            marksBulkReplace($db,$yearId,$user,$input); unset($_SESSION['marks_bulk']);
            $message='Η αντικατάσταση καθηγητή ολοκληρώθηκε στις επιλεγμένες διδασκαλίες.';
        } elseif(in_array($action,['sync','course_settings','teacher_access','assignment_add','assignment_replace'],true)) {
            $course=schoolRequestId($_POST['course_id']??null); if(!$course) throw new DomainException('Μη έγκυρο μάθημα.');
            marksManageCourse($db,$yearId,$course,$user,$_POST); $message='Η αλλαγή αποθηκεύτηκε.';
        } else throw new DomainException('Μη υποστηριζόμενη ενέργεια.');
        if($action!=='bulk_preview') { $_SESSION['marks_flash']=$message; header('Location: '.$url($_GET),true,303); exit; }
    } catch(DomainException $e) { $error=$e->getMessage(); }
    catch(Throwable $e) { $error=safeError($e); }
    $ready=marksReady($db);
}
$periods=$ready?marksPeriods($db,$yearId):[];
$courses=$ready?marksCourses($db,$yearId,$user):[];
$courseId=schoolOptionalRequestId($_GET['course_id']??null);
$periodId=schoolOptionalRequestId($_GET['period_id']??null);
$previousMarks = [];
$course=null; $period=null; $roster=[]; $submission=null; $diff=null;
if($ready&&in_array($view,['entry','manage','history'],true)&&$courseId) {
    try {
        $db->beginTransaction();
        $course=marksCourse($db,$yearId,$courseId,$user);
        if($view==='entry') {
            $periodId=$periodId?:((int)($periods[0]['id']??0)); $period=marksPeriod($db,$yearId,$periodId);
            $roster=marksRoster($db,$courseId,$periodId); $submission=marksSubmission($db,$courseId,$periodId);
            $previousCodes = [
                'term2' => ['term1' => 'Α΄'],
                'written' => ['term1' => 'Α΄', 'term2' => 'Β΄'],
            ][$period['code']] ?? [];
            foreach ($periods as $previousPeriod) {
                $label = $previousCodes[$previousPeriod['code']] ?? null;
                if ($label === null) continue;
                foreach (marksRoster($db, $courseId, (int)$previousPeriod['id']) as $previousRow) {
                    $status = $previousRow['mark_status'];
                    $previousMarks[$previousRow['course_student_id']][$label] = $status === 'value'
                        ? $displayGrade($previousRow['mark_value'])
                        : (['not_submitted' => 'Δ', 'no_show' => '-1'][$status] ?? $specialMarkLabels[$status] ?? '—');
                }
            }
        }
        if($admin) $diff=marksRosterDiff($db,$course);
        $db->commit();
    } catch(DomainException $e) { if($db->inTransaction()) $db->rollBack(); $error=$e->getMessage(); $course=null; }
    catch(Throwable $e) { if($db->inTransaction()) $db->rollBack(); throw $e; }
}
$baseFields=static function() use($yearId) { echo csrfField().'<input type="hidden" name="year_id" value="'.$yearId.'">'; };
if (!empty($marksAdminLayout)) {
    $pageTitle = 'Βαθμολογία';
    require __DIR__.'/../admin/header.php';
} else {
    $schoolName = getSchoolSetting('school_name', 'Σχολείο');
    $activeAcademicYear = $year;
    $marksNavigationActive = true;
?>
<!doctype html><html lang="el"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Βαθμολογία · <?= $h($schoolName) ?></title>
<link rel="stylesheet" href="../assets/css/style.css?v=daylight-20261029-1">
<link rel="stylesheet" href="../assets/css/marks.css?v=5">
<script src="../assets/js/theme.js?v=20261029-1"></script>
</head><body>
<?php require __DIR__.'/../teacher/navbar.php'; } ?>
<script src="../assets/js/marks.js?v=5" defer></script>
<button class="btn btn-secondary btn-sm marks-mobile-toggle" type="button" data-marks-menu aria-controls="marks-navigation" aria-expanded="false">☰ Μενού βαθμολογίας</button>
<div class="marks-layout"><nav class="marks-sidebar" id="marks-navigation" aria-label="Πλοήγηση βαθμολογίας"><h2>Βαθμολογία</h2>
<?php
$nav = $admin ? ['overview' => 'Επισκόπηση'] : [];
$nav += ['entry' => 'Καταχώριση βαθμών', 'courses' => $admin ? 'Μαθήματα' : 'Τα μαθήματά μου',
    'submissions' => $admin ? 'Πληρότητα υποβολών' : 'Οι υποβολές μου'];
if($admin) $nav+=['manage'=>'Μαθήματα και αναθέσεις','settings'=>'Ρυθμίσεις βαθμολογίας'];
foreach($nav as $key=>$label): ?><a href="<?= $h($url(['view'=>$key])) ?>" <?= $view===$key?'class="active" aria-current="page"':'' ?>><?= $h($label) ?></a><?php endforeach; ?>
</nav>
<main class="marks-main"><h1><?= $h($nav[$view]??'Ιστορικό αλλαγών') ?></h1><p class="marks-muted">Βαθμολογία · <?= $h($year['name']) ?></p>
<?php if($error): ?><div class="marks-notice marks-error" role="alert"><?= $h($error) ?></div><?php endif; ?>
<?php if($message): ?><div class="marks-notice marks-success" role="status"><?= $h($message) ?></div><?php endif; ?>
<?php if(isset($_GET['saved'])): ?><div class="marks-notice marks-success" role="status">Η βαθμολογία αποθηκεύτηκε.</div><?php endif; ?>
<?php if(!$ready): ?><section class="card"><h2>Ενεργοποίηση βαθμολογίας</h2><p>Η ενότητα χρειάζεται τους νέους πίνακες βάσης. Τα δεδομένα του παρουσιολογίου διατηρούνται.</p>
<?php if($admin): ?><form method="post"><?php $baseFields(); ?><button class="btn btn-primary" name="action" value="install">Ενεργοποίηση ενότητας</button></form><?php else: ?><p>Επικοινωνήστε με τον διαχειριστή.</p><?php endif; ?></section>
<?php elseif(!$periods): ?><section class="card"><p>Δεν έχουν οριστεί περίοδοι βαθμολογίας για αυτό το σχολικό έτος.</p><?php if($admin): ?><form method="post"><?php $baseFields(); ?><button class="btn btn-primary" name="action" value="initialize">Δημιουργία περιόδων με προεπιλογές</button></form><?php endif; ?></section>
<?php elseif($view==='settings'): ?>
<section class="card"><p>Όρια και βήμα ισχύουν στην μπάρα και στην αριθμητική εισαγωγή. Το δεκαδικό κόμμα υποστηρίζεται. Αλλαγές που δεν καλύπτουν υπάρχοντες βαθμούς απορρίπτονται.</p>
<form method="post"><?php $baseFields(); ?><input type="hidden" name="action" value="settings"><div class="marks-table-wrap"><table class="marks-table"><thead><tr><th>Περίοδος</th><th>Ελάχιστος</th><th>Μέγιστος</th><th>Βήμα</th><th>Ανοικτή καταχώριση</th></tr></thead><tbody>
<?php foreach($periods as $p): ?><tr><th scope="row"><?= $h($p['name']) ?><input type="hidden" name="periods[<?= (int)$p['id'] ?>][version]" value="<?= (int)$p['version'] ?>"></th>
<?php foreach(['min_mark'=>'Ελάχιστος','max_mark'=>'Μέγιστος','mark_step'=>'Βήμα'] as $key=>$label): ?><td><input class="form-control marks-settings-input" type="text" inputmode="decimal" required aria-label="<?= $h($p['name'].' '.$label) ?>" name="periods[<?= (int)$p['id'] ?>][<?= $key ?>]" value="<?= $h(str_replace('.',',',$p[$key])) ?>"></td><?php endforeach; ?>
<td><input type="checkbox" aria-label="<?= $h($p['name'].' ανοικτή καταχώριση') ?>" name="periods[<?= (int)$p['id'] ?>][is_open]" value="1" <?= $p['is_open']?'checked':'' ?>></td></tr><?php endforeach; ?></tbody></table></div><p><button class="btn btn-primary">Αποθήκευση ρυθμίσεων</button></p></form></section>
<?php elseif($view==='manage'&&$admin): ?>
<?php require __DIR__.'/marks_manage.php'; ?>
<?php elseif(in_array($view,['entry','history'],true)): ?>
<?php
$filterGrades=[]; $filterSections=[]; $selectedGrade=''; $selectedSection='';
foreach($courses as $c) {
    $filterGrades[$c['grade_id']]=$c['grade_name'];
    $filterSections[$c['section_id']]=['name'=>$c['section_name'],'grade_id'=>$c['grade_id']];
    if($courseId==$c['id']) { $selectedGrade=(string)$c['grade_id']; $selectedSection=(string)$c['section_id']; }
}
asort($filterGrades,SORT_NATURAL);
?>
<section class="card"><form method="get" class="marks-filters"><input type="hidden" name="view" value="<?= $h($view) ?>">
<?php if($view==='entry'): ?>
<label>Τάξη<select class="form-control" data-marks-grade><option value="">Επιλέξτε τάξη</option><?php foreach($filterGrades as $id=>$name): ?><option value="<?= (int)$id ?>" <?= $selectedGrade===(string)$id?'selected':'' ?>><?= $h($name) ?></option><?php endforeach; ?></select></label>
<label>Τμήμα<select class="form-control" data-marks-section><option value="">Επιλέξτε τμήμα</option><?php foreach($filterSections as $id=>$section): ?><option value="<?= (int)$id ?>" data-grade="<?= (int)$section['grade_id'] ?>" <?= $selectedSection===(string)$id?'selected':'' ?>><?= $h($section['name']) ?></option><?php endforeach; ?></select></label>
<?php endif; ?>
<label><?= $view==='entry'?'Μάθημα':'Μάθημα και τμήμα' ?><select class="form-control" name="course_id" data-marks-filter <?= $view==='entry'?'data-marks-course required':'' ?> data-original="<?= (int)$courseId ?>"><option value=""><?= $view==='entry'?'Επιλέξτε μάθημα':'Επιλέξτε διδασκαλία' ?></option><?php foreach($courses as $c): ?><option value="<?= (int)$c['id'] ?>" data-section="<?= (int)$c['section_id'] ?>" <?= $courseId==$c['id']?'selected':'' ?>><?= $h(($view==='entry'?'':$c['section_name'].' · ').$c['subject_name'].(!$c['is_active']?' · Ανενεργό':'')) ?></option><?php endforeach; ?></select></label>
<?php if($view==='entry'): ?><label>Περίοδος<select class="form-control" name="period_id" data-marks-filter data-original="<?= (int)$periodId ?>"><?php foreach($periods as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $periodId==$p['id']?'selected':'' ?>><?= $h($p['name']) ?></option><?php endforeach; ?></select></label><?php endif; ?><button class="btn btn-secondary">Προβολή</button></form></section>
<?php if($course&&$view==='entry'&&$period): [$min,$max,$step]=marksScale($period); $locked=$submission['status']==='submitted'||!$period['is_open']||!$course['is_active']||($period['code']==='written'&&!$course['has_written_exam']); ?>
<section class="card">
<div class="marks-entry-heading">
    <h2><?= $h($course['section_name'].' · '.$course['subject_name']) ?></h2>
    <span class="marks-entry-period"><?= $h($period['name']) ?></span>
</div>
<p><span class="marks-pill <?= $h($submission['status']) ?>"><?= $submission['status']==='submitted'?'Οριστική υποβολή':'Πρόχειρη καταχώριση' ?></span> · Όρια <?= $h(str_replace('.',',',$period['min_mark'])) ?>–<?= $h(str_replace('.',',',$period['max_mark'])) ?> · Βήμα <?= $h(str_replace('.',',',$period['mark_step'])) ?></p>
<?php if($locked): ?><p class="marks-notice">Η καταχώριση είναι κλειδωμένη<?= $period['code']==='written'&&!$course['has_written_exam']?' επειδή το μάθημα δεν έχει γραπτή εξέταση':'' ?>.</p><?php endif; ?>
<?php if($admin&&$diff&&($diff['add']||$diff['remove'])): ?><p class="marks-notice">Υπάρχουν αλλαγές στα τμήματα: <?= count($diff['add']) ?> προσθήκες και <?= count($diff['remove']) ?> αποχωρήσεις. Ελέγξτε τη <a href="<?= $h($url(['view'=>'manage','course_id'=>$courseId])) ?>">διαχείριση μαθήματος</a>.</p><?php endif; ?>
<form id="marks-entry" action="../api/save_marks.php" method="post" data-min="<?= $min ?>" data-max="<?= $max ?>" data-step="<?= $step ?>">
<?= csrfField() ?><input type="hidden" name="year_id" value="<?= $yearId ?>"><input type="hidden" name="course_id" value="<?= $courseId ?>"><input type="hidden" name="period_id" value="<?= $periodId ?>"><input type="hidden" name="course_version" value="<?= (int)$course['version'] ?>"><input type="hidden" name="period_version" value="<?= (int)$period['version'] ?>"><input type="hidden" name="version" value="<?= (int)$submission['version'] ?>">
<fieldset class="marks-entry-fields" <?= $locked?'disabled':'' ?> style="border:0;padding:0;margin:0"><legend class="marks-help">Πληκτρολογήστε βαθμό ή μετακινήστε την μπάρα. Το κενό δεν ισοδυναμεί με μηδέν.</legend><div class="marks-table-wrap"><table class="marks-table"><thead><tr><th>Μαθητής</th><th>Κατάσταση</th><th>Βαθμός</th><th>Μπάρα εισαγωγής</th><th></th></tr></thead><tbody>
<?php foreach($roster as $r): $name=$r['last_name'].' '.$r['first_name']; $value=$displayGrade($r['mark_value']); ?>
<tr data-mark-row data-id="<?= (int)$r['course_student_id'] ?>" data-active="<?= (int)$r['is_active'] ?>" class="<?= $r['is_active']?'':'marks-row-inactive' ?>"><td><strong><?= $h($name) ?></strong><br><small class="marks-muted">ΑΜ <?= $h($r['am']) ?><?= $r['is_active']?'':' · Παλαιά συμμετοχή' ?></small>
<?php foreach ($previousCodes as $label): ?>
    <small class="marks-prior-grade"><?= $h($label.' τετρ. '.($previousMarks[$r['course_student_id']][$label] ?? '—')) ?></small>
<?php endforeach; ?>
</td><td><select class="form-control" data-status aria-label="<?= $h('Κατάσταση '.$name) ?>" <?= !$r['is_active']?'disabled':'' ?>><?php $statusOptions = ['ungraded'=>'Χωρίς καταχώριση','value'=>'Αριθμητικός βαθμός'];
$periodStatus = marksPeriodStatus($period['code']);
if ($periodStatus !== null) $statusOptions[$periodStatus] = $specialMarkLabels[$periodStatus];
if ($r['mark_status'] === 'absent') $statusOptions['absent'] = $specialMarkLabels['absent'];
$statusOptions['exempt'] = $specialMarkLabels['exempt'];
foreach($statusOptions as $key=>$label): ?><option value="<?= $key ?>" <?= $r['mark_status']===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></td>
<td><input type="text" class="form-control" inputmode="decimal" data-number value="<?= $h($value) ?>" aria-label="<?= $h('Βαθμός '.$name) ?>" autocomplete="off" <?= !$r['is_active']?'disabled':'' ?>></td><td class="marks-range"><input type="range" data-range min="<?= $min ?>" max="<?= $max ?>" step="<?= $step ?>" value="<?= $r['mark_value']===null?$min:marksDecimal($r['mark_value']) ?>" aria-label="<?= $h('Μπάρα βαθμού '.$name) ?>" <?= !$r['is_active']?'disabled':'' ?>></td><td><button type="button" class="marks-clear" title="Καθαρισμός βαθμού" data-clear aria-label="<?= $h('Καθαρισμός βαθμού '.$name) ?>" <?= !$r['is_active']?'disabled':'' ?>><span aria-hidden="true">✕</span></button></td></tr><?php endforeach; ?>
<?php if(!$roster): ?><tr><td colspan="5">Δεν υπάρχουν μαθητές. Ο διαχειριστής μπορεί να συγχρονίσει τη λίστα από τα υπάρχοντα τμήματα.</td></tr><?php endif; ?></tbody></table></div>
<?php if(!$locked): ?><div class="marks-actions"><span id="marks-progress" class="marks-progress" role="status"></span><div><button class="btn btn-secondary" type="submit" data-save-action="save">Αποθήκευση</button><button class="btn btn-primary" type="submit" data-save-action="submit">Οριστική υποβολή</button></div></div><?php else: ?><span id="marks-progress" class="marks-progress"></span><?php endif; ?></fieldset>
<div id="marks-message" class="marks-notice" role="status" tabindex="-1" hidden></div></form><noscript><p>Απαιτείται JavaScript για τη συγχρονισμένη καταχώριση βαθμών.</p></noscript></section>
<?php if($admin&&$submission['status']==='submitted'): ?><section class="card"><h2>Επανάνοιγμα υποβολής</h2><form method="post" class="marks-inline-form"><?php $baseFields(); ?><input type="hidden" name="action" value="reopen"><input type="hidden" name="course_id" value="<?= $courseId ?>"><input type="hidden" name="period_id" value="<?= $periodId ?>"><input type="hidden" name="version" value="<?= (int)$submission['version'] ?>"><label>Αιτιολογία <input class="form-control" name="reason" required maxlength="255"></label><button class="btn btn-secondary">Επανάνοιγμα</button></form></section><?php endif; ?>
<?php elseif($course&&$view==='history'&&$admin): $q=$db->prepare('SELECT a.*,u.name FROM mark_audit_log a JOIN users u ON u.id=a.changed_by WHERE course_offering_id=? ORDER BY a.id DESC LIMIT 200'); $q->execute([$courseId]); ?>
<?php
$historyLabels = ['assignment_manual'=>'Χειροκίνητη αλλαγή ανάθεσης','assignment_reset'=>'Αντικατάσταση αναθέσεων από MySchool','import' => 'Εισαγωγή διδασκαλίας', 'assignment_import' => 'Εισαγωγή ανάθεσης'];
$historyValue = static function ($raw, $action) use ($h, $specialMarkLabels) {
    $data = json_decode((string)$raw, true);
    if ($data === null && ($raw === null || $raw === '' || $raw === 'null')) {
        return '—';
    }

    $description = '';
    if ($action === 'import' && isset($data['roster_added'], $data['roster_removed'])) {
        $description = 'Προσθήκες μαθητών: '.(int)$data['roster_added'].' · Αφαιρέσεις: '.(int)$data['roster_removed'];
    } elseif ($action === 'assignment_import' && isset($data['teacher_id'], $data['assignment_type'], $data['is_active'], $data['can_edit_marks'])) {
        $description = $data['assignment_type'].' · Κωδικός εκπαιδευτικού '.(int)$data['teacher_id']
            .' · '.($data['is_active'] ? 'Ενεργή' : 'Ανενεργή')
            .' · Καταχώριση βαθμών: '.($data['can_edit_marks'] ? 'Ναι' : 'Όχι');
    }

    if ($action === 'mark' && isset($data[0])) {
        $description = $specialMarkLabels[$data[0]] ?? '';
    }

    if ($description === '') {
        return $h($raw);
    }
    $details = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return $h($description).'<details><summary>Λεπτομέρειες</summary><code>'.$h($details).'</code></details>';
};
?>
<section class="card">
    <h2><?= $h($course['section_name'].' · '.$course['subject_name']) ?></h2>
    <p class="marks-help">Οι 200 πιο πρόσφατες ενέργειες.</p>
    <div class="marks-table-wrap">
        <table class="marks-table">
            <thead><tr><th>Χρόνος</th><th>Χρήστης</th><th>Ενέργεια / βαθμός</th><th>Πριν</th><th>Μετά</th><th>Αιτιολογία</th></tr></thead>
            <tbody>
            <?php foreach($q->fetchAll(PDO::FETCH_ASSOC) as $a): ?>
                <tr>
                    <td><?= $h($a['changed_at']) ?></td>
                    <td><?= $h($a['name']) ?></td>
                    <td><?= $h($historyLabels[$a['action']] ?? $a['action']) ?><?= isset($a['mark_id']) ? ' #'.$h($a['mark_id']) : '' ?></td>
                    <td><?= $historyValue($a['old_value'], $a['action']) ?></td>
                    <td><?= $historyValue($a['new_value'], $a['action']) ?></td>
                    <td><?= $h($a['reason']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php else: ?><p class="marks-notice">Επιλέξτε μάθημα και τμήμα για να συνεχίσετε.</p><?php endif; ?>
<?php elseif($view==='overview'&&$admin): ?>
<?php require __DIR__.'/marks_overview.php'; ?>
<?php else: ?>
<?php
if($view==='submissions') {
    require __DIR__.'/marks_filters.php';
    $courses=$overviewCourses; $periods=$overviewPeriods;
}
$stats=[]; $courseIds=array_fill_keys(array_map('intval',array_column($courses,'id')),true);
$visibleIds=$courseIds?implode(',',array_keys($courseIds)):'0';
$q=$db->prepare("SELECT cs.course_offering_id,m.assessment_period_id,COUNT(*) AS complete FROM student_marks m JOIN course_students cs ON cs.id=m.course_student_id JOIN course_offerings c ON c.id=cs.course_offering_id WHERE c.academic_year_id=? AND c.id IN ($visibleIds) AND cs.is_active=1 AND m.mark_status<>'ungraded' GROUP BY cs.course_offering_id,m.assessment_period_id"); $q->execute([$yearId]); foreach($q->fetchAll(PDO::FETCH_ASSOC) as $s) $stats[$s['course_offering_id']][$s['assessment_period_id']]['complete']=(int)$s['complete'];
$q=$db->prepare("SELECT ms.* FROM mark_submissions ms JOIN course_offerings c ON c.id=ms.course_offering_id WHERE c.academic_year_id=? AND c.id IN ($visibleIds)"); $q->execute([$yearId]); foreach($q->fetchAll(PDO::FETCH_ASSOC) as $s) $stats[$s['course_offering_id']][$s['assessment_period_id']]['status']=$s['status']; ?>
<?php if($view==='submissions') require __DIR__.'/marks_completion_summary.php'; ?>
<section class="card"><p><?= $admin?'Μαθήματα και πορεία καταχώρισης για το ενεργό σχολικό έτος.':'Εμφανίζονται μόνο τα μαθήματα και τα τμήματα που σας έχουν ανατεθεί.' ?></p>
<div class="marks-table-wrap"><table class="marks-table"><thead><tr><th>Τμήμα / μάθημα</th><?php if($view==='submissions'): ?><th>Καθηγητής</th><?php endif; ?><th>Μαθητές</th><?php foreach($periods as $p): ?><th><?= $h($p['name']) ?></th><?php endforeach; ?><?php if($admin): ?><th>Διαχείριση</th><?php endif; ?></tr></thead><tbody>
<?php foreach($courses as $c): ?><tr><td><strong><?= $h($c['section_name']) ?></strong><br><?= $h($c['subject_name']) ?><?= !$c['is_active']?'<br><span class="marks-pill">Ανενεργό</span>':'' ?></td><?php if($view==='submissions'): ?><td><?= $h(implode(', ',$overviewTeachers[$c['id']]??[]) ?: 'Χωρίς καθηγητή') ?></td><?php endif; ?><td><?= (int)$c['student_count'] ?></td><?php foreach($periods as $p): $s=$stats[$c['id']][$p['id']]??[]; ?><td><?php if($p['code']==='written'&&!$c['has_written_exam']): ?>Δεν εξετάζεται<?php else: ?><a class="marks-course-link" href="<?= $h($url(['view'=>'entry','course_id'=>$c['id'],'period_id'=>$p['id']])) ?>"><?= (int)($s['complete']??0) ?>/<?= (int)$c['student_count'] ?></a><br><span class="marks-pill <?= ($s['status']??'draft')==='submitted'?'submitted':'draft' ?>"><?= ($s['status']??'draft')==='submitted'?'Υποβλήθηκε':'Πρόχειρο' ?></span><?php if($view==='submissions'): $completion=marksCompletion($c,$p,$s); ?><br><small class="marks-help"><?= $completion['incomplete']?'Λείπουν '.$completion['missing'].' βαθμοί':($completion['draft']?'Πλήρης · εκκρεμεί υποβολή':'') ?></small><?php endif; ?><?php endif; ?></td><?php endforeach; ?><?php if($admin): ?><td><a href="<?= $h($url(['view'=>'manage','course_id'=>$c['id']])) ?>">Αναθέσεις / μαθητές</a></td><?php endif; ?></tr><?php endforeach; ?>
<?php if(!$courses): ?><tr><td colspan="<?= 2+count($periods)+($admin?1:0)+($view==='submissions'?1:0) ?>"><?= $view==='submissions'?'Δεν βρέθηκαν διδασκαλίες για τα επιλεγμένα φίλτρα.':($admin?'Δεν υπάρχουν διδασκαλίες. Ξεκινήστε από την εισαγωγή αναθέσεων MySchool.':'Δεν έχουν καταχωριστεί αναθέσεις μαθημάτων στον λογαριασμό σας. Επικοινωνήστε με τον διαχειριστή.') ?></td></tr><?php endif; ?></tbody></table></div></section>
<?php endif; ?>
<footer class="marks-help" style="padding:20px 0">Absences · Απουσίες και βαθμολογία · <?= $h($year['name']) ?></footer></main></div></body></html>
