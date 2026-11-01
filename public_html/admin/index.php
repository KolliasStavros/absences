<?php
// admin/index.php - Live Absences Dashboard with Filters (All/Grade/Section/Status), Add/Edit/Delete Absences & Email Preview
$pageTitle = 'Ημερήσιο Παρουσιολόγιο & Απουσίες';
require_once __DIR__ . '/header.php';

$db = getDB();
require_once __DIR__.'/../includes/co_teaching.php';
$sectionMembershipSql=coTeachingMembershipSql(coTeachingGroups($db));
require_once __DIR__.'/../includes/email_delivery_state.php';
require_once __DIR__.'/../includes/attendance_security.php';
$selectedDate = $_GET['date'] ?? date('Y-m-d');
$dateCheck = is_string($selectedDate) ? DateTime::createFromFormat('!Y-m-d', $selectedDate) : false;
if (!$dateCheck || $dateCheck->format('Y-m-d') !== $selectedDate) {
    http_response_code(400);
    exit('Μη έγκυρη ημερομηνία.');
}
$filterGradeId = isset($_GET['grade_id']) && $_GET['grade_id'] !== '' ? (int)$_GET['grade_id'] : 0;
$filterSectionId = isset($_GET['section_id']) && $_GET['section_id'] !== '' ? (int)$_GET['section_id'] : 0;
$statusFilter = $_GET['status_filter'] ?? 'absent_only'; // 'absent_only', 'all', 'present_only', 'expulsion_only', 'justified_only'
if (!in_array($statusFilter,['absent_only','all','present_only','expulsion_only','justified_only'],true)) $statusFilter='absent_only';

$actionMessage = '';
$actionError = '';

$subjectTemplate = getSchoolSetting('email_template_subject', 'Ενημέρωση Απουσιών Μαθητή - {school_name}');
$bodyTemplate = getSchoolSetting('email_template_body', '');
$schoolPhone = getSchoolSetting('school_phone', '');
$maskStudentName = getSchoolSetting('mask_student_name', '0') === '1';
$smsEnabled = getSchoolSetting('sms_enabled', '0') === '1';
$schoolName = getSchoolSetting('school_name', 'Παρουσιολόγιο Σχολείου');
$activeAcademicYear = getActiveAcademicYear();
$activeYearId = $activeAcademicYear ? (int)$activeAcademicYear['id'] : 1;

