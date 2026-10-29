<?php
// includes/mailer.php - Multi-Channel Notification Engine with Multi-SMTP Profiles Support

function getSchoolSetting($key, $default = '') {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? $row['setting_value'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

function updateSchoolSetting($key, $value) {
    $db = getDB();
    // Step 7A: Ensure any legacy smtp_pass setting is securely encrypted before saving
    if ($key === 'smtp_pass' && !empty($value) && !CryptoHelper::isEncrypted($value)) {
        $value = CryptoHelper::encrypt($value);
    }
    $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    return $stmt->execute([$key, $value, $value]);
}

/**
 * Default bell schedule for Greek secondary schools (24h format)
 */
function getDefaultBellSchedule(): array {
    return [
        1 => ['start' => '08:15', 'end' => '09:00'],
        2 => ['start' => '09:05', 'end' => '09:50'],
        3 => ['start' => '10:00', 'end' => '10:45'],
        4 => ['start' => '10:55', 'end' => '11:40'],
        5 => ['start' => '11:50', 'end' => '12:35'],
        6 => ['start' => '12:40', 'end' => '13:25'],
        7 => ['start' => '13:30', 'end' => '14:10'],
    ];
}

/**
 * Retrieve the configured bell schedule, or fallback to default
 */
function getBellSchedule(): array {
    $raw = getSchoolSetting('bell_schedule', '');
    if (!empty($raw)) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && !empty($decoded)) {
            $formatted = [];
            foreach ($decoded as $h => $times) {
                $intH = (int)$h;
                if ($intH >= 1 && $intH <= 8 && isset($times['start'], $times['end'])) {
                    $formatted[$intH] = [
                        'start' => substr(trim($times['start']), 0, 5),
                        'end'   => substr(trim($times['end']), 0, 5)
                    ];
                }
            }
            if (!empty($formatted)) {
                ksort($formatted);
                return $formatted;
            }
        }
    }
    return getDefaultBellSchedule();
}

/**
 * Convert HH:MM string to minutes from midnight
 */
function bellTimeToMinutes(string $timeStr): int {
    $parts = explode(':', trim($timeStr));
    $h = (int)($parts[0] ?? 0);
    $m = (int)($parts[1] ?? 0);
    return ($h * 60) + $m;
}

/**
 * Determine the current suggested teaching hour based on local time
 */
function getCurrentSuggestedTeachingHour(?string $currentTime = null): int {
    if ($currentTime === null) {
        $currentTime = date('H:i');
    }
    $schedule = getBellSchedule();
    if (empty($schedule)) return 1;

    $nowMinutes = bellTimeToMinutes($currentTime);

    // 1. Direct match within a teaching hour
    foreach ($schedule as $h => $times) {
        $startMin = bellTimeToMinutes($times['start'] ?? '00:00');
        $endMin = bellTimeToMinutes($times['end'] ?? '00:00');
        if ($nowMinutes >= $startMin && $nowMinutes <= $endMin) {
            return (int)$h;
        }
    }

    // 2. Break or out of hours matching
    $hours = array_keys($schedule);
    sort($hours);

    $firstHour = reset($hours);
    $firstStart = bellTimeToMinutes($schedule[$firstHour]['start']);
    if ($nowMinutes < $firstStart) {
        return (int)$firstHour; // Morning before school starts -> 1st hour
    }

    $lastHour = end($hours);
    $lastEnd = bellTimeToMinutes($schedule[$lastHour]['end']);
    if ($nowMinutes > $lastEnd) {
        return 1; // After school day -> fallback to 1st hour
    }

    for ($i = 0; $i < count($hours) - 1; $i++) {
        $hCurr = $hours[$i];
        $hNext = $hours[$i + 1];
        $currEnd = bellTimeToMinutes($schedule[$hCurr]['end']);
        $nextStart = bellTimeToMinutes($schedule[$hNext]['start']);

        if ($nowMinutes > $currEnd && $nowMinutes < $nextStart) {
            // During break: if in first half, suggest the hour just concluded; otherwise upcoming hour
            $midPoint = $currEnd + (($nextStart - $currEnd) / 2);
            return ($nowMinutes <= $midPoint) ? (int)$hCurr : (int)$hNext;
        }
    }

    return 1;
}

