<?php
require_once __DIR__.'/../public_html/includes/absence_history_email.php';
function summaryCheck(bool $ok,string $message): void {
    if(!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
}
$student=['last_name'=>'ΔΟΚΙΜΗ','first_name'=>'ΜΑΘΗΤΗΣ','am'=>'123'];
$rows=[['date'=>'2026-10-01','absence_type'=>null],['date'=>'2026-10-01','absence_type'=>'leave'],['date'=>'2026-10-02','absence_type'=>'justified'],['date'=>'2026-10-03','absence_type'=>'expulsion']];
$summary=buildAbsenceSummaryEmail($student,$rows,null,null,'2026-2027','Δοκιμαστικό σχολείο');
summaryCheck(str_starts_with($summary['subject'],'Δοκιμαστικό σχολείο — '),'school starts subject');
summaryCheck(str_contains($summary['subject'],'ΔΟ**** ΜΑ*****')&&str_contains($summary['body'],'ΔΟ**** ΜΑ*****'),'masked names in subject and body');
summaryCheck(!str_contains($summary['subject'],'ΔΟΚΙΜΗ')&&!str_contains($summary['body'],'ΜΑΘΗΤΗΣ'),'full names excluded from message');
summaryCheck(str_contains($summary['body'],"μετά την ολοκλήρωση του ελέγχου και της καταχώρισής τους.\n\nΜε εκτίμηση,"),'pending justifications explained before signature');
summaryCheck(str_contains($summary['body'],'Σύνολο απουσιών: 4 ώρες'),'all absence types included');
foreach(['Κοινές απουσίες: 1','Δικαιολογημένες: 1','Ωριαίες αποβολές: 1','Με άδεια: 1'] as $label) summaryCheck(str_contains($summary['body'],$label),$label);
summaryCheck(!str_contains($summary['body'],'01/10/2026')&&!str_contains($summary['body'],'Ανάλυση ανά ημερομηνία'),'summary omits daily history');
summaryCheck(str_contains($summary['body'],'2026-2027')&&str_contains($summary['body'],'Δοκιμαστικό σχολείο'),'school and year included');
$summary=buildAbsenceSummaryEmail($student,[],'2026-10-01','2026-10-31','2026-2027','Σχολείο');
summaryCheck(str_contains($summary['body'],'01/10/2026 έως 31/10/2026')&&str_contains($summary['body'],'Σύνολο απουσιών: 0 ώρες'),'explicit period and zero supported');

$daily=buildAbsenceSummaryEmail($student,$rows,null,null,'2026-2027','Σχολείο',true);
summaryCheck(str_contains($daily['body'],'Ανάλυση ανά ημερομηνία'),'daily breakdown optional');
summaryCheck(str_contains($daily['body'],'01/10/2026 | Σύνολο 2 | Κοινές 1 | Δικαιολογημένες 0 | Αποβολές 0 | Με άδεια 1'),'daily categories retain leave');
summaryCheck(str_contains($daily['body'],'Σύνολο απουσιών: 4 ώρες'),'daily breakdown preserves overall total');
summaryCheck(str_contains($daily['body'],"καταχώρισής τους.\n\nΜε εκτίμηση,"),'daily summary includes justification notice');
