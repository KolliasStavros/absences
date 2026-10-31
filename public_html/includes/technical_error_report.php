<?php
// SPDX-License-Identifier: GPL-3.0-only
function technicalErrorReport(array $groups,int $total,string $day,string $category,string $reference,string $version): string {
    $lines=['Absences — Αναφορά τεχνικών σφαλμάτων','Έκδοση: '.$version,'Ημέρα αρχείου (UTC): '.$day,'Κατηγορία: '.($category?:'Όλες'),'Κωδικός φίλτρου: '.($reference?:'Χωρίς φίλτρο'),'Συμβάντα: '.$total.' · Ομάδες: '.count($groups),'Περιλαμβάνονται έως 200 ομάδες. Ώρες Europe/Athens.',''];
    foreach(array_slice($groups,0,200) as $event){
        $time=(new DateTimeImmutable($event['time']))->setTimezone(new DateTimeZone('Europe/Athens'))->format('d/m/Y H:i:s');
        $lines[]= $time.' | '.$event['category'].' / '.$event['kind'].' | '.$event['file'].':'.(int)$event['line'].' | '.$event['route'].' | Πλήθος: '.(int)$event['count'].' | Αναφορά: '.$event['id'];
    }
    return implode("\n",$lines);
}
