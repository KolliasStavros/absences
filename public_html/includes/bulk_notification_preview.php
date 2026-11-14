<?php
require_once __DIR__ . "/absence_types.php";
require_once __DIR__ . "/email_templates.php";
// Render only: the same template substitutions as consolidated email sending.
function buildBulkEmailPreview(array $student, array $absences, string $selectedDate): array {
    $schoolName = getSchoolSetting('school_name', 'Παρουσιολόγιο Σχολείου');
    $schoolPhone = getSchoolSetting('school_phone', '');
    $maskStudentName = getSchoolSetting('mask_student_name', '0') === '1';
    $subjectTemplate = getSchoolSetting('email_template_subject', 'Ενημέρωση Απουσιών Μαθητή - {school_name}');
    $bodyTemplate = str_replace('{hours}.', '{hours}', getSchoolSetting('email_template_body', defaultAbsenceEmailTemplate()));
            $hoursText = absenceEmailHours($absences);

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

            $values=[];
            foreach($replacements as $key=>$value) $values[trim($key,'{}')]=$value;
            $subject = preg_replace('/[\r\n]+/u',' ',renderSchoolEmailText($subjectTemplate,$values));
            $message = renderSchoolEmailText($bodyTemplate,$values);

    return ['subject'=>$subject, 'body'=>$message];
}
