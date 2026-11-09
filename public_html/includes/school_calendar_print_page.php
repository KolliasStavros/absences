<?php
require_once __DIR__.'/school_calendar_print_data.php';
$db=getDB(); $user=getCurrentUser();
$yearId=schoolRequestId($_GET['year_id']??null);
$h=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');
try {
    if(!$yearId) throw new DomainException('Επιλέξτε σχολικό έτος.');
    $q=$db->prepare('SELECT id,name FROM academic_years WHERE id=?'); $q->execute([$yearId]); $year=$q->fetch(PDO::FETCH_ASSOC);
    if(!$year||!calendarReady($db)) throw new DomainException('Το ημερολόγιο δεν είναι διαθέσιμο.');
    $selection=calendarPrintSelection($db,$yearId,$user,$_GET);
} catch(DomainException $e) { http_response_code(400); exit($h($e->getMessage())); }
catch(Throwable $e) { http_response_code(503); exit('Η εκτύπωση δεν είναι προσωρινά διαθέσιμη.'); }
$years=[$year]; $schoolName=getSchoolSetting('school_name','Σχολείο');
$calendarPrintEvents=$selection['events']; $calendarPrintScope=$selection['scope'];
$category=$selection['category']; $status=$selection['status']; $calendarPrintStandalone=true;
$dateLabel=static fn($date)=>(new DateTimeImmutable($date))->format('d/m/Y');
?>
<!doctype html><html lang="el"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Εκτύπωση ημερολογίου · <?= $h($schoolName) ?></title><link rel="stylesheet" href="../assets/css/school-calendar-print.css?v=2"></head>
<body class="calendar-print-preview calendar-printing"><nav class="calendar-print-controls"><button type="button" onclick="window.print()">🖨️ Εκτύπωση / Αποθήκευση ως PDF</button><p>Προεπισκόπηση · <?= count($calendarPrintEvents) ?> καταχωρίσεις. Οι εσωτερικές σημειώσεις δεν εκτυπώνονται.</p></nav>
<?php require __DIR__.'/school_calendar_print.php'; ?>
</body></html>