/**
 * Get active SMTP account, or specific account by ID
 */
function getActiveSmtpAccount($specificId = null) {
    try {
        $db = getDB();
        if ($specificId) {
            $stmt = $db->prepare("SELECT * FROM smtp_accounts WHERE id = ?");
            $stmt->execute([(int)$specificId]);
            return $stmt->fetch();
        }
        $stmt = $db->query("SELECT * FROM smtp_accounts WHERE is_active = 1 LIMIT 1");
        $acc = $stmt->fetch();
        if ($acc) return $acc;

        // If none is active, pick first available
        $stmt2 = $db->query("SELECT * FROM smtp_accounts ORDER BY id ASC LIMIT 1");
        return $stmt2->fetch();
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Send email using PHP socket SMTP (compatible with mail.sch.gr, Gmail, etc.)
 */
function sendEmailSMTP($to, $subject, $bodyText, $bodyHtml = null, $smtpAccountId = null) {
    $account = getActiveSmtpAccount($smtpAccountId);

    if ($account) {
        $host = $account['host'];
        $port = (int)$account['port'];
        $secure = strtolower($account['encryption']);
        $user = $account['username'];
        try {
            $pass = CryptoHelper::decrypt($account['password'] ?? '');
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Αποτυχία ασφαλούς ανάκτησης κωδικού SMTP. Ελέγξτε τις ρυθμίσεις του διακομιστή.'];
        }
        $fromEmail = $account['from_email'] ?: getSchoolSetting('school_email', 'noreply@sch.gr');
        $fromName = $account['from_name'] ?: getSchoolSetting('school_name', 'Παρουσιολόγιο Σχολείου');
    } else {
        $host = getSchoolSetting('smtp_host', 'mail.sch.gr');
        $port = (int)getSchoolSetting('smtp_port', 465);
        $secure = strtolower(getSchoolSetting('smtp_secure', 'ssl'));
        $user = getSchoolSetting('smtp_user', '');
        try {
            $pass = CryptoHelper::decrypt(getSchoolSetting('smtp_pass', ''));
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Αποτυχία ασφαλούς ανάκτησης κωδικού SMTP. Ελέγξτε τις ρυθμίσεις του διακομιστή.'];
        }
        $fromEmail = getSchoolSetting('school_email', 'noreply@sch.gr');
        $fromName = getSchoolSetting('school_name', 'Παρουσιολόγιο Σχολείου');
    }

    // SEC-09: Validate recipient email and prevent CRLF injection in SMTP headers
    $to = trim($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Μη έγκυρη διεύθυνση παραλήπτη email: ' . htmlspecialchars($to)];
    }
    $subject = preg_replace('/[\r\n]+/', ' ', trim($subject));
    $fromEmail = preg_replace('/[\r\n]+/', '', trim($fromEmail));
    $fromName = preg_replace('/[\r\n]+/', '', trim($fromName));
    if (defined('MAIL_DELIVERY_ENABLED') && !MAIL_DELIVERY_ENABLED) return ['success'=>false,'error'=>'Η αποστολή email είναι απενεργοποιημένη στη δοκιμαστική εγκατάσταση.'];

    if (empty($host) || !in_array($secure,['ssl','tls'],true) || !preg_match('/^[a-zA-Z0-9.-]+$/',$host) || $port<1 || $port>65535) {
        return ['success'=>false,'error'=>'Απαιτείται έγκυρος διακομιστής SMTP με SSL/TLS.'];
    }
    try {
        $timeout = 10;
        $context=stream_context_create(['ssl'=>[
            'verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$host,
            'allow_self_signed'=>false,'SNI_enabled'=>true
        ]]);
        $prefix=($secure==='ssl')?'tls://':'tcp://';
        $socket=@stream_socket_client($prefix.$host.':'.$port,$errno,$errstr,$timeout,STREAM_CLIENT_CONNECT,$context);
        if(!$socket) throw new RuntimeException('SMTP connection failed: ' . ($errstr ?: 'Unknown error') . ' (' . $errno . ')');
        stream_set_timeout($socket,$timeout);

        $getResponse = function($sock) {
            $data = '';
            while ($line = fgets($sock, 515)) {
                $data .= $line;
                if(strlen($data)>65536) throw new RuntimeException('SMTP response limit');
                if (substr($line, 3, 1) === ' ') break;
            }
            $meta=stream_get_meta_data($sock);
            if($meta['timed_out'] || $data==='' || strlen($data)>65536) throw new RuntimeException('SMTP response timeout or invalid response');
            return $data;
        };

        $sendCommand = function($sock, $cmd, $expectedCode, $isSensitive = false) use ($getResponse) {
            $out=$cmd."\r\n";
            if(fwrite($sock,$out)!==strlen($out)) throw new RuntimeException('SMTP write failed');
            $response = $getResponse($sock);
            $code = substr($response, 0, 3);
            if ($code != $expectedCode) {
                $displayCmd = $isSensitive ? '***[CREDENTIALS HIDDEN]***' : $cmd;
                throw new Exception("SMTP Error: {$displayCmd} => {$response}");
            }
            return $response;
        };

        if(substr($getResponse($socket),0,3)!=='220') throw new RuntimeException('SMTP greeting rejected');

        $rawHost = $_SERVER['SERVER_NAME'] ?? 'localhost';
        $localhost = preg_replace('/[^a-zA-Z0-9\.\-]/', '', $rawHost) ?: 'localhost';
        $sendCommand($socket, "EHLO " . $localhost, 250);

        if ($secure === 'tls') {
            $sendCommand($socket, "STARTTLS", 220);
            if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) throw new RuntimeException('STARTTLS negotiation failed');
            $sendCommand($socket, "EHLO " . $localhost, 250);
        }

        if (!empty($user) && !empty($pass)) {
            $sendCommand($socket, "AUTH LOGIN", 334);
            $sendCommand($socket, base64_encode($user), 334, true);
            $sendCommand($socket, base64_encode($pass), 235, true);
        }

        $fromEmailValid = filter_var($fromEmail, FILTER_VALIDATE_EMAIL) ? $fromEmail : 'noreply@sch.gr';
        $sendCommand($socket, "MAIL FROM: <{$fromEmailValid}>", 250);
        $sendCommand($socket, "RCPT TO: <{$to}>", 250);
        $sendCommand($socket, "DATA", 354);

        $boundary = "----=_Part_" . md5(uniqid());
        $headers = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromEmail}>\r\n";
        $headers .= "To: <{$to}>\r\n";
        $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Date: " . date('r') . "\r\n";

        if ($bodyHtml) {
            $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n";
            $messageData = "--{$boundary}\r\n";
            $messageData .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $messageData .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $messageData .= chunk_split(base64_encode($bodyText)) . "\r\n";
            $messageData .= "--{$boundary}\r\n";
            $messageData .= "Content-Type: text/html; charset=UTF-8\r\n";
            $messageData .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $messageData .= chunk_split(base64_encode($bodyHtml)) . "\r\n";
            $messageData .= "--{$boundary}--\r\n";
        } else {
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $headers .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $messageData = chunk_split(base64_encode($bodyText)) . "\r\n";
        }

        $payload = $headers . $messageData . ".\r\n";
        if (fwrite($socket,$payload)!==strlen($payload)) throw new RuntimeException('SMTP DATA write failed');
        if(substr($getResponse($socket),0,3)!=='250') throw new RuntimeException('SMTP DATA rejected');

        $sendCommand($socket, "QUIT", 221);
        fclose($socket);

        return ['success' => true, 'error' => null];
    } catch (Exception $e) {
        if (isset($socket) && is_resource($socket)) {
            fclose($socket);
        }
        $msg = $e->getMessage();
        $sender = $fromEmailValid ?? ($fromEmail ?? 'αποστολέα');
        if (strpos($msg, '535') !== false || stripos($msg, 'authentication failed') !== false) {
            $friendlyError = 'Σφάλμα ταυτοποίησης (535): Το όνομα χρήστη ή ο κωδικός πρόσβασης SMTP δεν είναι σωστά.';
        } elseif (strpos($msg, '553') !== false || strpos($msg, '550') !== false || stripos($msg, 'sender address rejected') !== false) {
            $friendlyError = "Ο διακομιστής απέρριψε τη διεύθυνση αποστολέα ({$sender}). Βεβαιωθείτε ότι συμπίπτει με το επίσημο email του σχολείου στο ΠΣΔ.";
        } elseif (strpos($msg, '554') !== false || stripos($msg, 'relay access denied') !== false) {
            $friendlyError = 'Σφάλμα αναμετάδοσης (554 Relay Access Denied): Ο διακομιστής δεν επιτρέπει αποστολή προς αυτή τη διεύθυνση.';
        } elseif (stripos($msg, 'connection failed') !== false) {
            $friendlyError = "Αδυναμία σύνδεσης στον διακομιστή SMTP ({$host}:{$port}). Ελέγξτε τις ρυθμίσεις διακομιστή/θύρας.";
        } elseif (stripos($msg, 'STARTTLS') !== false) {
            $friendlyError = 'Αποτυχία διαπραγμάτευσης ασφαλούς σύνδεσης STARTTLS.';
        } elseif (stripos($msg, 'SMTP greeting rejected') !== false) {
            $friendlyError = 'Ο διακομιστής SMTP απέρριψε τη σύνδεση κατά την έναρξη (Greeting rejected).';
        } else {
            $friendlyError = safeError($e);
        }
        return ['success' => false, 'error' => $friendlyError];
    }
}

