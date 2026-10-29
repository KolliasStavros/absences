<?php
// SPDX-License-Identifier: GPL-3.0-only
if(!defined('ABSENCES_MAINTENANCE_DISPATCHED')){http_response_code(404);exit;}

require_once __DIR__.'/../config/config.php';
requireAdmin();
require_once __DIR__.'/../includes/lifecycle.php';
require_once __DIR__.'/../includes/github_updates.php';
$root=dirname(__DIR__);$actor=getCurrentUser();$message='';$error='';$manager=null;$tables=[];$state=null;$drop=false;
function onlineMaintenanceLimit(string $value): int {
    if(!preg_match('/^\s*(\d+)\s*([KMG]?)\s*$/i',$value,$m))return 0;
    return (int)$m[1] * (1024 ** ([''=>0,'K'=>1,'M'=>2,'G'=>3][strtoupper($m[2])]));
}
$uploadLimit=min(array_filter([67108864,onlineMaintenanceLimit((string)ini_get('upload_max_filesize')),onlineMaintenanceLimit((string)ini_get('post_max_size'))]));
function onlineMaintenancePassword(array $actor): void {
    $rate=checkRateLimit($actor['email']);
    if (!$rate['allowed']) throw new DomainException($rate['message']);
    $q=getDB()->prepare("SELECT password_hash FROM users WHERE id=? AND role='admin' AND status='active'");$q->execute([$actor['id']]);$hash=$q->fetchColumn();
    $password=$_POST['password']??'';
    if(!is_string($password) || !$hash || !password_verify($password,$hash)) {recordLoginAttempt($actor['email'],false);throw new DomainException('Ο κωδικός διαχειριστή δεν επιβεβαιώθηκε.');}
}
function onlineMaintenanceDB(): PDO {
    $user=defined('APP_MAINTENANCE_DB_USER') ? APP_MAINTENANCE_DB_USER : getenv('APP_MAINTENANCE_DB_USER');
    if(!$user)return getDB();
    $password=defined('APP_MAINTENANCE_DB_PASS') ? APP_MAINTENANCE_DB_PASS : getenv('APP_MAINTENANCE_DB_PASS');
    if($password===false)throw new DomainException('Λείπει ο ιδιωτικός κωδικός λογαριασμού συντήρησης.');
    return new PDO('mysql:host='.DB_HOST.';'.(defined('DB_PORT')?'port='.DB_PORT.';':'').'dbname='.DB_NAME.';charset=utf8mb4',$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false]);
}
try {$manager=new AbsencesLifecycle($root,lifecycleStorage($root),onlineMaintenanceDB(),DB_NAME,defined('DB_PREFIX')?DB_PREFIX:'');}catch(Throwable $e){$error=$e instanceof DomainException || $e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : 'Δεν είναι διαθέσιμη η ρύθμιση συντήρησης.';}
if($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if((int)($_SERVER['CONTENT_LENGTH']??0)>$uploadLimit)throw new DomainException('Το αίτημα υπερβαίνει το όριο μεταφόρτωσης του server. Χρησιμοποιήστε μικρότερο πακέτο.');
        if(!verifyCsrfToken($_POST['csrf_token']??''))throw new DomainException('Μη έγκυρο αίτημα ασφαλείας.');
        $action=$_POST['action']??'';
        if(!is_string($action))throw new DomainException('Μη έγκυρη ενέργεια.');
        if(!in_array($action,['github_check','github_stage'],true))onlineMaintenancePassword($actor);
        if($action==='setup') {
            if($manager || defined('APP_MAINTENANCE_DIR') || getenv('APP_MAINTENANCE_DIR'))throw new DomainException('Η αποθήκευση έχει ήδη ρυθμιστεί. Αλλαγή μόνο από τον υπεύθυνο εγκατάστασης.');
            $path=$_POST['private_path']??'';
            if(!is_string($path) || !is_dir($path))throw new DomainException('Ο ιδιωτικός φάκελος πρέπει να υπάρχει ήδη και να είναι εγγράψιμος.');
            $candidate=new AbsencesLifecycle($root,realpath($path),onlineMaintenanceDB(),DB_NAME,defined('DB_PREFIX')?DB_PREFIX:'');
            $candidate->inventory();
            $setupLock=fopen($root.'/config/maintenance.setup.lock','c+b');
            if(!$setupLock || !flock($setupLock,LOCK_EX|LOCK_NB))throw new DomainException('Μια άλλη αρχική ρύθμιση εκτελείται ήδη.');
            $temporary=$root.'/config/.maintenance-'.bin2hex(random_bytes(12)).'.tmp';
            try {
                if(is_file($root.'/config/maintenance.php'))throw new DomainException('Η τοπική ρύθμιση υπάρχει ήδη.');
                $source="<?php\n// Local installation setting; exclude from deployment packages.\ndefine('APP_MAINTENANCE_DIR', ".var_export(realpath($path),true).");\n";
                if(file_put_contents($temporary,$source,LOCK_EX)!==strlen($source))throw new RuntimeException('Δεν ολοκληρώθηκε η τοπική ρύθμιση.');
                chmod($temporary,0600);
                if(!rename($temporary,$root.'/config/maintenance.php'))throw new RuntimeException('Δεν αποθηκεύτηκε η τοπική ρύθμιση.');
            } finally {if(is_file($temporary))unlink($temporary);flock($setupLock,LOCK_UN);fclose($setupLock);}
            header('Location: maintenance.php');exit;
        }
        if(!$manager)throw new DomainException('Προηγείται ρύθμιση ιδιωτικού φακέλου.');
        $state=$manager->read('state.json');
        if($state && !in_array($action,['download','download_digest','cancel','uninstall'],true))throw new DomainException('Ολοκληρώστε ή ακυρώστε πρώτα την εκκρεμή συντήρηση.');
        if($action==='github_check') {
            $_SESSION['github_updates']=AbsencesGitHubUpdates::check(APP_VERSION);
            $message='Ο έλεγχος GitHub ολοκληρώθηκε.';
        } elseif($action==='github_stage') {
            if(!is_file($manager->store.'/release-public-key.txt'))throw new DomainException('Ορίστε πρώτα το έμπιστο δημόσιο κλειδί εκδόσεων.');
            // Refresh metadata server-side; never accept a download URL from POST/session cache.
            $check=AbsencesGitHubUpdates::check(APP_VERSION);$_SESSION['github_updates']=$check;
            $release=$check['available'];
            if(!$release)throw new DomainException('Δεν υπάρχει συμβατό υπογεγραμμένο πακέτο ενημέρωσης.');
            if(!hash_equals($release['version'],is_string($_POST['target_version']??null)?$_POST['target_version']:''))throw new DomainException('Η διαθέσιμη έκδοση άλλαξε. Ελέγξτε ξανά τις πληροφορίες.');
            $id=bin2hex(random_bytes(16));$file=$manager->store.'/package-'.$id.'.zip';
            AbsencesGitHubUpdates::download($release,$file);
            try {
                $manifest=$manager->verifyPackage($file,APP_VERSION);
                if($manifest['version']!==$release['version'])throw new DomainException('Η υπογεγραμμένη έκδοση διαφέρει από το GitHub Release.');
                $_SESSION['pending_update']=['id'=>$id,'hash'=>hash_file('sha256',$file),'manifest'=>$manifest,'origin'=>'github'];
            } catch(Throwable $e) {if(is_file($file))unlink($file);throw $e;}
            $message='Το πακέτο κατέβηκε και επαληθεύτηκε. Εξετάστε την προεπισκόπηση στην κάρτα GitHub και επιβεβαιώστε την εγκατάσταση.';
        } elseif($action==='trust_key') {
            if(($_POST['confirm']??'')!=='ΕΜΠΙΣΤΕΥΟΜΑΙ ΤΟ ΚΛΕΙΔΙ')throw new DomainException('Επιβεβαιώστε ρητά το κλειδί εκδότη.');
            $encoded=trim($_POST['public_key']??'');$key=base64_decode($encoded,true);
            if($key===false || strlen($key)!==32)throw new DomainException('Απαιτείται δημόσιο κλειδί Ed25519 σε base64.');
            $manager->setPublicKey($encoded,$actor['id']);
            unset($_SESSION['pending_update']);$message='Το δημόσιο κλειδί εκδόσεων αποθηκεύτηκε.';
        } elseif($action==='stage') {
            if(!isset($_FILES['package']) || $_FILES['package']['error']!==UPLOAD_ERR_OK || !is_uploaded_file($_FILES['package']['tmp_name']))throw new DomainException('Επιλέξτε έγκυρο πακέτο ενημέρωσης.');
            $manifest=$manager->verifyPackage($_FILES['package']['tmp_name'],APP_VERSION);
            $id=bin2hex(random_bytes(16));$file=$manager->store.'/package-'.$id.'.zip';
            if(!move_uploaded_file($_FILES['package']['tmp_name'],$file))throw new RuntimeException('Δεν αποθηκεύτηκε το πακέτο.');chmod($file,0600);
            $_SESSION['pending_update']=['id'=>$id,'hash'=>hash_file('sha256',$file),'manifest'=>$manifest];
            $message='Το πακέτο ελέγχθηκε. Εξετάστε τις αλλαγές πριν από την εφαρμογή.';
        } elseif($action==='update' || $action==='github_install') {
            if($action==='update' && ($_POST['confirm']??'')!=='ΕΝΗΜΕΡΩΣΗ')throw new DomainException('Πληκτρολογήστε ΕΝΗΜΕΡΩΣΗ.');
            $pending=$_SESSION['pending_update']??null;
            if($action==='github_install' && (($pending['origin']??'')!=='github' || !is_string($_POST['target_version']??null) || $_POST['target_version']!==($pending['manifest']['version']??null)))throw new DomainException('Η προεπισκόπηση άλλαξε. Ελέγξτε ξανά το πακέτο GitHub.');
            if($action==='update' && ($pending['origin']??'')==='github')throw new DomainException('Χρησιμοποιήστε την επιβεβαίωση στην κάρτα GitHub.');
            if(!$pending || !preg_match('/^[a-f0-9]{32}$/D',$pending['id']))throw new DomainException('Προηγείται έλεγχος πακέτου.');
            $file=$manager->store.'/package-'.$pending['id'].'.zip';
            if(!is_file($file) || !hash_equals($pending['hash'],hash_file('sha256',$file)))throw new DomainException('Το ελεγμένο πακέτο άλλαξε.');
            $backup=$manager->update($file,APP_VERSION,$actor['id']);$_SESSION['lifecycle_backup']=$backup['id'];unset($_SESSION['pending_update']);
            $message='Η ενημέρωση ολοκληρώθηκε. Το προηγούμενο αντίγραφο είναι διαθέσιμο για λήψη. Ανανεώστε τη σελίδα για τη νέα έκδοση.';
        } elseif($action==='backup') {
            $backup=$manager->createBackup(APP_VERSION,$actor['id']);$_SESSION['lifecycle_backup']=$backup['id'];$message='Δημιουργήθηκε και επαληθεύτηκε το αντίγραφο.';
        } elseif($action==='restore_preview') {
            $uploaded=isset($_FILES['restore_file']) && $_FILES['restore_file']['error']!==UPLOAD_ERR_NO_FILE;
            $id=$_POST['restore_id']??'';
            if($uploaded) {
                if($id!=='')throw new DomainException('Επιλέξτε είτε υπάρχον αντίγραφο είτε μεταφόρτωση αρχείου.');
                if($_FILES['restore_file']['error']!==UPLOAD_ERR_OK || !is_uploaded_file($_FILES['restore_file']['tmp_name']))throw new DomainException('Δεν μεταφορτώθηκε πλήρες backup.');
                $id=$manager->importBackup($_FILES['restore_file']['tmp_name']);
            }
            $preview=$manager->verifyRestoreBackup($id);
            $_SESSION['restore_preview']=['id'=>$id,'sha256'=>$preview['sha256'],'summary'=>['created'=>$preview['created'],'version'=>$preview['version'],'tables'=>$preview['tables'],'restore_files_count'=>count($preview['restore_files'])]];
            $message='Το backup ελέγχθηκε. Δεν έγινε ακόμα αντικατάσταση δεδομένων.';
        } elseif($action==='restore') {
            if(($_POST['confirm']??'')!=='ΑΝΤΙΚΑΤΑΣΤΑΣΗ ΔΕΔΟΜΕΝΩΝ' || empty($_POST['replace_ack']))throw new DomainException('Επιβεβαιώστε την αντικατάσταση νεότερων δεδομένων και πληκτρολογήστε ΑΝΤΙΚΑΤΑΣΤΑΣΗ ΔΕΔΟΜΕΝΩΝ.');
            $preview=$_SESSION['restore_preview']??null;
            if(!$preview)throw new DomainException('Προηγείται έλεγχος και προεπισκόπηση backup.');
            $result=$manager->restoreBackup($preview['id'],$actor['id'],APP_VERSION,$preview['sha256']);
            $_SESSION=[];session_destroy();
            echo '<!DOCTYPE html><html lang="el"><meta charset="utf-8"><title>Επαναφορά Absences</title><p>Η επαναφορά ολοκληρώθηκε. Δημιουργήθηκε πρώτα νέο αντίγραφο ασφαλείας. Οι παλιές συνεδρίες ακυρώθηκαν.</p><p><a href="../index.php">Σύνδεση με λογαριασμό του επιλεγμένου backup</a></p></html>';exit;
        } elseif($action==='prepare_uninstall') {
            $backup=$manager->prepareUninstall(APP_VERSION,$actor['id']);$_SESSION['lifecycle_backup']=$backup['id'];
            $message='Η εφαρμογή μπήκε σε συντήρηση. Κατεβάστε το backup πριν από οποιαδήποτε διαγραφή.';
        } elseif($action==='download' || $action==='download_digest') {
            $id=$_POST['backup_id']??'';$meta=$manager->backupMeta($id);
            if($action==='download_digest') {
                session_write_close();header('Content-Type: text/plain; charset=utf-8');header('Content-Disposition: attachment; filename="absences-backup-'.$id.'.zip.sha256"');header('Cache-Control: no-store, private');echo $meta['sha256'].'  absences-backup-'.$id.'.zip'."\n";exit;
            }
            $_SESSION['lifecycle_downloaded'][$id]=$meta['sha256'];
            session_write_close();header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="absences-backup-'.$id.'.zip"');header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');readfile($manager->store.'/'.$id.'.zip');exit;
        } elseif($action==='cancel') {
            $manager->cancel($actor['id']);$message='Η απεγκατάσταση ακυρώθηκε και η εφαρμογή λειτουργεί ξανά.';
        } elseif($action==='uninstall') {
            if(($_POST['confirm']??'')!=='ΟΡΙΣΤΙΚΗ ΔΙΑΓΡΑΦΗ' || empty($_POST['backup_saved']))throw new DomainException('Απαιτείται επιβεβαίωση λήψης backup και η φράση ΟΡΙΣΤΙΚΗ ΔΙΑΓΡΑΦΗ.');
            $id=$_POST['backup_id']??'';$meta=$manager->backupMeta($id);
            if(!hash_equals($meta['sha256'],$_SESSION['lifecycle_downloaded'][$id]??''))throw new DomainException('Κατεβάστε πρώτα το επαληθευμένο αντίγραφο από αυτή τη συνεδρία.');
            $manager->uninstall($id,$actor['id']);$_SESSION=[];session_destroy();
            echo '<!DOCTYPE html><html lang="el"><meta charset="utf-8"><title>Απεγκατάσταση Absences</title><p>Η απεγκατάσταση ολοκληρώθηκε. Διαγράφηκαν μόνο οι αναγνωρισμένοι πίνακες της εγκατάστασης. Τα αρχεία και τα ιδιωτικά αντίγραφα διατηρήθηκαν.</p></html>';exit;
        } else throw new DomainException('Μη έγκυρη ενέργεια.');
        $error='';
    }catch(Throwable $e){$error=$e instanceof DomainException ? $e->getMessage() : 'Η ενέργεια δεν ολοκληρώθηκε. Ελέγξτε την κατάσταση συντήρησης και το ιδιωτικό αρχείο καταγραφής.';if(in_array($action??'',['github_check','github_stage'],true))$_SESSION['github_updates']=['checked_at'=>time(),'current'=>APP_VERSION,'available'=>null,'failed'=>true,'error'=>$error];error_log('Absences maintenance: '.get_class($e).' code='.$e->getCode());}
}
if($manager){try{$tables=$manager->inventory();$drop=$manager->canDrop();$state=$manager->read('state.json');}catch(Throwable $e){$error=$e instanceof DomainException?$e->getMessage():'Δεν ολοκληρώθηκε ο έλεγχος εγκατάστασης.';}}
// At most once per day per admin session when opening this page. No unattended installation.
$githubCheck=$_SESSION['github_updates']??null;$githubError='';
if($manager && !$state && (!$githubCheck || ($githubCheck['current']??'')!==APP_VERSION || time()-($githubCheck['checked_at']??0)>86400)) {
    try {$_SESSION['github_updates']=AbsencesGitHubUpdates::check(APP_VERSION);}
    catch(Throwable $e) {$_SESSION['github_updates']=['checked_at'=>time(),'current'=>APP_VERSION,'available'=>null,'failed'=>true];$githubError=$e instanceof DomainException?$e->getMessage():'Ο έλεγχος ενημερώσεων δεν ολοκληρώθηκε.';$_SESSION['github_updates']['error']=$githubError;}
}
$githubCheck=$_SESSION['github_updates']??null;
$escape=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$backupId=$_SESSION['lifecycle_backup']??null;
$pending=$_SESSION['pending_update']['manifest']??null;
$pendingOrigin=$_SESSION['pending_update']['origin']??'manual';
$restorePreview=null;$restoreChoices=[];
if($manager){$restoreChoices=$manager->listBackups();$restorePreview=$_SESSION['restore_preview']['summary']??null;}
$phaseLabels=['backup'=>'Δημιουργία αντιγράφου','updating'=>'Εφαρμογή ενημέρωσης','restoring'=>'Επαναφορά από backup','preparing_uninstall'=>'Προετοιμασία απεγκατάστασης','uninstall_prepared'=>'Αναμονή λήψης backup και τελικής επιβεβαίωσης','recovery_required'=>'Απαιτείται ανάκτηση από τον υπεύθυνο εγκατάστασης'];
$pageTitle='Ενημερώσεις & Απεγκατάσταση';require __DIR__.'/../admin/header.php';
?>
<style>.maintenance-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,330px),1fr));gap:18px}.maintenance-card{padding:22px}.maintenance-card h2{font-size:18px;margin-bottom:12px}.maintenance-card p{line-height:1.6}.maintenance-card label{display:block;margin-top:12px}.maintenance-card button{margin-top:12px}.maintenance-card input:not([type=checkbox]),.maintenance-card textarea{width:100%;max-width:100%}.maintenance-card code{overflow-wrap:anywhere}.maintenance-alert{padding:16px;margin-bottom:16px;border:1px solid var(--border);border-radius:10px}</style>
<h1 style="font-size:24px;margin-bottom:16px">Ενημερώσεις &amp; Απεγκατάσταση</h1>
<p>Έκδοση <?= $escape(APP_VERSION) ?> · Μόνο για διαχειριστές συστήματος. Κάθε ενέργεια απαιτεί ξανά τον προσωπικό σας κωδικό.</p>
<?php if($error):?><p role="alert" class="maintenance-alert"><?= $escape($error) ?></p><?php endif;?>
<?php if($message):?><p role="status" class="maintenance-alert"><?= $escape($message) ?></p><?php endif;?>
<?php if($state):?><p class="maintenance-alert">Η εφαρμογή βρίσκεται σε συντήρηση. <?= $escape($phaseLabels[$state['phase']] ?? 'Απαιτείται έλεγχος από τον υπεύθυνο εγκατάστασης') ?>. Οι καταχωρήσεις των χρηστών έχουν προσωρινά σταματήσει.</p><?php endif;?>
<div class="maintenance-grid">
<?php if(!$manager):?>
<section class="card maintenance-card"><h2>Αρχική ρύθμιση</h2><p>Δημιουργήστε ιδιωτικό φάκελο εκτός του δημόσιου καταλόγου του ιστοτόπου. Δεν πρέπει να είναι προσβάσιμος από URL. Η εφαρμογή δεν αλλάζει δικαιώματα βάσης ή λογαριασμούς hosting.</p>
<form method="post"><?= csrfField() ?><input type="hidden" name="action" value="setup"><label>Πλήρης διαδρομή ιδιωτικού φακέλου<input class="form-control" name="private_path" required></label><label>Κωδικός διαχειριστή<input class="form-control" type="password" name="password" autocomplete="current-password" required></label><button class="btn btn-primary">Έλεγχος και αποθήκευση ρύθμισης</button></form></section>
<?php else:?>
<section class="card maintenance-card"><h2>Ενημερώσεις από GitHub</h2>
<p>Πηγή: <a href="https://github.com/KolliasStavros/absences/releases" target="_blank" rel="noopener">KolliasStavros/absences</a>. Ο έλεγχος γίνεται κατά το άνοιγμα αυτής της σελίδας, έως μία φορά την ημέρα ανά συνεδρία. Η εγκατάσταση απαιτεί επιβεβαίωση.</p>
<?php if($githubCheck): ?><p>Τελευταία προσπάθεια: <?= $escape(date('d/m/Y H:i',(int)$githubCheck['checked_at'])) ?>.</p><?php endif; ?>
<?php if($githubError || !empty($githubCheck['failed'])): ?><p role="status"><?= $escape($githubError ?: ($githubCheck['error']??'Ο τελευταίος έλεγχος απέτυχε. Πατήστε Έλεγχος τώρα.')) ?></p>
<?php elseif(!empty($githubCheck['available'])): $available=$githubCheck['available']; ?><p>Διαθέσιμη ενημέρωση: <strong><?= $escape($available['version']) ?></strong>.</p><p><a href="<?= $escape($available['page']) ?>" target="_blank" rel="noopener">Πληροφορίες έκδοσης</a></p>
<form method="post"><?= csrfField() ?><input type="hidden" name="action" value="github_stage"><input type="hidden" name="target_version" value="<?= $escape($available['version']) ?>"><button class="btn btn-primary" <?= $state?'disabled':'' ?>>Λήψη και έλεγχος υπογραφής</button></form>
<?php else: ?><p>Δεν βρέθηκε νεότερο πακέτο συμβατό με την έκδοση <?= $escape(APP_VERSION) ?>. Ένα ZIP νέας εγκατάστασης δεν αποτελεί ενημέρωση.</p><?php endif; ?>
<form method="post"><?= csrfField() ?><input type="hidden" name="action" value="github_check"><button class="btn btn-secondary" <?= $state?'disabled':'' ?>>Έλεγχος τώρα</button></form>
<p>Πριν από τη λήψη απαιτείται ορισμός έμπιστου δημόσιου κλειδιού εκδότη στην αντίστοιχη ενότητα. Το κλειδί δεν λαμβάνεται αυτόματα από το GitHub. Ο κωδικός ζητείται μόνο στην τελική εγκατάσταση, στην ίδια κάρτα.</p><?php if($pending && $pendingOrigin==='github'):?><hr><p>Νέα έκδοση: <strong><?= $escape($pending['version']) ?></strong></p><p><?= $escape($pending['description']??'') ?></p><details><summary>Αρχεία και μεταβολές βάσης</summary><ul><?php foreach(array_keys($pending['files']??[]) as $path):?><li><?= $escape($path) ?></li><?php endforeach;?><?php foreach(($pending['delete']??[]) as $path):?><li>Αφαίρεση: <?= $escape($path) ?></li><?php endforeach;?><?php foreach(array_keys($pending['migrations']??[]) as $path):?><li>Βάση: <?= $escape($path) ?></li><?php endforeach;?></ul></details>
<form method="post"><?= csrfField() ?><input type="hidden" name="action" value="github_install"><input type="hidden" name="target_version" value="<?= $escape($pending['version']) ?>"><p>Με το επόμενο κουμπί επιβεβαιώνετε την εγκατάσταση της παραπάνω έκδοσης. Θα δημιουργηθεί πρώτα επαληθευμένο backup.</p><label>Κωδικός διαχειριστή<input class="form-control" type="password" name="password" id="githubInstallPassword" autocomplete="current-password" required></label><button class="btn btn-primary" <?= $state?'disabled':'' ?>>Backup και εγκατάσταση</button></form><?php endif;?></section>
<section class="card maintenance-card"><h2>Χειροκίνητη ενημέρωση από ZIP</h2><p>Ανεβάστε υπογεγραμμένο πακέτο ενημέρωσης Absences, έως <?= $escape(round($uploadLimit/1048576,1)) ?> MB σύμφωνα με τα όρια του server. Το πακέτο εγκατάστασης δεν είναι πακέτο ενημέρωσης.</p>
<form method="post" enctype="multipart/form-data"><?= csrfField() ?><input type="hidden" name="action" value="stage"><label>Πακέτο<input type="file" name="package" accept=".zip" required></label><label>Κωδικός διαχειριστή<input class="form-control" type="password" name="password" autocomplete="current-password" required></label><button class="btn btn-primary" <?= $state?'disabled':'' ?>>Έλεγχος πακέτου</button></form>
<?php if($pending && $pendingOrigin!=='github'):?><hr><p>Νέα έκδοση: <strong><?= $escape($pending['version']) ?></strong></p><p><?= $escape($pending['description']??'') ?></p><details><summary>Αρχεία και μεταβολές βάσης</summary><ul><?php foreach(array_keys($pending['files']??[]) as $path):?><li><?= $escape($path) ?></li><?php endforeach;?><?php foreach(($pending['delete']??[]) as $path):?><li>Αφαίρεση: <?= $escape($path) ?></li><?php endforeach;?><?php foreach(array_keys($pending['migrations']??[]) as $path):?><li>Βάση: <?= $escape($path) ?></li><?php endforeach;?></ul></details>
<form method="post"><?= csrfField() ?><input type="hidden" name="action" value="update"><label>Πληκτρολογήστε ΕΝΗΜΕΡΩΣΗ<input class="form-control" name="confirm" required></label><label>Κωδικός διαχειριστή<input class="form-control" type="password" name="password" autocomplete="current-password" required></label><button class="btn btn-primary" <?= $state?'disabled':'' ?>>Backup και εφαρμογή ενημέρωσης</button></form><?php endif;?></section>
<section class="card maintenance-card"><h2>Αντίγραφα ασφαλείας</h2><p>Backup αρχείων και πινάκων, με επαλήθευση αποτυπωμάτων. Περιέχει προσωπικά δεδομένα και τοπικές ρυθμίσεις: φυλάξτε το ιδιωτικά. Τα εξωτερικά ιδιωτικά configs και το κλειδί κρυπτογράφησης δεν περιλαμβάνονται· πρέπει να διατηρούνται χωριστά από τον υπεύθυνο εγκατάστασης.</p>
<form method="post"><?= csrfField() ?><input type="hidden" name="action" value="backup"><label>Κωδικός διαχειριστή<input class="form-control" type="password" name="password" autocomplete="current-password" required></label><button class="btn btn-secondary" <?= $state?'disabled':'' ?>>Δημιουργία backup</button></form>
<?php if($backupId):?><form method="post"><?= csrfField() ?><input type="hidden" name="backup_id" value="<?= $escape($backupId) ?>"><label>Κωδικός διαχειριστή<input class="form-control" type="password" name="password" autocomplete="current-password" required></label><button name="action" value="download" class="btn btn-primary">Λήψη επαληθευμένου backup</button><button name="action" value="download_digest" class="btn btn-secondary">Λήψη αποτυπώματος (.sha256)</button></form><?php endif;?></section>
<section class="card maintenance-card"><h2>Επαναφορά από backup</h2><p>Αντικαθίστανται τα δεδομένα και τα αρχεία εφαρμογής με εκείνα του επιλεγμένου αντιγράφου. Δεν συγχωνεύονται νεότερες απουσίες. Οι τοπικές ρυθμίσεις, τα κλειδιά και ο μηχανισμός ανάκτησης διατηρούνται.</p>
<form method="post" enctype="multipart/form-data"><?= csrfField() ?><input type="hidden" name="action" value="restore_preview">
<label>Υπάρχον ιδιωτικό αντίγραφο<select class="form-control" name="restore_id"><option value="">— Επιλέξτε αντίγραφο ή ανεβάστε αρχείο —</option><?php foreach($restoreChoices as $choice):?><option value="<?= $escape($choice['id']) ?>" <?= !$choice['available']?'disabled':'' ?>><?= $escape(date('d/m/Y H:i',(int)$choice['created']).' · έκδοση '.$choice['version'].(!$choice['available']?' · απαιτείται μεταφόρτωση':'')) ?></option><?php endforeach;?></select></label>
<label>Ή μεταφόρτωση καταγεγραμμένου backup<input type="file" name="restore_file" accept=".zip"></label>
<label>Κωδικός διαχειριστή<input class="form-control" type="password" name="password" autocomplete="current-password" required></label><button class="btn btn-secondary" <?= $state?'disabled':'' ?>>Έλεγχος και προεπισκόπηση backup</button></form>
<?php if($restorePreview):?><hr><p><strong>Επιλεγμένο αντίγραφο:</strong> <?= $escape(date('d/m/Y H:i',(int)$restorePreview['created'])) ?> · Έκδοση <?= $escape($restorePreview['version']) ?> · <?= count($restorePreview['tables']) ?> πίνακες · <?= (int)$restorePreview['restore_files_count'] ?> αρχεία.</p><p>Θα δημιουργηθεί νέο backup πριν την αντικατάσταση. Μετά απαιτείται σύνδεση με λογαριασμό και κωδικό που υπήρχαν στο επιλεγμένο αντίγραφο.</p>
<form method="post"><?= csrfField() ?><input type="hidden" name="action" value="restore"><label><input type="checkbox" name="replace_ack" value="1" required> Κατανοώ ότι οι νεότερες καταχωρήσεις θα αντικατασταθούν.</label><label>Πληκτρολογήστε ΑΝΤΙΚΑΤΑΣΤΑΣΗ ΔΕΔΟΜΕΝΩΝ<input class="form-control" name="confirm" required></label><label>Κωδικός διαχειριστή<input class="form-control" type="password" name="password" autocomplete="current-password" required></label><button class="btn btn-danger" <?= $state?'disabled':'' ?>>Νέο backup και επαναφορά</button></form><?php endif;?>
<p>Αν δεν λειτουργεί η σύνδεση ή έχει γίνει απεγκατάσταση, ο υπεύθυνος εγκατάστασης χρησιμοποιεί το ξεχωριστό εργαλείο ανάκτησης από γραμμή εντολών. Δεν υπάρχει δημόσια παράκαμψη σύνδεσης.</p></section>
<section class="card maintenance-card"><h2>Απεγκατάσταση</h2><p>Διαγράφονται οριστικά μόνο οι παρακάτω πίνακες. Η βάση δεδομένων, οι πίνακες άλλων εφαρμογών, τα αρχεία και τα ιδιωτικά αντίγραφα δεν διαγράφονται.</p><details><summary>Πίνακες εγκατάστασης (<?= count($tables) ?>)</summary><ul><?php foreach($tables as $table):?><li><code><?= $escape($table) ?></code></li><?php endforeach;?></ul></details>
<?php if(!$drop):?><p>Δεν υπάρχει δικαίωμα DROP. Ο υπεύθυνος hosting μπορεί να ορίσει ξεχωριστά διαπιστευτήρια συντήρησης στο ιδιωτικό config. Η κανονική λειτουργία παραμένει με τα περιορισμένα δικαιώματά της.</p><?php endif;?>
<?php if(($state['phase']??'')==='uninstall_prepared'):?>
<form method="post"><?= csrfField() ?><input type="hidden" name="action" value="uninstall"><input type="hidden" name="backup_id" value="<?= $escape($state['backup']) ?>"><label><input type="checkbox" name="backup_saved" value="1" required> Κατέβασα και φύλαξα το backup.</label><label>Πληκτρολογήστε ΟΡΙΣΤΙΚΗ ΔΙΑΓΡΑΦΗ<input class="form-control" name="confirm" required></label><label>Κωδικός διαχειριστή<input class="form-control" type="password" name="password" autocomplete="current-password" required></label><button class="btn btn-danger">Οριστική διαγραφή πινάκων Absences</button></form>
<form method="post"><?= csrfField() ?><input type="hidden" name="action" value="cancel"><label>Κωδικός διαχειριστή<input class="form-control" type="password" name="password" autocomplete="current-password" required></label><button class="btn btn-secondary">Ακύρωση και επιστροφή σε λειτουργία</button></form>
<?php else:?><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="prepare_uninstall"><label>Κωδικός διαχειριστή<input class="form-control" type="password" name="password" autocomplete="current-password" required></label><button class="btn btn-danger" <?= !$drop||$state?'disabled':'' ?>>Προετοιμασία backup και απεγκατάστασης</button></form><?php endif;?></section>
<section class="card maintenance-card"><h2>Έμπιστος εκδότης ενημερώσεων</h2><p>Καταχωρήστε μόνο το δημόσιο κλειδί που λάβατε από τον υπεύθυνο εκδόσεων μέσω έμπιστου καναλιού. Όποιος κατέχει το αντίστοιχο ιδιωτικό κλειδί μπορεί να εκδώσει κώδικα για αυτή την εγκατάσταση. Ποτέ μην ανεβάζετε το ιδιωτικό κλειδί.</p>
<form method="post"><?= csrfField() ?><input type="hidden" name="action" value="trust_key"><label>Δημόσιο κλειδί Ed25519 (base64)<textarea class="form-control" name="public_key" required></textarea></label><label>Πληκτρολογήστε ΕΜΠΙΣΤΕΥΟΜΑΙ ΤΟ ΚΛΕΙΔΙ<input class="form-control" name="confirm" required></label><label>Κωδικός διαχειριστή<input class="form-control" type="password" name="password" autocomplete="current-password" required></label><button class="btn btn-secondary" <?= $state?'disabled':'' ?>>Ορισμός έμπιστου εκδότη</button></form></section>
<?php endif;?></div>
<?php require __DIR__.'/../admin/footer.php'; ?>
