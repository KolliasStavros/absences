<?php
require_once __DIR__.'/../config/config.php';requireAdmin();
require_once __DIR__.'/../includes/lifecycle.php';
$actor=getCurrentUser();$error='';$message='';$manager=null;$escape=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
function portableMaintenanceConnection(): PDO {
    $user=defined('APP_MAINTENANCE_DB_USER')?APP_MAINTENANCE_DB_USER:getenv('APP_MAINTENANCE_DB_USER');if(!$user)return getDB();
    $pass=defined('APP_MAINTENANCE_DB_PASS')?APP_MAINTENANCE_DB_PASS:getenv('APP_MAINTENANCE_DB_PASS');if($pass===false)throw new DomainException('Λείπει η ρύθμιση λογαριασμού συντήρησης.');
    return new PDO('mysql:host='.DB_HOST.';port='.(defined('DB_PORT')?DB_PORT:3306).';dbname='.DB_NAME.';charset=utf8mb4',$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
}
function portablePassword(array $actor): void {
    if(!checkRateLimit($actor['email'])['allowed'])throw new DomainException('Πολλές προσπάθειες. Δοκιμάστε αργότερα.');
    $q=getDB()->prepare("SELECT password_hash FROM users WHERE id=? AND role='admin' AND status='active'");$q->execute([$actor['id']]);$hash=$q->fetchColumn();$pass=$_POST['password']??null;
    if(!is_string($pass)||!$hash||!password_verify($pass,$hash)){recordLoginAttempt($actor['email'],false);throw new DomainException('Ο κωδικός διαχειριστή δεν επιβεβαιώθηκε.');}
}
try{$manager=new AbsencesLifecycle(dirname(__DIR__),lifecycleStorage(dirname(__DIR__)),portableMaintenanceConnection(),DB_NAME,defined('DB_PREFIX')?DB_PREFIX:'');}catch(Throwable $e){$error='Απαιτείται έγκυρη ιδιωτική ρύθμιση συντήρησης πριν από την ανάκτηση.';}
if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
    try{
        if(!$manager)throw new DomainException('Δεν είναι διαθέσιμη η συντήρηση.');
        if(!verifyCsrfToken($_POST['csrf_token']??''))throw new DomainException('Το αίτημα ασφαλείας έληξε.');
        portablePassword($actor);$action=$_POST['action']??'';
        if($action==='export'){
            $meta=$manager->createPortableRecovery(APP_VERSION,(int)$actor['id']);$_SESSION['portable_export_id']=$meta['id'];$message='Δημιουργήθηκε ελεγμένο πακέτο σχολικών δεδομένων. Κατεβάστε το ZIP και φυλάξτε χωριστά το αρχείο SHA-256.';
        }elseif(in_array($action,['download','digest'],true)){
            $meta=$manager->portableRecoveryMeta($_SESSION['portable_export_id']??'');if(($meta['purpose']??'')!=='export')throw new DomainException('Δεν βρέθηκε εξαγωγή.');
            session_write_close();header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');
            if($action==='digest'){header('Content-Type: text/plain; charset=utf-8');header('Content-Disposition: attachment; filename="absences-school-recovery-'.$meta['id'].'.zip.sha256"');echo $meta['sha256'].'  absences-school-recovery-'.$meta['id'].'.zip'."\n";}
            else{header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="absences-school-recovery-'.$meta['id'].'.zip"');readfile($manager->store.'/portable-'.$meta['id'].'.zip');}exit;
        }elseif($action==='preview'){
            $file=$_FILES['recovery_file']??null;if(!is_array($file)||($file['error']??1)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']??'')||($file['size']??0)>67108864)throw new DomainException('Επιλέξτε πλήρες πακέτο σχολικών δεδομένων ZIP έως 64 MB και εντός του ορίου του server.');
            $sha=$_POST['sha256']??'';if(!is_string($sha))throw new DomainException('Μη έγκυρο SHA-256.');$sha=strtolower(trim($sha));
            $checksum=$_FILES['checksum_file']??null;
            if(is_array($checksum)&&($checksum['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){if(($checksum['error']??1)!==UPLOAD_ERR_OK||($checksum['size']??0)>4096||!is_uploaded_file($checksum['tmp_name']??''))throw new DomainException('Μη έγκυρο αρχείο SHA-256.');$text=file_get_contents($checksum['tmp_name']);if(!preg_match('/\A([a-fA-F0-9]{64})(?:\s|$)/D',$text,$match))throw new DomainException('Το συνοδευτικό αρχείο δεν περιέχει SHA-256.');$fromFile=strtolower($match[1]);if($sha!==''&&!hash_equals($sha,$fromFile))throw new DomainException('Οι δύο τιμές SHA-256 διαφέρουν.');$sha=$fromFile;}
            if(!preg_match('/^[a-f0-9]{64}$/D',$sha))throw new DomainException('Δώστε το χωριστά φυλαγμένο SHA-256 ή το συνοδευτικό αρχείο του.');
            $meta=$manager->stagePortableRecovery($file['tmp_name'],$sha,APP_VERSION,(int)$actor['id']);
            $_SESSION['portable_preview']=['id'=>$meta['id'],'sha256'=>$meta['sha256'],'actor'=>(int)$actor['id'],'expires'=>$meta['expires'],'token'=>bin2hex(random_bytes(24)),'created'=>$meta['created'],'version'=>$meta['version'],'counts'=>$meta['counts']];$message='Το πακέτο επαληθεύτηκε. Δεν έγινε ακόμη εισαγωγή.';
        }elseif(in_array($action,['import','cancel'],true)){
            $preview=$_SESSION['portable_preview']??null;if(!is_array($preview)||($preview['actor']??0)!==(int)$actor['id']||($preview['expires']??0)<time()||!is_string($_POST['preview_token']??null)||!hash_equals($preview['token'],$_POST['preview_token']))throw new DomainException('Η προεπισκόπηση έληξε. Ανεβάστε ξανά το πακέτο.');
            if($action==='cancel'){$manager->cancelPortableRecovery($preview['id'],(int)$actor['id']);unset($_SESSION['portable_preview']);$message='Η προεπισκόπηση διαγράφηκε χωρίς εισαγωγή.';}
            else{
                if(($_POST['confirm']??'')!=='ΑΝΑΚΤΗΣΗ ΣΧΟΛΙΚΩΝ ΔΕΔΟΜΕΝΩΝ'||($_POST['fresh_ack']??'')!=='1')throw new DomainException('Επιβεβαιώστε τη νέα εγκατάσταση και πληκτρολογήστε ΑΝΑΚΤΗΣΗ ΣΧΟΛΙΚΩΝ ΔΕΔΟΜΕΝΩΝ.');
                $result=$manager->importPortableRecovery($preview['id'],$preview['sha256'],APP_VERSION,(int)$actor['id']);$_SESSION=[];session_destroy();
                echo '<!doctype html><html lang="el"><meta charset="utf-8"><title>Η ανάκτηση ολοκληρώθηκε</title><p>Τα σχολικά δεδομένα ανακτήθηκαν στη νέα εγκατάσταση. Συνδεθείτε με τον διαχειριστή και τον κωδικό αυτής της εγκατάστασης. Οι υπόλοιποι λογαριασμοί χρειάζονται νέο κωδικό. Ρυθμίστε email και SMS με τα στοιχεία της νέας εγκατάστασης.</p><p><a href="../index.php">Σύνδεση</a></p></html>';exit;
            }
        }else throw new DomainException('Μη υποστηριζόμενη ενέργεια.');
    }catch(DomainException $e){$error=$e->getMessage();}catch(Throwable $e){$error=safeError($e);}
    finally{foreach($_FILES as $file)if(is_array($file)&&is_string($file['tmp_name']??null)&&is_uploaded_file($file['tmp_name']))@unlink($file['tmp_name']);}
}
$export=null;if($manager&&!empty($_SESSION['portable_export_id'])){try{$export=$manager->portableRecoveryMeta($_SESSION['portable_export_id']);}catch(Throwable $e){unset($_SESSION['portable_export_id']);}}
$preview=$_SESSION['portable_preview']??null;if(is_array($preview)&&($preview['expires']??0)<time()){unset($_SESSION['portable_preview']);$preview=null;}
$pageTitle='Ανάκτηση σε νέα εγκατάσταση';require __DIR__.'/header.php';
$password=static function(){echo '<label>Κωδικός διαχειριστή<input class="form-control" type="password" name="password" autocomplete="current-password" required></label>';};
?>
<link rel="stylesheet" href="../assets/css/portable-recovery.css?v=1">
<p class="portable-intro">Μεταφορά σχολικών δεδομένων μετά από βλάβη, χωρίς εξάρτηση από την παλιά διαδρομή, το όνομα βάσης ή το πρόθεμα πινάκων. Στη νέα εγκατάσταση χρησιμοποιούνται οι δικές της ιδιωτικές ρυθμίσεις.</p>
<?php if($error):?><div class="alert alert-danger" role="alert"><?= $escape($error) ?></div><?php endif;?>
<?php if($message):?><div class="alert alert-success" role="status"><?= $escape($message) ?></div><?php endif;?>
<?php if($manager):?>
<div class="portable-grid">
<section class="card portable-card"><h2><span>1</span> Εξαγωγή από την παλιά εγκατάσταση</h2><p>Το πακέτο περιλαμβάνει μαθητές, τμήματα, καθηγητές, απουσίες, βαθμολογία και το σχολικό ιστορικό τους. Διατηρούνται οι ΑΜ και οι παλιοί συντάκτες καταχωρίσεων.</p>
<div class="portable-note"><strong>Δεν περιλαμβάνονται</strong><p>Ιδιωτικά αρχεία, κλειδιά κρυπτογράφησης, στοιχεία βάσης, λογαριασμοί/κωδικοί SMTP, ρυθμίσεις/κλειδιά SMS, κωδικοί χρηστών, ενεργές συνεδρίες και σύνδεσμοι επαναφοράς κωδικού. Το πακέτο δεν περιέχει κώδικα εφαρμογής.</p></div>
<form method="post"><?= csrfField() ?><input type="hidden" name="action" value="export"><?php $password(); ?><button class="btn btn-primary">Δημιουργία πακέτου σχολικών δεδομένων</button></form>
<?php if($export):?><div class="portable-result"><p>Ελεγμένη εξαγωγή · <?= $escape($export['version']) ?> · <?= $escape(number_format($export['size']/1048576,2)) ?> MB</p>
<?php foreach(['download'=>'Λήψη ZIP','digest'=>'Λήψη SHA-256'] as $action=>$label):?><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="<?= $action ?>"><?php $password(); ?><button class="btn btn-secondary"><?= $label ?></button></form><?php endforeach;?>
<p>Φυλάξτε το SHA-256 χωριστά από το ZIP. Θα ζητηθεί στη νέα εγκατάσταση για επαλήθευση της προέλευσης.</p></div><?php endif;?></section>
<section class="card portable-card"><h2><span>2</span> Ανάκτηση στη νέα εγκατάσταση</h2><p>Εγκαταστήστε πρώτα την ίδια ή νεότερη συμβατή έκδοση της εφαρμογής, με έναν ενεργό διαχειριστή. Η εισαγωγή επιτρέπεται μόνο όταν δεν υπάρχουν ήδη σχολικές καταχωρίσεις.</p>
<p>Οι καθηγητές μεταφέρονται χωρίς κωδικούς και περιμένουν ενεργοποίηση. Ο διαχειριστής της νέας εγκατάστασης κρατά τα στοιχεία σύνδεσής του. Email και SMS ρυθμίζονται με τα νέα στοιχεία.</p>
<form method="post" enctype="multipart/form-data"><?= csrfField() ?><input type="hidden" name="action" value="preview"><label>Πακέτο σχολικών δεδομένων (.zip)<input class="form-control" type="file" name="recovery_file" accept=".zip" required></label><label>Χωριστά φυλαγμένο αρχείο SHA-256<input class="form-control" type="file" name="checksum_file" accept=".sha256,.txt"></label><label>Ή τιμή SHA-256<input class="form-control" name="sha256" maxlength="64" pattern="[a-fA-F0-9]{64}" autocomplete="off"></label><?php $password(); ?><button class="btn btn-primary">Έλεγχος και προεπισκόπηση</button></form>
<p class="portable-small">Έως 64 MB και εντός του ορίου μεταφόρτωσης του server. Γίνονται δεκτά μόνο τα ειδικά πακέτα σχολικών δεδομένων, όχι τα πλήρη backup της παλιάς εγκατάστασης.</p></section>
</div>
<?php if(is_array($preview)):?><section class="card portable-card portable-preview"><h2>Προεπισκόπηση ανάκτησης</h2><p>Έκδοση προέλευσης <?= $escape($preview['version']) ?> · Δημιουργία <?= $escape($preview['created']) ?>. Η νέα διαδρομή, βάση και ιδιωτικές ρυθμίσεις θα διατηρηθούν.</p>
<div class="portable-counts"><?php foreach(['students'=>'Μαθητές','users'=>'Λογαριασμοί','attendance'=>'Καταχωρίσεις απουσιών','student_marks'=>'Βαθμοί'] as $key=>$label):?><div><strong><?= (int)($preview['counts'][$key]??0) ?></strong><span><?= $label ?></span></div><?php endforeach;?></div>
<p>Πριν από την εισαγωγή δημιουργείται νέο ιδιωτικό backup της νέας εγκατάστασης. Η εισαγωγή γίνεται σε μία συναλλαγή, χωρίς αλλαγή του κώδικα εφαρμογής.</p>
<form method="post"><?= csrfField() ?><input type="hidden" name="action" value="import"><input type="hidden" name="preview_token" value="<?= $escape($preview['token']) ?>"><label><input type="checkbox" name="fresh_ack" value="1" required> Επιβεβαιώνω ότι αυτή είναι η νέα εγκατάσταση στην οποία θέλω να μεταφέρω τα δεδομένα.</label><label>Πληκτρολογήστε ΑΝΑΚΤΗΣΗ ΣΧΟΛΙΚΩΝ ΔΕΔΟΜΕΝΩΝ<input class="form-control" name="confirm" required autocomplete="off"></label><?php $password(); ?><button class="btn btn-primary">Επιβεβαίωση ανάκτησης</button></form>
<form method="post"><?= csrfField() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="preview_token" value="<?= $escape($preview['token']) ?>"><?php $password(); ?><button class="btn btn-secondary">Ακύρωση και διαγραφή προεπισκόπησης</button></form></section><?php endif;?>
<?php endif;require __DIR__.'/footer.php'; ?>