/**
 * Dispatch notification and record to notification_logs
 */
function sendNotification($channel, $recipient, $subject, $message, $metadata = []) {
    if ($channel !== 'sms' || empty($metadata['student_id'])) return dispatchNotification($channel,$recipient,$subject,$message,$metadata);
    require_once __DIR__.'/sms.php';
    $normalized=normalizeGreekMobileNumber($recipient);
    if (!$normalized['valid']) return ['success'=>false,'error'=>$normalized['error']];
    $recipient=$normalized['e164'];
    $db=getDB();
    $lockName='abs-sms-'.substr(hash('sha256',DB_NAME.'|'.(defined('DB_PREFIX')?DB_PREFIX:'').'|'.$metadata['student_id'].'|'.$recipient.'|'.$message),0,48);
    $stmt=$db->prepare('SELECT GET_LOCK(?,5)');$stmt->execute([$lockName]);
    if((int)$stmt->fetchColumn()!==1) return ['success'=>false,'error'=>'Η ειδοποίηση επεξεργάζεται ήδη. Δοκιμάστε αργότερα.'];
    try {
        $stmt=$db->prepare("SELECT id FROM notification_logs WHERE channel='sms' AND student_id=? AND recipient=? AND message=? AND status='sent' AND created_at>DATE_SUB(NOW(),INTERVAL 24 HOUR) LIMIT 1");
        $stmt->execute([$metadata['student_id'],$recipient,$message]);
        if($stmt->fetchColumn()) return ['success'=>true,'error'=>null,'duplicate'=>true,'simulated'=>false];
        return dispatchNotification($channel,$recipient,$subject,$message,$metadata);
    } finally {
        $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    }
}

