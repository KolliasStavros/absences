<?php
// includes/sms.php - High-Performance Multi-Provider SMS Gateway for Greek Schools
require_once __DIR__ . '/crypto.php';

/**
 * Convert Greek text to GSM-7 compatible uppercase without accents.
 * Enables 160 characters per SMS instead of 70 Unicode characters.
 */
function sanitizeGreekSmsText($text) {
    if (empty($text)) return '';

    $map = [
        'ά' => 'Α', 'έ' => 'Ε', 'ή' => 'Η', 'ί' => 'Ι', 'ό' => 'Ο', 'ύ' => 'Υ', 'ώ' => 'Ω',
        'ϊ' => 'Ι', 'ϋ' => 'Υ', 'ΐ' => 'Ι', 'ΰ' => 'Υ',
        'Ά' => 'Α', 'Έ' => 'Ε', 'Ή' => 'Η', 'Ί' => 'Ι', 'Ό' => 'Ο', 'Ύ' => 'Υ', 'Ώ' => 'Ω',
        'Ϊ' => 'Ι', 'Ϋ' => 'Υ',
        'α' => 'Α', 'β' => 'Β', 'γ' => 'Γ', 'δ' => 'Δ', 'ε' => 'Ε', 'ζ' => 'Ζ', 'η' => 'Η', 'θ' => 'Θ',
        'ι' => 'Ι', 'κ' => 'Κ', 'λ' => 'Λ', 'μ' => 'Μ', 'ν' => 'Ν', 'ξ' => 'Ξ', 'ο' => 'Ο', 'π' => 'Π',
        'ρ' => 'Ρ', 'σ' => 'Σ', 'ς' => 'Σ', 'τ' => 'Τ', 'υ' => 'Υ', 'φ' => 'Φ', 'χ' => 'Χ', 'ψ' => 'Ψ', 'ω' => 'Ω'
    ];

    $converted = strtr($text, $map);
    // Replace consecutive whitespaces or newlines with a single space
    $converted = preg_replace('/[ \t\r\n]+/', ' ', $converted);
    return trim($converted);
}

/**
 * Validate and normalize Greek mobile numbers into national and international formats.
 */
function normalizeGreekMobileNumber($mobile) {
    $raw = preg_replace('/[^0-9]/', '', (string)$mobile);

    // Strip leading international prefix
    if (str_starts_with($raw, '0030')) {
        $raw = substr($raw, 4);
    } elseif (str_starts_with($raw, '30') && strlen($raw) === 12) {
        $raw = substr($raw, 2);
    }

    // Must be a valid 10-digit Greek mobile starting with 69
    if (strlen($raw) !== 10 || !str_starts_with($raw, '69')) {
        return [
            'valid' => false,
            'error' => 'Μη έγκυρος αριθμός ελληνικού κινητού (πρέπει να έχει 10 ψηφία και να ξεκινά με 69).'
        ];
    }

    return [
        'valid' => true,
        'local' => $raw,                       // e.g. 6912345678
        'international' => '30' . $raw,         // e.g. 306912345678
        'e164' => '+30' . $raw                 // e.g. +306912345678
    ];
}

/**
 * Send SMS message via the configured gateway provider
 */
function sendSmsMessage($mobile, $messageText, $options = []) {
    $force = !empty($options['force']); // Bypass enabled check for test SMS
    $isEnabled = (getSchoolSetting('sms_enabled', '0') === '1');

    if (!$isEnabled && !$force) {
        return [
            'success' => false,
            'error' => 'Η αποστολή SMS είναι απενεργοποιημένη στις ρυθμίσεις του σχολείου.'
        ];
    }

    $norm = normalizeGreekMobileNumber($mobile);
    if (!$norm['valid']) {
        return [
            'success' => false,
            'error' => $norm['error']
        ];
    }

    $cleanText = sanitizeGreekSmsText($messageText);
    if (empty($cleanText)) {
        return [
            'success' => false,
            'error' => 'Το κείμενο του SMS δεν μπορεί να είναι κενό.'
        ];
    }

    $provider = getSchoolSetting('sms_provider', 'log');
    $rawKey = getSchoolSetting('sms_api_key', '');
    $apiKey = (CryptoHelper::isEncrypted($rawKey)) ? CryptoHelper::decrypt($rawKey) : $rawKey;
    $senderId = trim(getSchoolSetting('sms_sender_id', ''));
    if (empty($senderId)) {
        $senderId = 'SXOLEIO';
    } else {
        // Alphanumeric Sender ID, max 11 characters
        $senderId = substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', $senderId), 0, 11);
    }

    // 1. Simulation / Log Mode (Free for testing)
    if ($provider === 'log' || empty($apiKey)) {
        return [
            'success' => true,
            'provider' => 'log',
            'simulated' => true,
            'message_id' => 'SIM-' . strtoupper(bin2hex(random_bytes(4))),
            'to' => $norm['e164'],
            'text' => $cleanText,
            'char_count' => mb_strlen($cleanText, 'UTF-8')
        ];
    }

    // 2. EasySMS.gr Provider
    if (defined('SMS_DELIVERY_ENABLED') && !SMS_DELIVERY_ENABLED) {
        return ['success'=>false,'error'=>'Η πραγματική αποστολή SMS είναι απενεργοποιημένη στη δοκιμαστική εγκατάσταση.'];
    }
    if ($provider === 'easysms') {
        return executeEasySmsSend($apiKey, $senderId, $norm['international'], $cleanText);
    }

    // 3. SMS.to Provider
    if ($provider === 'smsto') {
        return executeSmsToSend($apiKey, $senderId, $norm['e164'], $cleanText);
    }

    return [
        'success' => false,
        'error' => 'Μη υποστηριζόμενος πάροχος SMS: ' . htmlspecialchars($provider)
    ];
}

