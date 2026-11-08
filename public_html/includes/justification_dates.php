<?php
require_once __DIR__.'/request_validation.php';
// Explicit dates only: never turn non-contiguous choices into an inclusive range.
function justificationDates(array $input): array {
    $values=$input['selected_dates']??[$input['date_from']??null];
    if(!is_array($values)||!array_is_list($values)||!$values||count($values)>366) throw new DomainException('Επιλέξτε από 1 έως 366 έγκυρες ημέρες.');
    $dates=[];
    foreach($values as $value) {
        $date=schoolRequestDate($value);
        if($date===null) throw new DomainException('Μη έγκυρη ημερομηνία στην επιλογή.');
        $dates[$date]=true;
    }
    $dates=array_keys($dates); sort($dates); return $dates;
}
