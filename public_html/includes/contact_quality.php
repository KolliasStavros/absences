<?php
require_once __DIR__.'/sms.php';

/** Read-only indications; never rewrite or reject a stored contact. */
function guardianContactQuality(array $student): array {
    $emails=[trim((string)($student['guardian_email'] ?? '')),trim((string)($student['guardian_email_2'] ?? ''))];
    $mobiles=[trim((string)($student['guardian_mobile'] ?? '')),trim((string)($student['guardian_mobile_2'] ?? ''))];
    $noEmail=($emails[0]==='' && $emails[1]==='');
    $noMobile=($mobiles[0]==='' && $mobiles[1]==='');
    $messages=[]; $invalid=false;
    if ($noEmail && $noMobile) $messages[]='Χωρίς στοιχεία επικοινωνίας κηδεμόνων.';
    else {
        if ($noEmail) $messages[]='Χωρίς email κηδεμόνα.';
        if ($noMobile) $messages[]='Χωρίς κινητό κηδεμόνα.';
    }
    foreach($emails as $i=>$email) if ($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL)) {
        $invalid=true; $messages[]='Ελέγξτε τη μορφή email '.($i+1).'ου κηδεμόνα.';
    }
    foreach($mobiles as $i=>$mobile) if ($mobile!=='' && (!preg_match('/^[+0-9\s().-]+$/u',$mobile) || !normalizeGreekMobileNumber($mobile)['valid'])) {
        $invalid=true; $messages[]='Ελέγξτε το ελληνικό κινητό '.($i+1).'ου κηδεμόνα.';
    }
    return ['missing_email'=>$noEmail,'missing_mobile'=>$noMobile,'missing_all'=>$noEmail && $noMobile,'invalid'=>$invalid,'messages'=>$messages];
}
