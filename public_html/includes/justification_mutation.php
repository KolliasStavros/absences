<?php
require_once __DIR__.'/absence_reasons.php';
require_once __DIR__.'/attendance_undo.php';
// Called inside the controller transaction after active-year and student locks.
function applyJustificationDates(PDO $db,int $activeYearId,int $studentId,array $dates,string $action,string $code,string $unjustified,string $protocol,array $actor): string {
    if(($actor['role']??'')!=='admin'||!$db->inTransaction()) throw new DomainException('Απαιτείται διαχειριστής και ενεργή συναλλαγή.');
    $message='';
            $totalAffected=0;
            foreach($dates as $date) {
            $undoCapture=attendanceUndoCapture($db,$activeYearId,$date,[$studentId]);
            $q=$db->prepare("SELECT absence_type,COUNT(*) AS n FROM attendance WHERE student_id=? AND academic_year_id=? AND date=? AND is_absent=1 GROUP BY absence_type");
            $q->execute([$studentId,$activeYearId,$date]);$counts=[];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $counts[$row['absence_type'] ?? 'absent']=($counts[$row['absence_type'] ?? 'absent']??0)+(int)$row['n'];
            if (!$counts) throw new DomainException('Δεν υπάρχουν απουσίες στις '.date('d/m/Y',strtotime($date)).'. Δεν αποθηκεύτηκε καμία αλλαγή.');
            if ($action === 'save_unjustified') {
                if (($counts['absent']??0)+($counts['leave']??0)+($counts['expulsion']??0)===0 && $unjustified !== '') throw new DomainException('Η ημέρα δεν περιέχει αδικαιολόγητες απουσίες ή αποβολές.');
                $q=$db->prepare('INSERT INTO absence_day_reasons(academic_year_id,student_id,date,unjustified_reason) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE unjustified_reason=VALUES(unjustified_reason),updated_at=NOW()');
                $q->execute([$activeYearId,$studentId,$date,$unjustified===''?null:unjustifiedReasons()[$unjustified]]);
                $message = 'Η αιτία αδικαιολόγητης απουσίας αποθηκεύτηκε. Οι καταστάσεις των απουσιών δεν άλλαξαν.';
            } else {
                if (($counts['absent']??0)+($counts['leave']??0)+($counts['justified']??0)===0) throw new DomainException('Υπάρχουν μόνο αποβολές· δεν επιτρέπεται να μετατραπούν σε δικαιολογημένες απουσίες.');
                $q=$db->prepare("UPDATE attendance SET absence_type='justified',updated_at=NOW() WHERE student_id=? AND academic_year_id=? AND date=? AND is_absent=1 AND (absence_type IN ('absent','leave') OR absence_type IS NULL)");
                $q->execute([$studentId,$activeYearId,$date]);$affected=$q->rowCount(); $totalAffected+=$affected;
                $q=$db->prepare('INSERT INTO absence_day_reasons(academic_year_id,student_id,date,justification_reason,protocol,justified_by) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE justification_reason=VALUES(justification_reason),protocol=VALUES(protocol),justified_by=VALUES(justified_by),updated_at=NOW()');
                $q->execute([$activeYearId,$studentId,$date,justificationReasons()[$code],trim($protocol),$actor['id']]);
                $message = "Δικαιολογήθηκαν {$affected} ώρες. Ο λόγος δικαιολόγησης και οι σημειώσεις αποθηκεύτηκαν για την επιλεγμένη ημέρα.";
                if (($counts['expulsion']??0)>0) $message .= ' Οι αποβολές διατηρήθηκαν αμετάβλητες.';
            }
            attendanceUndoRecord($db,$undoCapture,$actor);
            }
            if(count($dates)>1) $message=$action==='justify' ? 'Δικαιολογήθηκαν '.$totalAffected.' ώρες σε '.count($dates).' επιλεγμένες ημέρες. Οι αιτιολογίες και οι σημειώσεις εφαρμόστηκαν στις ημέρες αυτές· οι αποβολές διατηρήθηκαν.' : 'Η αιτία αδικαιολόγητης απουσίας αποθηκεύτηκε σε '.count($dates).' επιλεγμένες ημέρες. Οι καταστάσεις δεν άλλαξαν.';
    return $message;
}
