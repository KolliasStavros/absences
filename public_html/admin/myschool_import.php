<?php
require_once __DIR__.'/../config/config.php';
requireAdmin();
require_once __DIR__.'/../includes/myschool_import.php';
$db=getDB();$user=getCurrentUser();$year=getActiveAcademicYear();$yearId=(int)$year['id'];
$ready=marksReady($db)&&myschoolRegistryReady($db);$error='';$message=$_SESSION['myschool_flash']??'';unset($_SESSION['myschool_flash']);
$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
if(isset($_SESSION['myschool_preview'])&&(!is_array($_SESSION['myschool_preview'])||time()-($_SESSION['myschool_preview']['created']??0)>1800||($_SESSION['myschool_preview']['year_id']??0)!==$yearId||($_SESSION['myschool_preview']['actor']??0)!==(int)$user['id']))unset($_SESSION['myschool_preview']);
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    try{
        if(!verifyCsrfToken($_POST['csrf_token']??''))throw new DomainException('Το αίτημα ασφαλείας έληξε. Ανανεώστε τη σελίδα.');
        if(schoolRequestId($_POST['year_id']??null)!==$yearId)throw new DomainException('Το σχολικό έτος άλλαξε. Ανανεώστε τη σελίδα.');
        if(!$ready)throw new DomainException('Απαιτείται πρώτα προετοιμασία της Βαθμολογίας και του ΑΜ καθηγητών.');
        $action=$_POST['action']??'';
        if($action==='preview'){
            unset($_SESSION['myschool_preview']);$files=[];
            try{
                foreach(['students','teachers','assignments'] as $key){$file=$_FILES[$key]??null;if(!is_array($file)||($file['error']??1)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']??'')||($file['size']??0)>2097152)throw new DomainException('Ανεβάστε και τα τρία αρχεία XLS, έως 2 MB το καθένα.');if(strtolower(pathinfo($file['name']??'',PATHINFO_EXTENSION))!=='xls'||file_get_contents($file['tmp_name'],false,null,0,8)!=="\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")throw new DomainException('Χρειάζονται αρχεία MySchool σε μορφή .xls.');$files[$key]=$file['tmp_name'];}
                $students=myschoolReadPeople($files['students'],'students');$teachers=myschoolReadPeople($files['teachers'],'teachers');$assignments=marksReadAssignments($files['assignments']);
            }finally{foreach($_FILES as $file)if(is_array($file)&&is_string($file['tmp_name']??null)&&is_uploaded_file($file['tmp_name']))unlink($file['tmp_name']);}
            if($assignments['year_name']!==$year['name'])throw new DomainException('Το έτος αναθέσεων δεν συμφωνεί με το ενεργό σχολικό έτος.');
            $preview=myschoolPreview($students,$teachers,$assignments);
            $preview+=['created'=>time(),'year_id'=>$yearId,'actor'=>(int)$user['id'],'token'=>bin2hex(random_bytes(24)),'snapshot'=>myschoolState($db,$yearId)];
            $_SESSION['myschool_preview']=$preview;$message='Η ανάγνωση ολοκληρώθηκε. Δεν έχουν αποθηκευτεί εγγραφές. Ελέγξτε τις αντιστοιχίσεις.';
        }elseif($action==='commit'){
            if(($_POST['confirm_import']??'')!=='1')throw new DomainException('Επιβεβαιώστε ότι ελέγξατε τις αντιστοιχίσεις.');
            $preview=$_SESSION['myschool_preview']??null;
            if(!is_array($preview)||!is_string($_POST['preview_token']??null)||!hash_equals($preview['token'],$_POST['preview_token']))throw new DomainException('Η προεπισκόπηση έληξε. Ανεβάστε ξανά τα αρχεία.');
            foreach(['account_map','assignment_map','section_map','section_grade'] as $key)if(!is_array($_POST[$key]??null))throw new DomainException('Ελλιπείς αντιστοιχίσεις.');
            $policy=$_POST['assignment_policy']??'';if(!is_string($policy))throw new DomainException('Επιλέξτε πολιτική αναθέσεων.');
            $result=myschoolCommit($db,$yearId,$user,$preview,$_POST['account_map'],$_POST['assignment_map'],$_POST['section_map'],$_POST['section_grade'],$policy);
            unset($_SESSION['myschool_preview']);$_SESSION['myschool_flash']='Ολοκληρώθηκε η εισαγωγή: '.$result['students_new'].' νέοι μαθητές, '.$result['students_updated'].' ενημερώσεις μαθητών, '.$result['teachers_new'].' νέοι καθηγητές και '.$result['assignments'].' γραμμές αναθέσεων. Δεν στάλθηκαν προσκλήσεις ή email.';
            header('Location: myschool_import.php',true,303);exit;
        }elseif($action==='cancel'){unset($_SESSION['myschool_preview']);$message='Η προεπισκόπηση ακυρώθηκε χωρίς αλλαγές.';}
        else throw new DomainException('Μη υποστηριζόμενη ενέργεια.');
    }catch(DomainException $e){$error=$e->getMessage();}catch(Throwable $e){$error=safeError($e);}
}
$pageTitle='Εισαγωγή MySchool';require __DIR__.'/header.php';
$base=static function()use($yearId){echo csrfField().'<input type="hidden" name="year_id" value="'.$yearId.'">';};
?>
<link rel="stylesheet" href="../assets/css/marks.css?v=5">
<link rel="stylesheet" href="../assets/css/myschool-import.css?v=1">
<p class="myschool-intro">Ενιαία εισαγωγή μαθητών, μητρώου καθηγητών και αναθέσεων για το σχολικό έτος <strong><?= $h($year['name']) ?></strong>. Πρώτα γίνεται προεπισκόπηση και μετά επιβεβαίωση.</p>
<?php if($error): ?><div class="alert alert-danger"><?= $h($error) ?></div><?php endif; ?>
<?php if($message): ?><div class="alert alert-success"><?= $h($message) ?></div><?php endif; ?>
<?php if(!$ready): ?>
<div class="alert alert-warning">Απαιτείται προετοιμασία της βάσης για τον ΑΜ καθηγητών. Εκτελέστε το <code>bin/migrate_teacher_registry.php</code> με λογαριασμό συντήρησης και ελέγξτε την ενεργοποίηση της Βαθμολογίας. Δεν εισάγονται δεδομένα πριν ολοκληρωθεί η προετοιμασία.</div>
<?php else: ?>
<form method="post" enctype="multipart/form-data" class="card myschool-upload">
<?php $base(); ?><input type="hidden" name="action" value="preview">
<div class="myschool-upload-heading"><div><h2>Τα τρία αρχεία MySchool</h2><p>Επιλέξτε τις αρχικές αναφορές με τις παρακάτω στήλες, χωρίς αλλαγή της θέσης τους.</p></div><span class="myschool-format">.xls</span></div>
<div class="myschool-files">
<?php
$fileCards=[
 ['students','Μαθητές και τμήματα',['B'=>'Αριθμός μητρώου μαθητή','C'=>'Επώνυμο μαθητή','D'=>'Όνομα μαθητή','G'=>'Τμήματα'],'Τα τμήματα χωρίζονται με κόμμα, ελληνικό ερωτηματικό ή αλλαγή γραμμής. Κάθε μαθητής εμφανίζεται μία φορά. Οι υπάρχουσες επαφές κηδεμόνων διατηρούνται.'],
 ['teachers','Μητρώο καθηγητών',['B'=>'Αριθμός μητρώου καθηγητή','C'=>'Επώνυμο','D'=>'Όνομα'],'Ο ΑΜ πρέπει να είναι μοναδικός. Τα υπάρχοντα email διατηρούνται. Οι νέοι λογαριασμοί μένουν σε αναμονή ενεργοποίησης, χωρίς αυτόματη πρόσκληση.'],
 ['assignments','Αναθέσεις εκπαιδευτικών',['A'=>'Επώνυμο','B'=>'Όνομα','H'=>'Τμήμα','J'=>'Ονομασία μαθήματος','L'=>'Ώρες — αριθμός ή κενό','N'=>'Ανάθεση, π.χ. Α΄ ή Β΄'],'Στην κεφαλίδα της στήλης M χρειάζεται το σχολικό έτος (π.χ. 2026-2027). Τα κενά επαναλαμβανόμενα ονόματα ακολουθούν τον προηγούμενο καθηγητή.']
];
foreach($fileCards as $i=>[$field,$title,$columns,$note]): ?>
<section class="myschool-file" aria-labelledby="file-title-<?= $field ?>">
<div class="myschool-file-heading"><span class="myschool-file-number" aria-hidden="true"><?= $i+1 ?></span><h3 id="file-title-<?= $field ?>"><?= $h($title) ?></h3></div>
<table class="myschool-columns"><caption>ΑΠΑΙΤΟΥΜΕΝΕΣ ΣΤΗΛΕΣ</caption><tbody><?php foreach($columns as $letter=>$label): ?><tr><th scope="row"><?= $letter ?></th><td><?= $h($label) ?></td></tr><?php endforeach; ?></tbody></table>
<p class="myschool-file-note"><?= $h($note) ?></p>
<label class="myschool-file-select"><span>Επιλογή αρχείου .xls</span><input type="file" name="<?= $field ?>" accept=".xls" aria-label="<?= $h('Αρχείο: '.$title) ?>" required></label>
</section>
<?php endforeach; ?>
</div>
<div class="myschool-upload-footer"><p>Έως <strong>2 MB ανά αρχείο</strong> · Ένα φύλλο · Έως 5.000 γραμμές και 30 στήλες<br>Στο επόμενο βήμα θα ελέγξετε τις αντιστοιχίσεις πριν από την εισαγωγή.</p><button class="btn btn-primary">Ανάγνωση και προεπισκόπηση →</button></div>
<p class="myschool-privacy">Το ΑΦΜ αγνοείται. Τα αρχικά αρχεία διαγράφονται μετά την ανάγνωση. Επιτρέπονται τίτλοι και κενές γραμμές πριν από τις επικεφαλίδες.</p>
</form>
<?php $preview=$_SESSION['myschool_preview']??null;if(is_array($preview)):
$matches=myschoolAccountMatches($db,$preview);$sections=$db->query('SELECT s.id,s.name,g.name AS grade FROM sections s JOIN grades g ON g.id=s.grade_id ORDER BY g.name,s.name,s.id')->fetchAll(PDO::FETCH_ASSOC);
$retry=($_POST['action']??'')==='commit'&&$error!=='';
$posted=static function(string $field,string $key,$default)use($retry){return $retry&&is_array($_POST[$field]??null)&&is_string($_POST[$field][$key]??null)?$_POST[$field][$key]:$default;};
$selectedPolicy=$retry&&($_POST['assignment_policy']??'')==='replace'?'replace':'preserve';
$counts=['new'=>0,'updated'=>0];$q=$db->prepare('SELECT id FROM students WHERE academic_year_id=? AND am=?');foreach($preview['students'] as $s){$q->execute([$yearId,$s['am']]);$counts[$q->fetchColumn()?'updated':'new']++;}
?>
<section class="card"><h2>Προεπισκόπηση εισαγωγής</h2>
<p><strong><?= count($preview['students']) ?></strong> μαθητές (<?= $counts['new'] ?> νέοι, <?= $counts['updated'] ?> υπάρχοντες), <strong><?= count($preview['teachers']) ?></strong> μητρώα καθηγητών, <strong><?= count($preview['assignments']['rows']) ?></strong> αναθέσεις και <strong><?= count($preview['sections']) ?></strong> τμήματα.</p>
<form method="post" style="margin-bottom:12px"><?php $base(); ?><input type="hidden" name="action" value="cancel"><button class="btn btn-secondary">Ακύρωση προεπισκόπησης</button></form>
<form method="post"><?php $base(); ?><input type="hidden" name="action" value="commit"><input type="hidden" name="preview_token" value="<?= $h($preview['token']) ?>">
<fieldset style="margin-bottom:20px"><legend>Τρόπος ενημέρωσης αναθέσεων</legend><label><input type="radio" name="assignment_policy" value="preserve" <?= $selectedPolicy==='preserve'?'checked':'' ?>> Διατήρηση χειροκίνητων αλλαγών</label><br><label><input type="radio" name="assignment_policy" value="replace" <?= $selectedPolicy==='replace'?'checked':'' ?>> Αντικατάσταση αναθέσεων των μαθημάτων που περιλαμβάνει η αναφορά</label><p>Η αντικατάσταση ανακαλεί τους παλιούς καθηγητές μόνο στα μαθήματα της αναφοράς. Αναθέσεις εκτός αναφοράς και πρόσθετα χειροκίνητα τμήματα απουσιών διατηρούνται. Για πλήρη αποχώρηση χρησιμοποιήστε «Αποχώρηση» στους καθηγητές.</p></fieldset>
<div class="marks-table-wrap"><table class="marks-table"><caption>ΑΜ καθηγητών → λογαριασμοί</caption><thead><tr><th>ΑΜ</th><th>Καθηγητής στην αναφορά</th><th>Λογαριασμός εφαρμογής</th></tr></thead><tbody>
<?php foreach($preview['teachers'] as $t):$selected=$posted('account_map',(string)$t['am'],$matches['matches'][$t['am']]); ?><tr><td><?= $h($t['am']) ?></td><td><?= $h($t['name']) ?></td><td><select class="form-control" name="account_map[<?= $h($t['am']) ?>]" required><option value="">Επιλέξτε λογαριασμό</option><option value="new" <?= $selected==='new'?'selected':'' ?>>Νέος λογαριασμός σε αναμονή</option><?php foreach($matches['users'] as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (string)$selected===(string)$u['id']?'selected':'' ?>><?= $h($u['name'].' · '.(str_ends_with($u['email'],'@myschool.local')?'Χρειάζεται email':$u['email']).' · '.(['active'=>'Ενεργός','inactive'=>'Αποχωρήσας','pending_activation'=>'Σε αναμονή'][$u['status']]??$u['status'])) ?></option><?php endforeach; ?></select></td></tr><?php endforeach; ?>
</tbody></table></div>
<div class="marks-table-wrap"><table class="marks-table"><caption>Ονόματα αναθέσεων → μητρώο καθηγητών</caption><thead><tr><th>Όνομα στις αναθέσεις</th><th>ΑΜ και όνομα μητρώου</th></tr></thead><tbody>
<?php foreach($preview['links'] as $key=>$link): $link['am']=$posted('assignment_map',hash('sha256',$key),$link['am']); ?><tr><td><?= $h($link['name']) ?></td><td><select class="form-control" name="assignment_map[<?= hash('sha256',$key) ?>]" required><option value="">Επιλέξτε ΑΜ καθηγητή</option><?php foreach($preview['teachers'] as $t): ?><option value="<?= $h($t['am']) ?>" <?= $link['am']===$t['am']?'selected':'' ?>><?= $h($t['am'].' · '.$t['name']) ?></option><?php endforeach; ?></select></td></tr><?php endforeach; ?>
</tbody></table></div>
<div class="marks-table-wrap"><table class="marks-table"><caption>Τμήματα και τάξεις</caption><thead><tr><th>Τμήμα αναφοράς</th><th>Υπάρχον ή νέο τμήμα</th><th>Τάξη αν είναι νέο</th></tr></thead><tbody>
<?php foreach($preview['sections'] as $name=>$grade):$ids=[];foreach($sections as $s)if(marksNameKey($s['name'])===marksNameKey($name))$ids[]=$s['id'];$selected=$posted('section_map',hash('sha256',$name),count($ids)===1?$ids[0]:($ids?'':'new'));$grade=$posted('section_grade',hash('sha256',$name),$grade); ?><tr><td><?= $h($name) ?></td><td><select name="section_map[<?= hash('sha256',$name) ?>]" class="form-control" required><option value="">Επιλέξτε τμήμα</option><option value="new" <?= $selected==='new'?'selected':'' ?>>Δημιουργία τμήματος από την αναφορά</option><?php foreach($sections as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (string)$selected===(string)$s['id']?'selected':'' ?>><?= $h($s['grade'].' · '.$s['name']) ?></option><?php endforeach; ?></select></td><td><select name="section_grade[<?= hash('sha256',$name) ?>]" class="form-control"><option value="">Επιλέξτε τάξη</option><?php foreach(['Α','Β','Γ'] as $g): ?><option <?= $grade===$g?'selected':'' ?>><?= $g ?></option><?php endforeach; ?></select></td></tr><?php endforeach; ?>
</tbody></table></div>
<details><summary>Μαθητές και τμήματα που θα εφαρμοστούν</summary><div class="marks-table-wrap"><table class="marks-table"><thead><tr><th>ΑΜ</th><th>Ονοματεπώνυμο</th><th>Τμήματα</th></tr></thead><tbody><?php foreach($preview['students'] as $s): ?><tr><td><?= $h($s['am']) ?></td><td><?= $h($s['name']) ?></td><td><?= $h(implode(', ',$s['sections'])) ?></td></tr><?php endforeach; ?></tbody></table></div></details>
<details><summary>Αναθέσεις που θα εφαρμοστούν</summary><div class="marks-table-wrap"><table class="marks-table"><thead><tr><th>Καθηγητής</th><th>Τμήμα</th><th>Μάθημα</th><th>Ώρες</th><th>Ανάθεση</th></tr></thead><tbody><?php foreach($preview['assignments']['rows'] as $r): ?><tr><?php foreach(['teacher_name','section','subject','hours','assignment'] as $key): ?><td><?= $h($r[$key]) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div></details>
<p>Τα τμήματα των μαθητών της αναφοράς συγχρονίζονται με το αρχείο. Οι άλλοι μαθητές, οι επαφές κηδεμόνων, οι βαθμοί και οι απουσίες διατηρούνται. Αν αλλάζει η λίστα μαθητών σε μάθημα με οριστική υποβολή, απαιτείται πρώτα επανάνοιγμα. Η εισαγωγή δεν ενεργοποιεί ανενεργούς λογαριασμούς ούτε στέλνει email.</p>
<label style="display:block;margin:16px 0"><input type="checkbox" name="confirm_import" value="1" required> Έλεγξα τις αντιστοιχίσεις και τις αλλαγές στα τμήματα μαθητών και τις αναθέσεις.</label>
<button class="btn btn-primary">Επιβεβαίωση εισαγωγής των τριών αρχείων</button></form></section>
<?php endif;endif;require __DIR__.'/footer.php'; ?>
