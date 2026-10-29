<?php
// admin/settings.php - System, Multi-SMTP Profiles & Email Template Configuration
$pageTitle = 'Ρυθμίσεις & Email';
require_once __DIR__ . '/header.php';

$db = getDB();
$message = '';
$error = '';

// Handle all POST actions with CSRF verification
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Μη έγκυρο αίτημα ασφαλείας (CSRF Token mismatch). Παρακαλώ ανανεώστε τη σελίδα.';
    } else {
        // 1. Handle Set Active SMTP Account
        if (isset($_POST['set_active_smtp'])) {
            $accId = (int)$_POST['account_id'];
            try {
                $db->beginTransaction();
                $db->exec("UPDATE smtp_accounts SET is_active = 0");
                $stmt = $db->prepare("UPDATE smtp_accounts SET is_active = 1 WHERE id = ?");
                $stmt->execute([$accId]);
                $db->commit();
                $message = 'Ο επιλεγμένος διακομιστής SMTP ενεργοποιήθηκε επιτυχώς!';
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                $error = 'Σφάλμα ενεργοποίησης: ' . safeError($e);
            }
        }

// 2. Handle Add New SMTP Account
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_smtp_account'])) {
    $title = trim($_POST['title'] ?? '');
    $fromEmail = trim($_POST['from_email'] ?? '');
    $fromName = trim($_POST['from_name'] ?? '');
    $host = trim($_POST['host'] ?? '');
    $port = (int)($_POST['port'] ?? 465);
    $encryption = trim($_POST['encryption'] ?? 'ssl');
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $makeActive = isset($_POST['make_active']) ? 1 : 0;

    if (!in_array($encryption, ['ssl','tls'], true)) {
        $error = 'Επιλέξτε SSL ή STARTTLS για ασφαλή σύνδεση SMTP.';
    } elseif (empty($title) || empty($host) || empty($fromEmail)) {
        $error = 'Παρακαλώ συμπληρώστε τα υποχρεωτικά πεδία (Τίτλος, Διακομιστής Host, Email Αποστολέα).';
    } else {
        try {
            $db->beginTransaction();
            if ($makeActive) {
                $db->exec("UPDATE smtp_accounts SET is_active = 0");
            }
            // Step 7: Securely encrypt SMTP password with AES-256-GCM
            $encryptedPassword = ($password !== '') ? CryptoHelper::encrypt($password) : null;

            $stmt = $db->prepare("
                INSERT INTO smtp_accounts (title, from_email, from_name, host, port, encryption, username, password, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$title, $fromEmail, $fromName, $host, $port, $encryption, $username, $encryptedPassword, $makeActive]);
            $db->commit();
            $message = "Το προφίλ SMTP «{$title}» προστέθηκε επιτυχώς!";
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $error = 'Σφάλμα προσθήκης: ' . safeError($e);
        }
    }
}

// 3. Handle Edit SMTP Account
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_smtp_account'])) {
    $accId = (int)$_POST['account_id'];
    $title = trim($_POST['title'] ?? '');
    $fromEmail = trim($_POST['from_email'] ?? '');
    $fromName = trim($_POST['from_name'] ?? '');
    $host = trim($_POST['host'] ?? '');
    $port = (int)($_POST['port'] ?? 465);
    $encryption = trim($_POST['encryption'] ?? 'ssl');
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!in_array($encryption, ['ssl','tls'], true)) {
        $error = 'Επιλέξτε SSL ή STARTTLS για ασφαλή σύνδεση SMTP.';
    } elseif ($accId <= 0 || empty($title) || empty($host) || empty($fromEmail)) {
        $error = 'Παρακαλώ συμπληρώστε τα υποχρεωτικά πεδία.';
    } else {
        try {
            if (empty($password)) {
                $stmt = $db->prepare("
                    UPDATE smtp_accounts SET 
                        title = ?, from_email = ?, from_name = ?, host = ?, port = ?, encryption = ?, username = ?
                    WHERE id = ?
                ");
                $stmt->execute([$title, $fromEmail, $fromName, $host, $port, $encryption, $username, $accId]);
            } else {
                // Step 7: Encrypt updated password with AES-256-GCM
                $encryptedPassword = CryptoHelper::encrypt($password);
                $stmt = $db->prepare("
                    UPDATE smtp_accounts SET 
                        title = ?, from_email = ?, from_name = ?, host = ?, port = ?, encryption = ?, username = ?, password = ?
                    WHERE id = ?
                ");
                $stmt->execute([$title, $fromEmail, $fromName, $host, $port, $encryption, $username, $encryptedPassword, $accId]);
            }
            $message = "Το προφίλ SMTP «{$title}» ενημερώθηκε επιτυχώς!";
        } catch (Exception $e) {
            $error = 'Σφάλμα ενημέρωσης: ' . safeError($e);
        }
    }
}

// 4. Handle Delete SMTP Account
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_smtp_account'])) {
    $accId = (int)$_POST['account_id'];
    try {
        $count = $db->query("SELECT COUNT(*) FROM smtp_accounts")->fetchColumn();
        if ($count <= 1) {
            $error = 'Δεν μπορείτε να διαγράψετε το μοναδικό διαθέσιμο προφίλ SMTP.';
        } else {
            $stmtActive = $db->prepare("SELECT is_active FROM smtp_accounts WHERE id = ?");
            $stmtActive->execute([$accId]);
            $isActive = $stmtActive->fetchColumn();
            $db->prepare("DELETE FROM smtp_accounts WHERE id = ?")->execute([$accId]);
            if ($isActive) {
                $db->exec("UPDATE smtp_accounts SET is_active = 1 ORDER BY id ASC LIMIT 1");
            }
            $message = 'Το προφίλ SMTP διαγράφηκε επιτυχώς.';
        }
    } catch (Exception $e) {
        $error = 'Σφάλμα διαγραφής: ' . safeError($e);
    }
}

// 5. Handle Test Email for Specific SMTP Account
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_test_email'])) {
    $accId = (int)$_POST['test_account_id'];
    $testTo = trim($_POST['test_email_recipient'] ?? '');
    if (empty($testTo)) {
        $error = 'Παρακαλώ εισάγετε email παραλήπτη για τη δοκιμή.';
    } else {
        $acc = getActiveSmtpAccount($accId);
        $sub = 'Δοκιμαστικό Email - ' . ($acc ? $acc['title'] : getSchoolSetting('school_name'));
        $body = "Αυτό είναι ένα δοκιμαστικό μήνυμα από το σύστημα παρουσιολογίου.\n\nΔιακομιστής: " . ($acc['host'] ?? 'N/A') . " (" . ($acc['title'] ?? '') . ")\n\nΟι ρυθμίσεις λειτουργούν επιτυχώς!";
        $res = sendEmailSMTP($testTo, $sub, $body, null, $accId);
        if ($res['success']) {
            $message = "Το δοκιμαστικό email στάλθηκε επιτυχώς στο {$testTo} μέσω του προφίλ «" . htmlspecialchars($acc['title'] ?? '') . "»!";
        } else {
            $error = "Αποτυχία δοκιμαστικής αποστολής: " . ($res['error'] ?? 'Άγνωστο σφάλμα');
        }
    }
}

