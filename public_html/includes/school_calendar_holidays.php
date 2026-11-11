<?php
// SPDX-License-Identifier: GPL-3.0-only
// Nationwide secondary-school closures for 2026–2027, not event records.
// Source: FEK B 5130/2024, article 2 (Christmas, Easter, summer and holidays).
// https://www.minedu.gov.gr/site/70056-engraphes-metengraphes-phoitese
// Local holidays and any future exceptional/transferred holidays are not inferred.
function calendarSchoolHoliday(string $date): ?string {
    if ($date < '2026-09-01' || $date > '2027-08-31') return null;
    $fixed = [
        '2026-10-28' => '28η Οκτωβρίου',
        '2027-03-15' => 'Καθαρά Δευτέρα',
        '2027-03-25' => '25η Μαρτίου',
        '2027-05-01' => 'Πρωτομαγιά',
        '2027-06-21' => 'Αγίου Πνεύματος',
    ];
    if (isset($fixed[$date])) return $fixed[$date];
    if ($date >= '2026-12-24' && $date <= '2027-01-07') return 'Διακοπές Χριστουγέννων';
    // Orthodox Easter: 2 May 2027; Holy Monday through Thomas Sunday inclusive.
    if ($date >= '2027-04-26' && $date <= '2027-05-09') return 'Διακοπές Πάσχα';
    if ($date >= '2027-07-01') return 'Θερινές διακοπές';
    return null;
}