/**
 * Execute HTTP POST to EasySMS.gr
 */
function executeEasySmsSend($apiKey, $senderId, $to, $text) {
    $url = 'https://www.easysms.gr/api/sms/send';
    $postData = [
        'key' => $apiKey,
        'to' => $to,
        'from' => $senderId,
        'text' => $text,
        'type' => 'json'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErr) {
        return ['success' => false, 'error' => 'EasySMS σφάλμα δικτύου: ' . $curlErr];
    }

    $json = json_decode($response, true);
    if (!is_array($json)) {
        return ['success' => false, 'error' => 'EasySMS μη αναμενόμενη απόκριση διακομιστή: ' . substr($response, 0, 100)];
    }

    if (isset($json['status']) && (int)$json['status'] === 1) {
        return [
            'success' => true,
            'provider' => 'easysms',
            'message_id' => $json['id'] ?? null,
            'balance' => $json['balance'] ?? null
        ];
    }

    $err = $json['error'] ?? $json['message'] ?? 'Άγνωστο σφάλμα EasySMS';
    return ['success' => false, 'error' => 'EasySMS: ' . $err];
}

/**
 * Execute HTTP POST to SMS.to
 */
function executeSmsToSend($apiKey, $senderId, $to, $text) {
    $url = 'https://api.sms.to/sms/send';
    $payload = json_encode([
        'to' => $to,
        'message' => $text,
        'sender_id' => $senderId
    ]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
        'Accept: application/json'
    ]);

    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErr) {
        return ['success' => false, 'error' => 'SMS.to σφάλμα δικτύου: ' . $curlErr];
    }

    $json = json_decode($response, true);
    if (!is_array($json)) {
        return ['success' => false, 'error' => 'SMS.to μη αναμενόμενη απόκριση διακομιστή.'];
    }

    if ($httpCode >= 200 && $httpCode < 300 && ($json['success'] ?? null) === true) {
        return [
            'success' => true,
            'provider' => 'smsto',
            'message_id' => $json['message_id'] ?? null
        ];
    }

    $err = $json['message'] ?? $json['error'] ?? 'Σφάλμα αποστολής SMS.to';
    return ['success' => false, 'error' => 'SMS.to: ' . $err];
}

/**
 * Retrieve current SMS balance from the active provider
 */
function getSmsBalance() {
    $provider = getSchoolSetting('sms_provider', 'log');
    $rawKey = getSchoolSetting('sms_api_key', '');
    $apiKey = (CryptoHelper::isEncrypted($rawKey)) ? CryptoHelper::decrypt($rawKey) : $rawKey;

    if ($provider === 'log' || empty($apiKey)) {
        return [
            'success' => true,
            'balance' => 'Δοκιμαστική Λειτουργία (Χωρίς Χρέωση)',
            'provider' => 'log'
        ];
    }

    if ($provider === 'easysms') {
        $url = 'https://www.easysms.gr/api/sms/balance?key=' . urlencode($apiKey) . '&type=json';
        $res = @file_get_contents($url);
        if ($res !== false) {
            $json = json_decode($res, true);
            if (isset($json['balance'])) {
                return ['success' => true, 'balance' => $json['balance'] . ' SMS', 'provider' => 'easysms'];
            }
        }
        return ['success' => false, 'error' => 'Αδυναμία ανάκτησης υπολοίπου από EasySMS.'];
    }

    if ($provider === 'smsto') {
        $ch = curl_init('https://api.sms.to/balance');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $apiKey,
            'Accept: application/json'
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        if ($res !== false) {
            $json = json_decode($res, true);
            if (isset($json['balance'])) {
                return ['success' => true, 'balance' => $json['balance'] . ' ' . ($json['currency'] ?? 'EUR'), 'provider' => 'smsto'];
            }
        }
        return ['success' => false, 'error' => 'Αδυναμία ανάκτησης υπολοίπου από SMS.to.'];
    }

    return ['success' => false, 'error' => 'Μη υποστηριζόμενος πάροχος.'];
}

/**
 * Build concise GSM-7 SMS message for student absences
 */
function buildStudentSmsMessage($student, $absencesCount, $dateFormatted, $schoolName, $customTemplate = '') {
    $template = !empty($customTemplate) 
        ? $customTemplate 
        : getSchoolSetting('sms_template', 'ΕΝΗΜΕΡΩΣΗ ΑΠΟ {school_name}: {student_title} {student_full_name} ΣΗΜΕΙΩΣΕ {hours_count} ΑΠΟΥΣΙΕΣ ΣΤΙΣ {date}.');

    $gender = $student['gender'] ?? 'male';
    $title = ($gender === 'female') ? 'Η ΜΑΘΗΤΡΙΑ' : 'Ο ΜΑΘΗΤΗΣ';

    $fullName = ($student['last_name'] ?? '') . ' ' . ($student['first_name'] ?? '');
    
    // Check if GDPR name masking is enabled
    $maskEnabled = (getSchoolSetting('mask_student_name', '0') === '1');
    if ($maskEnabled && function_exists('mask_student_name')) {
        $fullName = mask_student_name($fullName);
    }

    $replacements = [
        '{school_name}' => $schoolName,
        '{student_title}' => $title,
        '{student_full_name}' => $fullName,
        '{hours_count}' => $absencesCount,
        '{date}' => $dateFormatted
    ];

    $text = str_replace(array_keys($replacements), array_values($replacements), $template);
    return sanitizeGreekSmsText($text);
}