// 6. Handle General Settings & Template & Privacy
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_general_settings'])) {
    $keys = ['school_name', 'school_email', 'school_phone', 'email_template_subject', 'email_template_body'];
    foreach ($keys as $k) {
        if (isset($_POST[$k])) {
            updateSchoolSetting($k, trim($_POST[$k]));
        }
    }
    $maskVal = isset($_POST['mask_student_name']) ? '1' : '0';
    updateSchoolSetting('mask_student_name', $maskVal);

    // Save School Bell Schedule (Ωράριο Διδακτικών Ωρών)
    if (isset($_POST['bell_start'], $_POST['bell_end']) && is_array($_POST['bell_start']) && is_array($_POST['bell_end'])) {
        $newSchedule = [];
        for ($h = 1; $h <= 7; $h++) {
            $start = trim($_POST['bell_start'][$h] ?? '');
            $end = trim($_POST['bell_end'][$h] ?? '');
            
            // Normalize to HH:MM format (leading zeros)
            if (preg_match('/^(\d{1,2}):(\d{2})$/', $start, $m)) {
                $start = sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
            }
            if (preg_match('/^(\d{1,2}):(\d{2})$/', $end, $m)) {
                $end = sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
            }

            if (!empty($start) && !empty($end)) {
                $newSchedule[$h] = ['start' => $start, 'end' => $end];
            }
        }
        if (!empty($newSchedule)) {
            updateSchoolSetting('bell_schedule', json_encode($newSchedule, JSON_UNESCAPED_UNICODE));
        }
    }

    $message = 'Τα στοιχεία του σχολείου, το ωράριο διδακτικών ωρών, οι ρυθμίσεις απορρήτου και το πρότυπο email αποθηκεύτηκαν επιτυχώς!';
}

// 6a. Handle SMS Gateway Settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_sms_settings'])) {
    require_once __DIR__ . '/../includes/sms.php';
    $smsEnabled = isset($_POST['sms_enabled']) ? '1' : '0';
    updateSchoolSetting('sms_enabled', $smsEnabled);

    if (isset($_POST['sms_provider'])) {
        $allowedProviders = ['easysms', 'smsto', 'log'];
        $prov = in_array($_POST['sms_provider'], $allowedProviders, true) ? $_POST['sms_provider'] : 'log';
        updateSchoolSetting('sms_provider', $prov);
    }

    $apiKey = trim($_POST['sms_api_key'] ?? '');
    if (!empty($apiKey)) {
        updateSchoolSetting('sms_api_key', CryptoHelper::encrypt($apiKey));
    }

    if (isset($_POST['sms_sender_id'])) {
        $senderId = substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', trim($_POST['sms_sender_id'])), 0, 11);
        updateSchoolSetting('sms_sender_id', $senderId);
    }

    if (isset($_POST['sms_template'])) {
        updateSchoolSetting('sms_template', trim($_POST['sms_template']));
    }

    if (isset($_POST['sms_send_mode'])) {
        $mode = ($_POST['sms_send_mode'] === 'fallback_no_email') ? 'fallback_no_email' : 'all';
        updateSchoolSetting('sms_send_mode', $mode);
    }

    $message = 'Οι ρυθμίσεις SMS αποθηκεύτηκαν επιτυχώς!';
}

// 6b. Handle Test SMS Dispatch
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_sms_send'])) {
    require_once __DIR__ . '/../includes/sms.php';
    $testMobile = trim($_POST['test_mobile'] ?? '');
    $testText = trim($_POST['test_text'] ?? 'Δοκιμαστικό μήνυμα SMS από το ηλεκτρονικό παρουσιολόγιο σχολείου.');

    $res = sendSmsMessage($testMobile, $testText, ['force' => true]);
    if ($res['success']) {
        if (!empty($res['simulated'])) {
            $message = "Δοκιμαστική αποστολή SMS (Προσομοίωση): Το μήνυμα καταγράφηκε επιτυχώς για τον αριθμό {$testMobile}!";
        } else {
            $message = "Το SMS στάλθηκε επιτυχώς στον αριθμό {$testMobile}!";
        }
    } else {
        $error = "Αποτυχία αποστολής δοκιμαστικού SMS: " . ($res['error'] ?? 'Άγνωστο σφάλμα');
    }
}

// 6c. Handle Check SMS Balance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['check_sms_balance'])) {
    require_once __DIR__ . '/../includes/sms.php';
    $bal = getSmsBalance();
    if ($bal['success']) {
        $message = "Διαθέσιμο Υπόλοιπο SMS: " . $bal['balance'];
    } else {
        $error = "Σφάλμα ελέγχου υπολοίπου: " . ($bal['error'] ?? 'Άγνωστο σφάλμα');
    }
}

        // 6b. Handle Administrator Account & Password Update
        if (isset($_POST['update_admin_profile'])) {
        $adminName = trim($_POST['admin_name'] ?? '');
        $adminEmail = trim($_POST['admin_email'] ?? '');
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        try {
            // Fetch current user record with password hash
            $stmtUser = $db->prepare("SELECT id, name, email, password_hash FROM users WHERE id = ?");
            $stmtUser->execute([$_SESSION['user_id']]);
            $currUser = $stmtUser->fetch();

            if (!$currUser) {
                $error = 'Ο λογαριασμός χρήστη δεν βρέθηκε.';
            } elseif (empty($currentPassword) || !password_verify($currentPassword, $currUser['password_hash'])) {
                $error = 'Ο τρέχων κωδικός πρόσβασης που εισάγατε είναι εσφαλμένος.';
            } elseif (empty($adminName)) {
                $error = 'Παρακαλώ συμπληρώστε το ονοματεπώνυμο / περιγραφή του λογαριασμού σας.';
            } elseif (empty($adminEmail) || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                $error = 'Παρακαλώ εισάγετε μια έγκυρη διεύθυνση email.';
            } else {
                // Check if email already used by another user
                $stmtCheck = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $stmtCheck->execute([$adminEmail, $_SESSION['user_id']]);
                if ($stmtCheck->fetch()) {
                    $error = "Το email «{$adminEmail}» χρησιμοποιείται ήδη από άλλον λογαριασμό.";
                } elseif (!empty($newPassword)) {
                    if (mb_strlen($newPassword) < 6) {
                        $error = 'Ο νέος κωδικός πρόσβασης πρέπει να έχει τουλάχιστον 6 χαρακτήρες.';
                    } elseif ($newPassword !== $confirmPassword) {
                        $error = 'Ο νέος κωδικός και η επιβεβαίωσή του δεν ταιριάζουν.';
                    } else {
                        $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
                        $stmtUpd = $db->prepare("UPDATE users SET name = ?, email = ?, password_hash = ?, auth_version=auth_version+1 WHERE id = ?");
                        $stmtUpd->execute([$adminName, $adminEmail, $hashed, $_SESSION['user_id']]);
                        $message = 'Τα στοιχεία του λογαριασμού σας και ο νέος κωδικός πρόσβασης αποθηκεύτηκαν επιτυχώς!';
                        $user['name'] = $adminName;
                        $user['email'] = $adminEmail;
                        $_SESSION['user_name'] = $adminName;
                    }
                } else {
                    $stmtUpd = $db->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
                    $stmtUpd->execute([$adminName, $adminEmail, $_SESSION['user_id']]);
                    $message = 'Τα στοιχεία του λογαριασμού σας (Όνομα & Email) ενημερώθηκαν επιτυχώς!';
                    $user['name'] = $adminName;
                    $user['email'] = $adminEmail;
                    $_SESSION['user_name'] = $adminName;
                }
            }
        } catch (Exception $e) {
            $error = 'Σφάλμα κατά την αποθήκευση: ' . safeError($e);
        }
    }

    // 7. Handle Academic Year: Switch Active Year
    if (isset($_POST['switch_academic_year'])) {
        $targetYearId = (int)$_POST['academic_year_id'];
        if (setActiveAcademicYear($targetYearId)) {
            $message = 'Το ενεργό διδακτικό έτος άλλαξε επιτυχώς!';
        } else {
            $error = 'Σφάλμα κατά την αλλαγή του ενεργού διδακτικού έτους.';
        }
    }

    // 8. Handle Academic Year: Create New Year
    if (isset($_POST['create_academic_year'])) {
        $yearName = trim($_POST['year_name'] ?? '');
        $setAsActive = !empty($_POST['set_as_active']);
        if (empty($yearName)) {
            $error = 'Παρακαλώ εισάγετε ονομασία για το διδακτικό έτος (π.χ. 2027-2028).';
        } else {
            $newId = createAcademicYear($yearName, $setAsActive);
            if ($newId) {
                $message = "Το νέο διδακτικό έτος '{$yearName}' δημιουργήθηκε επιτυχώς" . ($setAsActive ? ' και ορίστηκε ως ενεργό! Η νέα σχολική χρονιά ξεκινά με κενή βάση δεδομένων (0 μαθητές, 0 απουσίες).' : '!');
            } else {
                $error = "Υπάρχει ήδη διδακτικό έτος με την ονομασία '{$yearName}' ή σημειώθηκε σφάλμα.";
            }
        }
    }

    // 9. Handle Academic Year: Delete Inactive Year
    if (isset($_POST['delete_academic_year'])) {
        $delYearId = (int)$_POST['academic_year_id'];
        $delRes = deleteAcademicYear($delYearId);
        if ($delRes['success']) {
            $message = 'Το διδακτικό έτος διαγράφηκε επιτυχώς.';
        } else {
            $error = 'Αποτυχία διαγραφής: ' . $delRes['error'];
        }
    }
}
}

