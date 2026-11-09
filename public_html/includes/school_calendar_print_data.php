<?php
require_once __DIR__.'/school_calendar.php';
function calendarPrintSelection(PDO $db,int $year,array $user,array $input,?string $today=null): array {
    $mode=$input['scope']??'upcoming';
    if(!is_string($mode)||!in_array($mode,['upcoming','range','year'],true)) throw new DomainException('Μη έγκυρη επιλογή εκτύπωσης.');
    $category=$input['category']??''; $status=$input['status']??'';
    if(!is_string($category)||($category!==''&&!isset(calendarCategories()[$category]))||!is_string($status)||($status!==''&&!isset(calendarStatuses()[$status]))) throw new DomainException('Μη έγκυρα φίλτρα.');
    $from='1000-01-01'; $to='9999-12-31';
    $label='Ολόκληρο το σχολικό έτος';
    if($mode==='upcoming') { $from=$today??date('Y-m-d'); $label='Προσεχείς καταχωρίσεις από '.date('d/m/Y',strtotime($from)).' και ανοιχτές εκκρεμότητες'; }
    if($mode==='range') {
        $from=schoolRequestDate($input['from']??null); $to=schoolRequestDate($input['to']??null);
        if(!$from||!$to||$from>$to) throw new DomainException('Επιλέξτε έγκυρο διάστημα Από–Έως.');
        $label='Διάστημα '.date('d/m/Y',strtotime($from)).' – '.date('d/m/Y',strtotime($to));
    }
    $events=calendarEvents($db,$year,$user,$from,$to,$mode==='upcoming');
    $events=array_values(array_filter($events,static fn($event)=>($category===''||$event['category']===$category)&&($status===''||$event['status']===$status)));
    return ['events'=>$events,'scope'=>$label,'category'=>$category,'status'=>$status];
}
