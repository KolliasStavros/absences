<?php
// api/toggle_student_absence.php - Instant inline absence toggle for Admin Daily Dashboard
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/request_validation.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
require_once __DIR__ . '/../includes/attendance_security.php';

$user = requireApiLogin();
requirePost();

$user = getCurrentUser();
if (!canManageAnyAttendance()) {
    http_response_code(403);
    echo json_encode(['error' => 'Δεν έχετε δικαίωμα διαχείρισης απουσιών']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || !$input || array_is_list($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Μη έγκυρα δεδομένα']);
    exit;
}

// CSRF Protection
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? '');
if (!verifyCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Μη έγκυρο αίτημα ασφαλείας (CSRF Token mismatch).']);
    exit;
}

$studentId=schoolRequestId($input['student_id'] ?? null);
$date=schoolRequestDate($input['date'] ?? date('Y-m-d'));
if ($date===null) schoolJsonResponse(['error'=>'Μη έγκυρη μορφή ημερομηνίας'],400);
if ($studentId===null) schoolJsonResponse(['error'=>'Μη έγκυρος μαθητής'],400);
$action=$input['action'] ?? 'toggle';
$absenceType=$input['absence_type'] ?? 'absent';
if (!is_string($action) || !is_string($absenceType)) schoolJsonResponse(['error'=>'Μη έγκυρη ενέργεια ή τύπος απουσίας.'],400);
$action=trim($action);$absenceType=trim($absenceType);
if (!in_array($action,['toggle','set','cycle','set_all_day','clear_all_day','set_note'],true) || !in_array($absenceType,['absent','expulsion','justified'],true)) schoolJsonResponse(['error'=>'Μη έγκυρη ενέργεια ή τύπος απουσίας.'],400);
if ($absenceType==='justified' && !canJustifyAbsences()) schoolJsonResponse(['error'=>'Μόνο οι διαχειριστές του σχολείου μπορούν να καταχωρούν δικαιολογημένες απουσίες.'],403);
$sectionId=schoolOptionalRequestId($input['section_id'] ?? null);
if ($sectionId===null) schoolJsonResponse(['error'=>'Μη έγκυρο τμήμα.'],400);
$allDay=in_array($action,['set_all_day','clear_all_day'],true);
$hourValue=$input['hour'] ?? 0;
$hour=$allDay && in_array($hourValue,[0,'0'],true) ? 0 : schoolRequestHour($hourValue);
if ($hour===null) schoolJsonResponse(['error'=>'Μη έγκυρη διδακτική ώρα.'],400);
if ($action==='set_note' && isset($input['note']) && !is_string($input['note'])) schoolJsonResponse(['error'=>'Μη έγκυρη σημείωση.'],400);

$db = getDB();
$activeYear = getActiveAcademicYear();
$activeYearId = $activeYear ? (int)$activeYear['id'] : 1;

if (!in_array($action, ['toggle','set','cycle','set_all_day','clear_all_day','set_note'], true) || (!in_array($action, ['set_all_day','clear_all_day'], true) && ($hour < 1 || $hour > 8))) {
    attendanceDenied($db, 400, 'Μη έγκυρη ενέργεια ή ώρα.');
}
try {
    $user = beginAttendanceMutation($db, $activeYearId, ['admin','teacher_admin']);
    if ($absenceType==='justified' && !canJustifyAbsences()) attendanceDenied($db,403,'Δεν επιτρέπεται η δικαιολόγηση.');
    if ($sectionId <= 0) {
        $stmt = $db->prepare('SELECT ss.section_id FROM student_sections ss JOIN students s ON s.id=ss.student_id WHERE s.id=? AND s.academic_year_id=? ORDER BY ss.section_id LIMIT 1');
        $stmt->execute([$studentId, $activeYearId]);
        $sectionId = (int)$stmt->fetchColumn();
    }
    validateAttendanceSection($db, $sectionId, $activeYearId, $user);
    validateAttendanceStudent($db, $studentId, $sectionId, $activeYearId);
    $protected = $db->prepare("SELECT hour FROM attendance WHERE student_id=? AND date=? AND academic_year_id=? AND is_absent=1 AND absence_type='justified' FOR UPDATE");
    $protected->execute([$studentId, $date, $activeYearId]);
    $protectedHours = array_map('intval', $protected->fetchAll(PDO::FETCH_COLUMN));
    if (!canJustifyAbsences() && ($action === 'set_all_day' || $action === 'clear_all_day' ? count($protectedHours)>0 : in_array($hour, $protectedHours, true))) {
        attendanceDenied($db, 403, 'Οι δικαιολογημένες απουσίες προστατεύονται. Η ενέργεια ακυρώθηκε ολόκληρη.');
    }

    if ($action === 'set_all_day') {
        // Mark hours 1 through 7 with requested absence_type
        $stmtUpsert = $db->prepare("
            INSERT INTO attendance (academic_year_id, date, hour, section_id, student_id, teacher_id, is_absent, absence_type, email_notified)
            VALUES (?, ?, ?, ?, ?, ?, 1, ?, 0)
            ON DUPLICATE KEY UPDATE 
                academic_year_id = VALUES(academic_year_id),
                is_absent = 1,
                absence_type = VALUES(absence_type),
                updated_at = NOW()
        ");
        for ($h = 1; $h <= 7; $h++) {
            $stmtUpsert->execute([$activeYearId, $date, $h, $sectionId, $studentId, $user['id'], $absenceType]);
        }
    } elseif ($action === 'clear_all_day') {
        // Delete all absences for this student on this date (preserve justified absences if not school admin)
        if (canJustifyAbsences()) {
            $stmtDel = $db->prepare("
                DELETE FROM attendance 
                WHERE student_id = ? AND date = ? AND academic_year_id = ?
            ");
            $stmtDel->execute([$studentId, $date, $activeYearId]);
        } else {
            $stmtDel = $db->prepare("
                DELETE FROM attendance 
                WHERE student_id = ? AND date = ? AND academic_year_id = ? AND (absence_type != 'justified' OR absence_type IS NULL)
            ");
            $stmtDel->execute([$studentId, $date, $activeYearId]);
        }
        } elseif ($action === 'set_note') {
        $rawNote = isset($input['note']) ? trim((string)$input['note']) : '';
        $noteVal = ($rawNote !== '') ? mb_substr($rawNote, 0, 255) : null;

        if ($noteVal === null) {
            // Delete note from attendance_notes
            $stmtDelNote = $db->prepare("
                DELETE FROM attendance_notes 
                WHERE student_id = ? AND date = ? AND hour = ? AND academic_year_id = ?
            ");
            $stmtDelNote->execute([$studentId, $date, $hour, $activeYearId]);

            // Clear note from attendance if an absence row exists
            $stmtClearAtt = $db->prepare("
                UPDATE attendance 
                SET note = NULL, updated_at = NOW() 
                WHERE student_id = ? AND date = ? AND hour = ? AND academic_year_id = ?
            ");
            $stmtClearAtt->execute([$studentId, $date, $hour, $activeYearId]);
        } else {
            // Save or update note in attendance_notes (independent of absence)
            $stmtSaveNote = $db->prepare("
                INSERT INTO attendance_notes (academic_year_id, student_id, date, hour, note, teacher_id, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                    academic_year_id = VALUES(academic_year_id),
                    note = VALUES(note),
                    teacher_id = VALUES(teacher_id),
                    updated_at = NOW()
            ");
            $stmtSaveNote->execute([$activeYearId, $studentId, $date, $hour, $noteVal, $user['id']]);

            // If an absence row exists for this hour, keep attendance.note synced
            $stmtSyncAtt = $db->prepare("
                UPDATE attendance 
                SET note = ?, updated_at = NOW() 
                WHERE student_id = ? AND date = ? AND hour = ? AND academic_year_id = ?
            ");
            $stmtSyncAtt->execute([$noteVal, $studentId, $date, $hour, $activeYearId]);
        }
    } elseif ($action === 'cycle') {
        if ($hour < 1 || $hour > 8) {
            http_response_code(400);
            echo json_encode(['error' => 'Μη έγκυρη διδακτική ώρα']);
            exit;
        }

        $stmtCheck = $db->prepare("
            SELECT id, is_absent, absence_type 
            FROM attendance 
            WHERE student_id = ? AND date = ? AND hour = ? AND academic_year_id = ?
            LIMIT 1
        ");
        $stmtCheck->execute([$studentId, $date, $hour, $activeYearId]);
        $existing = $stmtCheck->fetch();

        if (!$existing || (int)$existing['is_absent'] !== 1) {
            // Step 1: Present -> Absent
            $nextType = 'absent';
            $stmtInsert = $db->prepare("
                INSERT INTO attendance (academic_year_id, date, hour, section_id, student_id, teacher_id, is_absent, absence_type, email_notified)
                VALUES (?, ?, ?, ?, ?, ?, 1, ?, 0)
                ON DUPLICATE KEY UPDATE 
                    academic_year_id = VALUES(academic_year_id),
                    is_absent = 1,
                    absence_type = VALUES(absence_type),
                    email_notified = 0,
                    updated_at = NOW()
            ");
            $stmtInsert->execute([$activeYearId, $date, $hour, $sectionId, $studentId, $user['id'], $nextType]);
        } elseif ($existing['absence_type'] === 'absent') {
            // Step 2: Absent -> Expulsion
            $nextType = 'expulsion';
            $stmtUp = $db->prepare("
                UPDATE attendance SET absence_type = ?, is_absent = 1, updated_at = NOW() WHERE id = ?
            ");
            $stmtUp->execute([$nextType, $existing['id']]);
        } elseif ($existing['absence_type'] === 'expulsion') {
            // Step 3: Expulsion -> Justified (if admin), else Present
            if (canJustifyAbsences()) {
                $nextType = 'justified';
                $stmtUp = $db->prepare("
                    UPDATE attendance SET absence_type = ?, is_absent = 1, updated_at = NOW() WHERE id = ?
                ");
                $stmtUp->execute([$nextType, $existing['id']]);
            } else {
                $stmtDel = $db->prepare("DELETE FROM attendance WHERE id = ?");
                $stmtDel->execute([$existing['id']]);
            }
        } else {
            // Step 4: Justified -> Present (Delete - only school admin can delete justified absences)
            if (!canJustifyAbsences()) {
                http_response_code(403);
                echo json_encode(['error' => 'Μόνο οι διαχειριστές του σχολείου μπορούν να τροποποιήσουν ή να διαγράψουν δικαιολογημένες απουσίες.']);
                if ($db->inTransaction()) $db->rollBack();
                exit;
            }
            $stmtDel = $db->prepare("DELETE FROM attendance WHERE id = ?");
            $stmtDel->execute([$existing['id']]);
        }
    } elseif ($action === 'set') {
        // Direct set to target absence_type
        if ($hour < 1 || $hour > 8) {
            http_response_code(400);
            echo json_encode(['error' => 'Μη έγκυρη διδακτική ώρα']);
            exit;
        }

        $stmtUpsert = $db->prepare("
            INSERT INTO attendance (academic_year_id, date, hour, section_id, student_id, teacher_id, is_absent, absence_type, email_notified)
            VALUES (?, ?, ?, ?, ?, ?, 1, ?, 0)
            ON DUPLICATE KEY UPDATE 
                academic_year_id = VALUES(academic_year_id),
                is_absent = 1,
                absence_type = VALUES(absence_type),
                email_notified = 0,
                updated_at = NOW()
        ");
        $stmtUpsert->execute([$activeYearId, $date, $hour, $sectionId, $studentId, $user['id'], $absenceType]);
    } else {
        // Single hour toggle with target absence_type
        if ($hour < 1 || $hour > 8) {
            http_response_code(400);
            echo json_encode(['error' => 'Μη έγκυρη διδακτική ώρα']);
            exit;
        }

        // Check if attendance record currently exists
        $stmtCheck = $db->prepare("
            SELECT id, is_absent, absence_type 
            FROM attendance 
            WHERE student_id = ? AND date = ? AND hour = ? AND academic_year_id = ?
            LIMIT 1
        ");
        $stmtCheck->execute([$studentId, $date, $hour, $activeYearId]);
        $existing = $stmtCheck->fetch();

        if ($existing && (int)$existing['is_absent'] === 1) {
            // Non-admin cannot toggle/overwrite justified absences
            if ($existing['absence_type'] === 'justified' && !canJustifyAbsences()) {
                http_response_code(403);
                echo json_encode(['error' => 'Μόνο οι διαχειριστές του σχολείου μπορούν να τροποποιήσουν ή να διαγράψουν δικαιολογημένες απουσίες.']);
                if ($db->inTransaction()) $db->rollBack();
                exit;
            }

            if ($existing['absence_type'] === $absenceType) {
                // Already has this type -> Remove it (toggle to present)
                $stmtDel = $db->prepare("
                    DELETE FROM attendance 
                    WHERE id = ?
                ");
                $stmtDel->execute([$existing['id']]);
            } else {
                // Has different type -> Update to the selected type!
                $stmtUpdate = $db->prepare("
                    UPDATE attendance 
                    SET absence_type = ?, email_notified = 0, updated_at = NOW() 
                    WHERE id = ?
                ");
                $stmtUpdate->execute([$absenceType, $existing['id']]);
            }
        } else {
            // Present -> Insert/Update with requested absenceType
            $stmtInsert = $db->prepare("
                INSERT INTO attendance (academic_year_id, date, hour, section_id, student_id, teacher_id, is_absent, absence_type, email_notified)
                VALUES (?, ?, ?, ?, ?, ?, 1, ?, 0)
                ON DUPLICATE KEY UPDATE 
                    academic_year_id = VALUES(academic_year_id),
                    is_absent = 1,
                    absence_type = VALUES(absence_type),
                    email_notified = 0,
                    updated_at = NOW()
            ");
            $stmtInsert->execute([$activeYearId, $date, $hour, $sectionId, $studentId, $user['id'], $absenceType]);
        }
    }

    $db->commit();

    // Fetch updated absence hours for this student on this date
    $stmtHours = $db->prepare("
        SELECT a.id, a.hour, a.absence_type, a.note, a.email_notified, sec.name as section_name, u.name as teacher_name
        FROM attendance a
        LEFT JOIN sections sec ON a.section_id = sec.id
        LEFT JOIN users u ON a.teacher_id = u.id
        WHERE a.student_id = ? AND a.date = ? AND a.academic_year_id = ? AND a.is_absent = 1
        ORDER BY a.hour ASC
    ");
    $stmtHours->execute([$studentId, $date, $activeYearId]);
    $updatedRecords = $stmtHours->fetchAll(PDO::FETCH_ASSOC);

      // Fetch all notes for this student on this date from attendance_notes
      $stmtStudentNotes = $db->prepare("
          SELECT hour, note 
          FROM attendance_notes 
          WHERE student_id = ? AND date = ? AND academic_year_id = ?
      ");
      $stmtStudentNotes->execute([$studentId, $date, $activeYearId]);
      $studentNotesMap = [];
      while ($nr = $stmtStudentNotes->fetch(PDO::FETCH_ASSOC)) {
          $studentNotesMap[(int)$nr['hour']] = $nr['note'];
      }
      // Merge notes into updated absence records
      foreach ($updatedRecords as &$rec) {
          $h = (int)$rec['hour'];
          $rec['note'] = $studentNotesMap[$h] ?? null;
      }
      unset($rec);

    // Compute updated stats for the date
    $stmtTotalHours = $db->prepare("
        SELECT COUNT(*) 
        FROM attendance 
        WHERE date = ? AND academic_year_id = ? AND is_absent = 1
    ");
    $stmtTotalHours->execute([$date, $activeYearId]);
    $kpiTotalHours = (int)$stmtTotalHours->fetchColumn();

    $stmtTotalStudents = $db->prepare("
        SELECT COUNT(DISTINCT student_id) 
        FROM attendance 
        WHERE date = ? AND academic_year_id = ? AND is_absent = 1
    ");
    $stmtTotalStudents->execute([$date, $activeYearId]);
    $kpiAbsentStudents = (int)$stmtTotalStudents->fetchColumn();

    $stmtPendingEmails = $db->prepare("
        SELECT COUNT(DISTINCT a.student_id) 
        FROM attendance a
        JOIN students s ON a.student_id = s.id
        WHERE a.date = ? AND a.academic_year_id = ? AND a.is_absent = 1 AND a.email_notified = 0
          AND (s.guardian_email != '' OR s.guardian_email_2 != '')
    ");
    $stmtPendingEmails->execute([$date, $activeYearId]);
    $kpiPendingEmails = (int)$stmtPendingEmails->fetchColumn();

    // Compute updated email preview for this student
    $previewData = [
        'subject' => '',
        'body' => '',
        'recipients' => []
    ];

    $stmtSt = $db->prepare("
        SELECT id, am, last_name, first_name, gender, last_name_gen, first_name_gen,
               guardian_name, guardian_name_voc, guardian_email, guardian_email_2
        FROM students 
        WHERE id = ? 
        LIMIT 1
    ");
    $stmtSt->execute([$studentId]);
    $stInfo = $stmtSt->fetch(PDO::FETCH_ASSOC);

    if ($stInfo) {
        $previewData['recipients'] = array_values(array_filter([$stInfo['guardian_email'], $stInfo['guardian_email_2']]));
        if (!empty($updatedRecords)) {
            require_once __DIR__ . '/../includes/greek_helper.php';
            require_once __DIR__ . '/../includes/mailer.php';
            $schoolName = getSchoolSetting('school_name', '1ο Γενικό Λύκειο');
            $schoolPhone = getSchoolSetting('school_phone', '210-1234567');
            $maskStudentName = (int)getSchoolSetting('mask_student_name', 0);
            $subjectTemplate = getSchoolSetting('email_template_subject', 'Ενημέρωση Απουσιών Μαθητή - {school_name}');
            $bodyTemplate = getSchoolSetting('email_template_body', "Αξιότιμε κ./κα {guardian_name_voc},\n\nΣας ενημερώνουμε ότι {student_article} {student_noun} {student_full_name} σημείωσε απουσία τις ακόλουθες διδακτικές ώρες την ημερομηνία {date}:\n\nΏρες Απουσίας: {hours}\n\nΜε εκτίμηση,\n{school_name}\nΤηλέφωνο Επικοινωνίας: {school_phone}");

            $gender = $stInfo['gender'] ?: 'male';
            $studentTitle = get_student_title($gender);
            $studentTitleGen = get_student_title_gen($gender);
            $studentNoun = get_student_noun($gender);
            $studentArticle = get_student_article($gender);
            $studentArticleGen = get_student_article_gen($gender);

            $studentFullName = $stInfo['last_name'] . ' ' . $stInfo['first_name'];
            $studentGenitive = ($stInfo['last_name_gen'] ?: $stInfo['last_name']) . ' ' . ($stInfo['first_name_gen'] ?: $stInfo['first_name']);
            if ($maskStudentName) {
                $studentFullName = mask_student_name($studentFullName);
                $studentGenitive = mask_student_name($studentGenitive);
            }
            $guardianVoc = $stInfo['guardian_name_voc'] ?: ($stInfo['guardian_name'] ? 'κ./κα ' . inflect_word_vocative($stInfo['last_name']) : 'γονέα');
            $formattedDate = date('d/m/Y', strtotime($date));
            $hoursArr = array_map(function($r) { return $r['hour'] . 'η'; }, $updatedRecords);
            $hoursText = implode(', ', $hoursArr);

            $replacements = [
                '{school_name}' => $schoolName,
                '{school_phone}' => $schoolPhone,
                '{student_full_name}' => $studentFullName,
                '{student_genitive}' => $studentGenitive,
                '{student_title}' => $studentTitle,
                '{student_title_gen}' => $studentTitleGen,
                '{student_noun}' => $studentNoun,
                '{student_article}' => $studentArticle,
                '{student_article_gen}' => $studentArticleGen,
                '{guardian_name_voc}' => $guardianVoc,
                '{date}' => $formattedDate,
                '{hours}' => $hoursText
            ];

            $previewData['subject'] = str_replace(array_keys($replacements), array_values($replacements), $subjectTemplate);
            $previewData['body'] = str_replace(array_keys($replacements), array_values($replacements), $bodyTemplate);
        }
    }

    echo json_encode([
        'success' => true,
        'student_id' => $studentId,
        'date' => $date,
        'attendances' => $updatedRecords,
        'notes' => (object)$studentNotesMap,
        'count' => count($updatedRecords),
        'kpi' => [
            'total_hours' => $kpiTotalHours,
            'absent_students' => $kpiAbsentStudents,
            'pending_emails' => $kpiPendingEmails
        ],
        'preview' => $previewData
    ]);
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Σφάλμα επεξεργασίας: ' . safeError($e)]);
}
