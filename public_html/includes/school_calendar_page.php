<?php
// SPDX-License-Identifier: GPL-3.0-only
require_once __DIR__.'/school_calendar.php';
require_once __DIR__.'/school_calendar_holidays.php';
$db=getDB(); $user=getCurrentUser(); $activeAcademicYear=getActiveAcademicYear();
$schoolName=getSchoolSetting('school_name','Σχολείο'); $pageTitle='Ημερολόγιο σχολείου';
$h=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');
$ready=calendarReady($db); $manager=$ready&&calendarCanManage($db,$user); $admin=$user['role']==='admin';
$years=$db->query('SELECT id,name,is_active FROM academic_years ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
$yearId=schoolOptionalRequestId($_GET['year_id']??null)?:((int)$activeAcademicYear['id']);
if(!in_array($yearId,array_map('intval',array_column($years,'id')),true)) $yearId=(int)$activeAcademicYear['id'];
$editable=$manager&&$yearId===(int)$activeAcademicYear['id'];
$month=is_string($_GET['month']??null)&&preg_match('/^[1-9][0-9]{3}-(0[1-9]|1[0-2])$/D',$_GET['month'])?$_GET['month']:date('Y-m');
$first=new DateTimeImmutable($month.'-01'); $last=$first->modify('last day of this month');
$view=is_string($_GET['view']??null)&&in_array($_GET['view'],['month','agenda','edit','detail','access'],true)?$_GET['view']:'month';
$category=is_string($_GET['category']??null)&&isset(calendarCategories()[$_GET['category']])?$_GET['category']:'';
$status=is_string($_GET['status']??null)&&isset(calendarStatuses()[$_GET['status']])?$_GET['status']:'';
$url=static fn(array $extra=[])=>'calendar.php?'.http_build_query(array_merge(['year_id'=>$yearId,'month'=>$month,'view'=>$view,'category'=>$category,'status'=>$status],$extra));
$error=''; $message=$_SESSION['calendar_flash']??''; unset($_SESSION['calendar_flash']);
$event=null; $failedInput=null;
$modalPost=str_contains($_SERVER['HTTP_ACCEPT']??'','application/json');
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    try {
        if(!$ready) throw new DomainException('Το ημερολόγιο δεν έχει ενεργοποιηθεί ακόμη.');
        if(!verifyCsrfToken($_POST['csrf_token']??'')) throw new DomainException('Μη έγκυρο αίτημα ασφαλείας. Ανανεώστε τη σελίδα.');
        if(!$editable||schoolRequestId($_POST['year_id']??null)!==$yearId) throw new DomainException('Η αλλαγή δεν επιτρέπεται σε αυτό το σχολικό έτος.');
        $action=$_POST['action']??null;
        if($action==='save') {
            $id=calendarSave($db,$yearId,$user,$_POST); $message='Η καταχώριση αποθηκεύτηκε.';
            $redirect=$url(['view'=>'detail','event'=>$id]);
        } elseif($action==='delete') {
            $id=schoolRequestId($_POST['id']??null); $version=schoolRequestId($_POST['version']??null);
            if(!$id||!$version) throw new DomainException('Μη έγκυρη καταχώριση.');
            calendarDelete($db,$yearId,$user,$id,$version); $message='Η καταχώριση διαγράφηκε.'; $redirect=$url(['view'=>'month']);
        } elseif($action==='manager') {
            $target=schoolRequestId($_POST['user_id']??null);
            if(!$target||!in_array($_POST['enabled']??null,['0','1'],true)) throw new DomainException('Μη έγκυρη επιλογή.');
            calendarSetManager($db,$yearId,$user,$target,$_POST['enabled']==='1'); $message='Τα δικαιώματα ημερολογίου ενημερώθηκαν.'; $redirect=$url(['view'=>'access']);
        } else throw new DomainException('Μη υποστηριζόμενη ενέργεια.');
        if($modalPost) schoolJsonResponse(['url'=>$redirect,'message'=>$message]);
        $_SESSION['calendar_flash']=$message; header('Location: '.$redirect,true,303); exit;
    } catch(DomainException $e) { $error=$e->getMessage(); }
    catch(Throwable $e) { $error=safeError($e); }
    if($modalPost) schoolJsonResponse(['error'=>$error],422);
    if(($_POST['action']??null)==='save'&&$editable) { $failedInput=$_POST; $view='edit'; }
}
$eventId=schoolRequestId($_GET['event']??null);
if($ready&&in_array($view,['edit','detail'],true)) {
    try {
        if($view==='edit'&&!$editable) throw new DomainException('Δεν έχετε δικαίωμα επεξεργασίας σε αυτό το σχολικό έτος.');
        if($eventId) $event=calendarEvent($db,$yearId,$eventId,$user);
        elseif($view==='detail') throw new DomainException('Η καταχώριση δεν είναι διαθέσιμη.');
    } catch(DomainException $e) { $error=$e->getMessage(); $view='month'; }
}
if($view==='access'&&!$admin) { http_response_code(403); $error='Η διαχείριση δικαιωμάτων επιτρέπεται μόνο σε διαχειριστή.'; $view='month'; }
$people=$ready&&$manager?$db->query("SELECT id,name FROM users WHERE status='active' AND role IN ('teacher','teacher_admin') ORDER BY name,id")->fetchAll(PDO::FETCH_ASSOC):[];
$events=[];
if($ready&&in_array($view,['month','agenda'],true)) {
    $events=calendarEvents($db,$yearId,$user,$view==='agenda'?date('Y-m-d'):$first->format('Y-m-d'),$last->format('Y-m-d'),$view==='agenda');
    $events=array_values(array_filter($events,fn($e)=>($category===''||$e['category']===$category)&&($status===''||$e['status']===$status)));
}
$monthNames=[1=>'Ιανουάριος','Φεβρουάριος','Μάρτιος','Απρίλιος','Μάιος','Ιούνιος','Ιούλιος','Αύγουστος','Σεπτέμβριος','Οκτώβριος','Νοέμβριος','Δεκέμβριος'];
$dateLabel=static fn($date)=>(new DateTimeImmutable($date))->format('d/m/Y');
if($calendarAdminLayout) { require __DIR__.'/../admin/header.php'; }
else { $calendarNavigationActive=true; ?>
<!DOCTYPE html><html lang="el"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Ημερολόγιο σχολείου · <?= $h($schoolName) ?></title><link rel="stylesheet" href="../assets/css/style.css?v=daylight-20261029-1"><script src="../assets/js/theme.js?v=20261029-1"></script><?php require __DIR__.'/diagnostics_client.php'; ?></head><body>
<?php require __DIR__.'/../teacher/navbar.php'; ?><div class="main-container"><?php } ?>
<link rel="stylesheet" href="../assets/css/school-calendar.css?v=6">
<script src="../assets/js/school-calendar.js?v=5" defer></script>
<link rel="stylesheet" href="../assets/css/school-calendar-print.css?v=2">
<script src="../assets/js/school-calendar-print.js?v=2" defer></script>
<main class="school-calendar">
<div class="cal-heading"><div><h1>📅 Ημερολόγιο σχολείου</h1><p>Προγραμματισμός, εκκρεμότητες και όσα έγιναν.</p></div><?php if($editable): ?><a class="btn btn-primary" data-calendar-modal href="<?= $h($url(['view'=>'edit','event'=>null])) ?>">＋ Νέα καταχώριση</a><?php endif; ?></div>
<?php if($message): ?><p class="cal-notice" role="status"><?= $h($message) ?></p><?php endif; ?>
<?php if($error): ?><p class="cal-notice cal-error" role="alert"><?= $h($error) ?></p><?php endif; ?>
<?php if(!$ready): ?><section class="card"><p>Το ημερολόγιο δεν έχει ενεργοποιηθεί ακόμη. Επικοινωνήστε με τον υπεύθυνο εγκατάστασης.</p></section><?php else: ?>
<nav class="cal-toolbar" aria-label="Προβολές ημερολογίου"><a class="btn <?= $view==='month'?'btn-primary':'btn-secondary' ?>" href="<?= $h($url(['view'=>'month'])) ?>">Μήνας</a><a class="btn <?= $view==='agenda'?'btn-primary':'btn-secondary' ?>" href="<?= $h($url(['view'=>'agenda'])) ?>">Προσεχώς / Εκκρεμότητες</a><?php if($admin): ?><a class="btn <?= $view==='access'?'btn-primary':'btn-secondary' ?>" href="<?= $h($url(['view'=>'access'])) ?>">Δικαιώματα γραμματείας</a><?php endif; ?><?php if(in_array($view,['month','agenda','detail'],true)): ?><button type="button" class="btn btn-secondary" style="margin-left:auto" data-calendar-print>🖨️ Εκτύπωση καταχωρίσεων</button><?php endif; ?></nav>
<form method="get" class="cal-filters"><input type="hidden" name="view" value="<?= $h(in_array($view,['month','agenda','access'],true)?$view:'month') ?>"><label>Σχολικό έτος<select class="form-control" name="year_id"><?php foreach($years as $year): ?><option value="<?= (int)$year['id'] ?>" <?= $yearId===(int)$year['id']?'selected':'' ?>><?= $h($year['name']) ?></option><?php endforeach; ?></select></label><label>Μήνας<input class="form-control" type="month" name="month" value="<?= $h($month) ?>" required></label><label>Κατηγορία<select name="category" class="form-control"><option value="">Όλες</option><?php foreach(calendarCategories() as $key=>$label): ?><option value="<?= $key ?>" <?= $category===$key?'selected':'' ?>><?= $h($label) ?></option><?php endforeach; ?></select></label><label>Κατάσταση<select name="status" class="form-control"><option value="">Όλες</option><?php foreach(calendarStatuses() as $key=>$label): ?><option value="<?= $key ?>" <?= $status===$key?'selected':'' ?>><?= $h($label) ?></option><?php endforeach; ?></select></label><button class="btn btn-secondary">Προβολή</button></form>
<?php if($yearId!==(int)$activeAcademicYear['id']): ?><p class="cal-notice">Αρχείο προηγούμενου σχολικού έτους · μόνο ανάγνωση.</p><?php endif; ?>
<?php if($view==='month'): ?>
<section class="card"><div class="cal-month-title"><a class="btn btn-secondary" aria-label="Προηγούμενος μήνας" href="<?= $h($url(['month'=>$first->modify('-1 month')->format('Y-m')])) ?>">‹</a><h2><?= $h($monthNames[(int)$first->format('n')].' '.$first->format('Y')) ?></h2><a class="btn btn-secondary" aria-label="Επόμενος μήνας" href="<?= $h($url(['month'=>$first->modify('+1 month')->format('Y-m')])) ?>">›</a><a href="<?= $h($url(['month'=>date('Y-m')])) ?>">Σήμερα</a></div>
<div class="cal-day-legend"><span class="cal-legend-weekend">Σαββατοκύριακο</span><span class="cal-legend-holiday">Σχολική αργία / διακοπές 2026–2027</span></div><div class="cal-scroll"><div class="cal-grid"><?php foreach(['Δευ','Τρι','Τετ','Πέμ','Παρ','Σάβ','Κυρ'] as $day): ?><div class="cal-weekday"><?= $day ?></div><?php endforeach; ?>
<?php for($i=1;$i<(int)$first->format('N');$i++): ?><div class="cal-day cal-blank" aria-hidden="true"></div><?php endfor; ?>
<?php for($d=1;$d<=(int)$last->format('j');$d++): $date=$month.'-'.str_pad((string)$d,2,'0',STR_PAD_LEFT); $holiday=calendarSchoolHoliday($date); $weekend=(int)(new DateTimeImmutable($date))->format('N')>=6; ?>
<div class="cal-day <?= $date===date('Y-m-d')?'cal-today ':'' ?><?= $weekend?'cal-weekend ':'' ?><?= $holiday?'cal-holiday':'' ?>"><div class="cal-day-heading"><strong class="cal-day-number"><?= $d ?></strong><?php if($editable): ?><a class="cal-day-add" data-calendar-modal href="<?= $h($url(['view'=>'edit','event'=>null,'date'=>$date])) ?>" aria-label="<?= $h('Νέα καταχώριση στις '.$dateLabel($date)) ?>" title="Νέα καταχώριση">+</a><?php endif; ?></div><?php if($holiday): ?><div class="cal-holiday-label"><?= $h($holiday) ?></div><?php endif; ?><?php foreach($events as $item): if($item['start_date']>$date||$item['end_date']<$date) continue; ?><a class="cal-event cal-<?= $h($item['category']) ?> <?= $item['status']==='cancelled'?'cal-cancelled':'' ?>" href="<?= $h($url(['view'=>'detail','event'=>$item['id']])) ?>"><?php if($item['start_time']&&$item['start_date']===$date): ?><small><?= $h(substr($item['start_time'],0,5)) ?></small> <?php endif; ?><?= $h($item['title']) ?><small class="cal-status cal-status-<?= $h($item['status']) ?>"><?= $h(calendarStatuses()[$item['status']]) ?></small></a><?php endforeach; ?></div>
<?php endfor; ?></div></div><?php if(!$events): ?><p class="cal-empty">Δεν υπάρχουν καταχωρίσεις με αυτά τα φίλτρα.</p><?php endif; ?></section>
<?php elseif($view==='agenda'): ?>
<section class="card"><h2>Προσεχώς / Εκκρεμότητες</h2><p class="cal-muted">Προγραμματισμένα από σήμερα και ανοιχτές εργασίες ή εκκρεμότητες, ακόμη και αν πέρασε η προθεσμία τους. Η λίστα αφορά όλο το επιλεγμένο σχολικό έτος.</p><?php if(!$events): ?><p class="cal-empty">Δεν υπάρχουν προσεχείς καταχωρίσεις ή ανοιχτές εκκρεμότητες.</p><?php endif; ?>
<?php foreach($events as $item): $overdue=$item['end_date']<date('Y-m-d'); ?><a class="cal-agenda-item <?= $overdue?'cal-overdue':'' ?>" href="<?= $h($url(['view'=>'detail','event'=>$item['id']])) ?>"><span class="cal-agenda-date"><?= $h($dateLabel($item['start_date'])) ?><?php if($item['start_date']!==$item['end_date']): ?> – <?= $h($dateLabel($item['end_date'])) ?><?php endif; ?><?php if($item['start_time']): ?><small><?= $h(substr($item['start_time'],0,5)) ?></small><?php endif; ?></span><span><strong><?= $h($item['title']) ?></strong><small><?= $h(calendarCategories()[$item['category']]) ?> · <span class="cal-status cal-status-<?= $h($item['status']) ?>"><?= $h(calendarStatuses()[$item['status']]) ?></span><?= $overdue?' · Εκπρόθεσμο':'' ?></small></span></a><?php endforeach; ?></section>
<?php elseif($view==='detail'&&$event): ?>
<article class="card cal-detail"><div class="cal-heading"><h2><?= $h($event['title']) ?></h2><?php if($editable): ?><a class="btn btn-primary" data-calendar-modal href="<?= $h($url(['view'=>'edit','event'=>$event['id']])) ?>">Επεξεργασία</a><?php endif; ?></div><p><?= $h(calendarCategories()[$event['category']]) ?> · <span class="cal-status cal-status-<?= $h($event['status']) ?>"><?= $h(calendarStatuses()[$event['status']]) ?></span></p><p><strong><?= $h($dateLabel($event['start_date'])) ?><?= $event['start_date']!==$event['end_date']?' – '.$h($dateLabel($event['end_date'])):'' ?></strong> <?= $event['start_time']?$h(substr($event['start_time'],0,5)):'' ?><?= $event['end_time']?' – '.$h(substr($event['end_time'],0,5)):'' ?></p><div class="cal-prose"><?= $h($event['description']) ?></div>
<?php if($manager): ?><hr><p><strong>Ορατότητα:</strong> <?= $h(calendarVisibilities()[$event['visibility']]) ?><?php if($event['visibility']==='selected'): ?> · <?= $h(implode(', ',array_column(array_filter($people,fn($p)=>in_array((int)$p['id'],$event['readers'],true)),'name'))) ?><?php endif; ?></p><div class="cal-private"><h3>🔒 Εσωτερικές σημειώσεις γραμματείας</h3><div class="cal-prose"><?= $event['internal_notes']!==''?$h($event['internal_notes']):'Δεν υπάρχουν εσωτερικές σημειώσεις.' ?></div></div>
<details class="cal-history"><summary>Ιστορικό αλλαγών</summary><?php $q=$db->prepare('SELECT a.action,a.created_at,u.name FROM calendar_audit_log a JOIN users u ON u.id=a.changed_by WHERE a.event_id=? ORDER BY a.id DESC LIMIT 100'); $q->execute([$event['id']]); foreach($q->fetchAll(PDO::FETCH_ASSOC) as $audit): ?><p><?= $h($audit['created_at'].' · '.$audit['name'].' · '.(['create'=>'Δημιουργία','update'=>'Επεξεργασία','delete'=>'Διαγραφή'][$audit['action']]??$audit['action'])) ?></p><?php endforeach; ?></details><?php endif; ?></article>
<?php elseif($view==='edit'&&$editable):
$newDate=schoolRequestDate($_GET['date']??null)??date('Y-m-d');
$form=$failedInput??$event??['id'=>'','version'=>'','title'=>'','description'=>'','internal_notes'=>'','category'=>'event','status'=>'planned','visibility'=>'private','start_date'=>$newDate,'end_date'=>$newDate,'start_time'=>'','end_time'=>'','readers'=>[]];
// Failed scalar/array input must never break rendering or become trusted HTML.
$fv=static fn($key)=>is_string($form[$key]??null)||is_int($form[$key]??null)?(string)$form[$key]:'';
$readers=is_array($form['readers']??null)?array_filter(array_map('schoolRequestId',$form['readers'])):[];
?>
<section class="card cal-editor"><h2><?= $event?'Επεξεργασία καταχώρισης':'Νέα καταχώριση' ?></h2><form method="post" action="<?= $h($url(['view'=>'edit','event'=>$event['id']??null])) ?>" class="cal-form" data-calendar-form><?= csrfField() ?><input type="hidden" name="year_id" value="<?= $yearId ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= $h($fv('id')) ?>"><input type="hidden" name="version" value="<?= $h($fv('version')) ?>">
<label>Τίτλος<input class="form-control" name="title" maxlength="200" required value="<?= $h($fv('title')) ?>"></label><div class="cal-form-row"><?php foreach(['category'=>['Κατηγορία',calendarCategories()],'status'=>['Κατάσταση',calendarStatuses()]] as $key=>[$label,$options]): ?><label><?= $label ?><select class="form-control" name="<?= $key ?>"><?php foreach($options as $value=>$text): ?><option value="<?= $value ?>" <?= $fv($key)===$value?'selected':'' ?>><?= $h($text) ?></option><?php endforeach; ?></select></label><?php endforeach; ?></div>
<div class="cal-form-row"><label>Από<input class="form-control" type="date" name="start_date" required value="<?= $h($fv('start_date')) ?>"></label><label>Έως<input class="form-control" type="date" name="end_date" required value="<?= $h($fv('end_date')) ?>"></label><label>Ώρα έναρξης (24ωρη, προαιρετικά)<input class="form-control" type="text" inputmode="text" placeholder="08:30" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" title="24ωρη μορφή ΩΩ:ΛΛ, π.χ. 08:30 ή 14:00" name="start_time" value="<?= $h(substr($fv('start_time'),0,5)) ?>"></label><label>Ώρα λήξης (24ωρη, προαιρετικά)<input class="form-control" type="text" inputmode="text" placeholder="14:00" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" title="24ωρη μορφή ΩΩ:ΛΛ, π.χ. 08:30 ή 14:00" name="end_time" value="<?= $h(substr($fv('end_time'),0,5)) ?>"></label></div>
<label>Περιγραφή<textarea class="form-control" name="description" rows="5" maxlength="10000"><?= $h($fv('description')) ?></textarea></label><label>Ποιοι μπορούν να δουν την καταχώριση;<select class="form-control" name="visibility" data-calendar-visibility><?php foreach(calendarVisibilities() as $value=>$label): ?><option value="<?= $value ?>" <?= $fv('visibility')===$value?'selected':'' ?>><?= $h($label) ?></option><?php endforeach; ?></select></label>
<fieldset class="cal-readers" data-calendar-readers><legend>Επιλεγμένοι καθηγητές</legend><p class="cal-muted">Χρησιμοποιείται μόνο όταν η ορατότητα είναι «Επιλεγμένοι καθηγητές».</p><?php foreach($people as $person): ?><label><input type="checkbox" name="readers[]" value="<?= (int)$person['id'] ?>" <?= in_array((int)$person['id'],$readers,true)?'checked':'' ?>> <?= $h($person['name']) ?></label><?php endforeach; ?></fieldset>
<label class="cal-private">🔒 Εσωτερικές σημειώσεις γραμματείας<textarea class="form-control" name="internal_notes" rows="4" maxlength="10000"><?= $h($fv('internal_notes')) ?></textarea><small>Πάντα ιδιωτικές, ανεξάρτητα από την ορατότητα της καταχώρισης.</small></label><div class="cal-toolbar"><button class="btn btn-primary">Αποθήκευση</button><a class="btn btn-secondary" <?= $event?'data-calendar-modal':'data-calendar-dismiss' ?> href="<?= $h($url(['view'=>$event?'detail':'month','event'=>$event['id']??null])) ?>">Ακύρωση</a></div></form>
<?php if($event): ?><details class="cal-delete"><summary>Διαγραφή καταχώρισης</summary><p>Η καταχώριση θα πάψει να εμφανίζεται στο ημερολόγιο. Το ιστορικό της διατηρείται.</p><form method="post" data-calendar-delete action="<?= $h($url(['view'=>'edit','event'=>$event['id']])) ?>"><?= csrfField() ?><input type="hidden" name="year_id" value="<?= $yearId ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$event['id'] ?>"><input type="hidden" name="version" value="<?= (int)$event['version'] ?>"><label><input type="checkbox" required> Επιβεβαιώνω τη διαγραφή</label> <button class="btn btn-danger">Διαγραφή</button></form></details><?php endif; ?></section>
<?php elseif($view==='access'&&$admin): $grants=$db->query('SELECT user_id FROM calendar_managers WHERE is_active=1')->fetchAll(PDO::FETCH_COLUMN); ?>
<section class="card"><h2>Δικαιώματα γραμματείας</h2><p>Οι διαχειριστές έχουν πρόσβαση σε όλες τις καταχωρίσεις. Επιλέξτε επιπλέον λογαριασμούς γραμματείας: θα μπορούν να βλέπουν και να επεξεργάζονται όλες τις εγγραφές και τις εσωτερικές σημειώσεις. Τα υπόλοιπα δικαιώματά τους παραμένουν ίδια.</p><?php foreach($people as $person): $granted=in_array($person['id'],$grants); ?><div class="cal-access-row"><strong><?= $h($person['name']) ?></strong><span><?= $granted?'Διαχείριση ημερολογίου':'Μόνο επιτρεπόμενες προβολές' ?></span><?php if($editable): ?><form method="post"><?= csrfField() ?><input type="hidden" name="year_id" value="<?= $yearId ?>"><input type="hidden" name="action" value="manager"><input type="hidden" name="user_id" value="<?= (int)$person['id'] ?>"><input type="hidden" name="enabled" value="<?= $granted?'0':'1' ?>"><button class="btn btn-secondary btn-sm"><?= $granted?'Αφαίρεση δικαιώματος':'Ανάθεση στη γραμματεία' ?></button></form><?php endif; ?></div><?php endforeach; ?></section>
<?php endif; endif; ?>
<?php if($ready&&in_array($view,['month','agenda','detail'],true)) require __DIR__.'/school_calendar_print.php'; ?>
<?php if($ready&&in_array($view,['month','agenda','detail'],true)): ?>
<dialog id="calendar-print-options" class="cal-event-dialog" aria-labelledby="calendar-print-options-title"><div class="cal-dialog-header"><h2 id="calendar-print-options-title">Εκτύπωση καταχωρίσεων</h2><button type="button" class="btn btn-secondary btn-sm" data-calendar-print-close>✕ Κλείσιμο</button></div><div class="cal-dialog-content"><form action="calendar_print.php" method="get" target="_blank" class="cal-form" data-calendar-print-form><input type="hidden" name="year_id" value="<?= $yearId ?>">
<label>Ποιες καταχωρίσεις;<select name="scope" class="form-control"><option value="upcoming">Προσεχείς &amp; εκκρεμότητες (όλοι οι επόμενοι μήνες)</option><option value="range">Συγκεκριμένο διάστημα</option><option value="year">Ολόκληρο το σχολικό έτος</option></select></label>
<fieldset data-calendar-print-range hidden><legend>Διάστημα ημερομηνιών</legend><div class="cal-form-row"><label>Από<input class="form-control" type="date" name="from" value="<?= $h($first->format('Y-m-d')) ?>"></label><label>Έως<input class="form-control" type="date" name="to" value="<?= $h($last->format('Y-m-d')) ?>"></label></div></fieldset>
<div class="cal-form-row"><label>Κατηγορία<select class="form-control" name="category"><option value="">Όλες</option><?php foreach(calendarCategories() as $key=>$label): ?><option value="<?= $key ?>"><?= $h($label) ?></option><?php endforeach; ?></select></label><label>Κατάσταση<select class="form-control" name="status"><option value="">Όλες</option><?php foreach(calendarStatuses() as $key=>$label): ?><option value="<?= $key ?>"><?= $h($label) ?></option><?php endforeach; ?></select></label></div>
<p>Η επιλογή αφορά το σχολικό έτος που έχετε επιλέξει, ανεξάρτητα από τον εμφανιζόμενο μήνα. Οι εσωτερικές σημειώσεις παραμένουν εκτός εκτύπωσης.</p><button class="btn btn-primary">Προεπισκόπηση εκτύπωσης ↗</button></form></div></dialog>
<?php endif; ?>
</main>
<?php if($calendarAdminLayout) require __DIR__.'/../admin/footer.php'; else { ?></div></body></html><?php } ?>
