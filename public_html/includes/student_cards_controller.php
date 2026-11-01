<?php
// Loaded only by the authenticated student-cards entry point.
if (!defined('ABSENCES_STUDENT_CARDS_CONTEXT')) { http_response_code(404); exit; }
require_once __DIR__.'/request_validation.php';

$db = getDB();
require_once __DIR__.'/co_teaching.php';
$sectionMembershipSql=coTeachingMembershipSql(coTeachingGroups($db));
require_once __DIR__ . '/absence_reasons.php';
$activeAcademicYear = getActiveAcademicYear();
$activeYearId = $activeAcademicYear ? (int)$activeAcademicYear['id'] : 1;

$message = '';
$error = '';
require_once __DIR__ . '/absence_export_range.php';
$exportFrom=null; $exportTo=null; $exportRangeValid=true;
if (in_array($_GET['export'] ?? '', ['xls','csv'], true)) {
    try {
        [$exportFrom,$exportTo]=absenceExportRange($_GET['export_from'] ?? null,$_GET['export_to'] ?? null);
    } catch (DomainException $e) {
        $exportRangeValid=false;
        $error=$e->getMessage();
        http_response_code(400);
    }
}
$exportFilePeriod=$exportFrom === null ? date('Y-m-d') : $exportFrom.'_to_'.$exportTo;

// Reasons are day-specific; setting an unjustified reason never justifies attendance.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_justify_range'])) {
    if (!canJustifyAbsences()) {
        $error = 'Μόνο οι διαχειριστές του σχολείου μπορούν να διαχειριστούν αιτιολογίες.';
    } elseif (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Μη έγκυρο αίτημα ασφαλείας (CSRF Token mismatch).';
    } else {
        try {
            $studentId = schoolRequestId($_POST['student_id'] ?? null);
            $date = schoolRequestDate($_POST['date_from'] ?? null);
            $action = $_POST['reason_action'] ?? 'justify';
            $code = $_POST['reason'] ?? '';
            $unjustified = $_POST['unjustified_reason'] ?? '';
            $protocol = $_POST['protocol'] ?? '';
            if ($date===null || $studentId===null || !in_array($action,['justify','save_unjustified'],true)) throw new DomainException('Ελέγξτε μαθητή, ημερομηνία και ενέργεια.');
            if (!is_string($unjustified) || ($unjustified !== '' && !array_key_exists($unjustified,unjustifiedReasons()))) throw new DomainException('Μη έγκυρη αιτία αδικαιολόγητης απουσίας.');
            if ($action === 'justify' && (!is_string($code) || !array_key_exists($code,justificationReasons()))) throw new DomainException('Επιλέξτε έγκυρο λόγο δικαιολόγησης.');
            if (!is_string($protocol) || mb_strlen($protocol)>255) throw new DomainException('Οι σημειώσεις επιτρέπονται έως 255 χαρακτήρες.');
            require_once __DIR__ . '/attendance_security.php';
            $actor = beginAttendanceMutation($db,$activeYearId,['admin']);
            $q=$db->prepare('SELECT id FROM students WHERE id=? AND academic_year_id=? FOR UPDATE');$q->execute([$studentId,$activeYearId]);
            if (!$q->fetchColumn()) throw new DomainException('Ο μαθητής δεν ανήκει στο ενεργό σχολικό έτος.');
            $q=$db->prepare("SELECT absence_type,COUNT(*) AS n FROM attendance WHERE student_id=? AND academic_year_id=? AND date=? AND is_absent=1 GROUP BY absence_type");
            $q->execute([$studentId,$activeYearId,$date]);$counts=[];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $counts[$row['absence_type'] ?? 'absent']=(int)$row['n'];
            if (!$counts) throw new DomainException('Δεν υπάρχουν απουσίες για την επιλεγμένη ημέρα.');
            if ($action === 'save_unjustified') {
                if (($counts['absent']??0)+($counts['expulsion']??0)===0 && $unjustified !== '') throw new DomainException('Η ημέρα δεν περιέχει αδικαιολόγητες απουσίες ή αποβολές.');
                $q=$db->prepare('INSERT INTO absence_day_reasons(academic_year_id,student_id,date,unjustified_reason) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE unjustified_reason=VALUES(unjustified_reason),updated_at=NOW()');
                $q->execute([$activeYearId,$studentId,$date,$unjustified===''?null:unjustifiedReasons()[$unjustified]]);
                $message = 'Η αιτία αδικαιολόγητης απουσίας αποθηκεύτηκε. Οι καταστάσεις των απουσιών δεν άλλαξαν.';
            } else {
                if (($counts['absent']??0)+($counts['justified']??0)===0) throw new DomainException('Υπάρχουν μόνο αποβολές· δεν επιτρέπεται να μετατραπούν σε δικαιολογημένες απουσίες.');
                $q=$db->prepare("UPDATE attendance SET absence_type='justified',updated_at=NOW() WHERE student_id=? AND academic_year_id=? AND date=? AND is_absent=1 AND (absence_type='absent' OR absence_type IS NULL)");
                $q->execute([$studentId,$activeYearId,$date]);$affected=$q->rowCount();
                $q=$db->prepare('INSERT INTO absence_day_reasons(academic_year_id,student_id,date,justification_reason,protocol,justified_by) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE justification_reason=VALUES(justification_reason),protocol=VALUES(protocol),justified_by=VALUES(justified_by),updated_at=NOW()');
                $q->execute([$activeYearId,$studentId,$date,justificationReasons()[$code],trim($protocol),$actor['id']]);
                $message = "Δικαιολογήθηκαν {$affected} ώρες. Ο λόγος δικαιολόγησης και οι σημειώσεις αποθηκεύτηκαν για την επιλεγμένη ημέρα.";
                if (($counts['expulsion']??0)>0) $message .= ' Οι αποβολές διατηρήθηκαν αμετάβλητες.';
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $error = $e instanceof DomainException ? $e->getMessage() : 'Σφάλμα αποθήκευσης αιτιολογίας: '.safeError($e);
        }
    }
}

