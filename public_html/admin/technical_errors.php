<?php
require_once __DIR__.'/../config/config.php';
requireAdmin();
$pageTitle='Τεχνικά σφάλματα';
require_once __DIR__.'/header.php';
$day=is_string($_GET['day']??null) ? $_GET['day'] : gmdate('Y-m-d');
$parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$day);
if (!$parsed || $parsed->format('Y-m-d')!==$day) $day=gmdate('Y-m-d');
$category=is_string($_GET['category']??null) ? $_GET['category'] : '';
if (!in_array($category,['','php','database','javascript'],true)) $category='';
$reference=is_string($_GET['reference']??null) ? substr(trim($_GET['reference']),0,32) : '';
$events=technicalReadEvents($day);$groups=[];$total=0;
foreach ($events as $event) {
    if ($category!=='' && $event['category']!==$category) continue;
    if ($reference!=='' && $event['id']!==$reference) continue;
    $total++;
    $key=hash('sha256',json_encode([$event['category'],$event['kind'],$event['file'],$event['line'],$event['route']]));
    if (!isset($groups[$key])) $groups[$key]=$event+['count'=>0];
    $groups[$key]['count']++;
}
$escape=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');
$labels=['php'=>'PHP','database'=>'Βάση δεδομένων','javascript'=>'JavaScript'];
?>
<style>
.technical-heading { padding:22px; margin-bottom:18px; }
.technical-heading p { color:var(--text-muted); line-height:1.7; margin-top:8px; }
.technical-filters { display:flex; flex-wrap:wrap; align-items:end; gap:12px; margin:18px 0; }
.technical-filters > div { flex:1 1 180px; min-width:0; }
.technical-filters label { display:block; margin-bottom:6px; font-size:13px; }
.technical-filters .form-control { width:100%; min-height:44px; }
.technical-table { width:100%; border-collapse:collapse; font-size:13px; }
.technical-table td,.technical-table th { padding:12px; text-align:left; border-bottom:1px solid var(--border); vertical-align:top; }
.technical-table code { overflow-wrap:anywhere; }
.technical-empty { padding:30px; color:var(--text-muted); }
</style>
<section class="card technical-heading">
    <h1 style="font-size:22px;">🛠️ Τεχνικά σφάλματα</h1>
    <p>Σφάλματα PHP, βάσης και browser. Δεν αποθηκεύονται μηνύματα εξαιρέσεων, κωδικοί, IP, email, δεδομένα φορμών ή SQL. Ο κωδικός αναφοράς συνδέει το μήνυμα χρήστη με το συμβάν.</p>
    <?php if (!technicalLogDirectory()): ?>
    <p role="alert"><strong>Δεν είναι διαθέσιμος ιδιωτικός χώρος καταγραφής.</strong> Ορίστε APP_TECHNICAL_LOG_DIR εκτός δημόσιου φακέλου ή ενεργοποιήστε τον ιδιωτικό φάκελο συντήρησης. Η βασική ένδειξη συμβάντος αποστέλλεται στο PHP error log του server.</p>
    <?php endif; ?>
    <form method="get" class="technical-filters">
        <div><label for="technicalDay">Ημέρα αρχείου (UTC)</label><input id="technicalDay" type="date" name="day" class="form-control" value="<?= $escape($day) ?>" required></div>
        <div><label for="technicalCategory">Κατηγορία</label><select id="technicalCategory" name="category" class="form-control"><option value="">Όλες</option><?php foreach ($labels as $value=>$label): ?><option value="<?= $value ?>" <?= $category===$value?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div>
        <div><label for="technicalReference">Κωδικός αναφοράς</label><input id="technicalReference" name="reference" class="form-control" value="<?= $escape($reference) ?>" maxlength="32" placeholder="Προαιρετικός κωδικός"></div>
        <button class="btn btn-primary" style="min-height:44px;">Εφαρμογή</button>
        <a href="technical_errors.php" class="btn btn-secondary">Καθαρισμός φίλτρων</a>
    </form>
    <p><strong><?= $total ?> συμβάντα · <?= count($groups) ?> ομάδες</strong>. Εμφανίζονται έως 200 ομάδες. Τα επαναλαμβανόμενα συμβάντα ομαδοποιούνται ανά κατηγορία, τύπο, αρχείο, γραμμή και σελίδα. Ώρες σε Europe/Athens. Διατήρηση 30 ημερολογιακών ημερών UTC, έως περίπου 1 MiB ανά ημέρα.</p>
</section>
<div class="card" style="overflow-x:auto;padding:0;">
<?php if (!$groups): ?><p class="technical-empty">Δεν υπάρχουν καταγεγραμμένα τεχνικά σφάλματα για τα επιλεγμένα κριτήρια. Η απουσία καταγραφών δεν εγγυάται ότι δεν υπάρχουν σφάλματα λογικής.</p>
<?php else: ?>
<table class="technical-table"><thead><tr><th>Τελευταία εμφάνιση</th><th>Κατηγορία / Τύπος</th><th>Αρχείο / Σελίδα</th><th>Πλήθος</th><th>Τελευταίος κωδικός</th></tr></thead><tbody>
<?php foreach (array_slice($groups,0,200) as $event): ?>
<tr><td><?= $escape(date('d/m/Y H:i:s',strtotime($event['time']))) ?></td><td><?= $escape($labels[$event['category']]??'Άλλο') ?><br><code><?= $escape($event['kind']) ?></code></td><td><code><?= $escape($event['file']) ?>:<?= (int)$event['line'] ?></code><br><small><?= $escape($event['route']) ?></small></td><td><?= (int)$event['count'] ?></td><td><code><?= $escape($event['id']) ?></code></td></tr>
<?php endforeach; ?></tbody></table>
<?php endif; ?></div>
<?php require_once __DIR__.'/footer.php'; ?>
