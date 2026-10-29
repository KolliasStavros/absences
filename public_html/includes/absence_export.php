<?php
require_once __DIR__ . '/spreadsheet/vendor/autoload.php';
require_once __DIR__ . '/absence_export_range.php';

function buildAbsenceExport(PDO $db, int $yearId, ?string $from = null, ?string $to = null): \PhpOffice\PhpSpreadsheet\Spreadsheet {
    [$from,$to]=absenceExportRange($from,$to);
    $dateWhere=$from === null ? '' : ' AND a.date >= ? AND a.date <= ?';
    $q=$db->prepare("SELECT s.am,s.last_name,s.first_name,s.father_name,s.mother_name,a.date,
        COUNT(*) AS total_absences,
        SUM(CASE WHEN a.absence_type='justified' THEN 1 ELSE 0 END) AS justified,
        SUM(CASE WHEN a.absence_type='justified' THEN 0 ELSE 1 END) AS unjustified,
        r.unjustified_reason,r.justification_reason
        FROM attendance a JOIN students s ON s.id=a.student_id AND s.academic_year_id=a.academic_year_id
        LEFT JOIN absence_day_reasons r ON r.student_id=a.student_id AND r.academic_year_id=a.academic_year_id AND r.date=a.date
        WHERE a.academic_year_id=? AND a.is_absent=1 $dateWhere
        GROUP BY s.id,s.am,s.last_name,s.first_name,s.father_name,s.mother_name,a.date,r.unjustified_reason,r.justification_reason
        ORDER BY s.am,a.date,s.last_name,s.first_name");
    $q->execute($from === null ? [$yearId] : [$yearId,$from,$to]);
    $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $book->getActiveSheet(); $sheet->setTitle('Απουσίες');
    $headers=['Αρ. Μητρώου','Επώνυμο','Όνομα','Όνομα πατέρα','Όνομα μητέρας','Ημ/νία Απουσίας','Σύνολο απουσιών','Αιτία Αδικαιολόγητης απουσίας','Δικαιολογημένες απουσίες','Δικαιολογημένες από'];
    foreach ($headers as $col=>$value) $sheet->setCellValueExplicit([$col+1,1],$value,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $n=2;
    while ($row=$q->fetch(PDO::FETCH_ASSOC)) {
        if ($n>65536) throw new LengthException('Το .xls υποστηρίζει έως 65.535 γραμμές δεδομένων.');
        $values=[$row['am'],$row['last_name'],$row['first_name'],$row['father_name']??'',$row['mother_name']??'',date('d/m/Y',strtotime($row['date'])),(int)$row['total_absences'],(int)$row['unjustified']>0?($row['unjustified_reason']??''):'',(int)$row['justified'],(int)$row['justified']>0?($row['justification_reason']??''):''];
        foreach ($values as $col=>$value) {
            // Explicit string cells preserve AM leading zeroes and prevent formula injection.
            $sheet->setCellValueExplicit([$col+1,$n],$value,in_array($col,[6,8],true)?\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC:\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
        $n++;
    }
    $sheet->freezePane('A2'); $sheet->setAutoFilter('A1:J'.max(1,$n-1));
    $sheet->getStyle('A1:J1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
    $sheet->getStyle('A1:J1')->getFill()->setFillType('solid')->getStartColor()->setARGB('FF4F46E5');
    $sheet->getStyle('A1:J'.max(1,$n-1))->getAlignment()->setWrapText(true)->setVertical('top');
    foreach (['A'=>16,'B'=>25,'C'=>22,'D'=>22,'E'=>22,'F'=>18,'G'=>17,'H'=>55,'I'=>23,'J'=>65] as $column=>$width) $sheet->getColumnDimension($column)->setWidth($width);
    $sheet->getRowDimension(1)->setRowHeight(34);
    return $book;
}