// Genuine BIFF8 XLS, one row per student and date in the active academic year.
if (($_GET['export'] ?? '') === 'xls' && $exportRangeValid) {
    try {
        require_once __DIR__ . '/absence_export.php';
        $book=buildAbsenceExport($db,$activeYearId,$exportFrom,$exportTo);
        $writer=new \PhpOffice\PhpSpreadsheet\Writer\Xls($book);
        // Render before sending binary headers, so an error cannot corrupt a download.
        $buffer=fopen('php://memory','w+b'); $writer->save($buffer); rewind($buffer);
        while (ob_get_level()>0) ob_end_clean();
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="absences_'.$exportFilePeriod.'.xls"');
        header('Cache-Control: no-store, private');
        fpassthru($buffer);fclose($buffer);$book->disconnectWorksheets();exit;
    } catch (Throwable $e) {
        $error=$e instanceof LengthException ? $e->getMessage() : 'Δεν δημιουργήθηκε το αρχείο Excel: '.safeError($e);
    }
}

// 2. Export to CSV (Summary of All Students with Absence Breakdown)
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $exportRangeValid) {
    while (ob_get_level()>0) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="absence_limits_' . $exportFilePeriod . '.csv"');
    
    // Output UTF-8 BOM for Excel compatibility
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Α.Μ.', 'Επώνυμο', 'Όνομα', 'Τμήμα', 'Σύνολο Απουσιών', 'Αδικαιολόγητες', 'Δικαιολογημένες', 'Αποβολές', 'Κατάσταση Ορίου (114 ώρες)', 'Email Κηδεμόνα', 'Τηλέφωνο']);

    $csvDateWhere=$exportFrom === null ? '' : ' AND a.date >= ? AND a.date <= ?';
    $exportQuery = "
        SELECT 
            s.id, s.am, s.last_name, s.first_name, s.guardian_email, s.guardian_mobile,
            GROUP_CONCAT(DISTINCT sec.name ORDER BY sec.name SEPARATOR ', ') as section_names,
            COUNT(DISTINCT CASE WHEN a.is_absent = 1 THEN a.id END) as total_absences,
            COUNT(DISTINCT CASE WHEN a.is_absent = 1 AND (a.absence_type = 'absent' OR a.absence_type IS NULL) THEN a.id END) as unjustified,
            COUNT(DISTINCT CASE WHEN a.is_absent = 1 AND a.absence_type = 'justified' THEN a.id END) as justified,
            COUNT(DISTINCT CASE WHEN a.is_absent = 1 AND a.absence_type = 'expulsion' THEN a.id END) as expulsions
        FROM students s
        LEFT JOIN {$sectionMembershipSql} ss ON s.id = ss.student_id
        LEFT JOIN sections sec ON ss.section_id = sec.id
        LEFT JOIN attendance a ON s.id = a.student_id AND a.academic_year_id = ? $csvDateWhere
        WHERE s.academic_year_id = ?
        GROUP BY s.id
        ORDER BY total_absences DESC, s.last_name ASC, s.first_name ASC
    ";
    $stmtExp = $db->prepare($exportQuery);
    $stmtExp->execute($exportFrom === null ? [$activeYearId,$activeYearId] : [$activeYearId,$exportFrom,$exportTo,$activeYearId]);
    
    while ($row = $stmtExp->fetch(PDO::FETCH_ASSOC)) {
        $tot = (int)$row['total_absences'];
        $statusText = 'Εντός Ορίου (<60)';
        if ($tot >= 114) {
            $statusText = 'ΥΠΕΡΒΑΣΗ ΟΡΙΟΥ (>114)';
        } elseif ($tot >= 60) {
            $statusText = 'ΠΡΟΕΙΔΟΠΟΙΗΣΗ (60-114)';
        }

        $sanitizeCsv = function($val) {
            $str = (string)$val;
            $trimmed = ltrim($str);
            if ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@', "\t", "\r"])) {
                return "'" . $str;
            }
            return $str;
        };

        fputcsv($output, [
            $sanitizeCsv($row['am']),
            $sanitizeCsv($row['last_name']),
            $sanitizeCsv($row['first_name']),
            $sanitizeCsv($row['section_names'] ?: '-'),
            $tot,
            (int)$row['unjustified'],
            (int)$row['justified'],
            (int)$row['expulsions'],
            $sanitizeCsv($statusText),
            $sanitizeCsv($row['guardian_email'] ?: '-'),
            $sanitizeCsv($row['guardian_mobile'] ?: '-')
        ]);
    }
    fclose($output);
    exit;
}