// Fetch all SMTP accounts
$smtpAccounts = $db->query("SELECT * FROM smtp_accounts ORDER BY is_active DESC, id ASC")->fetchAll();

// Current general values
$schoolName = getSchoolSetting('school_name', '1ο Γενικό Λύκειο');
$schoolEmail = getSchoolSetting('school_email', 'mail@myschool.mysch.gr');
$schoolPhone = getSchoolSetting('school_phone', '2100000000');
$templateSubject = getSchoolSetting('email_template_subject', 'Ενημέρωση Απουσιών Μαθητή - {school_name}');
$templateBody = getSchoolSetting('email_template_body', "Αγαπητέ κηδεμόνα {guardian_name_voc},\n\nΣας ενημερώνουμε ότι {student_title} {student_full_name} σημείωσε σήμερα {date} απουσία κατά τις εξής διδακτικές ώρες: {hours}.\n\nΜε εκτίμηση,\nΗ Διεύθυνση του {school_name}\nΤηλ: {school_phone}");
$maskStudentName = getSchoolSetting('mask_student_name', '0');
$bellSchedule = getBellSchedule();

// SMS Gateway Settings
$smsEnabled = getSchoolSetting('sms_enabled', '0');
$smsProvider = getSchoolSetting('sms_provider', 'easysms');
$rawSmsKey = getSchoolSetting('sms_api_key', '');
$hasSmsKey = !empty($rawSmsKey);
$smsSenderId = getSchoolSetting('sms_sender_id', 'SXOLEIO');
$smsTemplate = getSchoolSetting('sms_template', 'ΕΝΗΜΕΡΩΣΗ ΑΠΟ {school_name}: {student_title} {student_full_name} ΣΗΜΕΙΩΣΕ {hours_count} ΑΠΟΥΣΙΕΣ ΣΤΙΣ {date}.');
$smsSendMode = getSchoolSetting('sms_send_mode', 'fallback_no_email');

// Academic Years
$activeAcademicYear = getActiveAcademicYear();
$allAcademicYears = getAllAcademicYearsWithStats();
?>

<?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- 1. School Settings & Template Form (Top Section) -->
<form method="POST" action="settings.php" style="margin-bottom:28px;">
    <?= csrfField() ?>
    <div class="settings-grid" style="display:grid; grid-template-columns: 1fr 1fr; gap:24px;">
        <div class="card">
            <h3 class="card-title">🏫 Στοιχεία Σχολείου</h3>
            <div class="form-group">
                <label>Όνομα Σχολείου</label>
                <input type="text" name="school_name" class="form-control" value="<?= htmlspecialchars($schoolName) ?>" required>
            </div>
            <div class="form-group">
                <label>Επίσημο Email Σχολείου</label>
                <input type="email" name="school_email" class="form-control" value="<?= htmlspecialchars($schoolEmail) ?>" required>
            </div>
            <div class="form-group">
                <label>Τηλέφωνο Επικοινωνίας Σχολείου</label>
                <input type="text" name="school_phone" class="form-control" value="<?= htmlspecialchars($schoolPhone) ?>">
            </div>

            <div style="margin-top:20px; padding-top:16px; border-top:1px solid var(--border); margin-bottom:18px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; flex-wrap:wrap; gap:6px;">
                    <label style="font-size:13px; font-weight:700; color:var(--text-main); display:flex; align-items:center; gap:6px; margin-bottom:0;">
                        <span>⏰</span> Ωράριο Διδακτικών Ωρών (24ωρη μορφή)
                    </label>
                    <span style="font-size:11px; color:var(--text-muted);">Αυτόματη επιλογή ώρας στο Παρουσιολόγιο</span>
                </div>
                <div class="bell-schedule-box">
                    <div style="display:grid; grid-template-columns: 80px 1fr 1fr; gap:10px; font-size:11px; font-weight:700; color:var(--text-muted); margin-bottom:8px; text-transform:uppercase; letter-spacing:0.5px;">
                        <div>Διδ. Ώρα</div>
                        <div>Έναρξη (24ωρο)</div>
                        <div>Λήξη (24ωρο)</div>
                    </div>
                    <?php for ($h = 1; $h <= 7; $h++): ?>
                        <?php 
                            $hStart = $bellSchedule[$h]['start'] ?? '';
                            $hEnd = $bellSchedule[$h]['end'] ?? '';
                        ?>
                        <div style="display:grid; grid-template-columns: 80px 1fr 1fr; gap:10px; align-items:center; margin-bottom:8px;">
                            <div style="font-weight:700; font-size:13px; color:var(--text-main);"><?= $h ?>η ώρα:</div>
                            <div>
                                <input type="text" name="bell_start[<?= $h ?>]" class="form-control bell-time-input" value="<?= htmlspecialchars($hStart) ?>" placeholder="08:15" pattern="^([01]?[0-9]|2[0-3]):[0-5][0-9]$" maxlength="5" autocomplete="off" required style="height:34px; font-size:13px; padding:2px 8px;">
                            </div>
                            <div>
                                <input type="text" name="bell_end[<?= $h ?>]" class="form-control bell-time-input" value="<?= htmlspecialchars($hEnd) ?>" placeholder="09:00" pattern="^([01]?[0-9]|2[0-3]):[0-5][0-9]$" maxlength="5" autocomplete="off" required style="height:34px; font-size:13px; padding:2px 8px;">
                            </div>
                        </div>
                    <?php endfor; ?>
                    <div style="font-size:11px; color:var(--text-muted); margin-top:10px; display:flex; align-items:center; gap:5px; line-height:1.4;">
                        <span>💡</span> <em>Συμπληρώστε τις ώρες σε 24ωρη μορφή (π.χ. 08:15, 13:25, 14:10). Στο Παρουσιολόγιο προεπιλέγεται αυτόματα η σωστή ώρα.</em>
                    </div>
                </div>
            </div>

            <button type="submit" name="save_general_settings" class="btn btn-primary btn-block">
                💾 Αποθήκευση Στοιχείων &amp; Ωραρίου
            </button>
        </div>

        <div class="card">
            <h3 class="card-title">✉️ Πρότυπο Συγκεντρωτικού Email</h3>
            <div class="form-group">
                <label>Θέμα Email (Subject)</label>
                <input type="text" name="email_template_subject" class="form-control" value="<?= htmlspecialchars($templateSubject) ?>" required>
            </div>

            <div class="form-group">
                <label>Κείμενο Email (Body)</label>
                <textarea name="email_template_body" class="form-control" rows="8" required><?= htmlspecialchars($templateBody) ?></textarea>
            </div>

            <div class="settings-panel" style="background:#f8fafc; border:1px solid var(--border); border-radius:8px; padding:10px; margin-bottom:14px; font-size:12px;">
                <div style="font-weight:700; margin-bottom:4px;">Διαθέσιμες Μεταβλητές:</div>
                <div class="settings-grid" style="display:grid; grid-template-columns: 1fr 1fr; gap:4px;">
                    <div><code>{student_title}</code> : ο μαθητής / η μαθήτρια</div>
                    <div><code>{student_title_gen}</code> : του μαθητή / της μαθήτριας</div>
                    <div><code>{student_full_name}</code> : Ονοματεπώνυμο</div>
                    <div><code>{student_genitive}</code> : Σε Γενική πτώση</div>
                    <div><code>{guardian_name_voc}</code> : Κλητική Κηδεμόνα</div>
                    <div><code>{hours}</code> : Ώρες (π.χ. 1η, 2η, 3η)</div>
                    <div><code>{date}</code> : Σημερινή ημερομηνία</div>
                    <div><code>{school_name}</code> : Όνομα σχολείου</div>
                </div>
            </div>

            <div class="form-group" style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:12px; margin-top:10px; margin-bottom:14px;">
                <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer; font-weight:700; margin-bottom:4px; color:#166534; font-size:13px;">
                    <input type="checkbox" name="mask_student_name" value="1" <?= $maskStudentName === '1' ? 'checked' : '' ?> style="width:18px; height:18px; margin-top:2px;">
                    <span>🔒 Απόκρυψη Ονόματος Μαθητή στα Emails (Προστασία GDPR)</span>
                </label>
                <div style="font-size:12px; color:#15803d; line-height:1.45; margin-left:28px;">
                    Εμφάνιση <strong>μόνο των 2 πρώτων γραμμάτων</strong> από το όνομα και το επώνυμο, ενώ οι υπόλοιποι χαρακτήρες αντικαθίστανται από αστερίσκους.<br>
                    <strong>Παράδειγμα:</strong> <code style="background:#dcfce7; padding:2px 6px; border-radius:4px;">Παπαδόπουλος Νικόλαος</code> &rarr; <span class="badge" style="background:#166534; color:#fff; font-family:monospace; font-size:12.5px; padding:3px 8px;">Πα********* Νι*****</span>
                </div>
            </div>

            <button type="submit" name="save_general_settings" class="btn btn-primary btn-block">
                💾 Αποθήκευση Στοιχείων &amp; Προτύπου Email
            </button>
        </div>
    </div>
