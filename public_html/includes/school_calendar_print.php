<?php
// Uses only the current page's already-authorized, filtered events.
$printEvents=$calendarPrintEvents??($view==='detail'&&$event?[$event]:$events);
$printYear=''; foreach($years as $printYearRow) if((int)$printYearRow['id']===$yearId) $printYear=$printYearRow['name'];
$printScope=$calendarPrintScope??($view==='month'?$monthNames[(int)$first->format('n')].' '.$first->format('Y'):($view==='agenda'?'Προσεχώς / Εκκρεμότητες σχολικού έτους':'Λεπτομέρειες καταχώρισης'));
?>
<?php if(empty($calendarPrintStandalone)): ?><template id="calendar-print-template"><?php endif; ?><section id="calendar-print-report">
<header><h1><?= $h($schoolName) ?></h1><h2>Ημερολόγιο σχολείου</h2><p><?= $h($printScope.' · Σχολικό έτος '.$printYear) ?></p><p><?= $h('Κατηγορία: '.($category!==''?calendarCategories()[$category]:'Όλες').' · Κατάσταση: '.($status!==''?calendarStatuses()[$status]:'Όλες')) ?></p></header>
<p><?= count($printEvents) ?> καταχωρίσεις · Εκτύπωση <?= $h(date('d/m/Y H:i')) ?></p>
<?php if(!$printEvents): ?><p>Δεν υπάρχουν καταχωρίσεις με τα επιλεγμένα φίλτρα.</p><?php endif; ?>
<?php foreach($printEvents as $printEvent): ?><article><h3><?= $h($printEvent['title']) ?></h3><p><strong><?= $h($dateLabel($printEvent['start_date'])) ?><?= $printEvent['end_date']!==$printEvent['start_date']?' – '.$h($dateLabel($printEvent['end_date'])):'' ?></strong> <?= $printEvent['start_time']?$h(substr($printEvent['start_time'],0,5)):'' ?><?= $printEvent['end_time']?' – '.$h(substr($printEvent['end_time'],0,5)):'' ?></p><p><?= $h(calendarCategories()[$printEvent['category']].' · '.calendarStatuses()[$printEvent['status']]) ?></p><?php if($printEvent['description']!==''): ?><div class="calendar-print-description"><?= $h($printEvent['description']) ?></div><?php endif; ?></article><?php endforeach; ?>
</section><?php if(empty($calendarPrintStandalone)): ?></template><?php endif; ?>
