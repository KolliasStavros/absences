<?php
// SPDX-License-Identifier: GPL-3.0-only
require_once __DIR__.'/request_validation.php';
function historyEmailRange(mixed $from, mixed $to): array {
    if (($from===null || $from==='') && ($to===null || $to==='')) return [null,null];
    if (schoolRequestDate($from)===null || schoolRequestDate($to)===null || $from>$to) throw new DomainException('Επιλέξτε έγκυρη περίοδο Από και Έως.');
    return [$from,$to];
}
function historyEmailRows(PDO $db,int $id,int $year,?string $from,?string $to): array {
    $sql='SELECT id,date,hour,absence_type FROM attendance WHERE student_id=? AND academic_year_id=? AND is_absent=1';
    $args=[$id,$year];
    if($from!==null){$sql.=' AND date BETWEEN ? AND ?';$args[]=$from;$args[]=$to;}
    $q=$db->prepare($sql.' ORDER BY date,hour,id');$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC);
}
function buildHistoryEmail(array $student,array $rows,?string $from,?string $to): array {
    $days=[];$totals=['absent'=>0,'justified'=>0,'expulsion'=>0];
    foreach($rows as $r){$type=$r['absence_type']??'absent';if(!isset($totals[$type]))$type='absent';$totals[$type]++;$days[$r['date']][$type]=($days[$r['date']][$type]??0)+1;}
    $format=static fn($d)=>date('d/m/Y',strtotime($d));
    $period=$from===null?'Ολόκληρο το ενεργό σχολικό έτος':$format($from).' έως '.$format($to);
    $name=$student['last_name'].' '.$student['first_name'];
    $lines=['Αγαπητέ/ή κηδεμόνα,','','Ιστορικό απουσιών: '.$name.' (Α.Μ. '.$student['am'].')','Περίοδος: '.$period,'','Σύνολο: '.count($rows).' ώρες','Αδικαιολόγητες: '.$totals['absent'].' · Δικαιολογημένες: '.$totals['justified'].' · Αποβολές: '.$totals['expulsion'],'','Ανάλυση ανά ημερομηνία (ώρες):'];
    foreach($days as $date=>$counts)$lines[]=$format($date).' | Σύνολο '.array_sum($counts).' | Αδικαιολόγητες '.($counts['absent']??0).' | Δικαιολογημένες '.($counts['justified']??0).' | Αποβολές '.($counts['expulsion']??0);
    if(!$rows)$lines[]='Δεν υπάρχουν καταχωρισμένες απουσίες για την περίοδο.';
    $lines[]='';$lines[]='Στοιχεία κατά την προετοιμασία του μηνύματος.';
    return ['subject'=>'Ιστορικό απουσιών — '.$name,'body'=>implode("\n",$lines)];
}