</form>

<!-- 2. Administrator Account & Password Management Section -->
<div class="card" id="admin-profile" style="margin-bottom:28px; border-left: 4px solid #8b5cf6;">
    <div class="card-title" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:14px;">
        <div style="display:flex; align-items:center; gap:10px;">
            <span style="font-size:22px;">👤</span>
            <div>
                <span style="font-size:18px; font-weight:700;">Ο Λογαριασμός μου (Στοιχεία Σύνδεσης &amp; Κωδικός)</span>
                <div style="font-size:12px; color:var(--text-muted); font-weight:normal;">
                    Ενημέρωση ονοματεπωνύμου, email εισόδου και αλλαγή κωδικού πρόσβασης
                </div>
            </div>
        </div>
        <div>
            <span class="badge" style="background:#ede9fe; color:#6d28d9; font-size:12px; padding:6px 12px; font-weight:700;">
                🔑 Ρόλος: <?= htmlspecialchars($user['role'] === 'admin' ? 'Διαχειριστής Συστήματος' : 'Καθηγητής - Διαχειριστής') ?>
            </span>
        </div>
    </div>

    <form method="POST" action="settings.php#admin-profile" style="margin-top:10px;">
        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
        <div class="settings-grid" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap:20px; margin-bottom:18px;">
            <!-- Name & Email -->
            <div class="settings-panel" style="background:#f8fafc; border:1px solid var(--border); border-radius:10px; padding:16px;">
                <h4 style="margin:0 0 14px 0; font-size:14px; font-weight:700; color:var(--text-main); display:flex; align-items:center; gap:6px;">
                    <span>📝</span> Στοιχεία Ταυτοποίησης
                </h4>
                <div class="form-group">
                    <label style="display:block; font-size:12px; font-weight:600; color:var(--text-muted); margin-bottom:5px;">
                        Ονοματεπώνυμο / Περιγραφή Χρήστη: *
                    </label>
                    <input type="text" name="admin_name" class="form-control" value="<?= htmlspecialchars($user['name'] ?? '') ?>" required style="height:38px; font-weight:600;">
                    <div style="font-size:11px; color:var(--text-muted); margin-top:4px;">Εμφανίζεται στην επάνω μπάρα και στις ενέργειες του συστήματος.</div>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label style="display:block; font-size:12px; font-weight:600; color:var(--text-muted); margin-bottom:5px;">
                        Email Σύνδεσης (Username): *
                    </label>
                    <input type="email" name="admin_email" class="form-control" value="<?= htmlspecialchars($user['email'] ?? '') ?>" required style="height:38px; font-weight:600;">
                    <div style="font-size:11px; color:var(--text-muted); margin-top:4px;">Το email με το οποίο συνδέεστε στην αρχική οθόνη εισόδου.</div>
                </div>
            </div>

            <!-- New Password -->
            <div class="settings-panel" style="background:#f8fafc; border:1px solid var(--border); border-radius:10px; padding:16px;">
                <h4 style="margin:0 0 14px 0; font-size:14px; font-weight:700; color:var(--text-main); display:flex; align-items:center; gap:6px;">
                    <span>🔒</span> Αλλαγή Κωδικού Πρόσβασης
                </h4>
                <div class="form-group">
                    <label style="display:block; font-size:12px; font-weight:600; color:var(--text-muted); margin-bottom:5px;">
                        Νέος Κωδικός:
                    </label>
                    <input type="password" name="new_password" class="form-control" placeholder="Συμπληρώστε μόνο αν θέλετε να αλλάξει..." autocomplete="new-password" style="height:38px;">
                    <div style="font-size:11px; color:var(--text-muted); margin-top:4px;">Τουλάχιστον 6 χαρακτήρες. Αφήστε κενό για να διατηρηθεί ο ίδιος.</div>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label style="display:block; font-size:12px; font-weight:600; color:var(--text-muted); margin-bottom:5px;">
                        Επιβεβαίωση Νέου Κωδικού:
                    </label>
                    <input type="password" name="confirm_password" class="form-control" placeholder="Επανάληψη νέου κωδικού..." autocomplete="new-password" style="height:38px;">
                </div>
            </div>
        </div>

        <!-- Verification with current password & Submit -->
        <div style="background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:16px; display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:16px;">
            <div style="flex:1; min-width:260px;">
                <label style="display:block; font-size:13px; font-weight:700; color:#92400e; margin-bottom:5px;">
                    ⚠️ Τρέχων Κωδικός Πρόσβασης (Υποχρεωτικό για επιβεβαίωση): *
                </label>
                <input type="password" name="current_password" class="form-control" placeholder="Εισάγετε τον τρέχοντα κωδικό σας..." required style="height:38px; max-width:380px; background:#fff;">
                <div style="font-size:11px; color:#b45309; margin-top:4px;">Για λόγους ασφαλείας, απαιτείται η εισαγωγή του τρέχοντος κωδικού για την αποθήκευση οποιασδήποτε αλλαγής.</div>
            </div>
            <div>
                <button type="submit" name="update_admin_profile" class="btn btn-primary" style="height:40px; padding:0 22px; font-size:13.5px; font-weight:700;">
                    💾 Αποθήκευση Αλλαγών Λογαριασμού
                </button>
            </div>
        </div>
    </form>
</div>