function dispatchNotification($channel, $recipient, $subject, $message, $metadata = []) {
    $studentId = $metadata['student_id'] ?? null;
    $sentBy = $metadata['sent_by'] ?? ($_SESSION['user_id'] ?? 1);
    $hoursSummary = $metadata['hours_summary'] ?? null;

    $success = false;
    $errorMessage = null;

    if ($channel === 'email') {
        $html = '<div style="font-family:Arial,sans-serif;line-height:1.6;color:#333;max-width:600px;margin:0 auto;border:1px solid #e0e0e0;border-radius:8px;padding:20px;">'
              . nl2br(htmlspecialchars($message))
              . '</div>';
        $result = sendEmailSMTP($recipient, $subject, $message, $html);
        $success = $result['success'];
        $errorMessage = $result['error'];
    } elseif ($channel === 'sms') {
        require_once __DIR__ . '/sms.php';
        $result = sendSmsMessage($recipient, $message, $metadata);
        $success = $result['success'];
        $errorMessage = $result['error'] ?? null;
    } elseif ($channel === 'viber') {
        $errorMessage = 'Το Viber κανάλι είναι έτοιμο αλλά δεν έχουν οριστεί API κλειδιά.';
        $success = false;
    }

    // Record in notification_logs
    if ($studentId && empty($result['simulated'])) {
        try {
            $db = getDB();
            $stmt = $db->prepare("
                INSERT INTO notification_logs 
                (channel, student_id, recipient, subject, message, hours_summary, status, error_message, sent_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $channel,
                $studentId,
                $recipient,
                $subject,
                $message,
                $hoursSummary,
                $success ? 'sent' : 'failed',
                $errorMessage,
                $sentBy
            ]);
        } catch (Exception $e) {
            // Log silently
        }
    }

    return ['success' => $success, 'error' => $errorMessage, 'simulated' => !empty($result['simulated']), 'duplicate' => false];
}

