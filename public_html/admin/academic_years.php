<?php
require_once __DIR__.'/../config/config.php';requireAdmin();
require_once __DIR__.'/../includes/academic_year_archives.php';
$actor=getCurrentUser();$error='';$message='';$archives=null;$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
function yearMaintenanceDB(): PDO {
    $u=defined('APP_MAINTENANCE_DB_USER')?APP_MAINTENANCE_DB_USER:getenv('APP_MAINTENANCE_DB_USER');
    if(!$u)return getDB();$p=defined('APP_MAINTENANCE_DB_PASS')?APP_MAINTENANCE_DB_PASS:getenv('APP_MAINTENANCE_DB_PASS');
    if($p===false)throw new DomainException('Λείπει η ιδιωτική ρύθμιση συντήρησης.');
    return new PDO('mysql:host='.DB_HOST.';port='.(defined('DB_PORT')?DB_PORT:3306).';dbname='.DB_NAME.';charset=utf8mb4',$u,$p,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
}
function yearPassword(array $actor): void {
    if(!checkRateLimit($actor['email'])['allowed'])throw new DomainException('Πολλές προσπάθειες. Δοκιμάστε αργότερα.');
    $q=getDB()->prepare("SELECT password_hash FROM users WHERE id=? AND role='admin' AND status='active'");$q->execute([$actor['id']]);$hash=$q->fetchColumn();
    if(!is_string($_POST['password']??null)||!$hash||!password_verify($_POST['password'],$hash)){recordLoginAttempt($actor['email'],false);throw new DomainException('Ο κωδικός διαχειριστή δεν επιβεβαιώθηκε.');}
}
try{$archives=new AcademicYearArchives(yearMaintenanceDB(),defined('DB_PREFIX')?DB_PREFIX:'',lifecycleStorage(dirname(__DIR__)),(int)$actor['id']);}
catch(Throwable $e){$error='Η διαχείριση ετών απαιτεί έγκυρο ιδιωτικό φάκελο συντήρησης και συμβατή βάση δεδομένων. Οι υπάρχουσες καταχωρίσεις παραμένουν διαθέσιμες.';}
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    try{
        if(!$archives)throw new DomainException('Η διαχείριση ετών δεν είναι διαθέσιμη.');
        if(!verifyCsrfToken($_POST['csrf_token']??''))throw new DomainException('Το αίτημα ασφαλείας έληξε.');
        $action=$_POST['action']??'';$id=filter_var($_POST['year_id']??0,FILTER_VALIDATE_INT)?:0;
        if($action==='view'){$year=$archives->years->year($id);$_SESSION['academic_year_view']=$id;getActiveAcademicYear(true);header('Location: student_cards.php');exit;}
        elseif($action==='current'){unset($_SESSION['academic_year_view']);getActiveAcademicYear(true);$message='Επιστρέψατε στο ενεργό σχολικό έτος.';}
        else{
            yearPassword($actor);
            if($action==='create'){$name=trim(is_string($_POST['year_name']??null)?$_POST['year_name']:'');$activate=($_POST['activate']??'')==='1';$archives->exclusive(fn()=>$archives->years->create($name,$activate));if($activate)unset($_SESSION['academic_year_view']);$message='Το νέο έτος δημιουργήθηκε χωρίς μαθητές, αναθέσεις, απουσίες ή βαθμούς. Εισαγάγετε τα νέα αρχεία από την Εισαγωγή MySchool.';}
            elseif(in_array($action,['activate','close','reopen'],true)){
                $year=$archives->years->year($id);$expected=match($action){'activate'=>'ΕΝΕΡΓΟΠΟΙΗΣΗ ','close'=>'ΚΛΕΙΣΙΜΟ ',default=>'ΑΝΟΙΓΜΑ '}.$year['name'];
                if(($_POST['confirm']??'')!==$expected)throw new DomainException('Πληκτρολογήστε: '.$expected);
                $archives->exclusive(function()use($archives,$action,$id){if($action==='activate')$archives->years->activate($id);else $archives->years->setClosed($id,$action==='close');});
                if($action==='activate')unset($_SESSION['academic_year_view']);$message='Η κατάσταση του έτους ενημερώθηκε.';
            }elseif($action==='backup'){$m=$archives->export($id,APP_VERSION);$_SESSION['year_backups'][(string)$id]=$m['id'];$message='Το backup ελέγχθηκε. Κατεβάστε το ZIP και το SHA-256 και φυλάξτε τα εκτός server.';}
            elseif(in_array($action,['download','digest'],true)){
                $backup=$_SESSION['year_backups'][(string)$id]??'';$result=$archives->download($backup,$action==='digest');$m=$result['meta'];$filename='absences-year-'.$m['year']['name'].'-'.$m['id'].'.zip';
                header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');
                if($action==='digest'){header('Content-Type: text/plain; charset=utf-8');header('Content-Disposition: attachment; filename="'.$filename.'.sha256"');echo $m['sha256'].'  '.$filename."\n";}
                else{header('Content-Type: application/zip');header('Content-Length: '.filesize($result['path']));header('Content-Disposition: attachment; filename="'.$filename.'"');readfile($result['path']);}exit;
            }elseif($action==='delete'){
                $year=$archives->years->year($id);if(($_POST['confirm']??'')!=='ΔΙΑΓΡΑΦΗ '.$year['name']||($_POST['backup_ack']??'')!=='1')throw new DomainException('Επιβεβαιώστε ότι φυλάξατε το backup και πληκτρολογήστε ΔΙΑΓΡΑΦΗ '.$year['name'].'.');
                $archives->delete($id,$_SESSION['year_backups'][(string)$id]??'',APP_VERSION);unset($_SESSION['year_backups'][(string)$id]);if((int)($_SESSION['academic_year_view']??0)===$id)unset($_SESSION['academic_year_view']);$message='Διαγράφηκε μόνο το επιλεγμένο σχολικό έτος. Οι λογαριασμοί και τα υπόλοιπα έτη διατηρήθηκαν.';
            }elseif($action==='preview'){
                $file=$_FILES['archive']??null;if(!is_array($file)||($file['error']??1)!==UPLOAD_ERR_OK||($file['size']??0)>67108864||!is_uploaded_file($file['tmp_name']??''))throw new DomainException('Επιλέξτε ετήσιο ZIP έως 64 MB και εντός των ορίων του server.');
                $sha=is_string($_POST['sha256']??null)?strtolower(trim($_POST['sha256'])):'';$checksum=$_FILES['checksum']??null;
                if(is_array($checksum)&&($checksum['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){if(($checksum['error']??1)!==UPLOAD_ERR_OK||($checksum['size']??0)>4096||!is_uploaded_file($checksum['tmp_name']??'')||!preg_match('/\A([a-fA-F0-9]{64})(?:\s|$)/D',file_get_contents($checksum['tmp_name']),$match))throw new DomainException('Μη έγκυρο αρχείο SHA-256.');$fromFile=strtolower($match[1]);if($sha!==''&&!hash_equals($sha,$fromFile))throw new DomainException('Οι τιμές SHA-256 διαφέρουν.');$sha=$fromFile;}
                $m=$archives->stage($file['tmp_name'],$sha,APP_VERSION);$_SESSION['year_restore']=$m+['token'=>bin2hex(random_bytes(24))];$message='Το πακέτο επαληθεύτηκε. Δεν έγινε ακόμη επαναφορά.';
            }elseif(in_array($action,['restore','cancel'],true)){
                $p=$_SESSION['year_restore']??null;if(!is_array($p)||($p['actor']??0)!==(int)$actor['id']||($p['expires']??0)<time()||!is_string($_POST['preview_token']??null)||!hash_equals($p['token'],$_POST['preview_token']))throw new DomainException('Η προεπισκόπηση έληξε. Ανεβάστε ξανά το ZIP.');
                if($action==='cancel'){$archives->cancel($p['id']);unset($_SESSION['year_restore']);$message='Η προεπισκόπηση ακυρώθηκε.';}
                else{if(($_POST['confirm']??'')!=='ΕΠΑΝΑΦΟΡΑ '.$p['year']['name'])throw new DomainException('Πληκτρολογήστε ΕΠΑΝΑΦΟΡΑ '.$p['year']['name']);$newId=$archives->restore($p['id'],APP_VERSION);unset($_SESSION['year_restore']);$message='Το έτος επαναφέρθηκε κλειστό και μη ενεργό. Δεν άλλαξαν τα υπόλοιπα έτη ή τα στοιχεία σύνδεσης χρηστών.';}
            }else throw new DomainException('Άγνωστη ενέργεια.');
        }
        getActiveAcademicYear(true);
        $_SESSION['year_flash']=$message;header('Location: academic_years.php');exit;
    }catch(DomainException $e){$error=$e->getMessage();}catch(Throwable $e){$error=safeError($e);}
    finally{foreach($_FILES as $f)if(is_array($f)&&is_string($f['tmp_name']??null)&&is_uploaded_file($f['tmp_name']))@unlink($f['tmp_name']);}
}
$message=$_SESSION['year_flash']??$message;unset($_SESSION['year_flash']);$years=$archives?$archives->years->listing():[];
$preview=$_SESSION['year_restore']??null;if(is_array($preview)&&$preview['expires']<time()){unset($_SESSION['year_restore']);$preview=null;}
$pageTitle='Σχολικά έτη';require __DIR__.'/header.php';
$password=static function(){echo '<label>Κωδικός διαχειριστή<input class="form-control" type="password" name="password" autocomplete="current-password" required></label>';};
$formStart=static function(string $action,int $id=0){echo '<form method="post">'.csrfField().'<input type="hidden" name="action" value="'.$action.'"><input type="hidden" name="year_id" value="'.$id.'">';};
$labels=['students'=>'Μαθητές','attendance'=>'Καταχωρίσεις απουσιών','student_marks'=>'Βαθμοί','course_offerings'=>'Διδασκαλίες','assessment_periods'=>'Περίοδοι αξιολόγησης','school_calendar_events'=>'Εκδηλώσεις ημερολογίου'];
?>
<link rel="stylesheet" href="../assets/css/academic-years.css?v=1">
<div class="year-heading"><div><p class="year-eyebrow">ΟΡΓΑΝΩΣΗ ΣΧΟΛΕΙΟΥ</p><h1>Σχολικά έτη</h1><p>Κάθε χρονιά έχει τους δικούς της μαθητές, αναθέσεις, απουσίες και βαθμούς.</p></div><?php $formStart('current');?><button class="btn btn-secondary">Επιστροφή στο ενεργό έτος</button></form></div>
<?php if($error):?><div class="alert alert-danger" role="alert"><?= $h($error) ?></div><?php endif;?>
<?php if($message):?><div class="alert alert-success" role="status"><?= $h($message) ?></div><?php endif;?>
<?php if($archives):?>
<div class="year-tools">
<section class="card year-tool"><span class="year-icon">＋</span><h2>Νέα σχολική χρονιά</h2><p>Ξεκινήστε χωρίς καταχωρίσεις. Οι λογαριασμοί, οι ρυθμίσεις και τα κοινά τμήματα / μαθήματα διατηρούνται.</p><?php $formStart('create');?><label>Σχολικό έτος<input class="form-control" name="year_name" placeholder="2027-2028" pattern="20[0-9]{2}-20[0-9]{2}" maxlength="9" required></label><label class="year-check"><input type="checkbox" name="activate" value="1" checked> Ενεργοποίηση νέου έτους για όλους τους χρήστες</label><?php $password();?><button class="btn btn-primary">Δημιουργία κενού έτους</button></form></section>
<section class="card year-tool"><span class="year-icon">↥</span><h2>Επαναφορά ενός έτους</h2><p>Ανεβάστε ετήσιο backup. Γίνεται πρώτα έλεγχος και προεπισκόπηση. Το έτος επιστρέφει κλειστό, χωρίς αντικατάσταση υπάρχουσας χρονιάς.</p><form method="post" enctype="multipart/form-data"><?= csrfField() ?><input type="hidden" name="action" value="preview"><label>Ετήσιο backup (.zip)<input class="form-control" type="file" name="archive" accept=".zip" required></label><label>Συνοδευτικό SHA-256<input class="form-control" type="file" name="checksum" accept=".sha256,.txt"></label><details class="year-digest"><summary>Ή επικόλληση SHA-256</summary><input class="form-control" name="sha256" aria-label="SHA-256" maxlength="64" pattern="[a-fA-F0-9]{64}"></details><?php $password();?><button class="btn btn-secondary">Έλεγχος και προεπισκόπηση</button></form></section>
</div>
<div class="year-note">Τα ετήσια αντίγραφα δεν περιέχουν κωδικούς, SMTP/SMS ή ιδιωτικά κλειδιά. Νέοι ιστορικοί καθηγητές εισάγονται ανενεργοί. Δεν αλλάζουν υπάρχοντες λογαριασμοί. Το τεχνικό ιστορικό αναίρεσης απουσιών δεν μεταφέρεται.</div>
<?php if($preview):?><section class="card year-preview"><span class="badge badge-info">ΕΠΑΛΗΘΕΥΜΕΝΟ ΠΑΚΕΤΟ</span><h2>Επαναφορά <?= $h($preview['year']['name']) ?></h2><div class="year-stats"><?php foreach($labels as $key=>$label):?><div><strong><?= number_format($preview['counts'][$key]??0) ?></strong><span><?= $label ?></span></div><?php endforeach;?></div><p>Δεν θα αλλάξει το ενεργό έτος. Η προεπισκόπηση ισχύει για 30 λεπτά.</p><?php $formStart('restore');?><input type="hidden" name="preview_token" value="<?= $h($preview['token']) ?>"><label>Πληκτρολογήστε ΕΠΑΝΑΦΟΡΑ <?= $h($preview['year']['name']) ?><input class="form-control" name="confirm" autocomplete="off" required></label><?php $password();?><button class="btn btn-primary">Επιβεβαίωση επαναφοράς</button></form><?php $formStart('cancel');?><input type="hidden" name="preview_token" value="<?= $h($preview['token']) ?>"><?php $password();?><button class="btn btn-secondary">Ακύρωση προεπισκόπησης</button></form></section><?php endif;?>
<div class="year-list-heading"><h2>Οι σχολικές χρονιές</h2><span><?= count($years) ?> έτη</span></div>
<div class="year-list">
<?php foreach($years as $year):$id=(int)$year['id'];$backup=null;if(isset($_SESSION['year_backups'][(string)$id])){try{$backup=$archives->meta($_SESSION['year_backups'][(string)$id]);}catch(Throwable $e){unset($_SESSION['year_backups'][(string)$id]);}}?>
<section class="card year-card<?= $year['is_active']?' year-active':'' ?>"><div class="year-card-title"><div><h2><?= $h($year['name']) ?></h2><span class="badge <?= $year['is_active']?'badge-success':($year['closed']?'badge-gray':'badge-info') ?>"><?= $year['is_active']?'Ενεργό για καταχωρίσεις':($year['closed']?'Κλειστό · μόνο προβολή':'Ανοικτό · μη ενεργό') ?></span></div><?php $formStart('view',$id);?><button class="btn btn-secondary btn-sm">Προβολή ιστορικού</button></form></div>
<div class="year-stats"><?php foreach(['students','attendance','student_marks','course_offerings'] as $key):?><div><strong><?= number_format($year['counts'][$key]??0) ?></strong><span><?= $labels[$key] ?></span></div><?php endforeach;?></div>
<details class="year-detail"><summary>Backup και λήψη αντιγράφου</summary><div><?php $formStart('backup',$id);?><p>Περιλαμβάνει το σχολικό ιστορικό αυτής της χρονιάς και τις απαραίτητες κοινές ταυτότητες.</p><?php $password();?><button class="btn btn-primary">Δημιουργία ετήσιου backup</button></form>
<?php if($backup):?><div class="year-backup"><strong>Ελεγμένο αντίγραφο · <?= $h(date('d/m/Y H:i',strtotime($backup['created']))) ?></strong><p><?= $h(number_format($backup['size']/1048576,2)) ?> MB · Φυλάξτε και τα δύο αρχεία εκτός server.</p><div class="year-downloads"><?php foreach(['download'=>'Λήψη ZIP','digest'=>'Λήψη SHA-256'] as $action=>$label):$formStart($action,$id);$password();?><button class="btn btn-secondary"><?= $label ?></button></form><?php endforeach;?></div></div><?php endif;?></div></details>
<?php if(!$year['is_active']):?><details class="year-detail"><summary>Κατάσταση έτους</summary><div><p>Η ενεργοποίηση αλλάζει τη χρονιά καταχώρισης για όλους. Το άνοιγμα ενός κλειστού έτους επιτρέπει μεταγενέστερη ενεργοποίησή του.</p><?php $actions=$year['closed']?['reopen'=>'ΑΝΟΙΓΜΑ']:['activate'=>'ΕΝΕΡΓΟΠΟΙΗΣΗ','close'=>'ΚΛΕΙΣΙΜΟ'];foreach($actions as $action=>$verb):$formStart($action,$id);?><label>Πληκτρολογήστε <?= $verb.' '.$h($year['name']) ?><input class="form-control" name="confirm" autocomplete="off" required></label><?php $password();?><button class="btn btn-secondary"><?= $action==='activate'?'Ενεργοποίηση':($action==='close'?'Κλείσιμο έτους':'Άνοιγμα έτους') ?></button></form><?php endforeach;?></div></details>
<?php if($year['closed']):?><details class="year-detail year-danger"><summary>Οριστική διαγραφή έτους</summary><div><p>Θα διαγραφούν οι μαθητές, οι αναθέσεις, οι απουσίες, οι βαθμοί, οι εκδηλώσεις και το σχετικό ιστορικό του <?= $h($year['name']) ?>. Οι λογαριασμοί και τα υπόλοιπα έτη διατηρούνται.</p><?php if(!$backup||!$backup['zip_downloaded']||!$backup['sha_downloaded']):?><p><strong>Δημιουργήστε και κατεβάστε πρώτα το ZIP και το SHA-256 από την παραπάνω ομάδα.</strong></p><?php else:$formStart('delete',$id);?><label class="year-check"><input type="checkbox" name="backup_ack" value="1" required> Έχω φυλάξει το ZIP και το SHA-256 εκτός server.</label><label>Πληκτρολογήστε ΔΙΑΓΡΑΦΗ <?= $h($year['name']) ?><input class="form-control" name="confirm" autocomplete="off" required></label><?php $password();?><button class="btn btn-danger">Οριστική διαγραφή <?= $h($year['name']) ?></button></form><?php endif;?></div></details><?php endif;endif;?>
</section><?php endforeach;?>
</div>
<?php endif;require __DIR__.'/footer.php';?>
