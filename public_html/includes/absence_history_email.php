<?php
// SPDX-License-Identifier: GPL-3.0-only
require_once __DIR__.'/request_validation.php';
require_once __DIR__.'/absence_types.php';
require_once __DIR__.'/greek_helper.php';
require_once __DIR__.'/email_templates.php';
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
function buildHistoryEmail(array $student,array $rows,?string $from,?string $to,string $school='Σχολείο',string $year=''): array {
    $days=[];$totals=['absent'=>0,'justified'=>0,'expulsion'=>0,'leave'=>0];
    foreach($rows as $r){$type=$r['absence_type']??'absent';if(!isset($totals[$type]))$type='absent';$totals[$type]++;$days[$r['date']][$type]=($days[$r['date']][$type]??0)+1;}
    $format=static fn($d)=>date('d/m/Y',strtotime($d));
    $period=$from===null?'Ολόκληρο το ενεργό σχολικό έτος':$format($from).' έως '.$format($to);
    $name=mask_student_name($student['last_name'].' '.$student['first_name']);
    $school=trim($school)?:'Σχολείο';
    $lines=['Αγαπητέ/ή κηδεμόνα,','','Ιστορικό απουσιών: '.$name.' (Α.Μ. '.$student['am'].')','Περίοδος: '.$period,'','Σύνολο: '.count($rows).' ώρες','Αδικαιολόγητες: '.$totals['absent'].' · Δικαιολογημένες: '.$totals['justified'].' · Αποβολές: '.$totals['expulsion'].' · Με άδεια: '.$totals['leave'],'','Ανάλυση ανά ημερομηνία (ώρες):'];
    foreach($days as $date=>$counts)$lines[]=$format($date).' | Σύνολο '.array_sum($counts).' | Αδικαιολόγητες '.($counts['absent']??0).' | Δικαιολογημένες '.($counts['justified']??0).' | Αποβολές '.($counts['expulsion']??0).' | Με άδεια '.($counts['leave']??0);
    if(!$rows)$lines[]='Δεν υπάρχουν καταχωρισμένες απουσίες για την περίοδο.';
    $lines[]='';$lines[]=absenceEmailLegend($rows);$lines[]='';$lines[]='Στοιχεία κατά την προετοιμασία του μηνύματος.';
    return renderReportEmail('history',array_merge($totals,['student_full_name'=>$name,'am'=>$student['am'],'academic_year'=>$year,'school_name'=>$school,'school_phone'=>function_exists('getSchoolSetting')?getSchoolSetting('school_phone',''):'','period'=>$period,'total'=>count($rows),'absence_details'=>implode("\n",array_slice($lines,5)),'pending_justifications'=>absencePendingJustificationsText()]));
}

function buildAbsenceSummaryEmail(array $student,array $rows,?string $from,?string $to,string $year,string $school,bool $includeDaily=false): array {
    $totals=['absent'=>0,'justified'=>0,'expulsion'=>0,'leave'=>0];
    foreach($rows as $row) {
        $type=$row['absence_type']??'absent';
        if(!isset($totals[$type])) $type='absent';
        $totals[$type]++;
    }
    $name=mask_student_name($student['last_name'].' '.$student['first_name']);
    $school=trim($school)?:'Σχολείο';
    $period=$from===null?'Ολόκληρο το σχολικό έτος':date('d/m/Y',strtotime($from)).' έως '.date('d/m/Y',strtotime($to));
    $lines=['Αγαπητέ/ή κηδεμόνα,','','Σας ενημερώνουμε συγκεντρωτικά για τις καταχωρισμένες απουσίες του/της '.$name.' (Α.Μ. '.$student['am'].').',
        'Σχολικό έτος: '.$year,'Περίοδος: '.$period,'','Σύνολο απουσιών: '.count($rows).' ώρες',
        'Κοινές απουσίες: '.$totals['absent'],'Δικαιολογημένες: '.$totals['justified'],'Ωριαίες αποβολές: '.$totals['expulsion'],'Με άδεια: '.$totals['leave'],
        '','Οι άδειες και οι ωριαίες αποβολές περιλαμβάνονται στο σύνολο. Η άδεια δεν αποτελεί δικαιολόγηση.',
        'Τα στοιχεία αφορούν τις καταχωρίσεις κατά την προετοιμασία του μηνύματος.'];
    if($includeDaily) {
        $days=[];
        foreach($rows as $row) {
            $type=$row['absence_type']??'absent'; if(!isset($totals[$type])) $type='absent';
            $days[$row['date']][$type]=($days[$row['date']][$type]??0)+1;
        }
        ksort($days);
        $lines[]=''; $lines[]='Ανάλυση ανά ημερομηνία (ώρες):';
        foreach($days as $date=>$counts) $lines[]=date('d/m/Y',strtotime($date)).' | Σύνολο '.array_sum($counts).' | Κοινές '.($counts['absent']??0).' | Δικαιολογημένες '.($counts['justified']??0).' | Αποβολές '.($counts['expulsion']??0).' | Με άδεια '.($counts['leave']??0);
        if(!$rows) $lines[]='Δεν υπάρχουν καταχωρισμένες απουσίες για την περίοδο.';
    }
    return renderReportEmail('summary',array_merge($totals,['student_full_name'=>$name,'am'=>$student['am'],'academic_year'=>$year,'school_name'=>$school,'school_phone'=>function_exists('getSchoolSetting')?getSchoolSetting('school_phone',''):'','period'=>$period,'total'=>count($rows),'absence_details'=>implode("\n",array_slice($lines,6)),'pending_justifications'=>absencePendingJustificationsText()]));
}
