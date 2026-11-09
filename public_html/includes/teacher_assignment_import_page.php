<?php
// Unified MySchool import, reached from the admin-only teachers page.
require_once __DIR__.'/../config/config.php';
requireAdmin();
require_once __DIR__.'/marks_import.php';
$db=getDB(); $user=getCurrentUser(); $year=getActiveAcademicYear(); $yearId=(int)$year['id'];
$ready=marksReady($db); $error=''; $message=$_SESSION['teacher_import_flash']??''; unset($_SESSION['teacher_import_flash']);
$h=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');
if(isset($_SESSION['marks_import'])&&(!is_array($_SESSION['marks_import'])||time()-(int)($_SESSION['marks_import']['created']??0)>1800||($_SESSION['marks_import']['year_id']??null)!==$yearId)) unset($_SESSION['marks_import']);
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    try {
        if(!verifyCsrfToken($_POST['csrf_token']??'')) throw new DomainException('Μη έγκυρο αίτημα ασφαλείας. Ανανεώστε τη σελίδα.');
        if(schoolRequestId($_POST['year_id']??null)!==$yearId) throw new DomainException('Το σχολικό έτος άλλαξε. Ανανεώστε τη σελίδα.');
        if(!$ready) throw new DomainException('Προηγείται ενεργοποίηση της ενότητας βαθμολογίας.');
        $action=$_POST['action']??'';
        if($action==='preview') {
            unset($_SESSION['marks_import']);
            $file=$_FILES['assignments']??null;
            if(!is_array($file)||($file['error']??1)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']??'')) throw new DomainException('Δεν παραλήφθηκε έγκυρο αρχείο.');
            try { $preview=marksReadAssignments($file['tmp_name']); }
            catch(DomainException $e) { throw $e; }
            catch(Throwable $e) { throw new DomainException('Η αναφορά δεν διαβάζεται. Ελέγξτε ότι είναι έγκυρο αρχείο αναθέσεων MySchool.'); }
            finally { if(is_uploaded_file($file['tmp_name'])) unlink($file['tmp_name']); }
            if($preview['year_name']!==$year['name']) throw new DomainException('Η αναφορά αφορά διαφορετικό σχολικό έτος από το ενεργό.');
            $preview['created']=time(); $preview['year_id']=$yearId; $preview['token']=bin2hex(random_bytes(16));
            $_SESSION['marks_import']=$preview; $message='Ελέγξτε τις αντιστοιχίσεις και επιβεβαιώστε την εισαγωγή.';
        } elseif($action==='commit_import') {
            $preview=$_SESSION['marks_import']??null;
            if(!is_array($preview)||time()-(int)$preview['created']>1800||$preview['year_id']!==$yearId||!is_string($_POST['preview_token']??null)||!hash_equals($preview['token'],$_POST['preview_token'])) throw new DomainException('Η προεπισκόπηση έληξε. Ανεβάστε ξανά την αναφορά.');
            $teachers=$_POST['teacher_map']??[]; $sections=$_POST['section_map']??[];
            if(!is_array($teachers)||!is_array($sections)) throw new DomainException('Μη έγκυρες αντιστοιχίσεις.');
            $teacherMap=[]; $sectionMap=[];
            foreach($preview['rows'] as $row) { $teacherMap[$row['teacher_key']]=$teachers[hash('sha256',$row['teacher_key'])]??null; $sectionMap[$row['section']]=$sections[hash('sha256',$row['section'])]??null; }
            $policy=$_POST['assignment_policy']??null;
            if(!is_string($policy)||!in_array($policy,['preserve','replace'],true)) throw new DomainException('Επιλέξτε διατήρηση ή αντικατάσταση χειροκίνητων αναθέσεων.');
            $n=marksCommitImport($db,$yearId,$user,$preview,$teacherMap,$sectionMap,$policy); unset($_SESSION['marks_import']); $message='Επεξεργάστηκαν '.$n.' γραμμές αναθέσεων. '.($policy==='preserve'?'Διατηρήθηκαν οι χειροκίνητες αλλαγές.':'Οι αναθέσεις των μαθημάτων της αναφοράς αντικαταστάθηκαν από το MySchool.');

        } else throw new DomainException('Μη υποστηριζόμενη ενέργεια.');

        if($action==='commit_import') { $_SESSION['teacher_import_flash']=$message; header('Location: teachers.php?view=import',true,303); exit; }
    } catch(DomainException $e) { $error=$e->getMessage(); }
    catch(Throwable $e) { $error=safeError($e); }
}
$baseFields=static function() use($yearId) { echo csrfField().'<input type="hidden" name="year_id" value="'.$yearId.'">'; };
$pageTitle='Καθηγητές & Αναθέσεις · Εισαγωγή MySchool';
require __DIR__.'/../admin/header.php';
?>
<link rel="stylesheet" href="../assets/css/marks.css?v=5">
<p><a class="btn btn-secondary" href="teachers.php">← Καθηγητές &amp; Αναθέσεις</a></p>
<h1>Εισαγωγή καθηγητών και αναθέσεων</h1>
<p>Μία εισαγωγή για τα μαθήματα βαθμολογίας και τα τμήματα απουσιών, με κοινούς λογαριασμούς. Τα τμήματα προκύπτουν από τις ενεργές διδασκαλίες και εμφανίζονται μία φορά ανά καθηγητή. Οι πρόσθετες χειροκίνητες αναθέσεις τμημάτων διατηρούνται.</p>
<?php if($error): ?><p class="alert alert-danger" role="alert"><?= $h($error) ?></p><?php endif; ?>
<?php if($message): ?><p class="alert alert-success" role="status"><?= $h($message) ?></p><?php endif; ?>
<?php if(!$ready): ?><p class="alert alert-warning">Ενεργοποιήστε πρώτα την ενότητα βαθμολογίας για να εισαγάγετε τις κοινές αναθέσεις.</p><?php else: ?>