// Handle all POST actions with CSRF verification
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $actionError = 'Μη έγκυρο αίτημα ασφαλείας (CSRF Token mismatch). Παρακαλώ ανανεώστε τη σελίδα.';
    } else {
        // 1. Handle Delete Single Absence Hour
        if (isset($_POST['delete_absence_id'])) {
            $user = beginAttendanceMutation($db, $activeYearId, ['admin','teacher_admin']);
            $attId = (int)$_POST['delete_absence_id'];
            $stmt = $db->prepare("SELECT id, absence_type, is_absent FROM attendance WHERE id = ? AND academic_year_id = ? FOR UPDATE");
            $stmt->execute([$attId, $activeYearId]);
            $att = $stmt->fetch();
            if (!$att) {
                $actionError = 'Η απουσία δεν βρέθηκε.';
            } elseif ($att['absence_type'] === 'justified' && !canJustifyAbsences()) {
                $actionError = 'Μόνο οι διαχειριστές του σχολείου μπορούν να τροποποιήσουν ή να διαγράψουν δικαιολογημένες απουσίες.';
            } else {
                $stmt = $db->prepare("DELETE FROM attendance WHERE id = ?");
                $stmt->execute([$attId]);
                $actionMessage = 'Η απουσία διαγράφηκε επιτυχώς.';
            }
            $db->commit();
        }

        // 2. Handle Quick Add Hour to Existing Student
        if (isset($_POST['quick_add_hour'])) {
            $studentId = (int)$_POST['student_id'];
            $hour = (int)$_POST['hour'];
            $sectionId = (int)$_POST['section_id'];
            $date = trim($_POST['date'] ?? $selectedDate);
            $dObj = DateTime::createFromFormat('Y-m-d', $date);

            $absenceType = trim($_POST['absence_type'] ?? 'absent');
            if (!in_array($absenceType, ['absent', 'expulsion', 'justified'])) $absenceType = 'absent';
            if ($absenceType === 'justified' && !canJustifyAbsences()) {
                $absenceType = 'absent';
            }

            if (!$dObj || $dObj->format('Y-m-d') !== $date) {
                $actionError = 'Μη έγκυρη μορφή ημερομηνίας.';
            } elseif ($studentId > 0 && $hour >= 1 && $hour <= 8 && $sectionId > 0) {
                try {
                    $user = beginAttendanceMutation($db, $activeYearId, ['admin','teacher_admin']);
                    validateAttendanceSection($db,$sectionId,$activeYearId,$user);
                    validateAttendanceStudent($db,$studentId,$sectionId,$activeYearId);
                    if ($absenceType === 'justified' && !canJustifyAbsences()) attendanceDenied($db,403,'Δεν επιτρέπεται η δικαιολόγηση.');
                    assertMutableAttendanceHours($db,$studentId,$date,$activeYearId,[$hour]);
                    $stmt = $db->prepare("
                        INSERT INTO attendance (academic_year_id, date, hour, section_id, student_id, teacher_id, is_absent, absence_type, email_notified)
                        VALUES (?, ?, ?, ?, ?, ?, 1, ?, 0)
                        ON DUPLICATE KEY UPDATE academic_year_id = VALUES(academic_year_id), is_absent = 1, absence_type = VALUES(absence_type), email_notified = 0, updated_at = NOW()
                    ");
                    $stmt->execute([$activeYearId, $date, $hour, $sectionId, $studentId, $user['id'], $absenceType]);
                    
                    $db->commit();
                    $typeName = ($absenceType === 'expulsion') ? 'ωριαία αποβολή' : (($absenceType === 'justified') ? 'δικαιολογημένη απουσία' : 'απουσία');
                    $actionMessage = "Η {$hour}η ώρα καταχωρήθηκε επιτυχώς ως {$typeName}!";
                } catch (Exception $e) {
                    if($db->inTransaction())$db->rollBack();
                    $actionError = 'Σφάλμα προσθήκης: ' . safeError($e);
                }
            } else {
                $actionError = 'Μη έγκυρα στοιχεία καταχώρησης.';
            }
        }

        // 3. Handle Full Add Absence (Global Modal)
        if (isset($_POST['add_student_absence'])) {
            $studentId = (int)$_POST['student_id'];
            $sectionId = (int)$_POST['section_id'];
            $date = trim($_POST['date'] ?? $selectedDate);
            $dObj = DateTime::createFromFormat('Y-m-d', $date);
            $selectedHours = $_POST['hours'] ?? [];
            $absenceType = trim($_POST['absence_type'] ?? 'absent');
            if (!in_array($absenceType, ['absent', 'expulsion', 'justified'])) $absenceType = 'absent';
            if ($absenceType === 'justified' && !canJustifyAbsences()) {
                $absenceType = 'absent';
            }

            if ($studentId <= 0 || empty($selectedHours) || empty($date)) {
                $actionError = 'Παρακαλώ επιλέξτε μαθητή και τουλάχιστον μία διδακτική ώρα.';
            } elseif (!$dObj || $dObj->format('Y-m-d') !== $date) {
                $actionError = 'Μη έγκυρη μορφή ημερομηνίας.';
            } else {
                try {
                    if ($sectionId <= 0) {
                        $secStmt = $db->prepare("SELECT section_id FROM {$sectionMembershipSql} AS memberships WHERE student_id = ? LIMIT 1");
                        $secStmt->execute([$studentId]);
                        $sectionId = (int)$secStmt->fetchColumn();
                    }
                    if ($sectionId <= 0) {
                        $sectionId = (int)$db->query("SELECT id FROM sections LIMIT 1")->fetchColumn();
                    }

                    $user = beginAttendanceMutation($db,$activeYearId,['admin','teacher_admin']);
                    validateAttendanceSection($db,$sectionId,$activeYearId,$user);
                    validateAttendanceStudent($db,$studentId,$sectionId,$activeYearId);
                    if ($absenceType === 'justified' && !canJustifyAbsences()) attendanceDenied($db,403,'Δεν επιτρέπεται η δικαιολόγηση.');
                    if(!is_array($selectedHours) || array_filter($selectedHours,fn($h)=>(int)$h<1 || (int)$h>8)) attendanceDenied($db,400,'Μη έγκυρες ώρες.');
                    assertMutableAttendanceHours($db,$studentId,$date,$activeYearId,array_map('intval',$selectedHours));

                    $stmtUpsert = $db->prepare("
                        INSERT INTO attendance (academic_year_id, date, hour, section_id, student_id, teacher_id, is_absent, absence_type, email_notified)
                        VALUES (?, ?, ?, ?, ?, ?, 1, ?, 0)
                        ON DUPLICATE KEY UPDATE academic_year_id = VALUES(academic_year_id), is_absent = 1, absence_type = VALUES(absence_type), email_notified = 0, updated_at = NOW()
                    ");

                    foreach ($selectedHours as $h) {
                        $h = (int)$h;
                        if ($h >= 1 && $h <= 8) {
                            $stmtUpsert->execute([$activeYearId, $date, $h, $sectionId, $studentId, $user['id'], $absenceType]);
                        }
                    }

                    $db->commit();

                    $actionMessage = "Καταχωρήθηκαν επιτυχώς " . count($selectedHours) . " ώρες για την ημερομηνία " . date('d/m/Y', strtotime($date)) . "!";
                    if ($date !== $selectedDate) {
                        header("Location: index.php?date=" . urlencode($date) . ($filterGradeId ? "&grade_id=" . $filterGradeId : "") . ($filterSectionId ? "&section_id=" . $filterSectionId : "") . "&status_filter=" . urlencode($statusFilter));
                        exit;
                    }
                } catch (Exception $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    $actionError = 'Σφάλμα κατά την καταχώρηση: ' . safeError($e);
                }
            }
        }

        // 4. Handle Batch Sending Consolidated Emails
        if (isset($_POST['send_consolidated_emails'])) {
    $studentIds = $_POST['student_ids'] ?? [];
    if (empty($studentIds)) {
        $actionError = 'Δεν επιλέξατε μαθητές για αποστολή ειδοποίησης.';
    } else {
        $sentCount = 0;
        $failedCount = 0;

        foreach ($studentIds as $sId) {
            $sId = (int)$sId;
            $stmt = $db->prepare("SELECT * FROM students WHERE id = ? AND academic_year_id = ?");
            $stmt->execute([$sId,$activeYearId]);
            $student = $stmt->fetch();
            if (!$student) continue;

            $emailsToSend = [];
            if (!empty($student['guardian_email'])) $emailsToSend[] = $student['guardian_email'];
            if (!empty($student['guardian_email_2'])) $emailsToSend[] = $student['guardian_email_2'];

            $emailsToSend=array_values(array_unique(array_map('trim',$emailsToSend)));
            if (empty($emailsToSend)) {
                $failedCount++;
                continue;
            }

            $stmt = $db->prepare("
                SELECT id, hour, absence_type, email_notified 
                FROM attendance 
                WHERE student_id = ? AND date = ? AND academic_year_id = ? AND is_absent = 1 
                ORDER BY hour ASC
            ");
            $stmt->execute([$sId, $selectedDate,$activeYearId]);
            $absences = $stmt->fetchAll();

            if (empty($absences)) continue;

            $hourLabels = [];
            $attIds = [];
            foreach ($absences as $a) {
                $lbl = $a['hour'] . 'η';
                if ($a['absence_type'] === 'expulsion') {
                    $lbl .= ' (Αποβολή)';
                } elseif ($a['absence_type'] === 'justified') {
                    $lbl .= ' (Δικαιολογημένη)';
                }
                $hourLabels[] = $lbl;
                $attIds[] = $a['id'];
            }
            $hoursText = implode(', ', $hourLabels);

            $gender = $student['gender'] ?? 'male';
            $studentTitle = get_student_title($gender);
            $studentTitleGen = get_student_title_gen($gender);
            $studentNoun = get_student_noun($gender);
            $studentArticle = get_student_article($gender);
            $studentArticleGen = get_student_article_gen($gender);

            $studentFullName = $student['last_name'] . ' ' . $student['first_name'];
            $studentGenitive = ($student['last_name_gen'] ?: $student['last_name']) . ' ' . ($student['first_name_gen'] ?: $student['first_name']);
            if ($maskStudentName) {
                $studentFullName = mask_student_name($studentFullName);
                $studentGenitive = mask_student_name($studentGenitive);
            }
            $guardianVoc = $student['guardian_name_voc'] ?: ($student['guardian_name'] ? 'κ./κα ' . inflect_word_vocative($student['last_name']) : 'γονέα');
            $formattedDate = date('d/m/Y', strtotime($selectedDate));

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

            $subject = str_replace(array_keys($replacements), array_values($replacements), $subjectTemplate);
            $message = str_replace(array_keys($replacements), array_values($replacements), $bodyTemplate);

            $reference=emailDeliveryReference($selectedDate,$absences);
            $lockName='abs-email-'.substr(hash('sha256',DB_NAME.'|'.(defined('DB_PREFIX')?DB_PREFIX:'').'|'.$sId.'|'.$selectedDate),0,45);
            $lock=$db->prepare('SELECT GET_LOCK(?,5)');$lock->execute([$lockName]);
            if((int)$lock->fetchColumn()!==1){$failedCount++;continue;}
            try {
                $studentEmailSuccess=true;
                foreach($emailsToSend as $recipientEmail) {
                    $prior=$db->prepare("SELECT id FROM notification_logs WHERE channel='email' AND student_id=? AND recipient=? AND hours_summary=? AND BINARY subject=BINARY ? AND BINARY message=BINARY ? AND status='sent' LIMIT 1");
                    $prior->execute([$sId,$recipientEmail,$reference,$subject,$message]);
                    if($prior->fetchColumn())continue;
                    $res=sendNotification('email',$recipientEmail,$subject,$message,['student_id'=>$sId,'sent_by'=>$user['id'],'hours_summary'=>$reference]);
                    if(!empty($res['success']) && empty($res['simulated'])){$sentCount++;}
                    else{$studentEmailSuccess=false;$failedCount++;}
                }
                $inClause=implode(',',array_fill(0,count($attIds),'?'));
                $db->prepare("UPDATE attendance SET email_notified = ? WHERE academic_year_id=? AND id IN ($inClause)")->execute(array_merge([$studentEmailSuccess?1:0,$activeYearId],$attIds));
            } finally {$db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);}

        }

        $actionMessage = "Ολοκληρώθηκε η αποστολή: {$sentCount} email στάλθηκαν επιτυχώς στους κηδεμόνες" . ($failedCount > 0 ? ", {$failedCount} απέτυχαν." : ".");
    }
}

        // 4b. Handle Batch Sending Consolidated SMS
        if (isset($_POST['send_consolidated_sms'])) {
            $studentIds = $_POST['student_ids'] ?? [];
            if (empty($studentIds)) {
                $actionError = 'Δεν επιλέξατε μαθητές για αποστολή SMS.';
            } else {
                require_once __DIR__ . '/../includes/sms.php';
                $sentSmsCount = 0;
                $simulatedSmsCount = 0;
                $duplicateSmsCount = 0;
                $failedSmsCount = 0;
                $skippedEmailCount = 0;
                $errorsList = [];
                $smsSendMode = getSchoolSetting('sms_send_mode', 'fallback_no_email');

                foreach ($studentIds as $sId) {
                    $sId = (int)$sId;
                    $stmt = $db->prepare("SELECT * FROM students WHERE id = ? AND academic_year_id = ?");
                    $stmt->execute([$sId, $activeYearId]);
                    $student = $stmt->fetch();
                    if (!$student) continue;

                    // Smart Fallback Policy: If student already has email, skip SMS to save cost
                    if ($smsSendMode === 'fallback_no_email' && (!empty($student['guardian_email']) || !empty($student['guardian_email_2']))) {
                        $skippedEmailCount++;
                        continue;
                    }

                    $mobilesToSend = [];
                    if (!empty($student['guardian_mobile'])) $mobilesToSend[] = $student['guardian_mobile'];
                    if (!empty($student['guardian_mobile_2'])) $mobilesToSend[] = $student['guardian_mobile_2'];

                    if (empty($mobilesToSend)) {
                        $failedSmsCount++;
                        $errorsList[] = "Ο/Η μαθητής/τρια {$student['last_name']} {$student['first_name']} δεν έχει καταχωρημένο κινητό κηδεμόνα.";
                        continue;
                    }

                    $stmt = $db->prepare("
                        SELECT id, hour, absence_type 
                        FROM attendance 
                        WHERE student_id = ? AND date = ? AND academic_year_id = ? AND is_absent = 1 AND sms_notified = 0
                        ORDER BY hour ASC
                    ");
                    $stmt->execute([$sId, $selectedDate, $activeYearId]);
                    $absences = $stmt->fetchAll();
                    if (empty($absences)) continue;

                    $absCount = count($absences);
                    $formattedDate = date('d/m/Y', strtotime($selectedDate));
                    $smsMessage = buildStudentSmsMessage($student, $absCount, $formattedDate, $schoolName);

                    $studentSmsSuccess = true;
                    $mobilesToSend = array_values(array_unique($mobilesToSend));
                    foreach ($mobilesToSend as $mobile) {
                        $res = sendNotification('sms', $mobile, 'Ενημέρωση Απουσιών', $smsMessage, [
                            'student_id' => $sId,
                            'sent_by' => $user['id'],
                            'hours_summary' => "{$absCount} ώρες"
                        ]);
                        if ($res['success'] && !empty($res['simulated'])) {
                            $simulatedSmsCount++;
                            $studentSmsSuccess = false;
                        } elseif ($res['success']) {
                            if (!empty($res['duplicate'])) $duplicateSmsCount++;
                            else $sentSmsCount++;
                        } else {
                            $failedSmsCount++;
                            $studentSmsSuccess = false;
                            $errorsList[] = "Αποτυχία SMS στο {$mobile}: " . ($res['error'] ?? 'Σφάλμα παρόχου');
                        }
                    }

                    if ($studentSmsSuccess) {
                        $attIds = array_column($absences, 'id');
                        if (!empty($attIds)) {
                            $inClause = implode(',', array_fill(0, count($attIds), '?'));
                            $db->prepare("UPDATE attendance SET sms_notified = 1, updated_at = NOW() WHERE id IN ($inClause)")->execute($attIds);
                        }
                    }
                }

                if ($simulatedSmsCount>0) $actionMessage = "Προσομοιώθηκαν {$simulatedSmsCount} SMS χωρίς πραγματική αποστολή ή σήμανση απουσιών ως ειδοποιημένων.";
                if ($duplicateSmsCount>0) $actionMessage .= " Παραλείφθηκαν {$duplicateSmsCount} ήδη απεσταλμένες ειδοποιήσεις.";
                if ($sentSmsCount > 0) {
                    $actionMessage = "Στάλθηκαν επιτυχώς {$sentSmsCount} μηνύματα SMS στα κινητά των κηδεμόνων!";
                    if ($skippedEmailCount > 0) {
                        $actionMessage .= " ({$skippedEmailCount} μαθητές εξαιρέθηκαν αυτόματα επειδή έχουν δηλώσει Email - Smart Fallback).";
                    }
                }
                if ($failedSmsCount > 0 && empty($actionMessage)) {
                    $actionError = "Αποτυχία αποστολής SMS για {$failedSmsCount} παραλήπτες. " . implode(' | ', array_slice($errorsList, 0, 3));
                }
            }
        }
    }
}

// 5. Fetch Grades & Sections for filters
$allGrades = $db->query("SELECT id, name FROM grades ORDER BY name ASC")->fetchAll();
$allSections = $db->query("
    SELECT s.id, s.name, s.grade_id, g.name as grade_name 
    FROM sections s 
    JOIN grades g ON s.grade_id = g.id 
    ORDER BY g.name ASC, s.name ASC
")->fetchAll();

// Validate that selected section belongs to selected grade
if ($filterGradeId > 0 && $filterSectionId > 0) {
    $secBelongs = false;
    foreach ($allSections as $sec) {
        if ($sec['id'] == $filterSectionId && $sec['grade_id'] == $filterGradeId) {
            $secBelongs = true;
            break;
        }
    }
    if (!$secBelongs) {
        $filterSectionId = 0;
    }
}

// 6. Query Students matching Grade and Section filters
$studentSql = "
    SELECT 
        s.id as student_id,
        s.am,
        s.last_name,
        s.first_name,
        s.father_name,
        s.mother_name,
        s.gender,
        s.last_name_gen,
        s.first_name_gen,
        s.guardian_name,
        s.guardian_name_voc,
        s.guardian_email,
        s.guardian_mobile,
        s.guardian_email_2,
        s.guardian_mobile_2,
        GROUP_CONCAT(DISTINCT sec.id) as section_ids,
        GROUP_CONCAT(DISTINCT sec.name ORDER BY sec.name SEPARATOR ', ') as section_names,
        GROUP_CONCAT(DISTINCT g.id) as grade_ids,
        GROUP_CONCAT(DISTINCT g.name SEPARATOR ', ') as grade_names
    FROM students s
    LEFT JOIN {$sectionMembershipSql} ss ON s.id = ss.student_id
    LEFT JOIN sections sec ON ss.section_id = sec.id
    LEFT JOIN grades g ON sec.grade_id = g.id
    WHERE s.academic_year_id = ?
";
$studentParams = [$activeYearId];
if ($filterGradeId > 0) {
    $studentSql .= " AND g.id = ? ";
    $studentParams[] = $filterGradeId;
}
if ($filterSectionId > 0) {
    $studentSql .= " AND sec.id = ? ";
    $studentParams[] = $filterSectionId;
}
$studentSql .= " GROUP BY s.id ORDER BY s.last_name ASC, s.first_name ASC";
$stmtStudents = $db->prepare($studentSql);
$stmtStudents->execute($studentParams);
$studentRows = $stmtStudents->fetchAll();

// 7. Fetch all absences for selected date
$stmtAtt = $db->prepare("
    SELECT 
        a.id as attendance_id,
        a.student_id,
        a.hour,
        a.section_id,
        a.email_notified,
        a.absence_type,
        a.note,
        sec.name as section_name,
        u.name as teacher_name
    FROM attendance a
    JOIN sections sec ON a.section_id = sec.id
    JOIN users u ON a.teacher_id = u.id
    WHERE a.date = ? AND a.academic_year_id = ? AND a.is_absent = 1
    ORDER BY a.hour ASC
");
$stmtAtt->execute([$selectedDate, $activeYearId]);
$attRows = $stmtAtt->fetchAll();

$absencesByStudent = [];
foreach ($attRows as $ar) {
    $absencesByStudent[$ar['student_id']][] = $ar;
}

// Fetch all independent hour notes for this date
$stmtInitialNotes = $db->prepare("
    SELECT student_id, hour, note 
    FROM attendance_notes 
    WHERE date = ? AND academic_year_id = ?
");
$stmtInitialNotes->execute([$selectedDate, $activeYearId]);
$notesByStudent = [];
while ($nr = $stmtInitialNotes->fetch(PDO::FETCH_ASSOC)) {
    $notesByStudent[(int)$nr['student_id']][(int)$nr['hour']] = $nr['note'];
}

// 8. Build $grouped and apply $statusFilter
$emailDeliveryLog=emailDeliveryLedger($db,$selectedDate,$activeYearId);
$grouped = [];
$totalAbsentStudents = 0;
$totalAbsenceHours = 0;
$totalExpulsions = 0;
$totalJustified = 0;
$totalPendingEmails = 0;

foreach ($studentRows as $s) {
    $sid = $s['student_id'];
    $sAtts = $absencesByStudent[$sid] ?? [];
    $hasAbsence = count($sAtts) > 0;

    if ($hasAbsence) {
        $totalAbsentStudents++;
        $totalAbsenceHours += count($sAtts);
    }

    $hasExpulsion = false;
    $hasJustified = false;
    $hasPending = false;
    foreach ($sAtts as $a) {
        if ($a['absence_type'] === 'expulsion') {
            $hasExpulsion = true;
            $totalExpulsions++;
        } elseif ($a['absence_type'] === 'justified') {
            $hasJustified = true;
            $totalJustified++;
        }
        if (!$a['email_notified']) {
            $hasPending = true;
        }
    }
    $deliveryStatus=emailDeliveryState($emailDeliveryLog,(int)$sid,$selectedDate,$sAtts);
    if($deliveryStatus==='failed')$hasPending=true;
    if ($hasPending) {
        $totalPendingEmails++;
    }

    // Filter condition
    $include = false;
    if ($statusFilter === 'all') {
        $include = true;
    } elseif ($statusFilter === 'absent_only') {
        $include = $hasAbsence;
    } elseif ($statusFilter === 'present_only') {
        $include = !$hasAbsence;
    } elseif ($statusFilter === 'expulsion_only') {
        $include = $hasExpulsion;
    } elseif ($statusFilter === 'justified_only') {
        $include = $hasJustified;
    } else {
        $include = $hasAbsence;
    }

    if (!$include) {
        continue;
    }

    // Populate student item
    $sectionsArr = [];
    if (!empty($s['section_names'])) {
        foreach (explode(', ', $s['section_names']) as $sn) {
            $sectionsArr[$sn] = true;
        }
    }

    $hoursArr = [];
    $attendancesList = [];
    foreach ($sAtts as $a) {
        $hLabel = $a['hour'] . 'η';
        if ($a['absence_type'] === 'expulsion') {
            $hLabel .= ' (Ω.Α.)';
        } elseif ($a['absence_type'] === 'justified') {
            $hLabel .= ' (Δικ.)';
        }
        $hoursArr[] = $hLabel;
        $attendancesList[] = [
            'id' => $a['attendance_id'],
            'hour' => $a['hour'],
            'section_id' => $a['section_id'],
            'teacher' => $a['teacher_name'],
            'section' => $a['section_name'],
            'notified' => $a['email_notified'],
            'absence_type' => $a['absence_type'] ?: 'absent',
            'note' => $a['note'] ?? null
        ];
    }

    $primarySecId = 0;
    if (!empty($s['section_ids'])) {
        $secIdsArr = explode(',', $s['section_ids']);
        $primarySecId = (int)$secIdsArr[0];
    }

    $grouped[$sid] = [
        'student_id' => $sid,
        'am' => $s['am'],
        'gender' => $s['gender'] ?: 'male',
        'last_name' => $s['last_name'],
        'first_name' => $s['first_name'],
        'name' => $s['last_name'] . ' ' . $s['first_name'],
        'father_name' => $s['father_name'],
        'mother_name' => $s['mother_name'],
        'last_name_gen' => $s['last_name_gen'],
        'first_name_gen' => $s['first_name_gen'],
        'guardian_name' => $s['guardian_name'],
        'guardian_name_voc' => $s['guardian_name_voc'],
        'guardian_email' => $s['guardian_email'],
        'guardian_mobile' => $s['guardian_mobile'],
        'guardian_email_2' => $s['guardian_email_2'],
        'guardian_mobile_2' => $s['guardian_mobile_2'],
        'primary_section_id' => $primarySecId,
        'sections' => $sectionsArr,
        'hours' => $hoursArr,
        'has_pending_email' => $hasPending,
        'email_delivery_status'=>$deliveryStatus,
        'attendances' => $attendancesList,
            'notes' => $notesByStudent[$sid] ?? [],
        'preview_subject' => '',
        'preview_body' => '',
        'recipients' => array_filter([$s['guardian_email'], $s['guardian_email_2']])
    ];

    // Email preview if absent
    if ($hasAbsence) {
        $gender = $s['gender'] ?: 'male';
        $studentTitle = get_student_title($gender);
        $studentTitleGen = get_student_title_gen($gender);
        $studentNoun = get_student_noun($gender);
        $studentArticle = get_student_article($gender);
        $studentArticleGen = get_student_article_gen($gender);

        $studentFullName = $s['last_name'] . ' ' . $s['first_name'];
        $studentGenitive = ($s['last_name_gen'] ?: $s['last_name']) . ' ' . ($s['first_name_gen'] ?: $s['first_name']);
        if ($maskStudentName) {
            $studentFullName = mask_student_name($studentFullName);
            $studentGenitive = mask_student_name($studentGenitive);
        }
        $guardianVoc = $s['guardian_name_voc'] ?: ($s['guardian_name'] ? 'κ./κα ' . inflect_word_vocative($s['last_name']) : 'γονέα');
        $formattedDate = date('d/m/Y', strtotime($selectedDate));
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

        $grouped[$sid]['preview_subject'] = str_replace(array_keys($replacements), array_values($replacements), $subjectTemplate);
        $grouped[$sid]['preview_body'] = str_replace(array_keys($replacements), array_values($replacements), $bodyTemplate);
    }
}

// All students list for new absence modal (scoped to active academic year)
$stmtModalStudents = $db->prepare("
    SELECT s.id, s.am, s.last_name, s.first_name, s.gender,
           GROUP_CONCAT(DISTINCT sec.id) as section_ids,
           GROUP_CONCAT(DISTINCT sec.name ORDER BY sec.name SEPARATOR ', ') as section_names
    FROM students s
    LEFT JOIN {$sectionMembershipSql} ss ON s.id = ss.student_id
    LEFT JOIN sections sec ON ss.section_id = sec.id
    WHERE s.academic_year_id = ?
    GROUP BY s.id
    ORDER BY s.last_name ASC, s.first_name ASC
");
$stmtModalStudents->execute([$activeYearId]);
$allStudentsList = $stmtModalStudents->fetchAll();

$allSectionsList = $allSections;

// 9. Real-time Live Sync State Hash & Student Catalog
$stmtInitialState = $db->prepare("SELECT MAX(updated_at) as max_updated, COUNT(*) as total_records FROM attendance WHERE date = ? AND academic_year_id = ?");
$stmtInitialState->execute([$selectedDate, $activeYearId]);
$initInfo = $stmtInitialState->fetch(PDO::FETCH_ASSOC);
$serverInitialStateHash = md5(($initInfo['max_updated'] ?? 'none') . '_' . ($initInfo['total_records'] ?? 0) . '_' . $filterGradeId . '_' . $filterSectionId . '_' . $statusFilter);

$studentsCatalog = [];
foreach ($studentRows as $sr) {
    $secNames = $sr['section_names'] ?? '';
    $secNamesArr = !empty($secNames) ? explode(', ', $secNames) : [];
    $secMap = [];
    foreach ($secNamesArr as $sn) $secMap[$sn] = true;
    $pSecId = !empty($sr['section_ids']) ? (int)explode(',', $sr['section_ids'])[0] : 0;
    $studentsCatalog[(int)$sr['student_id']] = [
        'student_id' => (int)$sr['student_id'],
        'am' => $sr['am'],
        'last_name' => $sr['last_name'],
        'first_name' => $sr['first_name'],
        'name' => $sr['last_name'] . ' ' . $sr['first_name'],
        'gender' => $sr['gender'] ?: 'male',
        'section_names' => $secNames,
        'sections' => $secMap,
        'primary_section_id' => $pSecId,
        'notes' => $notesByStudent[(int)$sr['student_id']] ?? [],
        'guardian_name' => $sr['guardian_name'] ?? '',
        'guardian_email' => $sr['guardian_email'] ?? '',
        'guardian_mobile' => $sr['guardian_mobile'] ?? '',
        'guardian_email_2' => $sr['guardian_email_2'] ?? '',
        'guardian_mobile_2' => $sr['guardian_mobile_2'] ?? ''
    ];
};

?>

<style>
.live-status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #ecfdf5;
    color: #065f46;
    border: 1px solid #a7f3d0;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    box-shadow: 0 1px 2px rgba(0,0,0,0.05);
}
.live-pulse-dot {
    width: 8px;
    height: 8px;
    background: #10b981;
    border-radius: 50%;
    box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
    animation: liveDotPulse 2s infinite;
}
@keyframes liveDotPulse {
    0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
    70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
}
@keyframes rowHighlightFlash {
    0% { background-color: #fef08a !important; transform: scale(1.002); }
    40% { background-color: #fef9c3 !important; }
    100% { background-color: transparent !important; transform: scale(1); }
}
.row-highlight-flash {
    animation: rowHighlightFlash 2.5s ease-out;
}
.live-toast {
    background: #ffffff;
    border: 1px solid var(--border);
    border-left: 5px solid var(--primary);
    border-radius: 10px;
    padding: 12px 16px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.12);
    font-size: 13px;
    color: var(--text-main);
    display: flex;
    align-items: flex-start;
    gap: 10px;
    animation: toastSlideUp 0.3s ease;
    transition: opacity 0.3s ease, transform 0.3s ease;
}
@keyframes toastSlideUp {
    from { transform: translateY(20px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}
/* Inline Absences Grid Styles */
.inline-hours-grid {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    background: #f1f5f9;
    border: 1px solid #cbd5e1;
    padding: 3px 5px;
    border-radius: 8px;
    box-shadow: inset 0 1px 2px rgba(0,0,0,0.04);
}
.btn-hour-pill {
    width: 26px;
    height: 26px;
    border-radius: 5px;
    font-size: 11.5px;
    font-weight: 700;
    cursor: pointer;
    border: 1px solid transparent;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all 0.12s ease;
    user-select: none;
    padding: 0;
    line-height: 1;
}
/* Corner badge indicator when an hour has a note */
.btn-hour-pill {
    position: relative;
}
.btn-hour-pill.has-note::after {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 0;
    height: 0;
    border-top: 7px solid #f59e0b;
    border-left: 7px solid transparent;
    border-top-right-radius: 4px;
    pointer-events: none;
    filter: drop-shadow(0 0 1px rgba(0,0,0,0.35));
}
.btn-hour-pill.btn-hour-expulsion.has-note::after {
    border-top-color: #ffffff;
}
.btn-hour-pill.btn-hour-neutral.has-note::after {
    border-top-color: #f59e0b;
}
[data-theme="dark"] .btn-hour-pill.has-note::after {
    border-top-color: #fbbf24;
}

.btn-hour-neutral {
    background: #ffffff;
    color: #64748b;
    border-color: #cbd5e1;
}
.btn-hour-neutral:hover {
    background: #fee2e2;
    color: #dc2626;
    border-color: #f87171;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(220, 38, 38, 0.15);
}
.btn-hour-absent {
    background: #ef4444;
    color: #ffffff;
    border-color: #dc2626;
    box-shadow: 0 1px 3px rgba(239, 68, 68, 0.35);
}
.btn-hour-absent:hover {
    background: #b91c1c;
    border-color: #991b1b;
    transform: translateY(-1px);
}
.btn-hour-expulsion {
    background: #f59e0b;
    color: #ffffff;
    border-color: #d97706;
}
.btn-hour-expulsion:hover {
    background: #b45309;
}
.btn-hour-justified {
    background: #2563eb;
    color: #ffffff;
    border-color: #1d4ed8;
}
.btn-hour-justified:hover {
    background: #1e40af;
}
.btn-hour-all {
    margin-left: 3px;
    height: 26px;
    padding: 0 6px;
    border-radius: 5px;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
    border: 1px solid #cbd5e1;
    background: #ffffff;
    color: #475569;
    transition: all 0.12s ease;
    white-space: nowrap;
}
.btn-hour-all:hover {
    background: #4f46e5;
    color: #ffffff;
    border-color: #4338ca;
}
.btn-hour-all.active-all {
    background: #ef4444;
    color: #ffffff;
    border-color: #dc2626;
}
.btn-hour-all.active-all:hover {
    background: #10b981;
    color: #ffffff;
    border-color: #059669;
}
.btn-hour-pill.loading, .btn-hour-all.loading {
    opacity: 0.5;
    pointer-events: none;
    transform: scale(0.92);
}
.btn-tool-mode {
    border: 1px solid transparent;
    background: transparent;
    padding: 5px 12px;
    font-size: 12px;
    font-weight: 600;
    color: #475569;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.15s ease;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    user-select: none;
}
.btn-tool-mode:hover {
    background: #f1f5f9;
    color: var(--text-main);
}
.btn-tool-mode.active {
    font-weight: 700;
    box-shadow: 0 1px 2px rgba(0,0,0,0.08);
}
.btn-tool-mode.active[data-mode="absent"] {
    background: #fee2e2;
    color: #991b1b;
    border-color: #fca5a5;
}
.btn-tool-mode.active[data-mode="expulsion"] {
    background: #fef3c7;
    color: #92400e;
    border-color: #fcd34d;
}
.btn-tool-mode.active[data-mode="justified"] {
    background: #dbeafe;
    color: #1e40af;
    border-color: #93c5fd;
}
.btn-tool-mode.active[data-mode="cycle"] {
    background: #f3e8ff;
    color: #6b21a8;
    border-color: #d8b4fe;
}
.hour-context-menu {
    position: fixed;
    z-index: 100000;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.18);
    padding: 6px;
    display: none;
    min-width: 180px;
    animation: menuPop 0.12s ease-out;
}
@keyframes menuPop {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
}
.hour-context-item {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    padding: 7px 10px;
    font-size: 12.5px;
    font-weight: 600;
    color: #334155;
    border-radius: 5px;
    border: none;
    background: transparent;
    cursor: pointer;
    text-align: left;
    transition: background 0.1s ease;
}
.hour-context-item:hover {
    background: #f1f5f9;
}
.hour-context-item.danger:hover {
    background: #fee2e2;
    color: #b91c1c;
}
</style>
<link rel="stylesheet" href="../assets/css/dashboard-responsive.css?v=20261029-5">
<link rel="stylesheet" href="../assets/css/ui-components.css?v=20261029-2">
<link rel="stylesheet" href="../assets/css/bulk-notification-preview.css?v=20261029-2">
<script src="../assets/js/bulk-notification-preview.js?v=20261029-3" defer></script>
<div class="print-only" style="margin-bottom:20px; border-bottom:2px solid #333; padding-bottom:12px; text-align:center;">
    <h2 style="margin:0; font-size:16pt;"><?= htmlspecialchars($schoolName) ?></h2>
    <h3 style="margin:4px 0; font-size:13pt;">ΗΜΕΡΗΣΙΟ ΔΕΛΤΙΟ ΑΠΟΥΣΙΩΝ</h3>
    <div style="font-size:11pt; margin-top:4px;">
        Ημερομηνία: <strong><?= date('d/m/Y', strtotime($selectedDate)) ?></strong> &bull; 
        Σχολικό Έτος: <strong><?= htmlspecialchars($activeAcademicYear['name'] ?? '2026-2027') ?></strong>
    </div>
</div>
<div class="content-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; margin-bottom:20px;">
    <div>
        <h1 style="font-size:22px; font-weight:700; margin:0 0 4px 0; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <span>📋</span> Ημερήσιο Παρουσιολόγιο & Απουσίες
            <span class="live-status-badge" id="liveStatusBadge" data-state="connecting" role="status" aria-live="polite" title="Έλεγχος αυτόματης ενημέρωσης">
                <span class="live-pulse-dot" aria-hidden="true"></span>
                <span id="liveStatusLabel">Έλεγχος σύνδεσης…</span>
            </span>
        </h1>
        <p style="color:var(--text-muted); font-size:13px; margin:0;">
            Διαχείριση, καταχώρηση, φιλτράρισμα και αυτόματος συγχρονισμός σε πραγματικό χρόνο. Τελευταία επιτυχής ενημέρωση: <span id="liveLastSyncTime" style="font-weight:600; color:var(--text-main);"><?= date('H:i:s') ?></span>
        </p>
        <div class="live-sync-actions">
            <span id="liveSyncWarning" hidden>Η αυτόματη ενημέρωση διακόπηκε. Τα στοιχεία στην οθόνη μπορεί να μην είναι πρόσφατα.</span>
            <button type="button" id="liveRetryButton" class="btn btn-secondary btn-sm" hidden>Δοκιμή ξανά</button>
        </div>
        
    </div>
    <div class="dashboard-page-actions no-print">
        <button type="button" class="btn btn-secondary btn-sm" onclick="window.print()" aria-label="Εκτύπωση Δελτίου" style="display:inline-flex; align-items:center; gap:6px;" title="Εκτύπωση Επίσημου Ημερήσιου Δελτίου Απουσιών">
            <span>🖨️</span> <strong class="mobile-action-label">Εκτύπωση Δελτίου</strong>
        </button>
        <button type="button" class="btn btn-primary btn-sm" onclick="openNewAbsenceModal()" aria-label="Καταχώρηση Απουσίας" title="Καταχώρηση Απουσίας" style="display:inline-flex; align-items:center; gap:6px;">
            <span>➕</span> <strong class="mobile-action-label">Καταχώρηση Απουσίας</strong>
        </button>
        <a href="../teacher/index.php" class="btn btn-primary btn-sm dashboard-class-link" title="Μετάβαση στο Παρουσιολόγιο Τμήματος">👨‍🏫 Προβολή Τμήματος</a>
    </div>
</div>

<?php if (!empty($actionMessage)): ?>
    <div class="alert alert-success" style="margin-bottom:16px; padding:12px 16px; border-radius:8px; background:#d1fae5; color:#065f46; border:1px solid #a7f3d0; font-size:14px;">
        <?= htmlspecialchars($actionMessage) ?>
    </div>
<?php endif; ?>

<?php if (!empty($actionError)): ?>
    <div class="alert alert-danger" style="margin-bottom:16px; padding:12px 16px; border-radius:8px; background:#fee2e2; color:#991b1b; border:1px solid #fecaca; font-size:14px;">
        <?= htmlspecialchars($actionError) ?>
    </div>
<?php endif; ?>

<!-- FILTER TOOLBAR -->
<div class="card attendance-filters" style="margin-bottom:20px; padding:14px 18px; border-radius:12px; border:1px solid var(--border); background:#fff; box-shadow:var(--shadow-sm);">
    <form method="GET" action="index.php" id="filterForm" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
        <div style="flex:1; min-width:140px;">
            <label for="dateSelect" style="display:block; font-size:12px; font-weight:600; color:var(--text-muted); margin-bottom:4px;">Ημερομηνία:</label>
            <input type="date" id="dateSelect" name="date" value="<?= htmlspecialchars($selectedDate) ?>" class="form-control" style="font-size:13px; padding:6px 10px;" onchange="this.form.submit()">
        </div>

        <div style="flex:1; min-width:130px;">
            <label for="gradeSelect" style="display:block; font-size:12px; font-weight:600; color:var(--text-muted); margin-bottom:4px;">Τάξη:</label>
            <select name="grade_id" id="gradeSelect" class="form-control" style="font-size:13px; padding:6px 10px;" onchange="onGradeFilterChange(this)">
                <option value="">Όλες οι Τάξεις</option>
                <?php foreach ($allGrades as $g): ?>
                    <option value="<?= $g['id'] ?>" <?= $filterGradeId == $g['id'] ? 'selected' : '' ?>>
                        Τάξη <?= htmlspecialchars($g['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="flex:1.5; min-width:160px;">
            <label for="sectionSelect" style="display:block; font-size:12px; font-weight:600; color:var(--text-muted); margin-bottom:4px;">Τμήμα:</label>
            <select name="section_id" id="sectionSelect" class="form-control" style="font-size:13px; padding:6px 10px;" onchange="this.form.submit()">
                <option value="">Όλα τα Τμήματα<?= $filterGradeId > 0 ? ' της Τάξης' : '' ?></option>
                <?php foreach ($allSections as $sec): ?>
                    <?php if ($filterGradeId > 0 && $sec['grade_id'] != $filterGradeId) continue; ?>
                    <option value="<?= $sec['id'] ?>" data-grade="<?= $sec['grade_id'] ?>" <?= $filterSectionId == $sec['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($sec['name']) ?><?= $filterGradeId <= 0 ? ' (Τάξη ' . htmlspecialchars($sec['grade_name']) . ')' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="flex:1.8; min-width:200px;">
            <label for="statusFilterSelect" style="display:block; font-size:12px; font-weight:600; color:var(--text-muted); margin-bottom:4px;">Κατάσταση Μαθητών:</label>
            <select name="status_filter" id="statusFilterSelect" class="form-control" style="font-size:13px; padding:6px 10px; font-weight:600;" onchange="this.form.submit()">
                <option value="absent_only" <?= $statusFilter === 'absent_only' ? 'selected' : '' ?>>🔴 Μόνο με Απουσίες (Προεπιλογή)</option>
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>👥 Όλοι οι Μαθητές (και οι Παρόντες)</option>
                <option value="present_only" <?= $statusFilter === 'present_only' ? 'selected' : '' ?>>🟢 Μόνο Παρόντες (0 Απουσίες)</option>
                <option value="expulsion_only" <?= $statusFilter === 'expulsion_only' ? 'selected' : '' ?>>🟠 Μόνο με Ωριαία Αποβολή</option>
                <option value="justified_only" <?= $statusFilter === 'justified_only' ? 'selected' : '' ?>>🔵 Μόνο με Δικαιολογημένη</option>
            </select>
        </div>

        <div style="display:flex; gap:6px;">
            <?php if ($selectedDate !== date('Y-m-d')): ?>
                <a href="index.php?date=<?= date('Y-m-d') ?>&grade_id=<?= $filterGradeId ?>&section_id=<?= $filterSectionId ?>&status_filter=<?= urlencode($statusFilter) ?>" class="btn btn-secondary btn-sm" style="padding:7px 12px; font-size:12px;">Σήμερα</a>
            <?php endif; ?>
            <button type="submit" class="btn btn-secondary btn-sm" style="padding:7px 14px; font-size:12px;">🔍 Φίλτρο</button>
        </div>
    </form>
</div>

<!-- KPI Stats Cards -->
<div class="attendance-kpis" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:14px; margin-bottom:20px;">
    <div class="card" style="padding:16px; border-radius:12px; border:1px solid var(--border); background:#fff; box-shadow:var(--shadow-sm);">
        <div style="font-size:12px; font-weight:600; color:var(--text-muted); text-transform:uppercase;">Εμφανιζονται</div>
        <div style="font-size:24px; font-weight:700; color:var(--text-main); margin-top:4px;" id="kpi-displayed"><?= count($grouped) ?> μαθητές</div>
    </div>
    <div class="card" style="padding:16px; border-radius:12px; border:1px solid var(--border); background:#fff; box-shadow:var(--shadow-sm);">
        <div style="font-size:12px; font-weight:600; color:var(--text-muted); text-transform:uppercase;">Μαθητες με Απουσια</div>
        <div style="font-size:24px; font-weight:700; color:var(--danger); margin-top:4px;" id="kpi-absent-students"><?= $totalAbsentStudents ?></div>
    </div>
    <div class="card" style="padding:16px; border-radius:12px; border:1px solid var(--border); background:#fff; box-shadow:var(--shadow-sm);">
        <div style="font-size:12px; font-weight:600; color:var(--text-muted); text-transform:uppercase;">Ωρες Απουσιας</div>
        <div style="font-size:24px; font-weight:700; color:var(--primary); margin-top:4px;">
            <span id="kpi-total-hours"><?= $totalAbsenceHours ?></span>
            <?php if ($totalExpulsions > 0 || $totalJustified > 0): ?>
                <span style="font-size:12px; font-weight:normal; color:var(--text-muted);">
                    (<?= $totalExpulsions ?> Ω.Α., <?= $totalJustified ?> Δικ.)
                </span>
            <?php endif; ?>
        </div>
    </div>
    <div class="card" style="padding:16px; border-radius:12px; border:1px solid var(--border); background:#fff; box-shadow:var(--shadow-sm);">
        <div style="font-size:12px; font-weight:600; color:var(--text-muted); text-transform:uppercase;">Εκκρεμεις Ειδοποιησεις</div>
        <div style="font-size:24px; font-weight:700; color:<?= $totalPendingEmails > 0 ? 'var(--warning)' : 'var(--success)' ?>; margin-top:4px;" id="kpi-pending-emails">
            <?= $totalPendingEmails ?>
        </div>
    </div>
</div>

<!-- Main Absences Table Card -->
<div class="card" style="border-radius:12px; border:1px solid var(--border); background:#fff; box-shadow:var(--shadow-sm); overflow:hidden;">
    <form method="POST" id="bulkEmailForm" action="index.php?date=<?= urlencode($selectedDate) ?>&grade_id=<?= $filterGradeId ?>&section_id=<?= $filterSectionId ?>&status_filter=<?= urlencode($statusFilter) ?>">
        <?= csrfField() ?>
        <div style="padding:14px 18px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; background:#fbfcfe;">
            <div style="font-size:15px; font-weight:700; color:var(--text-main); display:flex; align-items:center; gap:8px;">
                <span>📅 Παρουσιολόγιο: <?= date('d/m/Y', strtotime($selectedDate)) ?></span> <span class="badge badge-success" style="font-size:12px; margin-left:8px;" title="Τρέχον Σχολικό Έτος">Έτος <?= htmlspecialchars($activeAcademicYear['name'] ?? '') ?></span>
                <span id="attendanceRecordCount" class="badge badge-info" style="font-size:12px;"><?= count($grouped) ?> εγγραφές</span>
                <?php if ($statusFilter === 'all'): ?>
                    <span class="badge badge-gray" style="font-size:11px;">(Όλοι οι μαθητές)</span>
                <?php elseif ($statusFilter === 'present_only'): ?>
                    <span class="badge badge-success" style="font-size:11px;">(Μόνο Παρόντες)</span>
                <?php endif; ?>
            </div>

            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                <button type="submit" name="send_consolidated_emails" value="1" id="btnBulkSendEmail" disabled class="btn btn-bulk-email-custom" onclick="return handleBulkEmailSubmit(event);" style="display: <?= $totalPendingEmails > 0 ? 'inline-flex' : 'none' ?>;" title="Μαζική αποστολή email ειδοποίησης στους κηδεμόνες">
                    <span style="font-size:15px; line-height:1;">✉️</span>
                    <span>Αποστολή Email στους Επιλεγμένους</span>
                    <span id="bulkEmailCount" class="bulk-email-counter-badge">0</span>
                </button>

                <?php if ($smsEnabled): ?>
                <button type="submit" name="send_consolidated_sms" value="1" id="btnBulkSendSms" disabled class="btn btn-bulk-sms-custom" onclick="return handleBulkSmsSubmit(event);" style="display: <?= $totalPendingEmails > 0 ? 'inline-flex' : 'none' ?>;" title="Μαζική αποστολή SMS στα κινητά των κηδεμόνων">
                    <span style="font-size:15px; line-height:1;">📱</span>
                    <span>Αποστολή SMS στους Επιλεγμένους</span>
                    <span id="bulkSmsCount" class="bulk-sms-counter-badge">0</span>
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Toolbar: Active Grid Tool Selector -->
        <div style="background:#f8fafc; padding:9px 18px; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                <span style="font-size:12px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px;">Εργαλειο Κλικ Πλεγματος:</span>
                <div class="inline-mode-selector" style="display:inline-flex; background:#fff; border:1px solid #cbd5e1; border-radius:8px; padding:2px; gap:3px; box-shadow:0 1px 2px rgba(0,0,0,0.04);">
                    <button type="button" class="btn-tool-mode active" data-mode="absent" onclick="setGridToolMode('absent')" title="Κλικ σε ώρα: Καταχώρηση/αφαίρεση Κοινής Απουσίας">
                        🔴 Κοινή Απουσία
                    </button>
                    <button type="button" class="btn-tool-mode" data-mode="expulsion" onclick="setGridToolMode('expulsion')" title="Κλικ σε ώρα: Καταχώρηση/αφαίρεση Ωριαίας Αποβολής">
                        🟠 Ωριαία Αποβολή
                    </button>
                    <?php if (canJustifyAbsences()): ?>
                    <button type="button" class="btn-tool-mode" data-mode="justified" onclick="setGridToolMode('justified')" title="Κλικ σε ώρα: Καταχώρηση/αφαίρεση Δικαιολογημένης Απουσίας (Μόνο Διαχειριστής)">
                        🔵 Δικαιολογημένη
                    </button>
                    <?php endif; ?>
                    <button type="button" class="btn-tool-mode" data-mode="cycle" onclick="setGridToolMode('cycle')" title="Διαδοχική εναλλαγή σε κάθε κλικ: Παρών ➔ Κοινή ➔ Αποβολή ➔ Δικαιολογημένη ➔ Παρών">
                        🔄 Κυκλική Εναλλαγή
                    </button>
                </div>
            </div>
            <div style="font-size:12px; color:var(--text-muted); display:flex; align-items:center; gap:8px;">
                <span>💡 <em>Δεξί κλικ σε οποιονδήποτε αριθμό ώρας ανοίγει άμεσο μενού επιλογής</em></span>
            </div>
        </div>

            <div id="attendanceEmptyState" style="text-align:center; padding:48px 20px;<?= empty($grouped) ? '' : ' display:none;' ?>">
                <div style="font-size:48px; margin-bottom:12px;">🔍</div>
                <h3 style="font-size:17px; font-weight:700; color:var(--text-main); margin-bottom:6px;">Δεν βρέθηκαν αποτελέσματα</h3>
                <p style="color:var(--text-muted); font-size:13px; max-width:460px; margin:0 auto 18px auto;">
                    Δεν βρέθηκαν μαθητές με τα επιλεγμένα φίλτρα για την ημερομηνία <strong><?= date('d/m/Y', strtotime($selectedDate)) ?></strong>.
                </p>
                <div style="display:flex; justify-content:center; gap:8px;">
                    <a href="index.php?date=<?= urlencode($selectedDate) ?>&status_filter=all" class="btn btn-secondary btn-sm">Εμφάνιση Όλων των Μαθητών</a>
                    <button type="button" class="btn btn-primary btn-sm" onclick="openNewAbsenceModal()">➕ Καταχώρηση Απουσίας</button>
                </div>
            </div>
            <div id="attendanceTableContainer" style="overflow-x:auto;<?= empty($grouped) ? ' display:none;' : '' ?>">
                <table class="attendance-table" style="width:100%; border-collapse:collapse; font-size:13px; text-align:left;">
                    <thead>
                        <tr style="background:#f8fafc; border-bottom:1px solid var(--border); color:var(--text-muted); font-size:12px; text-transform:uppercase;">
                            <th style="padding:10px 12px; width:36px; text-align:center;">
                                <input type="checkbox" id="masterCheckbox" onclick="toggleSelectAll(this)" title="Επιλογή όλων">
                            </th>
                            <th style="padding:10px 12px; width:60px;">Α.Μ.</th>
                            <th style="padding:10px 12px;">Μαθητής / Τμήμα</th>
                            <th style="padding:10px 12px; min-width:270px;">Ώρες Απουσίας (1η-7η) / Πλέγμα</th>
                            <th style="padding:10px 12px; width:70px; text-align:center;">Σύνολο</th>
                            <th style="padding:10px 12px;">Κηδεμόνες & Επαφή</th>
                            <th style="padding:10px 12px; text-align:center;">Ειδοποίηση Email</th>
                            <th style="padding:10px 12px; text-align:right;">Ενέργειες</th>
                        </tr>
                    </thead>
                    <tbody id="attendanceTableBody">
                        <?php foreach ($grouped as $studentId => $st): ?>
                            <?php $hasAtt = !empty($st['attendances']); ?>
                            <tr id="student-row-<?= $studentId ?>" class="student-attendance-row" data-student-id="<?= $studentId ?>" data-am="<?= htmlspecialchars($st['am']) ?>" data-name="<?= htmlspecialchars($st['name']) ?>" data-gender="<?= $st['gender'] ?>" style="border-bottom:1px solid var(--border); transition:background 0.2s ease;" onmouseover="this.style.background='#fdfefe'" onmouseout="this.style.background='transparent'">
                                <td id="check-cell-<?= $studentId ?>" style="padding:10px 12px; text-align:center;">
                                    <?php if ($hasAtt && $st['has_pending_email'] && (!empty($st['guardian_email']) || !empty($st['guardian_email_2']))): ?>
                                        <input type="checkbox" name="student_ids[]" value="<?= $studentId ?>" class="student-checkbox">
                                    <?php else: ?>
                                        <span style="color:var(--text-muted); font-size:11px;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:10px 12px; font-weight:600; color:var(--text-muted); font-family:monospace;">
                                    <?= htmlspecialchars($st['am']) ?>
                                </td>
                                <td style="padding:10px 12px;">
                                    <div style="font-weight:700; color:var(--text-main); font-size:14px;">
                                        <?= htmlspecialchars($st['name']) ?>
                                        <span style="font-size:11px; font-weight:normal; color:var(--text-muted);">
                                            (<?= $st['gender'] === 'female' ? 'Μαθήτρια' : 'Μαθητής' ?>)
                                            <?php if (!empty($st['father_name'])): ?> • π: <?= htmlspecialchars($st['father_name']) ?><?php endif; ?>
                                            <?php if (!empty($st['mother_name'])): ?> • μ: <?= htmlspecialchars($st['mother_name']) ?><?php endif; ?>
                                        </span>
                                    </div>
                                    <div style="display:flex; gap:4px; margin-top:3px; flex-wrap:wrap;">
                                        <?php foreach (array_keys($st['sections']) as $secName): ?>
                                            <span class="badge badge-info" style="font-size:10px;"><?= htmlspecialchars($secName) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td id="hours-cell-<?= $studentId ?>" style="padding:10px 12px;">
                                    <?php
                                        $studentHoursMap = [];
                                        foreach ($st['attendances'] as $att) {
                                            $studentHoursMap[(int)$att['hour']] = $att;
                                        }
                                        $totalAttCount = count($st['attendances']);
                                        $pSecId = (int)$st['primary_section_id'];
                                    ?>
                                    <div class="inline-hours-grid" data-student-id="<?= $studentId ?>" data-section-id="<?= $pSecId ?>">
                                        <?php for ($h = 1; $h <= 7; $h++): ?>
                                            <?php 
                                                $attItem = $studentHoursMap[$h] ?? null; 
                                                $isH = !empty($attItem);
                                                $type = $attItem['absence_type'] ?? '';
                                                $note = $notesByStudent[$studentId][$h] ?? '';
                                                $hasNote = ($note !== '');
                                                $btnCls = 'btn-hour-neutral';
                                                if ($isH) {
                                                    $typeName = ($type === 'expulsion') ? 'Ωριαία Αποβολή' : (($type === 'justified') ? 'Δικαιολογημένη' : 'Κοινή Απουσία');
                                                    if ($type === 'expulsion') {
                                                        $btnCls = 'btn-hour-expulsion';
                                                    } elseif ($type === 'justified') {
                                                        $btnCls = 'btn-hour-justified';
                                                    } else {
                                                        $btnCls = 'btn-hour-absent';
                                                    }
                                                    $noteSuffix = $hasNote ? " 📝 [Σημείωση: \"{$note}\"]" : "";
                                                    $titleText = "{$h}η ώρα: {$typeName}{$noteSuffix} (Κλικ για αλλαγή, Δεξί κλικ για μενού/σημείωση)";
                                                } else {
                                                    $noteSuffix = $hasNote ? " 📝 [Σημείωση: \"{$note}\"]" : "";
                                                    $titleText = "{$h}η ώρα: Παρών{$noteSuffix} (Αριστερό κλικ: καταχώρηση, Δεξί κλικ: μενού & σημείωση)";
                                                }
                                            ?>
                                            <button type="button" 
                                                    class="btn-hour-pill <?= $btnCls ?> <?= $hasNote ? 'has-note' : '' ?>" 
                                                    id="btn-h-<?= $studentId ?>-<?= $h ?>"
                                                    data-hour="<?= $h ?>"
                                                    data-current-type="<?= htmlspecialchars($type) ?>"
                                                    data-note="<?= htmlspecialchars($note) ?>"
                                                    onclick="handleInlineHourClick(<?= $studentId ?>, <?= $h ?>, <?= $pSecId ?>, event)"
                                                    oncontextmenu="handleInlineHourContextMenu(<?= $studentId ?>, <?= $h ?>, <?= $pSecId ?>, event)"
                                                    aria-label="<?= htmlspecialchars($titleText) ?>" title="<?= htmlspecialchars($titleText) ?>">
                                                <?= $h ?>
                                            </button>
                                        <?php endfor; ?>
                                        <button type="button" 
                                                class="btn-hour-all <?= $totalAttCount >= 7 ? 'active-all' : '' ?>" 
                                                id="btn-all-<?= $studentId ?>"
                                                onclick="toggleInlineAllDay(<?= $studentId ?>, <?= $pSecId ?>)" 
                                                title="<?= $totalAttCount >= 7 ? 'Καθαρισμός όλων των ωρών' : '⚡ Ολοήμερη καταχώρηση (1η-7η ώρα με βάση το επιλεγμένο εργαλείο)' ?>">
                                            <?= $totalAttCount >= 7 ? '✓ Όλες' : '⚡ 1-7' ?>
                                        </button>
                                    </div>
                                </td>
                                <td id="total-cell-<?= $studentId ?>" style="padding:10px 12px; text-align:center; font-weight:700; font-size:14px; color:<?= $hasAtt ? 'var(--primary)' : 'var(--success)' ?>;">
                                    <?= count($st['attendances']) ?>
                                </td>
                                <td style="padding:10px 12px; font-size:12px; line-height:1.4;">
                                    <?php if (!empty($st['guardian_email'])): ?>
                                        <div style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:220px;" title="<?= htmlspecialchars($st['guardian_email']) ?>">
                                            📧 <?= htmlspecialchars($st['guardian_email']) ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($st['guardian_mobile'])): ?>
                                        <div style="color:var(--text-muted); font-size:11px;">
                                            📱 <?= htmlspecialchars($st['guardian_mobile']) ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($st['guardian_email_2'])): ?>
                                        <div style="margin-top:3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:220px; color:#475569;" title="2ος: <?= htmlspecialchars($st['guardian_email_2']) ?>">
                                            📧 <span style="font-size:10px; font-weight:600; color:var(--text-muted);">(2ος)</span> <?= htmlspecialchars($st['guardian_email_2']) ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($st['guardian_mobile_2'])): ?>
                                        <div style="color:var(--text-muted); font-size:11px;">
                                            📱 <span style="font-size:10px;">(2ος)</span> <?= htmlspecialchars($st['guardian_mobile_2']) ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (empty($st['guardian_email']) && empty($st['guardian_email_2'])): ?>
                                        <span style="color:var(--danger); font-size:11px; font-weight:600;">⚠️ Χωρίς email</span>
                                    <?php endif; ?>
                                </td>
                                <td id="email-cell-<?= $studentId ?>" style="padding:10px 12px; text-align:center;">
                                    <?php if ($hasAtt): ?>
                                        <div style="display:inline-flex; align-items:center; gap:6px;">
                                            <button type="button" class="btn btn-secondary btn-sm" 
                                                    style="padding:3px 8px; font-size:11px;" 
                                                    aria-label="Προεπισκόπηση email" title="Προεπισκόπηση του email ειδοποίησης"
                                                    onclick="openEmailPreviewForStudent(<?= $studentId ?>)">
                                                👁️
                                            </button>

                                            <?php if (($st['email_delivery_status']??'')==='failed'): ?><span class="badge badge-danger" style="font-size:11px;">✕ Αποτυχία</span>
                                            <?php elseif ($st['has_pending_email']): ?>
                                                <span class="badge badge-warning" style="font-size:11px;">Εκκρεμεί</span>
                                            <?php else: ?>
                                                <span class="badge badge-success" style="font-size:11px;">Στάλθηκε</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color:var(--text-muted); font-size:12px;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td id="action-cell-<?= $studentId ?>" style="padding:10px 12px; text-align:right;"><button type="button" class="btn btn-secondary btn-sm" onclick="openStudentEmailComposer(<?= (int)$studentId ?>,'direct')">✉️ Νέο email</button>
                                    <button type="button" class="btn btn-secondary btn-sm" 
                                            style="padding:4px 10px; font-size:12px; font-weight:600;" 
                                            title="Διαχείριση ή Προσθήκη ώρας για αυτόν τον μαθητή"
                                            onclick="openManageModalForStudent(<?= $studentId ?>)">
                                        <?= $hasAtt ? '✏️ Διαχείριση / + Ώρα' : '➕ Προσθήκη Ώρας' ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
    </form>
</div>

<!-- ========================================== -->
<!-- MODAL 1: ΠΡΟΣΘΗΚΗ ΝΕΑΣ ΑΠΟΥΣΙΑΣ (GLOBAL) -->
<!-- ========================================== -->
<div id="newAbsenceModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.6); z-index:1000; align-items:center; justify-content:center; overflow-y:auto; padding:20px;">
    <div style="background:#fff; border-radius:14px; max-width:580px; width:100%; padding:24px; box-shadow:var(--shadow-lg); max-height:92vh; overflow-y:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h3 style="font-size:18px; font-weight:700; color:var(--text-main); margin:0;">➕ Καταχώρηση Απουσίας Μαθητή</h3>
            <button type="button" onclick="closeNewAbsenceModal()" aria-label="Κλείσιμο" title="Κλείσιμο" style="background:none; border:none; font-size:20px; cursor:pointer; color:var(--text-muted);">&times;</button>
        </div>

        <form method="POST" action="index.php?date=<?= urlencode($selectedDate) ?>&grade_id=<?= $filterGradeId ?>&section_id=<?= $filterSectionId ?>&status_filter=<?= urlencode($statusFilter) ?>">
            <?= csrfField() ?>
            <input type="hidden" name="add_student_absence" value="1">

            <div class="form-group">
                <label for="newAbsenceStudent" style="font-weight:600; font-size:13px;">Επιλογή Μαθητή: *</label>
                <select name="student_id" id="newAbsenceStudent" class="form-control" required onchange="onStudentSelectChanged(this)" style="font-size:13px;">
                    <option value="">-- Επιλέξτε μαθητή από τη λίστα --</option>
                    <?php foreach ($allStudentsList as $stItem): ?>
                        <option value="<?= $stItem['id'] ?>" data-sections="<?= htmlspecialchars($stItem['section_ids'] ?? '') ?>">
                            <?= htmlspecialchars($stItem['last_name'] . ' ' . $stItem['first_name']) ?> (ΑΜ: <?= htmlspecialchars($stItem['am']) ?><?= $stItem['section_names'] ? ' - ' . htmlspecialchars($stItem['section_names']) : '' ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="attendance-modal-fields" style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; margin-bottom:14px;">
                <div class="form-group" style="margin-bottom:0;">
                    <label for="newAbsenceDate" style="font-weight:600; font-size:13px;">Ημερομηνία: *</label>
                    <input type="date" name="date" id="newAbsenceDate" class="form-control" value="<?= htmlspecialchars($selectedDate) ?>" required style="font-size:13px;">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label for="newAbsenceSection" style="font-weight:600; font-size:13px;">Τμήμα:</label>
                    <select name="section_id" id="newAbsenceSection" class="form-control" style="font-size:13px;">
                        <?php foreach ($allSectionsList as $secItem): ?>
                            <option value="<?= $secItem['id'] ?>">
                                <?= htmlspecialchars($secItem['name']) ?> (<?= htmlspecialchars($secItem['grade_name']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label for="newAbsenceType" style="font-weight:600; font-size:13px;">Τύπος:</label>
                    <select name="absence_type" id="newAbsenceType" class="form-control" style="font-size:13px; font-weight:600;">
                        <option value="absent">Κοινή Απουσία</option>
                        <option value="expulsion">Ωριαία Αποβολή</option>
                        <?php if (canJustifyAbsences()): ?>
                        <option value="justified">Δικαιολογημένη Απουσία</option>
                        <?php endif; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <label style="font-weight:600; font-size:13px; margin:0;">Διδακτικές Ώρες: *</label>
                    <div style="display:flex; gap:6px;">
                        <button type="button" class="btn btn-secondary btn-sm" style="padding:2px 8px; font-size:11px;" onclick="setAllNewHours(true)">Όλες (1-8)</button>
                        <button type="button" class="btn btn-secondary btn-sm" style="padding:2px 8px; font-size:11px;" onclick="set1to7Hours()">1η-7η</button>
                        <button type="button" class="btn btn-secondary btn-sm" style="padding:2px 8px; font-size:11px;" onclick="setAllNewHours(false)">Καθαρισμός</button>
                    </div>
                </div>

                <div class="attendance-hour-checkboxes" style="display:grid; grid-template-columns:repeat(4, 1fr); gap:8px; background:#f8fafc; border:1px solid var(--border); padding:12px; border-radius:8px;">
                    <?php for ($h = 1; $h <= 8; $h++): ?>
                        <label style="display:flex; align-items:center; gap:6px; font-size:13px; font-weight:600; cursor:pointer; background:#fff; padding:6px 10px; border-radius:6px; border:1px solid #e2e8f0;">
                            <input type="checkbox" name="hours[]" value="<?= $h ?>" class="new-absence-hour-check" id="new_h_<?= $h ?>">
                            <span><?= $h ?>η ώρα</span>
                        </label>
                    <?php endfor; ?>
                </div>
                <div style="font-size:11px; color:var(--text-muted); margin-top:5px;">
                    * Επιλέξτε τις ώρες και τον τύπο καταχώρησης (Κοινή, Αποβολή ή Δικαιολογημένη).
                </div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px; padding-top:14px; border-top:1px solid var(--border);">
                <button type="button" class="btn btn-secondary btn-sm" onclick="closeNewAbsenceModal()">Ακύρωση</button>
                <button type="submit" class="btn btn-primary btn-sm" style="font-weight:600;">➕ Καταχώρηση</button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================= -->
<!-- MODAL 2: ΔΙΑΧΕΙΡΙΣΗ & ΠΡΟΣΘΗΚΗ ΩΡΑΣ ΣΕ ΜΑΘΗΤΗ -->
<!-- ======================================================= -->
<div id="studentAbsenceModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.6); z-index:1000; align-items:center; justify-content:center; overflow-y:auto; padding:20px;">
    <div style="background:#fff; border-radius:14px; max-width:640px; width:100%; padding:24px; box-shadow:var(--shadow-lg); max-height:92vh; overflow-y:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
            <div>
                <h3 id="mgmtStudentName" style="font-size:18px; font-weight:700; color:var(--text-main); margin:0;">Διαχείριση Απουσιών Μαθητή</h3>
                <div id="mgmtStudentSub" style="font-size:12px; color:var(--text-muted); margin-top:2px;"></div>
            </div>
            <button type="button" onclick="closeStudentAbsenceModal()" aria-label="Κλείσιμο" title="Κλείσιμο" style="background:none; border:none; font-size:20px; cursor:pointer; color:var(--text-muted);">&times;</button>
        </div>

        <!-- 1. Υπάρχουσες Ώρες -->
        <div style="margin-bottom:18px;">
            <div style="font-size:13px; font-weight:700; color:var(--text-main); margin-bottom:8px;">
                📋 Καταχωρημένες Ώρες για <?= date('d/m/Y', strtotime($selectedDate)) ?>:
            </div>
            <div id="mgmtHoursList" style="border:1px solid var(--border); border-radius:8px; overflow:hidden;">
                <!-- Filled dynamically via JS -->
            </div>
        </div>

        <!-- 2. Φόρμα Προσθήκης Επιπλέον Ώρας -->
        <div style="background:#f8fafc; border:1px solid var(--border); border-radius:10px; padding:16px;">
            <div style="font-size:14px; font-weight:700; color:var(--text-main); margin-bottom:10px; display:flex; align-items:center; gap:6px;">
                <span>➕</span> Προσθήκη Ώρας Απουσίας
            </div>
            <form id="quickAddHourForm" onsubmit="handleQuickAddHourSubmit(event)" method="POST" action="index.php?date=<?= urlencode($selectedDate) ?>&grade_id=<?= $filterGradeId ?>&section_id=<?= $filterSectionId ?>&status_filter=<?= urlencode($statusFilter) ?>" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
                <?= csrfField() ?>
                <input type="hidden" name="quick_add_hour" value="1">
                <input type="hidden" name="student_id" id="quickAddStudentId" value="">
                <input type="hidden" name="date" value="<?= htmlspecialchars($selectedDate) ?>">

                <div style="flex:1; min-width:85px;">
                    <label for="quickAddHour" style="display:block; font-size:12px; font-weight:600; color:var(--text-muted); margin-bottom:4px;">Ώρα:</label>
                    <select name="hour" id="quickAddHour" class="form-control" style="font-size:13px;" required>
                        <?php for ($i = 1; $i <= 8; $i++): ?>
                            <option value="<?= $i ?>"><?= $i ?>η Ώρα</option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div style="flex:1.5; min-width:130px;">
                    <label for="quickAddSection" style="display:block; font-size:12px; font-weight:600; color:var(--text-muted); margin-bottom:4px;">Τμήμα:</label>
                    <select name="section_id" id="quickAddSection" class="form-control" style="font-size:13px;" required>
                        <?php foreach ($allSectionsList as $secItem): ?>
                            <option value="<?= $secItem['id'] ?>">
                                <?= htmlspecialchars($secItem['name']) ?> (<?= htmlspecialchars($secItem['grade_name']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="flex:1.5; min-width:140px;">
                    <label for="quickAddType" style="display:block; font-size:12px; font-weight:600; color:var(--text-muted); margin-bottom:4px;">Τύπος:</label>
                    <select name="absence_type" id="quickAddType" class="form-control" style="font-size:13px; font-weight:600;">
                        <option value="absent">Κοινή Απουσία</option>
                        <option value="expulsion">Ωριαία Αποβολή</option>
                        <?php if (canJustifyAbsences()): ?>
                        <option value="justified">Δικαιολογημένη</option>
                        <?php endif; ?>
                    </select>
                </div>

                <div>
                    <button type="submit" class="btn btn-primary btn-sm" style="font-weight:600; padding:8px 14px;">
                        ➕ Προσθήκη
                    </button>
                </div>
            </form>
        </div>

        <div style="display:flex; justify-content:flex-end; margin-top:20px;">
            <button type="button" class="btn btn-secondary btn-sm" onclick="closeStudentAbsenceModal()">Κλείσιμο</button>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL 3: ΠΡΟΕΠΙΣΚΟΠΗΣΗ EMAIL ΕΙΔΟΠΟΙΗΣΗΣ -->
<!-- ========================================== -->
<div id="emailPreviewModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.6); z-index:1000; align-items:center; justify-content:center; overflow-y:auto; padding:20px;">
    <div style="background:#fff; border-radius:14px; max-width:640px; width:100%; padding:24px; box-shadow:var(--shadow-lg); max-height:92vh; overflow-y:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <div style="display:flex; align-items:center; gap:8px;">
                <span style="font-size:22px;">✉️</span>
                <h3 style="font-size:18px; font-weight:700; color:var(--text-main); margin:0;">Προεπισκόπηση Email Ειδοποίησης</h3>
            </div>
            <button type="button" onclick="closeEmailPreviewModal()" aria-label="Κλείσιμο" title="Κλείσιμο" style="background:none; border:none; font-size:20px; cursor:pointer; color:var(--text-muted);">&times;</button>
        </div>

        <div style="background:#f8fafc; border:1px solid var(--border); border-radius:10px; padding:16px; margin-bottom:16px; font-size:13px;">
            <div style="margin-bottom:8px; display:flex; gap:8px;">
                <strong style="width:90px; color:var(--text-muted);">Αποστολέας:</strong>
                <span><?= htmlspecialchars($schoolName) ?></span>
            </div>
            <div style="margin-bottom:8px; display:flex; gap:8px;">
                <strong style="width:90px; color:var(--text-muted);">Παραλήπτες:</strong>
                <span id="previewModalRecipients" style="font-weight:600; color:var(--primary);"></span>
            </div>
            <div style="display:flex; gap:8px;">
                <strong style="width:90px; color:var(--text-muted);">Θέμα:</strong>
                <span id="previewModalSubject" style="font-weight:700; color:var(--text-main);"></span>
            </div>
        </div>

        <div style="font-size:12px; font-weight:600; color:var(--text-muted); margin-bottom:6px; text-transform:uppercase;">
            Κείμενο Μηνύματος:
        </div>
        <div id="previewModalBody" style="background:#fff; border:1.5px solid var(--border); border-radius:8px; padding:16px; white-space:pre-wrap; font-family:inherit; font-size:13.5px; line-height:1.6; color:#334155; min-height:120px;">
        </div>

        <div style="display:flex; justify-content:flex-end; margin-top:20px;"><button type="button" class="btn btn-primary btn-sm" onclick="openStudentEmailComposer(window.emailPreviewStudentId,'absence')">Επεξεργασία μηνύματος / Αποστολή</button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="closeEmailPreviewModal()">Κλείσιμο</button>
        </div>
    </div>
</div>

<link rel="stylesheet" href="../assets/css/email-composer.css?v=20261030-1">
<script>window.studentEmailDate=<?= json_encode($selectedDate) ?>;</script>
<script src="../assets/js/email-composer.js?v=20261030-1" defer></script>
<!-- Floating Context Menu for Inline Grid Hours -->
<div id="hourContextMenu" class="hour-context-menu">
    <div style="padding:4px 8px 6px 8px; font-size:11px; font-weight:700; color:var(--text-muted); border-bottom:1px solid #e2e8f0; margin-bottom:4px;">
        Επιλογή Τύπου Ώρας:
    </div>
    <button type="button" class="hour-context-item" onclick="selectContextMenuType('absent')">
        🔴 Κοινή Απουσία
    </button>
    <button type="button" class="hour-context-item" onclick="selectContextMenuType('expulsion')">
        🟠 Ωριαία Αποβολή
    </button>
    <?php if (canJustifyAbsences()): ?>
    <button type="button" class="hour-context-item" onclick="selectContextMenuType('justified')">
        🔵 Δικαιολογημένη
    </button>
    <?php endif; ?>
    <div style="border-top:1px solid #e2e8f0; margin:4px 0;"></div>
    <button type="button" class="hour-context-item danger" onclick="selectContextMenuType('clear')">
        ⚪ Παρών (Διαγραφή)
    </button>
    <div style="border-top:1px solid #e2e8f0; margin:4px 0;"></div>
    <!-- Note preview inside context menu if note exists -->
    <div id="hourContextMenuNoteBox" style="display:none; padding:4px 8px; margin:2px 4px 6px 4px; background:#fef3c7; border:1px solid #fde68a; border-radius:6px; font-size:11px; color:#92400e; line-height:1.3; max-width:185px; word-break:break-word;">
        <strong>📝 Σημείωση:</strong> <span id="hourContextMenuNoteText"></span>
    </div>
    <button type="button" class="hour-context-item" style="color:#d97706; font-weight:600;" onclick="openHourNoteModal()">
        ✏️ <span id="hourContextMenuNoteBtnLabel">Σημείωση Ώρας...</span>
    </button>
</div>

<!-- Mini Modal for Hour Note -->
<div id="hourNoteModal" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.6); z-index:99999; align-items:center; justify-content:center; padding:16px;">
    <div style="background:var(--card-bg, #ffffff); border-radius:12px; width:100%; max-width:400px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.2), 0 10px 10px -5px rgba(0,0,0,0.1); border:1px solid var(--border, #e2e8f0); overflow:hidden;">
        <div style="padding:14px 18px; border-bottom:1px solid var(--border, #e2e8f0); display:flex; align-items:center; justify-content:space-between; background:var(--header-bg, #f8fafc);">
            <div style="display:flex; align-items:center; gap:8px;">
                <span style="font-size:18px;">📝</span>
                <div>
                    <h4 id="hourNoteModalTitle" style="margin:0; font-size:14px; font-weight:700; color:var(--text-main, #1e293b);">Σημείωση Ώρας</h4>
                    <span id="hourNoteModalSubtitle" style="font-size:11.5px; color:var(--text-muted, #64748b);"></span>
                </div>
            </div>
            <button type="button" onclick="closeHourNoteModal()" style="background:none; border:none; font-size:20px; line-height:1; color:#94a3b8; cursor:pointer; padding:4px;">&times;</button>
        </div>
        <div style="padding:16px 18px;">
            <label style="display:block; font-size:12px; font-weight:600; color:var(--text-main, #334155); margin-bottom:6px;">
                Κείμενο / Αιτιολογία Σημείωσης:
            </label>
            <input type="text" id="hourNoteInput" maxlength="255" placeholder="π.χ. Αργοπορία 15', Αποχώρηση με άδεια, Ιατρείο..." style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px; outline:none; transition:border-color 0.15s; box-sizing:border-box;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:5px; font-size:11px; color:var(--text-muted, #64748b);">
                <span>Μέγιστο 255 χαρακτήρες</span>
                <span id="hourNoteCharCount">0/255</span>
            </div>
        </div>
        <div style="padding:12px 18px; border-top:1px solid var(--border, #e2e8f0); background:var(--header-bg, #f8fafc); display:flex; justify-content:flex-end; gap:8px;">
            <button type="button" id="btnDeleteNote" onclick="saveHourNoteFromModal(true)" class="btn btn-sm" style="background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; display:none; margin-right:auto;">
                🗑️ Διαγραφή
            </button>
            <button type="button" onclick="closeHourNoteModal()" class="btn btn-secondary btn-sm">
                Ακύρωση
            </button>
            <button type="button" onclick="saveHourNoteFromModal(false)" class="btn btn-primary btn-sm" style="font-weight:600;">
                💾 Αποθήκευση
            </button>
        </div>
    </div>
</div>

<script>
// ==========================================
// INLINE ATTENDANCE GRID ENGINE WITH MULTI-TYPE SUPPORT
// ==========================================
window.allStudentsState = <?= json_encode($grouped, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?> || {};

function openManageModalForStudent(studentId) {
    const student = window.allStudentsState[studentId];
    if (student) {
        showStudentAbsenceModal(student);
    }
}

function openEmailPreviewForStudent(studentId) {
    const student = window.allStudentsState[studentId];
    if (student) {
        showEmailPreview(student);
    }
}

const canUserJustify = <?= canJustifyAbsences() ? 'true' : 'false' ?>;
let currentGridToolMode = localStorage.getItem('absences_grid_tool_mode') || 'absent';
if (currentGridToolMode === 'justified' && !canUserJustify) {
    currentGridToolMode = 'absent';
}

function setGridToolMode(mode) {
    if (mode === 'justified' && !canUserJustify) {
        alert('Μόνο οι διαχειριστές του σχολείου μπορούν να καταχωρούν δικαιολογημένες απουσίες.');
        return;
    }
    currentGridToolMode = mode;
    localStorage.setItem('absences_grid_tool_mode', mode);
    document.querySelectorAll('.btn-tool-mode').forEach(b => {
        b.classList.toggle('active', b.getAttribute('data-mode') === mode);
    });
}

// On DOM ready, set active tool class
document.addEventListener('DOMContentLoaded', function() {
    setGridToolMode(currentGridToolMode);
});

function renderInlineGridHtml(sid, primarySecId, attList, notesMap) {
    const hoursMap = {};
    (attList || []).forEach(a => {
        hoursMap[parseInt(a.hour, 10)] = a;
    });
    if (!notesMap) {
        notesMap = (window.allStudentsState && window.allStudentsState[sid] && window.allStudentsState[sid].notes)
            || (window.studentsCatalog && window.studentsCatalog[sid] && window.studentsCatalog[sid].notes)
            || {};
    }
    const totalCount = (attList || []).length;

    let html = `<div class="inline-hours-grid" data-student-id="${sid}" data-section-id="${primarySecId}">`;
    for (let h = 1; h <= 7; h++) {
        const att = hoursMap[h];
        let btnCls = 'btn-hour-neutral';
        let currentType = '';
        let currentNote = (notesMap && notesMap[h]) ? String(notesMap[h]).trim() : '';
        const hasNote = currentNote.length > 0;
        let titleText = '';
        if (att) {
            currentType = att.absence_type || 'absent';
            let typeName = (currentType === 'expulsion') ? 'Ωριαία Αποβολή' : ((currentType === 'justified') ? 'Δικαιολογημένη' : 'Κοινή Απουσία');
            if (currentType === 'expulsion') {
                btnCls = 'btn-hour-expulsion';
            } else if (currentType === 'justified') {
                btnCls = 'btn-hour-justified';
            } else {
                btnCls = 'btn-hour-absent';
            }
            const noteSuffix = hasNote ? ` 📝 [Σημείωση: "${currentNote}"]` : '';
            titleText = `${h}η ώρα: ${typeName}${noteSuffix} (Κλικ για αλλαγή, Δεξί κλικ για μενού/σημείωση)`;
        } else {
            const noteSuffix = hasNote ? ` 📝 [Σημείωση: "${currentNote}"]` : '';
            titleText = `${h}η ώρα: Παρών${noteSuffix} (Αριστερό κλικ: καταχώρηση, Δεξί κλικ: μενού & σημείωση)`;
        }
        const noteCls = hasNote ? 'has-note' : '';
        html += `<button type="button" class="btn-hour-pill ${btnCls} ${noteCls}" id="btn-h-${sid}-${h}" data-hour="${h}" data-current-type="${currentType}" data-note="${escapeHtml(currentNote)}" onclick="handleInlineHourClick(${sid}, ${h}, ${primarySecId}, event)" oncontextmenu="handleInlineHourContextMenu(${sid}, ${h}, ${primarySecId}, event)" aria-label="${escapeHtml(titleText)}" title="${escapeHtml(titleText)}">${h}</button>`;
    }
    const allCls = totalCount >= 7 ? 'active-all' : '';
    const allText = totalCount >= 7 ? '✓ Όλες' : '⚡ 1-7';
    const allTitle = totalCount >= 7 ? 'Καθαρισμός όλων των ωρών' : '⚡ Ολοήμερη καταχώρηση (1η-7η ώρα με βάση το επιλεγμένο εργαλείο)';
    html += `<button type="button" class="btn-hour-all ${allCls}" id="btn-all-${sid}" onclick="toggleInlineAllDay(${sid}, ${primarySecId})" title="${allTitle}">${allText}</button>`;
    html += `</div>`;
    return html;
}

async function handleInlineHourClick(studentId, hour, sectionId, event) {
    if (event) event.preventDefault();
    if (currentGridToolMode === 'cycle') {
        return sendInlineAbsenceAction(studentId, hour, sectionId, 'cycle', 'absent');
    } else {
        return sendInlineAbsenceAction(studentId, hour, sectionId, 'toggle', currentGridToolMode);
    }
}

function handleInlineHourContextMenu(studentId, hour, sectionId, event) {
    event.preventDefault();
    const menu = document.getElementById('hourContextMenu');
    if (!menu) return;

    const btn = document.getElementById(`btn-h-${studentId}-${hour}`);
    const currentNote = btn ? (btn.getAttribute('data-note') || '') : '';
    const currentType = btn ? (btn.getAttribute('data-current-type') || '') : '';

    menu.setAttribute('data-student-id', studentId);
    menu.setAttribute('data-hour', hour);
    menu.setAttribute('data-section-id', sectionId);
    menu.setAttribute('data-note', currentNote);
    menu.setAttribute('data-current-type', currentType);

    // Update note preview in menu
    const noteBox = document.getElementById('hourContextMenuNoteBox');
    const noteTextSpan = document.getElementById('hourContextMenuNoteText');
    const noteBtnLabel = document.getElementById('hourContextMenuNoteBtnLabel');

    if (noteBox && noteTextSpan && noteBtnLabel) {
        if (currentNote.trim().length > 0) {
            noteTextSpan.textContent = currentNote;
            noteBox.style.display = 'block';
            noteBtnLabel.textContent = 'Επεξεργασία Σημείωσης...';
        } else {
            noteBox.style.display = 'none';
            noteBtnLabel.textContent = 'Σημείωση Ώρας...';
        }
    }

    menu.style.display = 'block';
    const menuWidth = 210;
    const menuHeight = currentNote.trim().length > 0 ? 220 : 185;
    const x = Math.min(event.clientX, window.innerWidth - menuWidth - 10);
    const y = Math.min(event.clientY, window.innerHeight - menuHeight - 10);
    menu.style.left = x + 'px';
    menu.style.top = y + 'px';
}

function openHourNoteModal() {
    const menu = document.getElementById('hourContextMenu');
    if (!menu) return;
    const studentId = parseInt(menu.getAttribute('data-student-id'), 10);
    const hour = parseInt(menu.getAttribute('data-hour'), 10);
    const sectionId = parseInt(menu.getAttribute('data-section-id'), 10);
    const note = menu.getAttribute('data-note') || '';
    menu.style.display = 'none';

    const student = window.allStudentsState ? window.allStudentsState[studentId] : null;
    const studentName = student ? student.name : `Μαθητής #${studentId}`;

    const modal = document.getElementById('hourNoteModal');
    modal.setAttribute('data-student-id', studentId);
    modal.setAttribute('data-hour', hour);
    modal.setAttribute('data-section-id', sectionId);

    document.getElementById('hourNoteModalTitle').textContent = `📝 Σημείωση: ${hour}η Ώρα`;
    document.getElementById('hourNoteModalSubtitle').textContent = studentName;

    const input = document.getElementById('hourNoteInput');
    input.value = note;
    document.getElementById('hourNoteCharCount').textContent = `${note.length}/255`;

    const btnDel = document.getElementById('btnDeleteNote');
    if (btnDel) {
        btnDel.style.display = note.trim().length > 0 ? 'inline-block' : 'none';
    }

    modal.style.display = 'flex';
    setTimeout(() => {
        input.focus();
        input.select();
    }, 50);
}

function closeHourNoteModal() {
    const m = document.getElementById('hourNoteModal');
    if (m) m.style.display = 'none';
}

async function saveHourNoteFromModal(isDelete = false) {
    const modal = document.getElementById('hourNoteModal');
    if (!modal) return;
    const studentId = parseInt(modal.getAttribute('data-student-id'), 10);
    const hour = parseInt(modal.getAttribute('data-hour'), 10);
    const sectionId = parseInt(modal.getAttribute('data-section-id'), 10);

    const noteVal = isDelete ? '' : document.getElementById('hourNoteInput').value.trim();
    closeHourNoteModal();

    const btn = document.getElementById(`btn-h-${studentId}-${hour}`);
    const currentType = btn ? (btn.getAttribute('data-current-type') || '') : '';

    // Optimistic instant UI update
    if (btn) {
        if (!noteVal) {
            btn.classList.remove('has-note');
            btn.setAttribute('data-note', '');
            const typeName = (currentType === 'expulsion') ? 'Ωριαία Αποβολή' : ((currentType === 'justified') ? 'Δικαιολογημένη' : (currentType === 'absent' ? 'Κοινή Απουσία' : 'Παρών'));
            const newTitle = currentType 
                ? `${hour}η ώρα: ${typeName} (Κλικ για αλλαγή, Δεξί κλικ για μενού/σημείωση)`
                : `${hour}η ώρα: Παρών (Αριστερό κλικ: καταχώρηση, Δεξί κλικ: μενού & σημείωση)`;
            btn.setAttribute('title', newTitle);
            btn.setAttribute('aria-label', newTitle);
        } else {
            btn.classList.add('has-note');
            btn.setAttribute('data-note', noteVal);
            const typeName = (currentType === 'expulsion') ? 'Ωριαία Αποβολή' : ((currentType === 'justified') ? 'Δικαιολογημένη' : (currentType === 'absent' ? 'Κοινή Απουσία' : 'Παρών'));
            const newTitle = currentType 
                ? `${hour}η ώρα: ${typeName} 📝 [Σημείωση: "${noteVal}"] (Κλικ για αλλαγή, Δεξί κλικ για μενού/σημείωση)`
                : `${hour}η ώρα: Παρών 📝 [Σημείωση: "${noteVal}"] (Αριστερό κλικ: καταχώρηση, Δεξί κλικ: μενού & σημείωση)`;
            btn.setAttribute('title', newTitle);
            btn.setAttribute('aria-label', newTitle);
        }
    }
    if (window.allStudentsState && window.allStudentsState[studentId]) {
        if (!window.allStudentsState[studentId].notes) window.allStudentsState[studentId].notes = {};
        if (!noteVal) {
            delete window.allStudentsState[studentId].notes[hour];
        } else {
            window.allStudentsState[studentId].notes[hour] = noteVal;
        }
    }
    if (typeof studentsCatalog !== 'undefined' && studentsCatalog[studentId]) {
        if (!studentsCatalog[studentId].notes) studentsCatalog[studentId].notes = {};
        if (!noteVal) {
            delete studentsCatalog[studentId].notes[hour];
        } else {
            studentsCatalog[studentId].notes[hour] = noteVal;
        }
    }

    await sendInlineAbsenceAction(studentId, hour, sectionId, 'set_note', currentType, noteVal);
}

function selectContextMenuType(type) {
    const menu = document.getElementById('hourContextMenu');
    if (!menu) return;
    const studentId = parseInt(menu.getAttribute('data-student-id'), 10);
    const hour = parseInt(menu.getAttribute('data-hour'), 10);
    const sectionId = parseInt(menu.getAttribute('data-section-id'), 10);
    menu.style.display = 'none';

    if (type === 'clear') {
        const btn = document.getElementById(`btn-h-${studentId}-${hour}`);
        const currentType = btn ? (btn.getAttribute('data-current-type') || 'absent') : 'absent';
        sendInlineAbsenceAction(studentId, hour, sectionId, 'toggle', currentType);
    } else {
        sendInlineAbsenceAction(studentId, hour, sectionId, 'set', type);
    }
}

// Close context menu on outside click or scroll
window.addEventListener('click', function(e) {
    const menu = document.getElementById('hourContextMenu');
    if (menu && !menu.contains(e.target)) {
        menu.style.display = 'none';
    }
});
document.addEventListener('DOMContentLoaded', function() {
    const noteInput = document.getElementById('hourNoteInput');
    if (noteInput) {
        noteInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                saveHourNoteFromModal(false);
            } else if (e.key === 'Escape') {
                closeHourNoteModal();
            }
        });
        noteInput.addEventListener('input', function() {
            const counter = document.getElementById('hourNoteCharCount');
            if (counter) counter.textContent = `${this.value.length}/255`;
        });
    }
});
window.addEventListener('scroll', function() {
    const menu = document.getElementById('hourContextMenu');
    if (menu) menu.style.display = 'none';
}, true);

async function sendInlineAbsenceAction(studentId, hour, sectionId, action, absenceType, noteText = null) {
    const btn = document.getElementById(`btn-h-${studentId}-${hour}`);
    if (!btn || btn.classList.contains('loading')) return;

    btn.classList.add('loading');
    const selectedDate = document.getElementById('dateSelect')?.value || '<?= $selectedDate ?>';

    try {
        const res = await fetch('../api/toggle_student_absence.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': '<?= getCsrfToken() ?>'
            },
            body: JSON.stringify({
                student_id: studentId,
                hour: hour,
                date: selectedDate,
                section_id: sectionId,
                action: action,
                absence_type: absenceType,
                note: noteText
            })
        });
        const data = await res.json();
        if (data.success) {
            updateStudentRowGrid(studentId, sectionId, data.attendances, data.preview, data.notes);
            updateGlobalKpis(data.kpi);
        } else {
            alert(data.error || 'Σφάλμα κατά την ενημέρωση');
        }
    } catch (e) {
        alert('Σφάλμα επικοινωνίας με το σύστημα');
    } finally {
        btn.classList.remove('loading');
    }
}

async function toggleInlineAllDay(studentId, sectionId) {
    const btnAll = document.getElementById(`btn-all-${studentId}`);
    if (!btnAll || btnAll.classList.contains('loading')) return;

    const isCurrentlyAll = btnAll.classList.contains('active-all');
    const action = isCurrentlyAll ? 'clear_all_day' : 'set_all_day';
    const selectedDate = document.getElementById('dateSelect')?.value || '<?= $selectedDate ?>';
    const targetType = (currentGridToolMode === 'cycle') ? 'absent' : currentGridToolMode;

    btnAll.classList.add('loading');

    try {
        const res = await fetch('../api/toggle_student_absence.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': '<?= getCsrfToken() ?>'
            },
            body: JSON.stringify({
                student_id: studentId,
                date: selectedDate,
                section_id: sectionId,
                action: action,
                absence_type: targetType
            })
        });
        const data = await res.json();
        if (data.success) {
            updateStudentRowGrid(studentId, sectionId, data.attendances, data.preview, data.notes);
            updateGlobalKpis(data.kpi);
        } else {
            alert(data.error || 'Σφάλμα κατά την ενημέρωση');
        }
    } catch (e) {
        alert('Σφάλμα επικοινωνίας με το σύστημα');
    } finally {
        btnAll.classList.remove('loading');
    }
}

function updateStudentRowGrid(studentId, sectionId, attendances, previewData, notesMap) {
    const attList = attendances || [];
    const attCount = attList.length;

    if (!window.allStudentsState[studentId]) {
        window.allStudentsState[studentId] = {
            student_id: studentId,
            attendances: [],
            notes: {},
            recipients: []
        };
    }
    const stObj = window.allStudentsState[studentId];
    if (notesMap !== undefined && notesMap !== null) {
        const cleanNotes = {};
        if (typeof notesMap === 'object' && notesMap !== null) {
            Object.keys(notesMap).forEach(h => {
                if (notesMap[h]) cleanNotes[parseInt(h, 10)] = String(notesMap[h]).trim();
            });
        }
        stObj.notes = cleanNotes;
        if (typeof studentsCatalog !== 'undefined' && studentsCatalog[studentId]) {
            studentsCatalog[studentId].notes = cleanNotes;
        }
    }
    stObj.attendances = attList.map(a => ({
        id: a.id,
        hour: parseInt(a.hour, 10),
        absence_type: a.absence_type || 'absent',
        note: (stObj.notes && stObj.notes[parseInt(a.hour, 10)]) || a.note || '',
        section: a.section || a.section_name || '',
        section_name: a.section_name || a.section || '',
        teacher: a.teacher || a.teacher_name || '',
        teacher_name: a.teacher_name || a.teacher || '',
        notified: (a.notified !== undefined) ? !!a.notified : (parseInt(a.email_notified, 10) === 1),
        email_notified: (a.email_notified !== undefined) ? parseInt(a.email_notified, 10) : (a.notified ? 1 : 0)
    }));

    if (previewData) {
        stObj.preview_subject = previewData.subject || '';
        stObj.preview_body = previewData.body || '';
        if (previewData.recipients && Array.isArray(previewData.recipients)) {
            stObj.recipients = previewData.recipients;
        }
    }

    const hasPending = attList.some(a => !a.notified && parseInt(a.email_notified, 10) !== 1);
    stObj.has_pending_email = (attCount > 0 && hasPending);

    // 2. Update Hours Cell with Inline Grid
    const hoursCell = document.getElementById(`hours-cell-${studentId}`);
    if (hoursCell) {
        hoursCell.innerHTML = renderInlineGridHtml(studentId, sectionId, attList, stObj.notes);
    }

    // 3. Update Total Count Cell
    const totalCell = document.getElementById(`total-cell-${studentId}`);
    if (totalCell) {
        totalCell.textContent = attCount;
        totalCell.style.color = attCount > 0 ? 'var(--primary)' : 'var(--success)';
    }

    // 4. Update Checkbox Cell
    const checkCell = document.getElementById(`check-cell-${studentId}`);
    if (checkCell) {
        const hasEmails = !!(stObj.guardian_email || stObj.guardian_email_2 || (stObj.recipients && stObj.recipients.length > 0));
        if (attCount > 0 && hasPending && hasEmails) {
            if (!checkCell.querySelector('.student-checkbox')) {
                checkCell.innerHTML = `<input type="checkbox" name="student_ids[]" value="${studentId}" class="student-checkbox">`;
            }
        } else {
            checkCell.innerHTML = '<span style="color:var(--text-muted); font-size:11px;">—</span>';
        }
    }

    // 5. Update Email Preview & Status Cell
    const emailCell = document.getElementById(`email-cell-${studentId}`);
    if (emailCell) {
        if (attCount > 0) {
            const badgeCls = hasPending ? 'badge-warning' : 'badge-success';
            const badgeText = hasPending ? 'Εκκρεμεί' : 'Στάλθηκε';
            emailCell.innerHTML = `
                <div style="display:inline-flex; align-items:center; gap:6px;">
                    <button type="button" class="btn btn-secondary btn-sm" 
                            style="padding:3px 8px; font-size:11px;" 
                            aria-label="Προεπισκόπηση email" title="Προεπισκόπηση του email ειδοποίησης"
                            onclick="openEmailPreviewForStudent(${studentId})">
                        👁️
                    </button>
                    <span class="badge ${badgeCls}" style="font-size:11px;">${badgeText}</span>
                </div>
            `;
        } else {
            emailCell.innerHTML = '<span style="color:var(--text-muted); font-size:12px;">—</span>';
        }
    }

    // 6. Update Action Button Cell (Προσθήκη Ώρας / Διαχείριση)
    const actionCell = document.getElementById(`action-cell-${studentId}`);
    if (actionCell) {
        const btnLabel = attCount > 0 ? '✏️ Διαχείριση / + Ώρα' : '➕ Προσθήκη Ώρας';
        actionCell.innerHTML = `<button type="button" class="btn btn-secondary btn-sm" onclick="openStudentEmailComposer(${studentId},'direct')">✉️ Νέο email</button>
            <button type="button" class="btn btn-secondary btn-sm" 
                    style="padding:4px 10px; font-size:12px; font-weight:600;" 
                    title="Διαχείριση ή Προσθήκη ώρας για αυτόν τον μαθητή"
                    onclick="openManageModalForStudent(${studentId})">
                ${btnLabel}
            </button>
        `;
    }

    // 7. If the Management Modal is open for this student, refresh its table in real time!
    const modalEl = document.getElementById('studentAbsenceModal');
    const modalStudentId = parseInt(document.getElementById('quickAddStudentId')?.value, 10);
    if (modalEl && modalEl.style.display === 'flex' && modalStudentId === studentId) {
        showStudentAbsenceModal(stObj);
    }

    if (typeof updateBulkEmailButton === 'function') {
        updateBulkEmailButton();
    }
}

function updateGlobalKpis(kpi) {
    if (!kpi) return;
    const kpiTotalHours = document.getElementById('kpi-total-hours');
    const kpiAbsentStudents = document.getElementById('kpi-absent-students');
    const kpiPendingEmails = document.getElementById('kpi-pending-emails');

    if (kpiTotalHours) kpiTotalHours.textContent = kpi.total_hours;
    if (kpiAbsentStudents) kpiAbsentStudents.textContent = kpi.absent_students;
    if (kpiPendingEmails) {
        kpiPendingEmails.textContent = kpi.pending_emails;
        kpiPendingEmails.style.color = (parseInt(kpi.pending_emails, 10) > 0) ? 'var(--warning)' : 'var(--success)';
    }
}

// Bulk Email Dynamic UI Engine
function handleBulkEmailSubmit(event) {
    event.preventDefault();
    window.openBulkNotificationPreview('email');
    return false;
}
function handleBulkSmsSubmit(event) {
    event.preventDefault();
    window.openBulkNotificationPreview('sms');
    return false;
}

function updateBulkEmailButton() {
    const btnEmail = document.getElementById('btnBulkSendEmail');
    const btnSms = document.getElementById('btnBulkSendSms');
    const countSpan = document.getElementById('bulkEmailCount');
    const smsCountSpan = document.getElementById('bulkSmsCount');
    const kpiPending = document.getElementById('kpi-pending-emails');

    const allPending = document.querySelectorAll('.student-checkbox');
    const checked = document.querySelectorAll('.student-checkbox:checked');
    
    const count = checked.length;
    
    if (countSpan) {
        countSpan.textContent = count;
    }
    if (smsCountSpan) {
        smsCountSpan.textContent = count;
    }
    if (kpiPending) {
        kpiPending.textContent = allPending.length;
        kpiPending.style.color = (allPending.length > 0) ? 'var(--warning)' : 'var(--success)';
    }

    const disp = allPending.length > 0 ? 'inline-flex' : 'none';
    for (const button of [btnEmail, btnSms]) {
        if (!button) continue;
        button.style.display = disp;
        button.disabled = checked.length === 0;
    }
    const master = document.getElementById('masterCheckbox');
    if (master) {
        master.checked = allPending.length > 0 && checked.length === allPending.length;
        master.indeterminate = checked.length > 0 && checked.length < allPending.length;
        master.disabled = allPending.length === 0;
    }
}

document.addEventListener('change', function(e) {
    if (e.target && (e.target.classList.contains('student-checkbox') || e.target.id === 'masterCheckbox')) {
        updateBulkEmailButton();
    }
});
document.addEventListener('DOMContentLoaded', updateBulkEmailButton);

// Filter: Grade change handler with dynamic section filtering
const allSectionsData = <?= json_encode($allSections, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;

function onGradeFilterChange(gradeSelect) {
    const gradeId = gradeSelect.value;
    const secSelect = document.getElementById('sectionSelect');
    
    // Update section dropdown immediately to show only sections of the chosen grade
    if (secSelect) {
        secSelect.innerHTML = '<option value="">Όλα τα Τμήματα' + (gradeId ? ' της Τάξης' : '') + '</option>';
        allSectionsData.forEach(sec => {
            if (!gradeId || sec.grade_id == gradeId) {
                const opt = document.createElement('option');
                opt.value = sec.id;
                opt.setAttribute('data-grade', sec.grade_id);
                opt.textContent = sec.name + (gradeId ? '' : ' (Τάξη ' + sec.grade_name + ')');
                secSelect.appendChild(opt);
            }
        });
        secSelect.value = '';
    }
    
    // Auto submit form to filter
    gradeSelect.form.submit();
}

// Select All Master Checkbox
function toggleSelectAll(master) {
    const checkboxes = document.querySelectorAll('.student-checkbox');
    checkboxes.forEach(cb => cb.checked = master.checked);
    updateBulkEmailButton();
}

// Modal 1: New Absence Handlers
function openNewAbsenceModal() {
    document.getElementById('newAbsenceStudent').value = '';
    document.getElementById('newAbsenceType').value = 'absent';
    setAllNewHours(false);
    document.getElementById('new_h_1').checked = true;
    document.getElementById('newAbsenceModal').style.display = 'flex';
}

function closeNewAbsenceModal() {
    document.getElementById('newAbsenceModal').style.display = 'none';
}

function onStudentSelectChanged(sel) {
    const selectedOpt = sel.options[sel.selectedIndex];
    if (!selectedOpt) return;
    const secIdsStr = selectedOpt.getAttribute('data-sections') || '';
    const secIds = secIdsStr.split(',').map(s => s.trim()).filter(Boolean);
    if (secIds.length > 0) {
        const secDropdown = document.getElementById('newAbsenceSection');
        if (secDropdown) {
            secDropdown.value = secIds[0];
        }
    }
}

function setAllNewHours(checked) {
    document.querySelectorAll('.new-absence-hour-check').forEach(cb => cb.checked = checked);
}

function set1to7Hours() {
    document.querySelectorAll('.new-absence-hour-check').forEach(cb => {
        const val = parseInt(cb.value, 10);
        cb.checked = (val >= 1 && val <= 7);
    });
}

// Modal 2: Manage Student Absences Handlers
function showStudentAbsenceModal(student) {
    document.getElementById('mgmtStudentName').textContent = '✏️ ' + student.name;
    const secList = Object.keys(student.sections || {}).join(', ') || '—';
    document.getElementById('mgmtStudentSub').textContent = 'Α.Μ.: ' + student.am + ' | Τμήμα: ' + secList;
    document.getElementById('quickAddStudentId').value = student.student_id;
    document.getElementById('quickAddType').value = 'absent';
    
    // Auto set quickAddSection
    if (student.primary_section_id) {
        document.getElementById('quickAddSection').value = student.primary_section_id;
    }

    const container = document.getElementById('mgmtHoursList');
    container.innerHTML = '';

    if (!student.attendances || student.attendances.length === 0) {
        container.innerHTML = '<div style="padding:14px; text-align:center; color:var(--text-muted); font-size:13px;">Δεν υπάρχουν καταχωρημένες απουσίες για τη σημερινή ημέρα (πλήρης παρουσία). Μπορείτε να προσθέσετε παρακάτω.</div>';
    } else {
        let tableHtml = '<table style="width:100%; border-collapse:collapse; font-size:13px;">' +
            '<tr style="background:#f8fafc; border-bottom:1px solid var(--border); color:var(--text-muted); font-size:11px; text-transform:uppercase;">' +
            '<th style="padding:8px 12px; text-align:left;">Ώρα</th>' +
            '<th style="padding:8px 12px; text-align:left;">Τύπος</th>' +
            '<th style="padding:8px 12px; text-align:left;">Τμήμα</th>' +
            '<th style="padding:8px 12px; text-align:left;">Καθηγητής</th>' +
            '<th style="padding:8px 12px; text-align:left;">Σημείωση</th>' +
            '<th style="padding:8px 12px; text-align:center;">Email</th>' +
            '<th style="padding:8px 12px; text-align:right;">Ενέργεια</th>' +
            '</tr>';

        student.attendances.forEach(att => {
            const emailBadge = att.notified 
                ? '<span class="badge badge-success" style="font-size:10px;">Στάλθηκε</span>' 
                : '<span class="badge badge-warning" style="font-size:10px;">Εκκρεμεί</span>';

            let typeBadge = '<span class="badge badge-info" style="font-size:10px;">Κοινή</span>';
            if (att.absence_type === 'expulsion') {
                typeBadge = '<span class="badge" style="background:#fef3c7; color:#92400e; font-size:10px; font-weight:700;" title="Ωριαία Αποβολή">Ω.Α.</span>';
            } else if (att.absence_type === 'justified') {
                typeBadge = '<span class="badge" style="background:#eff6ff; color:#1d4ed8; font-size:10px; font-weight:700;" title="Δικαιολογημένη Απουσία">Δικ.</span>';
            }

            let actionsHtml = '<div style="display:inline-flex; gap:4px; align-items:center; justify-content:flex-end;">';
            if (att.absence_type !== 'absent') {
                actionsHtml += `<button type="button" class="btn btn-secondary btn-sm" style="padding:2px 6px; font-size:10px; color:#dc2626; font-weight:600;" onclick="changeModalAbsenceType(${student.student_id}, ${att.hour}, ${student.primary_section_id || 1}, 'absent')" title="Αλλαγή σε Κοινή Απουσία">🔴 Κοινή</button>`;
            }
            if (att.absence_type !== 'expulsion') {
                actionsHtml += `<button type="button" class="btn btn-secondary btn-sm" style="padding:2px 6px; font-size:10px; color:#d97706; font-weight:600;" onclick="changeModalAbsenceType(${student.student_id}, ${att.hour}, ${student.primary_section_id || 1}, 'expulsion')" title="Αλλαγή σε Ωριαία Αποβολή">🟠 Αποβολή</button>`;
            }
            if (canUserJustify && att.absence_type !== 'justified') {
                actionsHtml += `<button type="button" class="btn btn-secondary btn-sm" style="padding:2px 6px; font-size:10px; color:#2563eb; font-weight:600;" onclick="changeModalAbsenceType(${student.student_id}, ${att.hour}, ${student.primary_section_id || 1}, 'justified')" title="Αλλαγή σε Δικαιολογημένη">🔵 Δικαιολ.</button>`;
            }
            actionsHtml += `<button type="button" class="btn btn-danger btn-sm" style="padding:2px 7px; font-size:11px;" title="Διαγραφή ώρας" onclick="deleteModalAttendance(${student.student_id}, ${att.hour}, ${student.primary_section_id || 1})">🗑️</button></div>`;

            tableHtml += '<tr style="border-bottom:1px solid var(--border);">' +
                '<td style="padding:8px 12px; font-weight:700; color:var(--primary);">' + att.hour + 'η ώρα</td>' +
                '<td style="padding:8px 12px;">' + typeBadge + '</td>' +
                '<td style="padding:8px 12px;">' + escapeHtml(att.section) + '</td>' +
                '<td style="padding:8px 12px; color:var(--text-muted);">' + escapeHtml(att.teacher) + '</td>' +
                '<td style="padding:8px 12px;">' + (att.note ? '<span style="font-size:11px; background:#fef3c7; color:#92400e; padding:2px 6px; border-radius:4px; max-width:140px; display:inline-block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="' + escapeHtml(att.note) + '">📝 ' + escapeHtml(att.note) + '</span>' : '<span style="color:var(--text-muted); font-size:11px;">—</span>') + '</td>' +
                '<td style="padding:8px 12px; text-align:center;">' + emailBadge + '</td>' +
                '<td style="padding:8px 12px; text-align:right;">' + actionsHtml + '</td>' +
                '</tr>';
        });

        tableHtml += '</table>';
        container.innerHTML = tableHtml;
    }

    document.getElementById('studentAbsenceModal').style.display = 'flex';
}

async function changeModalAbsenceType(studentId, hour, sectionId, newType) {
    const selectedDate = document.getElementById('dateSelect')?.value || '<?= $selectedDate ?>';
    try {
        const res = await fetch('../api/toggle_student_absence.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': '<?= getCsrfToken() ?>'
            },
            body: JSON.stringify({
                student_id: studentId,
                hour: hour,
                date: selectedDate,
                section_id: sectionId,
                action: 'set',
                absence_type: newType
            })
        });
        const data = await res.json();
        if (data.success) {
            updateStudentRowGrid(studentId, sectionId, data.attendances, data.preview, data.notes);
            updateGlobalKpis(data.kpi);
        } else {
            alert(data.error || 'Σφάλμα κατά την ενημέρωση');
        }
    } catch (e) {
        alert('Σφάλμα επικοινωνίας με το σύστημα');
    }
}

async function deleteModalAttendance(studentId, hour, sectionId) {
    if (!confirm('Διαγραφή της ' + hour + 'ης ώρας;')) return;
    const selectedDate = document.getElementById('dateSelect')?.value || '<?= $selectedDate ?>';
    try {
        const res = await fetch('../api/toggle_student_absence.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': '<?= getCsrfToken() ?>'
            },
            body: JSON.stringify({
                student_id: studentId,
                hour: hour,
                date: selectedDate,
                section_id: sectionId,
                action: 'toggle',
                absence_type: 'absent'
            })
        });
        const data = await res.json();
        if (data.success) {
            updateStudentRowGrid(studentId, sectionId, data.attendances, data.preview, data.notes);
            updateGlobalKpis(data.kpi);
        } else {
            alert(data.error || 'Σφάλμα κατά τη διαγραφή');
        }
    } catch (e) {
        alert('Σφάλμα επικοινωνίας με το σύστημα');
    }
}

async function handleQuickAddHourSubmit(event) {
    event.preventDefault();
    const studentId = parseInt(document.getElementById('quickAddStudentId').value, 10);
    const hour = parseInt(document.getElementById('quickAddHour').value, 10);
    const sectionId = parseInt(document.getElementById('quickAddSection').value, 10);
    const absenceType = document.getElementById('quickAddType').value;
    const selectedDate = document.getElementById('dateSelect')?.value || '<?= $selectedDate ?>';

    try {
        const res = await fetch('../api/toggle_student_absence.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': '<?= getCsrfToken() ?>'
            },
            body: JSON.stringify({
                student_id: studentId,
                hour: hour,
                date: selectedDate,
                section_id: sectionId,
                action: 'set',
                absence_type: absenceType
            })
        });
        const data = await res.json();
        if (data.success) {
            updateStudentRowGrid(studentId, sectionId, data.attendances, data.preview, data.notes);
            updateGlobalKpis(data.kpi);
        } else {
            alert(data.error || 'Σφάλμα κατά την προσθήκη ώρας');
        }
    } catch (e) {
        alert('Σφάλμα επικοινωνίας με το σύστημα');
    }
}

function closeStudentAbsenceModal() {
    document.getElementById('studentAbsenceModal').style.display = 'none';
}

// Modal 3: Email Preview Handlers
let emailPreviewGeneration=0;
window.emailPreviewStudentId=null;
async function showEmailPreview(student) {
    window.emailPreviewStudentId=Number(student.student_id);
    const generation=++emailPreviewGeneration;
    document.getElementById('emailPreviewModal').style.display='flex';
    const recipients=document.getElementById('previewModalRecipients'),subject=document.getElementById('previewModalSubject'),body=document.getElementById('previewModalBody');
    recipients.textContent='Φόρτωση στοιχείων…';subject.textContent='';body.textContent='Φόρτωση προεπισκόπησης…';
    try {
        const response=await fetch('../api/bulk_notification_preview.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('input[name="csrf_token"]').value},body:JSON.stringify({date:<?= json_encode($selectedDate) ?>,channel:'email',student_ids:[Number(student.student_id)]}),cache:'no-store'});
        const data=await response.json();if(generation!==emailPreviewGeneration)return;
        if(!response.ok || !data.items?.[0])throw new Error(data.error||'Δεν φορτώθηκε η προεπισκόπηση.');
        const item=data.items[0];recipients.textContent=item.recipients.length?item.recipients.join(', '):'⚠️ Δεν έχει οριστεί email κηδεμόνα';subject.textContent=item.subject||'';body.textContent=item.body||item.skipped||'Το πρότυπο email είναι κενό. Ελέγξτε τις ρυθμίσεις email.';
    } catch(e){if(generation!==emailPreviewGeneration)return;recipients.textContent='Δεν ολοκληρώθηκε η φόρτωση.';subject.textContent='';body.textContent=e.message||'Δοκιμάστε ξανά την προεπισκόπηση.';}
}

function closeEmailPreviewModal() {
    emailPreviewGeneration++;
    document.getElementById('emailPreviewModal').style.display = 'none';
}

// Helper: Escape HTML
function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// Close modals when clicking backdrop or pressing Escape
window.addEventListener('click', function(e) {
    if (e.target.id === 'newAbsenceModal') closeNewAbsenceModal();
    if (e.target.id === 'studentAbsenceModal') closeStudentAbsenceModal();
    if (e.target.id === 'emailPreviewModal') closeEmailPreviewModal();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeNewAbsenceModal();
        closeStudentAbsenceModal();
        closeEmailPreviewModal();
    }
});
</script>

<!-- Live Notifications Toast Container -->
<div id="liveToastContainer" style="position:fixed; bottom:24px; right:24px; z-index:99999; display:flex; flex-direction:column; gap:10px; max-width:380px; pointer-events:none;"></div>

<script>
// ==========================================
// REAL-TIME ATTENDANCE LIVE SYNC ENGINE
// ==========================================
(function() {
    const studentsCatalog = <?= json_encode($studentsCatalog, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
    let currentLiveHash = '<?= $serverInitialStateHash ?>';
    const currentDate = '<?= $selectedDate ?>';
    const currentGradeId = <?= (int)$filterGradeId ?>;
    const currentSectionId = <?= (int)$filterSectionId ?>;
    const currentStatusFilter = '<?= $statusFilter ?>';
    let isLiveActive = true;
    let isFirstCheck = true;
    const seenNotificationKeys = new Set();
    let liveFailures = 0;
    let livePollInFlight = false;
    let livePollTimer = null;
    const liveRetryButton = document.getElementById('liveRetryButton');

    function updateLiveConnection(healthy) {
        const badge = document.getElementById('liveStatusBadge');
        const label = document.getElementById('liveStatusLabel');
        const warning = document.getElementById('liveSyncWarning');
        const state = healthy ? 'healthy' : (liveFailures >= 3 ? 'disconnected' : 'connecting');
        if (badge) {
            badge.dataset.state = state;
            badge.title = healthy ? 'Η αυτόματη ενημέρωση λειτουργεί.' : 'Γίνονται αυτόματες προσπάθειες επανασύνδεσης.';
        }
        const message = healthy ? 'Ενημερωμένο' : (state === 'disconnected' ? 'Δεν ενημερώνεται' : 'Επανασύνδεση…');
        if (label && label.textContent !== message) label.textContent = message;
        if (warning) warning.hidden = healthy;
        if (liveRetryButton) liveRetryButton.hidden = healthy;
    }

    async function pollAttendanceUpdates() {
        if (!isLiveActive || livePollInFlight) return;
        clearTimeout(livePollTimer);
        livePollInFlight = true;
        if (liveRetryButton) liveRetryButton.disabled = true;
        const controller = new AbortController();
        const requestTimeout = setTimeout(() => controller.abort(), 12000);

        const pollInterval = document.hidden ? 7000 : 2500;

        try {
            const url = `../api/get_live_attendance.php?date=${encodeURIComponent(currentDate)}&grade_id=${currentGradeId}&section_id=${currentSectionId}&status_filter=${encodeURIComponent(currentStatusFilter)}&state_hash=${encodeURIComponent(currentLiveHash)}`;

            const response = await fetch(url, { cache: 'no-store', signal: controller.signal });
            if (!response.ok) throw new Error('Live update unavailable');
            if (response.ok) {
                const data = await response.json();
                if (!data || typeof data.has_updates !== 'boolean' || typeof data.state_hash !== 'string' || !data.state_hash) {
                    throw new Error('Invalid live update response');
                }

                if (data.has_updates && data.state_hash) {
                    // 1. Update KPI stats
                    if (data.kpis) {
                        updateKpiBadges(data.kpis);
                    }

                    // 2. Update Student Rows in the table
                    if (data.student_attendances) {
                        const recentIds = new Set((data.recent_notifications || []).map(n => n.student_id));
                        updateAttendanceTable(data.student_attendances, recentIds, data.student_notes || {}, data.email_delivery_statuses || {});
                    }
                    // A failed render must be retried rather than acknowledged as synchronized.
                    currentLiveHash = data.state_hash;

                    // 3. Show live toast notifications for changes with deduplication
                    if (data.recent_notifications && data.recent_notifications.length > 0) {
                        if (isFirstCheck) {
                            // Pre-seed seen set so existing records don't trigger toasts on page load
                            data.recent_notifications.forEach(notif => {
                                const key = (notif.attendance_id || notif.student_id) + '_' + notif.hour + '_' + (notif.updated_at || '');
                                seenNotificationKeys.add(key);
                            });
                        } else {
                            const newNotifs = data.recent_notifications.filter(notif => {
                                const key = (notif.attendance_id || notif.student_id) + '_' + notif.hour + '_' + (notif.updated_at || '');
                                if (seenNotificationKeys.has(key)) return false;
                                seenNotificationKeys.add(key);
                                return true;
                            });

                            if (newNotifs.length > 0) {
                                handleRealtimeNotifications(newNotifs);
                            }
                        }
                    }

                }
                    // Every successful check counts, even when no attendance changed.
                    const now = new Date();
                    const timeStr = String(now.getHours()).padStart(2, '0') + ':' + 
                                    String(now.getMinutes()).padStart(2, '0') + ':' + 
                                    String(now.getSeconds()).padStart(2, '0');
                    const syncEl = document.getElementById('liveLastSyncTime');
                    if (syncEl) syncEl.textContent = timeStr;
                liveFailures = 0;
                updateLiveConnection(true);
                isFirstCheck = false;
            }
        } catch (e) {
            liveFailures++;
            updateLiveConnection(false);
        } finally {
            clearTimeout(requestTimeout);
            livePollInFlight = false;
            if (liveRetryButton) liveRetryButton.disabled = false;
            livePollTimer = setTimeout(pollAttendanceUpdates, pollInterval);
        }
    }

    function updateKpiBadges(kpis) {
        const elDisp = document.getElementById('kpi-displayed');
        if (elDisp) elDisp.textContent = kpis.displayed_count + ' μαθητές';

        const elAbs = document.getElementById('kpi-absent-students');
        if (elAbs) elAbs.textContent = kpis.total_absent_students;

        const elHours = document.getElementById('kpi-total-hours');
        if (elHours) elHours.textContent = kpis.total_absence_hours;

        const elPending = document.getElementById('kpi-pending-emails');
        if (elPending) {
            elPending.textContent = kpis.total_pending_emails;
            elPending.style.color = kpis.total_pending_emails > 0 ? 'var(--warning)' : 'var(--success)';
        }
    }

    function updateAttendanceTable(studentAttsMap, recentIds, studentNotesMap = {}, deliveryStates = {}) {
        const tbody = document.getElementById('attendanceTableBody');
        if (!tbody) return;

        Object.keys(studentsCatalog).forEach(sidStr => {
            const sid = parseInt(sidStr, 10);
            const student = studentsCatalog[sid];
            const attList = studentAttsMap[sid] || [];
            const notesMap = (studentNotesMap && studentNotesMap[sid]) ? studentNotesMap[sid] : {};
            student.attendances = attList;
            student.notes = notesMap;
            student.email_delivery_status=deliveryStates[sid]||'pending';
            window.allStudentsState=window.allStudentsState||{};
            if(!window.allStudentsState[sid])window.allStudentsState[sid]={...student};
            if (window.allStudentsState && window.allStudentsState[sid]) {
                window.allStudentsState[sid].attendances = attList.map(a=>({...a,notified:a.email_notified,teacher:a.teacher_name,section:a.section_name}));
                window.allStudentsState[sid].email_delivery_status=student.email_delivery_status;
                window.allStudentsState[sid].notes = notesMap;
            }
            const hasAtt = attList.length > 0;
            const isRecent = recentIds.has(sid);

            let row = document.getElementById('student-row-' + sid);

            // Determine if row should be visible based on filter
            let shouldBeVisible = false;
            if (currentStatusFilter === 'all') shouldBeVisible = true;
            else if (currentStatusFilter === 'absent_only') shouldBeVisible = hasAtt;
            else if (currentStatusFilter === 'present_only') shouldBeVisible = !hasAtt;
            else if (currentStatusFilter === 'expulsion_only') shouldBeVisible = attList.some(a => a.absence_type === 'expulsion');
            else if (currentStatusFilter === 'justified_only') shouldBeVisible = attList.some(a => a.absence_type === 'justified');

            if (row) {
                if (!shouldBeVisible) {
                    row.remove();
                    return;
                }

                // Update row contents in place
                updateRowElements(row, sid, student, attList, notesMap);
                if (isRecent) {
                    flashRow(row);
                }
            } else if (shouldBeVisible) {
                // Dynamically insert new row into table
                const newRow = createStudentRow(sid, student, attList);
                tbody.prepend(newRow);
                updateRowElements(newRow, sid, student, attList, notesMap);
                if (isRecent) {
                    flashRow(newRow);
                }
            }
        });
        const count = tbody.querySelectorAll('.student-attendance-row').length;
        const emptyState = document.getElementById('attendanceEmptyState');
        const tableContainer = document.getElementById('attendanceTableContainer');
        const recordCount = document.getElementById('attendanceRecordCount');
        if (emptyState) emptyState.style.display = count ? 'none' : '';
        if (tableContainer) tableContainer.style.display = count ? '' : 'none';
        if (recordCount) recordCount.textContent = count + ' εγγραφές';
    }

    function updateRowElements(row, sid, student, attList, notesMap) {
        const hasAtt = attList.length > 0;
        const deliveryFailed=student.email_delivery_status==='failed';
        const hasPending = deliveryFailed || attList.some(a => !a.email_notified);
        const findCell = (cellId) => (row && row.querySelector ? row.querySelector('#' + cellId) : null) || document.getElementById(cellId);

        // 1. Update Hours Cell with Interactive Inline Grid
        const hoursCell = findCell('hours-cell-' + sid);
        if (hoursCell) {
            const primarySecId = student.primary_section_id || 1;
            hoursCell.innerHTML = renderInlineGridHtml(sid, primarySecId, attList, notesMap || student.notes);
        }

        // 2. Update Total Cell
        const totalCell = findCell('total-cell-' + sid);
        if (totalCell) {
            totalCell.textContent = attList.length;
            totalCell.style.color = hasAtt ? 'var(--primary)' : 'var(--success)';
        }

        // 3. Update Checkbox Cell
        const checkCell = findCell('check-cell-' + sid);
        if (checkCell) {
            const hasEmails = !!(student.guardian_email || student.guardian_email_2);
            if (hasAtt && hasPending && hasEmails) {
                const existingCb = checkCell.querySelector('.student-checkbox');
                if (!existingCb) {
                    checkCell.innerHTML = `<input type="checkbox" name="student_ids[]" value="${sid}" class="student-checkbox">`;
                }
            } else {
                checkCell.innerHTML = '<span style="color:var(--text-muted); font-size:11px;">—</span>';
            }
        }

        // 4. Update Email Status Cell
        const emailCell = findCell('email-cell-' + sid);
        if (emailCell) {
            if (hasAtt) {
                const emailStatus = deliveryFailed ? '<span class="badge badge-danger" style="font-size:11px;">✕ Αποτυχία</span>' : hasPending 
                    ? '<span class="badge badge-warning" style="font-size:11px;">Εκκρεμεί</span>'
                    : '<span class="badge badge-success" style="font-size:11px;">Στάλθηκε</span>';
                emailCell.innerHTML = `
                    <div style="display:inline-flex; align-items:center; gap:6px;">
                        <button type="button" class="btn btn-secondary btn-sm" style="padding:3px 8px; font-size:11px;" title="Προεπισκόπηση email" onclick='onLiveEmailPreviewClick(${sid})'>👁️</button>
                        ${emailStatus}
                    </div>
                `;
            } else {
                emailCell.innerHTML = '<span style="color:var(--text-muted); font-size:12px;">—</span>';
            }
        }

        if (typeof updateBulkEmailButton === 'function') {
            updateBulkEmailButton();
        }
    }

    function createStudentRow(sid, student, attList) {
        const tr = document.createElement('tr');
        tr.id = 'student-row-' + sid;
        tr.className = 'student-attendance-row';
        tr.setAttribute('data-student-id', sid);
        tr.setAttribute('data-am', student.am);
        tr.setAttribute('data-name', student.name);
        tr.setAttribute('data-gender', student.gender);
        tr.style.cssText = 'border-bottom:1px solid var(--border); transition:background 0.2s ease;';

        const sectionsBadges = (student.section_names || '').split(', ').filter(Boolean).map(sn => 
            `<span class="badge badge-info" style="font-size:10px;">${escapeHtml(sn)}</span>`
        ).join(' ');

        const genderLabel = student.gender === 'female' ? 'Μαθήτρια' : 'Μαθητής';

        let guardiansHtml = '';
        if (student.guardian_email) {
            guardiansHtml += `<div style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:220px;" title="${escapeHtml(student.guardian_email)}">📧 ${escapeHtml(student.guardian_email)}</div>`;
        }
        if (student.guardian_mobile) {
            guardiansHtml += `<div style="color:var(--text-muted); font-size:11px;">📱 ${escapeHtml(student.guardian_mobile)}</div>`;
        }
        if (!student.guardian_email && !student.guardian_email_2) {
            guardiansHtml += '<span style="color:var(--danger); font-size:11px; font-weight:600;">⚠️ Χωρίς email</span>';
        }

        tr.innerHTML = `
            <td id="check-cell-${sid}" style="padding:10px 12px; text-align:center;"></td>
            <td style="padding:10px 12px; font-weight:600; color:var(--text-muted); font-family:monospace;">${escapeHtml(student.am)}</td>
            <td style="padding:10px 12px;">
                <div style="font-weight:700; color:var(--text-main); font-size:14px;">
                    ${escapeHtml(student.name)}
                    <span style="font-size:11px; font-weight:normal; color:var(--text-muted);">(${genderLabel})</span>
                </div>
                <div style="display:flex; gap:4px; margin-top:3px; flex-wrap:wrap;">${sectionsBadges}</div>
            </td>
            <td id="hours-cell-${sid}" style="padding:10px 12px;"></td>
            <td id="total-cell-${sid}" style="padding:10px 12px; text-align:center; font-weight:700; font-size:14px;"></td>
            <td style="padding:10px 12px; font-size:12px; line-height:1.4;">${guardiansHtml}</td>
            <td id="email-cell-${sid}" style="padding:10px 12px; text-align:center;"></td>
            <td id="action-cell-${sid}" style="padding:10px 12px; text-align:right;"><button type="button" class="btn btn-secondary btn-sm" onclick="openStudentEmailComposer(${sid},'direct')">✉️ Νέο email</button>
                <button type="button" class="btn btn-secondary btn-sm" style="padding:4px 10px; font-size:12px; font-weight:600;" onclick='onLiveManageClick(${sid})'>⚙️ Διαχείριση</button>
            </td>
        `;

        updateRowElements(tr, sid, student, attList);
        return tr;
    }

    function flashRow(row) {
        row.classList.remove('row-highlight-flash');
        void row.offsetWidth; // Trigger reflow
        row.classList.add('row-highlight-flash');
    }

    function handleRealtimeNotifications(notifs) {
        // Group by section, teacher, and hour
        const groups = {};
        notifs.forEach(n => {
            const gKey = (n.section_name || '') + '|' + (n.teacher_name || '') + '|' + n.hour;
            if (!groups[gKey]) groups[gKey] = [];
            groups[gKey].push(n);
        });

        Object.values(groups).forEach(group => {
            if (group.length > 3) {
                showConsolidatedToast(group);
            } else {
                group.forEach(n => showRealtimeToast(n));
            }
        });
    }

    function showConsolidatedToast(group) {
        const container = document.getElementById('liveToastContainer');
        if (!container || !group.length) return;

        while (container.children.length >= 4) {
            container.removeChild(container.firstElementChild);
        }

        const first = group[0];
        const studentNames = group.map(n => n.student_name);
        const displayNames = studentNames.slice(0, 3).join(', ') + (studentNames.length > 3 ? ` (+${studentNames.length - 3} ακόμα)` : '');

        const toast = document.createElement('div');
        toast.className = 'live-toast';
        toast.style.pointerEvents = 'auto';
        toast.style.borderLeftColor = 'var(--primary)';
        toast.innerHTML = `
            <div style="font-size:20px; line-height:1;">🔔</div>
            <div style="flex:1;">
                <div style="font-weight:700; font-size:13px; color:var(--primary); margin-bottom:2px;">
                    Νέα Μαζική Καταχώρηση (${group.length} μαθητές)
                </div>
                <div style="font-size:12px; font-weight:600; color:var(--text-main);">
                    Τμήμα: <b>${escapeHtml(first.section_name)}</b> &bull; ${first.hour}η ώρα
                </div>
                <div style="font-size:11px; color:var(--text-muted); margin-top:2px;">
                    Καθηγητής: <b>${escapeHtml(first.teacher_name)}</b>
                </div>
                <div style="font-size:11px; color:var(--text-main); margin-top:3px; background:#f8fafc; padding:3px 6px; border-radius:4px; border:1px solid #e2e8f0;">
                    ${escapeHtml(displayNames)}
                </div>
            </div>
            <button type="button" onclick="this.closest('.live-toast').remove()" style="background:none; border:none; font-size:16px; line-height:1; color:var(--text-muted); cursor:pointer; padding:0 4px; margin-left:4px;" title="Κλείσιμο">&times;</button>
        `;

        container.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(15px)';
            setTimeout(() => { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 350);
        }, 6000);
    }

    function showRealtimeToast(notif) {
        const container = document.getElementById('liveToastContainer');
        if (!container) return;

        while (container.children.length >= 4) {
            container.removeChild(container.firstElementChild);
        }

        const typeColor = notif.absence_type === 'expulsion' ? '#d97706' : (notif.absence_type === 'justified' ? '#2563eb' : '#dc2626');
        const icon = notif.absence_type === 'expulsion' ? '⚠️' : (notif.absence_type === 'justified' ? 'ℹ️' : '🔔');

        const toast = document.createElement('div');
        toast.className = 'live-toast';
        toast.style.pointerEvents = 'auto';
        toast.style.borderLeftColor = typeColor;
        toast.innerHTML = `
            <div style="font-size:20px; line-height:1;">${icon}</div>
            <div style="flex:1;">
                <div style="font-weight:700; font-size:13px; color:${typeColor}; margin-bottom:2px;">
                    Νέα Καταχώρηση από Εκπαιδευτικό
                </div>
                <div style="font-size:13px; font-weight:600; color:var(--text-main);">
                    ${escapeHtml(notif.student_name)} &bull; ${notif.hour}η ώρα
                </div>
                <div style="font-size:11px; color:var(--text-muted); margin-top:2px;">
                    Τμήμα: <b>${escapeHtml(notif.section_name)}</b> &bull; Καθηγητής: <b>${escapeHtml(notif.teacher_name)}</b>
                </div>
            </div>
            <button type="button" onclick="this.closest('.live-toast').remove()" style="background:none; border:none; font-size:16px; line-height:1; color:var(--text-muted); cursor:pointer; padding:0 4px; margin-left:4px;" title="Κλείσιμο">&times;</button>
        `;

        container.appendChild(toast);

        // Auto remove after 5 seconds
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(15px)';
            setTimeout(() => { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 350);
        }, 5000);
    }

    // Modal Helper functions for dynamically added rows
    window.onLiveManageClick = function(sid) {
        if (window.openManageModalForStudent && window.allStudentsState && window.allStudentsState[sid]) {
            window.openManageModalForStudent(sid);
            return;
        }
        const student = studentsCatalog[sid];
        if (!student) return;
        // Fetch current attendances from state
        fetch(`../api/get_live_attendance.php?date=${encodeURIComponent(currentDate)}&student_id=${sid}&state_hash=`)
            .then(r => r.json())
            .then(data => {
                const atts = (data.student_attendances && data.student_attendances[sid]) || [];
                student.attendances = atts.map(a => ({
                    id: a.id,
                    hour: a.hour,
                    section_id: a.section_id,
                    teacher: a.teacher_name,
                    section: a.section_name,
                    notified: a.email_notified,
                    absence_type: a.absence_type,
                    note: a.note || ''
                }));
                showStudentAbsenceModal(student);
            });
    };

    window.onLiveEmailPreviewClick = function(sid) {
        if (window.openEmailPreviewForStudent && window.allStudentsState && window.allStudentsState[sid]) {
            window.openEmailPreviewForStudent(sid);
            return;
        }
        const student = studentsCatalog[sid];
        if (!student) return;
        showEmailPreview(student);
    };

    // Start Real-time Polling Engine
    liveRetryButton?.addEventListener('click', pollAttendanceUpdates);
    window.addEventListener('online', pollAttendanceUpdates);
    livePollTimer = setTimeout(pollAttendanceUpdates, 2000);
})();
</script>
<div class="print-only" style="margin-top:50px; display:flex; justify-content:space-between; align-items:flex-end; font-size:11pt; page-break-inside:avoid;">
    <div>
        Εκτυπώθηκε: <?= date('d/m/Y H:i') ?>
    </div>
    <div style="text-align:center; min-width:240px;">
        Ο/Η Διευθυντής/ντρια<br><br><br><br>
        ___________________________
    </div>
</div>
<?php
require_once __DIR__ . '/footer.php';