<!-- 3. Academic Year Management Section -->
<div class="card" style="margin-bottom:28px; border-left: 4px solid var(--primary);">
    <div class="card-title" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div style="display:flex; align-items:center; gap:10px;">
            <span style="font-size:20px;">📅</span>
            <div>
                <span style="font-size:18px; font-weight:700;">Διαχείριση Διδακτικού Έτους (Σχολική Χρονιά)</span>
                <div style="font-size:12px; color:var(--text-muted); font-weight:normal;">
                    Τρέχον Ενεργό Έτος: <strong style="color:var(--primary); font-size:13px;"><?= htmlspecialchars($activeAcademicYear['name'] ?? 'Μη ορισμένο') ?></strong>
                </div>
            </div>
        </div>
        <div style="display:flex; gap:10px; align-items:center;">
            <span class="badge badge-success" style="font-size:13px; padding:6px 12px;">
                🌟 Ενεργό: <?= htmlspecialchars($activeAcademicYear['name'] ?? '-') ?>
            </span>
        </div>
    </div>

    <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:12px 16px; margin-bottom:20px; font-size:13px; color:#1e40af; line-height:1.5;">
        💡 <strong>Πώς λειτουργεί η νέα σχολική χρονιά:</strong> Όταν αλλάζει η διδακτική χρονιά, δημιουργείτε το νέο έτος (π.χ. <code>2027-2028</code>) και το ορίζετε ως ενεργό. Η νέα χρονιά ξεκινά αυτόματα με <strong>κενή βάση δεδομένων για μαθητές και απουσίες</strong>, έτοιμη να δεχτεί το νέο αρχείο MySchool. <strong>Τα δεδομένα της παλιάς σχολικής χρονιάς διατηρούνται πλήρως</strong> και μπορείτε ανά πάσα στιγμή να επιστρέψετε σε αυτήν από τον παρακάτω πίνακα!
    </div>

    <!-- Create New Year Form -->
    <div class="settings-panel" style="background:#f8fafc; border:1px solid var(--border); border-radius:10px; padding:16px; margin-bottom:24px;">
        <h4 style="margin:0 0 12px 0; font-size:15px; font-weight:700; color:var(--text-main); display:flex; align-items:center; gap:8px;">
            <span>➕</span> Δημιουργία Νέου Διδακτικού Έτους
        </h4>
        <form method="POST" action="settings.php" style="display:flex; gap:16px; align-items:flex-end; flex-wrap:wrap;">
            <?= csrfField() ?>
            <div style="flex:1; min-width:220px;">
                <label style="display:block; font-size:12px; font-weight:600; color:var(--text-muted); margin-bottom:4px;">
                    Ονομασία Έτους:
                </label>
                <input type="text" name="year_name" class="form-control" placeholder="π.χ. 2027-2028" required style="font-weight:600;">
            </div>
            <div style="padding-bottom:10px;">
                <label style="display:flex; align-items:center; gap:8px; font-size:13px; font-weight:600; cursor:pointer;">
                    <input type="checkbox" name="set_as_active" value="1" checked style="width:16px; height:16px;">
                    Ορισμός ως τρέχον ενεργό έτος αμέσως
                </label>
            </div>
            <div>
                <button type="submit" name="create_academic_year" class="btn btn-primary" style="font-weight:700;">
                    ✨ Δημιουργία Έτους
                </button>
            </div>
        </form>
    </div>

    <!-- Table of Academic Years -->
    <h4 style="margin:0 0 12px 0; font-size:15px; font-weight:700; color:var(--text-main);">
        📚 Ιστορικό &amp; Διαθέσιμα Διδακτικά Έτη
    </h4>
    <div class="table-responsive">
        <table class="table" style="font-size:13px;">
            <thead>
                <tr>
                    <th>Διδακτικό Έτος</th>
                    <th>Κατάσταση</th>
                    <th style="text-align:center;">Εγγεγραμμένοι Μαθητές</th>
                    <th style="text-align:center;">Καταχωρημένες Απουσίες</th>
                    <th>Ημερομηνία Δημιουργίας</th>
                    <th style="text-align:right;">Ενέργειες</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allAcademicYears as $ay): ?>
                    <tr style="<?= $ay['is_active'] ? 'background:#f0fdf4; font-weight:600;' : '' ?>">
                        <td>
                            <span style="font-size:14px;"><?= htmlspecialchars($ay['name']) ?></span>
                            <?php if ($ay['is_active']): ?>
                                <span class="badge badge-success" style="margin-left:6px;">Τρέχον Έτος</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($ay['is_active']): ?>
                                <span class="badge badge-success">Ενεργό</span>
                            <?php else: ?>
                                <span class="badge badge-gray">Ανενεργό</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;">
                            <span class="badge badge-info" style="font-size:12px;"><?= number_format($ay['students_count']) ?> μαθητές</span>
                        </td>
                        <td style="text-align:center;">
                            <span class="badge badge-warning" style="font-size:12px;"><?= number_format($ay['attendance_count']) ?> απουσίες</span>
                        </td>
                        <td style="color:var(--text-muted); font-size:12px;">
                            <?= date('d/m/Y H:i', strtotime($ay['created_at'])) ?>
                        </td>
                        <td style="text-align:right;">
                            <?php if (!$ay['is_active']): ?>
                                <form method="POST" action="settings.php" style="display:inline; margin-right:6px;">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="academic_year_id" value="<?= $ay['id'] ?>">
                                    <button type="submit" name="switch_academic_year" class="btn btn-secondary btn-sm" title="Ενεργοποίηση αυτού του διδακτικού έτους">
                                        🔄 Ενεργοποίηση
                                    </button>
                                </form>
                                <form method="POST" action="settings.php" style="display:inline;" onsubmit="return confirm('ΠΡΟΣΟΧΗ: Θα διαγραφούν οριστικά οι μαθητές και οι απουσίες του έτους <?= htmlspecialchars($ay['name']) ?>. Είστε απόλυτα βέβαιοι;');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="academic_year_id" value="<?= $ay['id'] ?>">
                                    <button type="submit" name="delete_academic_year" class="btn btn-danger btn-sm" title="Οριστική διαγραφή έτους">
                                        🗑️ Διαγραφή
                                    </button>
                                </form>
                            <?php else: ?>
                                <span style="font-size:12px; color:#166534; font-weight:700;">
                                    ✓ Σε χρήση
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 3. SMS Gateway Settings Section -->
<div class="card" id="sms-gateway" style="margin-bottom:28px; border-left: 4px solid #0284c7;">
    <div class="card-title" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:14px;">
        <div style="display:flex; align-items:center; gap:10px;">
            <span style="font-size:24px;">📱</span>
            <div>
                <span style="font-size:18px; font-weight:700;">Πύλη Αποστολής SMS (SMS Gateway)</span>
                <div style="font-size:12px; color:var(--text-muted); font-weight:normal;">
                    Άμεση ειδοποίηση κηδεμόνων μέσω SMS στα κινητά τηλέφωνα
                </div>
            </div>
        </div>
        <div style="display:flex; align-items:center; gap:8px;">
            <?php if ($smsEnabled === '1'): ?>
                <span class="badge badge-success" style="font-size:12px; padding:5px 12px; font-weight:700;">🟢 SMS ΕΝΕΡΓΟ</span>
            <?php else: ?>
                <span class="badge badge-gray" style="font-size:12px; padding:5px 12px; font-weight:700;">⚪ SMS ΑΝΕΝΕΡΓΟ</span>
            <?php endif; ?>
        </div>
    </div>

    <form method="POST" action="settings.php#sms-gateway" style="margin-bottom:20px;">
        <?= csrfField() ?>
        <input type="hidden" name="save_sms_settings" value="1">

        <div style="background:#f0f9ff; border:1px solid #bae6fd; border-radius:8px; padding:12px 16px; margin-bottom:20px; font-size:13px; color:#0369a1; line-height:1.5;">
            💡 <strong>Οικονομική Λειτουργία:</strong> Με τη στρατηγική <em>Smart Fallback</em>, τα SMS αποστέλλονται <strong>μόνο σε κηδεμόνες που δεν έχουν δηλώσει email</strong>. Έτσι το 90% των ειδοποιήσεων φεύγει δωρεάν μέσω Email και ελάχιστα SMS καταναλώνονται!
        </div>

        <div class="settings-grid" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:20px; margin-bottom:18px;">
            <!-- Column 1: Provider & Credentials -->
            <div class="settings-panel" style="background:#f8fafc; border:1px solid var(--border); border-radius:10px; padding:16px;">
                <h4 style="margin:0 0 14px 0; font-size:14px; font-weight:700; color:var(--text-main); display:flex; align-items:center; gap:6px;">
                    <span>⚙️</span> Πάροχος &amp; Στοιχεία Σύνδεσης
                </h4>

                <div class="form-group">
                    <label style="display:flex; align-items:center; gap:8px; font-weight:700; cursor:pointer; margin-bottom:12px; color:var(--text-main);">
                        <input type="checkbox" name="sms_enabled" value="1" <?= $smsEnabled === '1' ? 'checked' : '' ?> style="width:18px; height:18px;">
                        <span>Ενεργοποίηση Αποστολής SMS</span>
                    </label>
                </div>

                <div class="form-group">
                    <label style="display:block; font-size:12px; font-weight:600; color:var(--text-muted); margin-bottom:4px;">
                        Πάροχος Υπηρεσίας SMS:
                    </label>
                    <select name="sms_provider" class="form-control" style="font-weight:600; height:38px;">
                        <option value="easysms" <?= $smsProvider === 'easysms' ? 'selected' : '' ?>>EasySMS.gr (Ελληνικός - Τιμολόγιο Σχολικής Επιτροπής)</option>
                        <option value="smsto" <?= $smsProvider === 'smsto' ? 'selected' : '' ?>>SMS.to (Διεθνής / Σύγχρονο API)</option>
                        <option value="log" <?= $smsProvider === 'log' ? 'selected' : '' ?>>Δοκιμαστική Λειτουργία (Προσομοίωση - Χωρίς Χρέωση)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label style="display:block; font-size:12px; font-weight:600; color:var(--text-muted); margin-bottom:4px;">
                        API Key / Token Παρόχου:
                    </label>
                    <input type="password" name="sms_api_key" class="form-control" placeholder="<?= $hasSmsKey ? '•••••••••••••••••••••••• (Αποθηκευμένο)' : 'Εισάγετε το μυστικό API Key' ?>" autocomplete="new-password" style="height:38px;">
                    <div style="font-size:11px; color:var(--text-muted); margin-top:4px;">
                        <?= $hasSmsKey ? '🔒 Το κλειδί είναι κρυπτογραφημένο με ασφάλεια AES-256-GCM.' : 'Συμπληρώστε το κλειδί που σας δίνει ο πάροχος.' ?>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <label style="display:block; font-size:12px; font-weight:600; color:var(--text-muted); margin-bottom:4px;">
                        Όνομα Αποστολέα (Sender ID, έως 11 χαρακτήρες):
                    </label>
                    <input type="text" name="sms_sender_id" class="form-control" value="<?= htmlspecialchars($smsSenderId) ?>" placeholder="π.χ. 1o-GEL" maxlength="11" style="height:38px; font-weight:700; letter-spacing:0.5px;">
                    <div style="font-size:11px; color:var(--text-muted); margin-top:4px;">
                        Εμφανίζεται ως αποστολέας στην οθόνη του κινητού (λατινικοί χαρακτήρες &amp; αριθμοί).
                    </div>
                </div>
            </div>

            <!-- Column 2: Send Mode & Template -->
            <div class="settings-panel" style="background:#f8fafc; border:1px solid var(--border); border-radius:10px; padding:16px;">
                <h4 style="margin:0 0 14px 0; font-size:14px; font-weight:700; color:var(--text-main); display:flex; align-items:center; gap:6px;">
                    <span>📝</span> Στρατηγική Αποστολής &amp; Πρότυπο SMS
                </h4>

                <div class="form-group">
                    <label style="display:block; font-size:12px; font-weight:600; color:var(--text-muted); margin-bottom:4px;">
                        Στρατηγική Αποστολής (SMS Policy):
                    </label>
                    <select name="sms_send_mode" class="form-control" style="font-weight:600; height:38px;">
                        <option value="fallback_no_email" <?= $smsSendMode === 'fallback_no_email' ? 'selected' : '' ?>>
                            🎯 Smart Fallback: SMS ΜΟΝΟ σε όσους ΔΕΝ έχουν Email (Μέγιστη Οικονομία)
                        </option>
                        <option value="all" <?= $smsSendMode === 'all' ? 'selected' : '' ?>>
                            📱 Αποστολή SMS σε όλους τους επιλεγμένους μαθητές
                        </option>
                    </select>
                </div>

                <div class="form-group">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                        <label style="font-size:12px; font-weight:600; color:var(--text-muted); margin-bottom:0;">
                            Πρότυπο Μηνύματος SMS:
                        </label>
                        <span id="smsTemplateCounter" style="font-size:11px; font-weight:700; color:#0284c7;">
                            0 / 160 (1 SMS)
                        </span>
                    </div>
                    <textarea name="sms_template" id="smsTemplateInput" class="form-control" rows="3" oninput="updateSmsCharCounter()" style="font-size:13px; line-height:1.4; resize:vertical; font-family:monospace;"><?= htmlspecialchars($smsTemplate) ?></textarea>
                    <div style="font-size:11px; color:var(--text-muted); margin-top:4px;">
                        Διαθέσιμα: <code>{school_name}</code>, <code>{student_title}</code>, <code>{student_full_name}</code>, <code>{hours_count}</code>, <code>{date}</code>
                    </div>
                </div>

                <div style="background:#e0f2fe; border:1px solid #7dd3fc; border-radius:6px; padding:8px 10px; font-size:11px; color:#0369a1; line-height:1.4;">
                    ℹ️ Τα ελληνικά μετατρέπονται αυτόματα σε <strong>ΚΕΦΑΛΑΙΑ GSM-7</strong> κατά την αποστολή, εξασφαλίζοντας <strong>160 χαρακτήρες</strong> ανά SMS αντί για το όριο των 70 χαρακτήρων.
                </div>
            </div>
        </div>

        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
            <button type="submit" class="btn btn-primary" style="font-weight:700; padding:8px 20px;">
                💾 Αποθήκευση Ρυθμίσεων SMS
            </button>
        </div>
    </form>

    <!-- Balance & Test SMS Tools Sub-panel -->
    <div style="border-top:1px solid var(--border); padding-top:18px; margin-top:10px; display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:16px;">
        <!-- Balance Check -->
        <div style="background:#f8fafc; border:1px solid var(--border); border-radius:8px; padding:14px; display:flex; flex-direction:column; justify-content:space-between;">
            <div>
                <div style="font-weight:700; font-size:13px; color:var(--text-main); margin-bottom:4px;">
                    💳 Έλεγχος Διαθέσιμου Υπολοίπου
                </div>
                <div style="font-size:12px; color:var(--text-muted); margin-bottom:12px;">
                    Ελέγξτε απευθείας πόσα SMS απομένουν στον λογαριασμό σας.
                </div>
            </div>
            <form method="POST" action="settings.php#sms-gateway">
                <?= csrfField() ?>
                <input type="hidden" name="check_sms_balance" value="1">
                <button type="submit" class="btn btn-secondary btn-sm" style="font-weight:600;">
                    🔍 Ανάκτηση Υπολοίπου SMS
                </button>
            </form>
        </div>

        <!-- Test SMS Form -->
        <div style="background:#f8fafc; border:1px solid var(--border); border-radius:8px; padding:14px;">
            <div style="font-weight:700; font-size:13px; color:var(--text-main); margin-bottom:4px;">
                🧪 Δοκιμαστική Αποστολή SMS
            </div>
            <div style="font-size:12px; color:var(--text-muted); margin-bottom:10px;">
                Στείλτε ένα δοκιμαστικό μήνυμα σε οποιοδήποτε κινητό.
            </div>
            <form method="POST" action="settings.php#sms-gateway" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <?= csrfField() ?>
                <input type="hidden" name="test_sms_send" value="1">
                <input type="text" name="test_mobile" class="form-control" placeholder="π.χ. 6941234567" required style="flex:1; min-width:150px; height:34px; font-size:12px;">
                <button type="submit" class="btn btn-secondary btn-sm" style="font-weight:600; height:34px;">
                    🚀 Αποστολή Test
                </button>
            </form>
        </div>
    </div>
