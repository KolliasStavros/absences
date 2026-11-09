<?php
// Navigation indicator only; no dashboard panel or event details are rendered.
require_once __DIR__.'/school_calendar.php';
function calendarReminderBadge(PDO $db,array $user,int $year): string {
    try {
        if(!calendarReady($db)) return '';
        $today=date('Y-m-d'); $soon=date('Y-m-d',strtotime('+7 days'));
        $items=calendarEvents($db,$year,$user,$today,$today,true);
        $count=count(array_filter($items,static fn($item)=>$item['start_date']<=$soon));
        if(!$count) return '';
        $label=htmlspecialchars($count.' προσεχώς / εκκρεμότητες',ENT_QUOTES,'UTF-8');
        return '<span class="calendar-reminder-dot" role="img" aria-label="'.$label.'" title="'.$label.'" style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#f59e0b;box-shadow:0 0 0 2px var(--bg-card,#fff);margin-left:6px;vertical-align:middle;flex-shrink:0"></span>';
    } catch(Throwable $e) { return ''; }
}
