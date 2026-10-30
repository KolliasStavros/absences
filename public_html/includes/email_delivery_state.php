<?php
// SPDX-License-Identifier: GPL-3.0-only
function emailDeliveryReference(string $date,array $rows): string {
    $hours=array_values(array_unique(array_map(static fn($r)=>(int)$r['hour'],$rows)));sort($hours,SORT_NUMERIC);
    return $date.' | '.implode(', ',array_map(static fn($h)=>$h.'η',$hours));
}
function emailDeliveryLedger(PDO $db,string $date,int $year): array {
    $q=$db->prepare("SELECT n.id,n.student_id,n.recipient,n.hours_summary,n.status,s.guardian_email,s.guardian_email_2 FROM notification_logs n JOIN students s ON s.id=n.student_id WHERE n.channel='email' AND s.academic_year_id=? AND n.hours_summary LIKE ? ORDER BY n.id DESC");
    $q->execute([$year,$date.' | %']);$result=['logs'=>[],'contacts'=>[],'revision'=>0];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){$id=(int)$row['student_id'];$recipient=strtolower(trim($row['recipient']));$result['revision']=max($result['revision'],(int)$row['id']);$result['contacts'][$id]=[$row['guardian_email'],$row['guardian_email_2']];if(!isset($result['logs'][$id][$row['hours_summary']][$recipient]))$result['logs'][$id][$row['hours_summary']][$recipient]=$row['status'];}
    return $result;
}
function emailDeliveryState(array $ledger,int $studentId,string $date,array $rows): string {
    if(!$rows)return 'none';$attempts=$ledger['logs'][$studentId][emailDeliveryReference($date,$rows)]??[];
    foreach(($ledger['contacts'][$studentId]??[]) as $contact)if(($attempts[strtolower(trim($contact??''))]??'')==='failed')return 'failed';
    foreach($rows as $row)if(empty($row['email_notified']) && empty($row['notified']))return 'pending';
    return 'sent';
}