/**
 * Send Password Reset or Activation Email
 */
function sendPasswordResetEmail($user, $token, $type = 'reset') {
    $schoolName = getSchoolSetting('school_name', 'Παρουσιολόγιο Σχολείου');
    $link = BASE_URL . "/reset-password.php?token=" . urlencode($token);

    if ($type === 'invitation') {
        $subject = "Πρόσκληση & Ορισμός Κωδικού - {$schoolName}";
        $message = "Αγαπητέ/ή συνάδελφε {$user['name']},\n\n"
                 . "Έχει δημιουργηθεί λογαριασμός καθηγητή για εσάς στο ηλεκτρονικό παρουσιολόγιο του {$schoolName}.\n\n"
                 . "Για να ορίσετε τον προσωπικό σας κωδικό πρόσβασης και να ενεργοποιήσετε τον λογαριασμό σας, πατήστε στον παρακάτω σύνδεσμο:\n"
                 . "{$link}\n\n"
                 . "Ο σύνδεσμος θα παραμείνει ενεργός για 48 ώρες.\n\n"
                 . "Με εκτίμηση,\n{$schoolName}";
    } else {
        $subject = "Επαναφορά Κωδικού Πρόσβασης - {$schoolName}";
        $message = "Αγαπητέ/ή {$user['name']},\n\n"
                 . "Λάβαμε αίτημα για επαναφορά του κωδικού πρόσβασής σας στο ηλεκτρονικό παρουσιολόγιο του {$schoolName}.\n\n"
                 . "Πατήστε στον παρακάτω σύνδεσμο για να ορίσετε νέο κωδικό:\n"
                 . "{$link}\n\n"
                 . "Ο σύνδεσμος ισχύει για 1 ώρα. Εάν δεν κάνατε εσείς αυτό το αίτημα, μπορείτε να αγνοήσετε το παρόν μήνυμα.\n\n"
                 . "Με εκτίμηση,\n{$schoolName}";
    }

    return sendEmailSMTP($user['email'], $subject, $message);
}