</div>

<!-- 4. SMTP Profiles Section (Bottom Section) -->
<div class="card">
    <div class="card-title">
        <span>📡 Διακομιστές Email (Προφίλ SMTP)</span>
        <button type="button" class="btn btn-primary btn-sm" onclick="openAddSmtpModal()">
            ➕ Προσθήκη Προφίλ SMTP
        </button>
    </div>
    <p style="font-size:13px; color:var(--text-muted); margin-bottom:16px;">
        Μπορείτε να ορίσετε πολλαπλούς διακομιστές αποστολής (π.χ. ΠΣΔ, Gmail, εφεδρικό hosting). Εάν αντιμετωπίσετε πρόβλημα με κάποιον mail server, μπορείτε <b>με 1 κλικ</b> να αλλάξετε τον ενεργό server!
    </p>

    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Κατάσταση</th>
                    <th>Τίτλος Προφίλ</th>
                    <th>Email Αποστολέα (From:)</th>
                    <th>Διακομιστής (Host / Port)</th>
                    <th>Κρυπτογράφηση</th>
                    <th>Username</th>
                    <th width="240">Ενέργειες</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($smtpAccounts as $acc): ?>
                    <tr style="<?= $acc['is_active'] ? 'background:#f0fdf4;' : '' ?>">
                        <td>
                            <?php if ($acc['is_active']): ?>
                                <span class="badge badge-success" style="font-size:12px; padding:5px 10px;">🟢 ΕΝΕΡΓΟ</span>
                            <?php else: ?>
                                <form method="POST" action="settings.php" style="display:inline;">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="account_id" value="<?= $acc['id'] ?>">
                                    <button type="submit" name="set_active_smtp" class="btn btn-secondary btn-sm" title="Ορισμός ως ενεργός διακομιστής">
                                        Ενεργοποίηση
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?= htmlspecialchars($acc['title']) ?></strong>
                            <?php if ($acc['from_name']): ?>
                                <div style="font-size:11px; color:var(--text-muted);"><?= htmlspecialchars($acc['from_name']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($acc['from_email']) ?></td>
                        <td><code><?= htmlspecialchars($acc['host']) ?>:<?= $acc['port'] ?></code></td>
                        <td><span class="badge badge-gray"><?= strtoupper($acc['encryption']) ?></span></td>
                        <td><span style="font-size:12px; color:var(--text-muted);"><?= htmlspecialchars($acc['username'] ?: '(Χωρίς Auth)') ?></span></td>
                        <td>
                            <div style="display:flex; gap:6px;">
                                <?php 
                                    $safeAcc = $acc;
                                    unset($safeAcc['password']); 
                                ?>
                                <button type="button" class="btn btn-secondary btn-sm" onclick="openTestSmtpModal(<?= htmlspecialchars(json_encode($safeAcc)) ?>)" title="Δοκιμαστική αποστολή">
                                    🧪 Δοκιμή
                                </button>
                                <button type="button" class="btn btn-secondary btn-sm" onclick="openEditSmtpModal(<?= htmlspecialchars(json_encode($safeAcc)) ?>)" title="Επεξεργασία">
                                    ✏️
                                </button>
                                <form method="POST" action="settings.php" style="display:inline;" onsubmit="return confirm('Διαγραφή αυτού του προφίλ SMTP;');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="account_id" value="<?= $acc['id'] ?>">
                                    <button type="submit" name="delete_smtp_account" class="btn btn-danger btn-sm" title="Διαγραφή">
                                        🗑️
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Add / Edit SMTP Account -->
<div id="smtpHolderModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center; padding:20px;">
    <div style="background:#fff; border-radius:12px; max-width:580px; width:100%; padding:24px; box-shadow:0 10px 30px rgba(0,0,0,0.2); max-height:90vh; overflow-y:auto;">
        <h3 id="smtpModalTitle" style="margin-bottom:16px;">Προφίλ SMTP</h3>

        <!-- Quick Presets -->
        <div class="settings-panel" style="background:#f8fafc; border:1px solid var(--border); border-radius:8px; padding:10px; margin-bottom:16px; font-size:13px;">
            <span style="font-weight:600;">Γρήγορες Έτοιμες Ρυθμίσεις:</span>
            <div style="display:flex; gap:8px; margin-top:6px;">
                <button type="button" class="btn btn-secondary btn-sm" onclick="applySmtpPreset('psd_website')">🏛️ ΠΣΔ Websitemail (Προτεινόμενο για Ιστοτόπους ΠΣΔ)</button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="applySmtpPreset('psd')">🏛️ ΠΣΔ mail.sch.gr (Εξωτερικό)</button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="applySmtpPreset('gmail')">📮 Google Workspace / Gmail</button>
            </div>
        </div>

        <form method="POST" action="settings.php" id="smtpAccountForm">
            <?= csrfField() ?>
            <input type="hidden" name="account_id" id="smtpAccountId" value="">

            <div class="form-group">
                <label>Τίτλος Προφίλ (Αναγνωριστικό) *</label>
                <input type="text" name="title" id="smtpTitle" class="form-control" placeholder="π.χ. ΠΣΔ mail.sch.gr ή Gmail Backup" required>
            </div>

            <div class="settings-grid" style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                <div class="form-group">
                    <label>Email Αποστολέα (From:) *</label>
                    <input type="email" name="from_email" id="smtpFromEmail" class="form-control" placeholder="mail@myschool.mysch.gr" required>
                </div>
                <div class="form-group">
                    <label>Όνομα Αποστολέα (From Name)</label>
                    <input type="text" name="from_name" id="smtpFromName" class="form-control" placeholder="1ο Γενικό Λύκειο">
                </div>
            </div>

            <div class="settings-grid" style="display:grid; grid-template-columns: 2fr 1fr 1fr; gap:10px;">
                <div class="form-group">
                    <label>Διακομιστής (Host) *</label>
                    <input type="text" name="host" id="smtpHost" class="form-control" placeholder="mail.sch.gr" required>
                </div>
                <div class="form-group">
                    <label>Θύρα (Port) *</label>
                    <input type="number" name="port" id="smtpPort" class="form-control" value="465" required>
                </div>
                <div class="form-group">
                    <label>Κρυπτογράφηση</label>
                    <select name="encryption" id="smtpEncryption" class="form-control">
                        <option value="ssl">SSL</option>
                        <option value="tls">TLS</option>
                        
                    </select>
                </div>
            </div>

            <div class="settings-grid" style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                <div class="form-group">
                    <label>Όνομα Χρήστη (Username)</label>
                    <input type="text" name="username" id="smtpUsername" class="form-control" placeholder="π.χ. mail@myschool.mysch.gr">
                </div>
                <div class="form-group">
                    <label>Κωδικός (Password)</label>
                    <input type="password" name="password" id="smtpPassword" class="form-control" placeholder="••••••••">
                    <div id="smtpPassHint" style="display:none; font-size:11px; color:var(--text-muted); margin-top:3px;">
                        Αφήστε κενό για να διατηρηθεί ο τρέχων κωδικός.
                    </div>
                </div>
            </div>

            <div class="form-group" id="groupMakeActive">
                <label style="display:flex; align-items:center; gap:8px; font-weight:normal; cursor:pointer;">
                    <input type="checkbox" name="make_active" id="smtpMakeActive" value="1">
                    <span>Ορισμός ως <b>Ενεργός</b> διακομιστής τώρα</span>
                </label>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                <button type="button" class="btn btn-secondary btn-sm" onclick="closeSmtpModal()">Ακύρωση</button>
                <button type="submit" id="btnSubmitSmtp" name="add_smtp_account" class="btn btn-primary btn-sm">Αποθήκευση Προφίλ</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Test Specific SMTP Profile -->
<div id="testSmtpModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center; padding:20px;">
    <div style="background:#fff; border-radius:12px; max-width:480px; width:100%; padding:24px; box-shadow:0 10px 30px rgba(0,0,0,0.2);">
        <h3 style="margin-bottom:12px; font-size:18px;">🧪 Δοκιμή Σύνδεσης SMTP</h3>
        <p style="font-size:13px; color:var(--text-muted); margin-bottom:16px;">
            Διακομιστής: <strong id="testModalTitle"></strong>
        </p>

        <form method="POST" action="settings.php">
            <?= csrfField() ?>
            <input type="hidden" name="test_account_id" id="testAccountId" value="">

            <div class="form-group">
                <label>Email Παραλήπτη Δοκιμής</label>
                <input type="email" name="test_email_recipient" class="form-control" placeholder="π.χ. my_personal@email.com" required autofocus>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                <button type="button" class="btn btn-secondary btn-sm" onclick="closeTestSmtpModal()">Ακύρωση</button>
                <button type="submit" name="send_test_email" class="btn btn-primary btn-sm">Αποστολή Δοκιμαστικού Email</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openAddSmtpModal() {
        document.getElementById('smtpModalTitle').textContent = '➕ Προσθήκη Προφίλ SMTP';
        document.getElementById('smtpAccountId').value = '';
        document.getElementById('smtpTitle').value = '';
        document.getElementById('smtpFromEmail').value = '<?= htmlspecialchars($schoolEmail) ?>';
        document.getElementById('smtpFromName').value = '<?= htmlspecialchars($schoolName) ?>';
        document.getElementById('smtpHost').value = 'mail.sch.gr';
        document.getElementById('smtpPort').value = '465';
        document.getElementById('smtpEncryption').value = 'ssl';
        document.getElementById('smtpUsername').value = '';
        document.getElementById('smtpPassword').value = '';
        document.getElementById('smtpPassword').required = false;
        document.getElementById('smtpPassHint').style.display = 'none';
        document.getElementById('groupMakeActive').style.display = 'block';
        document.getElementById('smtpMakeActive').checked = false;

        const btn = document.getElementById('btnSubmitSmtp');
        btn.name = 'add_smtp_account';
        btn.textContent = 'Προσθήκη Προφίλ';

        document.getElementById('smtpHolderModal').style.display = 'flex';
    }

    function openEditSmtpModal(acc) {
        document.getElementById('smtpModalTitle').textContent = '✏️ Επεξεργασία Προφίλ: ' + acc.title;
        document.getElementById('smtpAccountId').value = acc.id;
        document.getElementById('smtpTitle').value = acc.title;
        document.getElementById('smtpFromEmail').value = acc.from_email || '';
        document.getElementById('smtpFromName').value = acc.from_name || '';
        document.getElementById('smtpHost').value = acc.host;
        document.getElementById('smtpPort').value = acc.port;
        document.getElementById('smtpEncryption').value = acc.encryption;
        document.getElementById('smtpUsername').value = acc.username || '';
        document.getElementById('smtpPassword').value = '';
        document.getElementById('smtpPassword').required = false;
        document.getElementById('smtpPassHint').style.display = 'block';
        document.getElementById('groupMakeActive').style.display = 'none';

        const btn = document.getElementById('btnSubmitSmtp');
        btn.name = 'edit_smtp_account';
        btn.textContent = 'Αποθήκευση Αλλαγών';

        document.getElementById('smtpHolderModal').style.display = 'flex';
    }

    function closeSmtpModal() {
        document.getElementById('smtpHolderModal').style.display = 'none';
    }

    function openTestSmtpModal(acc) {
        document.getElementById('testAccountId').value = acc.id;
        document.getElementById('testModalTitle').textContent = acc.title + ' (' + acc.host + ':' + acc.port + ')';
        document.getElementById('testSmtpModal').style.display = 'flex';
    }

    function closeTestSmtpModal() {
        document.getElementById('testSmtpModal').style.display = 'none';
    }

    function applySmtpPreset(type) {
        if (type === 'psd_website') {
            document.getElementById('smtpTitle').value = 'ΠΣΔ Websitemail (Ιστότοποι)';
            document.getElementById('smtpHost').value = 'websitemail.sch.gr';
            document.getElementById('smtpPort').value = '587';
            document.getElementById('smtpEncryption').value = 'tls';
        } else if (type === 'psd') {
            document.getElementById('smtpTitle').value = 'Πανελλήνιο Σχολικό Δίκτυο (mail.sch.gr)';
            document.getElementById('smtpHost').value = 'mail.sch.gr';
            document.getElementById('smtpPort').value = '465';
            document.getElementById('smtpEncryption').value = 'ssl';
        } else if (type === 'gmail') {
            document.getElementById('smtpTitle').value = 'Google Workspace / Gmail';
            document.getElementById('smtpHost').value = 'smtp.gmail.com';
            document.getElementById('smtpPort').value = '465';
            document.getElementById('smtpEncryption').value = 'ssl';
        }
    }

    // Auto-format 24h bell schedule time inputs (e.g. "8:15" -> "08:15", "815" -> "08:15")
    document.querySelectorAll('.bell-time-input').forEach(input => {
        input.addEventListener('blur', function() {
            let v = this.value.trim();
            if (/^\d{1,2}:\d{2}$/.test(v)) {
                let parts = v.split(':');
                let h = parseInt(parts[0], 10);
                let m = parseInt(parts[1], 10);
                if (h >= 0 && h <= 23 && m >= 0 && m <= 59) {
                    this.value = (h < 10 ? '0' + h : h) + ':' + (m < 10 ? '0' + m : m);
                }
            } else if (/^\d{3,4}$/.test(v)) {
                let h = parseInt(v.slice(0, -2), 10);
                let m = parseInt(v.slice(-2), 10);
                if (h >= 0 && h <= 23 && m >= 0 && m <= 59) {
                    this.value = (h < 10 ? '0' + h : h) + ':' + (m < 10 ? '0' + m : m);
                }
            }
        });
    });

    // Real-time SMS Character Counter (GSM-7 160 chars per SMS)
    function updateSmsCharCounter() {
        const textarea = document.getElementById('smsTemplateInput');
        const counter = document.getElementById('smsTemplateCounter');
        if (!textarea || !counter) return;

        const len = textarea.value.length;
        const smsUnits = len === 0 ? 0 : Math.ceil(len / 160);
        counter.textContent = `${len} / 160 (${smsUnits} SMS)`;
        if (len > 160) {
            counter.style.color = '#dc2626';
            counter.style.fontWeight = '700';
        } else {
            counter.style.color = '#0284c7';
            counter.style.fontWeight = '700';
        }
    }
    document.addEventListener('DOMContentLoaded', updateSmsCharCounter);
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