// 3. Filters
$exportBoundsQuery=$db->prepare('SELECT MIN(date) AS first_date,MAX(date) AS last_date FROM attendance WHERE academic_year_id=? AND is_absent=1');
$exportBoundsQuery->execute([$activeYearId]);
$exportBounds=$exportBoundsQuery->fetch(PDO::FETCH_ASSOC);
$exportFromValue=is_string($_GET['export_from'] ?? null) ? $_GET['export_from'] : ($exportBounds['first_date'] ?? date('Y-m-d'));
$exportToValue=is_string($_GET['export_to'] ?? null) ? $_GET['export_to'] : ($exportBounds['last_date'] ?? date('Y-m-d'));
$filterGradeId = isset($_GET['grade_id']) && $_GET['grade_id'] !== '' ? (int)$_GET['grade_id'] : 0;
$filterSectionId = isset($_GET['section_id']) && $_GET['section_id'] !== '' ? (int)$_GET['section_id'] : 0;
$riskFilter = $_GET['risk_filter'] ?? 'all'; // all, warning (60-114), danger (>=114), safe (<60), expulsions
$searchQuery = trim($_GET['q'] ?? '');

$grades = $db->query("SELECT id, name FROM grades ORDER BY name ASC")->fetchAll();
$sections = $db->query("
    SELECT s.id, s.name, s.grade_id, g.name as grade_name 
    FROM sections s 
    JOIN grades g ON s.grade_id = g.id 
    ORDER BY g.name ASC, s.name ASC
")->fetchAll();

// 4. Fetch Students with Aggregated Absence Stats
$whereClauses = ["s.academic_year_id = ?"];
$params = [$activeYearId, $activeYearId]; // First for attendance join, second for students

if ($filterGradeId > 0) {
    $whereClauses[] = "EXISTS (SELECT 1 FROM {$sectionMembershipSql} ss2 JOIN sections sec2 ON ss2.section_id = sec2.id WHERE ss2.student_id = s.id AND sec2.grade_id = ?)";
    $params[] = $filterGradeId;
}

if ($filterSectionId > 0) {
    $whereClauses[] = "EXISTS (SELECT 1 FROM {$sectionMembershipSql} ss3 WHERE ss3.student_id = s.id AND ss3.section_id = ?)";
    $params[] = $filterSectionId;
}

if (!empty($searchQuery)) {
    $whereClauses[] = "(s.last_name LIKE ? OR s.first_name LIKE ? OR s.am LIKE ?)";
    $term = "%{$searchQuery}%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

$whereSql = implode(" AND ", $whereClauses);

$studentsQuery = "
    SELECT 
        s.id, s.am, s.last_name, s.first_name, s.gender, s.guardian_name, s.guardian_email, s.guardian_mobile,
        GROUP_CONCAT(DISTINCT sec.name ORDER BY sec.name SEPARATOR ', ') as section_names,
        COUNT(DISTINCT CASE WHEN a.is_absent = 1 THEN a.id END) as total_absences,
        COUNT(DISTINCT CASE WHEN a.is_absent = 1 AND (a.absence_type = 'absent' OR a.absence_type IS NULL) THEN a.id END) as unjustified,
        COUNT(DISTINCT CASE WHEN a.is_absent = 1 AND a.absence_type = 'justified' THEN a.id END) as justified,
        COUNT(DISTINCT CASE WHEN a.is_absent = 1 AND a.absence_type = 'expulsion' THEN a.id END) as expulsions,
        MAX(a.date) as last_absence_date
    FROM students s
    LEFT JOIN {$sectionMembershipSql} ss ON s.id = ss.student_id
    LEFT JOIN sections sec ON ss.section_id = sec.id
    LEFT JOIN attendance a ON s.id = a.student_id AND a.academic_year_id = ?
    WHERE {$whereSql}
    GROUP BY s.id
    ORDER BY total_absences DESC, s.last_name ASC, s.first_name ASC
";

$stmtStudents = $db->prepare($studentsQuery);
$stmtStudents->execute($params);
$rawStudents = $stmtStudents->fetchAll();

// Filter in PHP for Risk Filter (based on aggregated counts)
$studentsList = [];
$statsTotalStudents = count($rawStudents);
$statsSafeCount = 0;
$statsWarningCount = 0;
$statsDangerCount = 0;
$statsTotalAbsencesSum = 0;

foreach ($rawStudents as $st) {
    $tot = (int)$st['total_absences'];
    $statsTotalAbsencesSum += $tot;

    if ($tot >= 114) {
        $statsDangerCount++;
    } elseif ($tot >= 60) {
        $statsWarningCount++;
    } else {
        $statsSafeCount++;
    }

    if ($riskFilter === 'with_absences' && $tot <= 0) continue;
    if ($riskFilter === 'danger' && $tot < 114) continue;
    if ($riskFilter === 'warning' && ($tot < 60 || $tot >= 114)) continue;
    if ($riskFilter === 'safe' && $tot >= 60) continue;
    if ($riskFilter === 'expulsions' && (int)$st['expulsions'] <= 0) continue;

    $studentsList[] = $st;
}

// Reuse the exact filtered roster and annual counts for a standalone print report.
if (($_GET['view'] ?? '') === 'print') {
    while (ob_get_level()>0) ob_end_clean();
    require __DIR__ . '/student_cards_print.php';
    exit;
}
$printUrl='student_cards.php?'.http_build_query([
    'view'=>'print','grade_id'=>$filterGradeId,'section_id'=>$filterSectionId,
    'risk_filter'=>$riskFilter,'q'=>$searchQuery,
]);
$activeFilterLabels=[];
if ($searchQuery !== '') $activeFilterLabels[]='Αναζήτηση: '.$searchQuery;
foreach ($grades as $grade) {
    if ((int)$grade['id'] === $filterGradeId) $activeFilterLabels[]='Τάξη: '.$grade['name'];
}
foreach ($sections as $section) {
    if ((int)$section['id'] === $filterSectionId) $activeFilterLabels[]='Τμήμα: '.$section['name'];
}
$riskLabels=['with_absences'=>'Με απουσίες','danger'=>'Κρίσιμο όριο (≥114)','warning'=>'Προειδοποίηση (60–113)','safe'=>'Εντός ορίου (<60)','expulsions'=>'Με αποβολές'];
if (isset($riskLabels[$riskFilter])) $activeFilterLabels[]=$riskLabels[$riskFilter];