<section class="card"><h2>Αναθέσεις MySchool</h2><p>Ανεβάστε την αναφορά «Αναθέσεις εκπαιδευτικών μονάδας» σε XLS ή XLSX. Το ΑΦΜ αγνοείται και το αρχικό αρχείο δεν διατηρείται. Εισάγονται διδασκαλίες και ενημερώνεται αυτόματα η πρόσβαση στα αντίστοιχα τμήματα απουσιών. Δεν εισάγονται βαθμοί.</p>
<form method="post" enctype="multipart/form-data" class="marks-inline-form"><?php $baseFields(); ?><input type="hidden" name="action" value="preview"><label>Αναφορά αναθέσεων <input type="file" name="assignments" accept=".xls,.xlsx" required></label><button class="btn btn-primary">Προεπισκόπηση</button></form></section>
<?php $preview=$_SESSION['marks_import']??null;
if(is_array($preview)&&$preview['year_id']===$yearId&&time()-$preview['created']<=1800): $matches=marksImportMatches($db,$preview); ?>
<section class="card"><h2>Αντιστοιχίσεις</h2><p><?= count($preview['rows']) ?> γραμμές αναθέσεων. Επιβεβαιώστε τους λογαριασμούς και τα υπάρχοντα τμήματα. Για καθηγητές που λείπουν μπορείτε να δημιουργήσετε εκκρεμή λογαριασμό χωρίς κωδικό. Η πρόσβαση ενεργοποιείται χωριστά από τη διαχείριση καθηγητών, αφού οριστούν πραγματικά στοιχεία σύνδεσης.</p>
<form method="post"><?php $baseFields(); ?><input type="hidden" name="action" value="commit_import"><input type="hidden" name="preview_token" value="<?= $h($preview['token']) ?>"><fieldset class="marks-fieldset"><legend>Πώς θέλετε να αντιμετωπιστούν οι χειροκίνητες αλλαγές αναθέσεων;</legend><label><input type="radio" name="assignment_policy" value="preserve" checked required> Διατήρηση χειροκίνητων αλλαγών</label><br><label><input type="radio" name="assignment_policy" value="replace" required> Αντικατάσταση από το MySchool</label><p class="marks-help">Η αντικατάσταση αφορά μόνο τα μαθήματα της αναφοράς: ανακαλεί τις προηγούμενες αναθέσεις τους και εφαρμόζει τις νέες. Η διατήρηση προστατεύει ολόκληρη τη σύνθεση καθηγητών των μαθημάτων που άλλαξαν χειροκίνητα. Βαθμοί και οριστικές υποβολές δεν αλλάζουν από την επιλογή αυτή.</p></fieldset>
<div class="marks-table-wrap"><table class="marks-table marks-mapping"><caption>Καθηγητές</caption><thead><tr><th>Όνομα στην αναφορά</th><th>Υπάρχων λογαριασμός</th></tr></thead><tbody>
<?php foreach($matches['teachers'] as $key=>$match): ?><tr><td><?= $h($match['name']) ?></td><td><select class="form-control" required name="teacher_map[<?= hash('sha256',$key) ?>]" aria-label="<?= $h('Λογαριασμός '.$match['name']) ?>"><option value="">Επιλέξτε λογαριασμό</option><option value="new" <?= $match['id']==='new'?'selected':'' ?>>Δημιουργία εκκρεμούς λογαριασμού από την αναφορά</option><?php foreach($matches['users'] as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (string)$match['id']===(string)$u['id']?'selected':'' ?>><?= $h($u['name'].' · '.$u['email'].($u['status']==='inactive'?' · Ανενεργός':'')) ?></option><?php endforeach; ?></select></td></tr><?php endforeach; ?></tbody></table></div>
<div class="marks-table-wrap"><table class="marks-table marks-mapping"><caption>Τμήματα</caption><thead><tr><th>Τμήμα στην αναφορά</th><th>Υπάρχον τμήμα</th></tr></thead><tbody>
<?php foreach($matches['section_matches'] as $name=>$id): ?><tr><td><?= $h($name) ?></td><td><select class="form-control" required name="section_map[<?= hash('sha256',$name) ?>]" aria-label="<?= $h('Αντιστοίχιση τμήματος '.$name) ?>"><option value="">Επιλέξτε τμήμα</option><?php foreach($matches['sections'] as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $id==$s['id']?'selected':'' ?>><?= $h($s['name']) ?></option><?php endforeach; ?></select></td></tr><?php endforeach; ?></tbody></table></div>
<details><summary>Γραμμές της αναφοράς</summary><div class="marks-table-wrap"><table class="marks-table"><thead><tr><th>Καθηγητής</th><th>Τμήμα</th><th>Μάθημα</th><th>Ώρες</th><th>Ανάθεση</th></tr></thead><tbody><?php foreach($preview['rows'] as $r): ?><tr><?php foreach(['teacher_name','section','subject','hours','assignment'] as $key): ?><td><?= $h($r[$key]) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div></details>
<p class="marks-help">Οι υπάρχοντες βαθμοί διατηρούνται. Αναθέσεις που λείπουν από την αναφορά δεν απενεργοποιούνται αυτόματα. Οι αναθέσεις εφαρμόζονται σύμφωνα με την παραπάνω επιλογή. Αν αλλάζει λίστα μαθητών με οριστική υποβολή, απαιτείται πρώτα επανάνοιγμα.</p><p class="marks-help">Η πρόσβαση στις απουσίες ακολουθεί τις ενεργές διδασκαλίες μετά την παραπάνω επιλογή. Τμήματα που έχουν δοθεί χειροκίνητα διατηρούνται ανεξάρτητα.</p><button class="btn btn-primary">Επιβεβαίωση κοινής εισαγωγής</button></form></section><?php endif; ?>

<?php endif; require __DIR__.'/../admin/footer.php'; ?>
