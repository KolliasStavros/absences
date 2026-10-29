<?php
require_once __DIR__.'/request_validation.php';
function absenceExportRange(mixed $from, mixed $to): array {
    if (($from === null || $from === '') && ($to === null || $to === '')) return [null,null];
    foreach ([$from,$to] as $date) {
        if (schoolRequestDate($date)===null) throw new DomainException('Συμπληρώστε έγκυρες ημερομηνίες Από και Έως για την εξαγωγή.');
    }
    if ($from > $to) throw new DomainException('Η ημερομηνία Από δεν μπορεί να είναι μεταγενέστερη της Έως.');
    return [$from,$to];
}
