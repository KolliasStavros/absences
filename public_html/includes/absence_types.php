<?php
// Shared labels for daily attendance and guardian email notifications.
function absenceTypeLabel(string $type): string {
    return ['absent'=>'Κοινή Απουσία','leave'=>'Με άδεια','expulsion'=>'Ωριαία Αποβολή','justified'=>'Δικαιολογημένη'][$type] ?? 'Κοινή Απουσία';
}
function absenceEmailHours(array $rows): string {
    $labels = ['leave'=>'Με άδεια','justified'=>'Δικ.','expulsion'=>'Ω.Α.'];
    $hours = [];
    foreach ($rows as $row) {
        $suffix = $labels[$row['absence_type'] ?? 'absent'] ?? '';
        $hours[] = $row['hour'].'η'.($suffix === '' ? '' : ' ('.$suffix.')');
    }
    return implode(', ', $hours).($rows ? "\n\nΕπεξήγηση ενδείξεων:\n".absenceEmailLegend($rows) : '');
}
function absenceEmailLegend(?array $rows = null): string {
    $definitions = [
        'absent'=>'Χωρίς ένδειξη: Απουσία χωρίς ειδική ένδειξη.',
        'leave'=>'Με άδεια: Αποχώρηση από το σχολείο, έπειτα από επικοινωνία με τον κηδεμόνα.',
        'justified'=>'Δικ.: Δικαιολογημένη απουσία.',
        'expulsion'=>'Ω.Α.: Ωριαία αποβολή.',
    ];
    if ($rows === null) return implode("\n", $definitions);
    $used = [];
    foreach ($rows as $row) {
        $type = $row['absence_type'] ?? 'absent';
        $used[isset($definitions[$type]) ? $type : 'absent'] = true;
    }
    return implode("\n", array_intersect_key($definitions, $used));
}
function defaultAbsenceEmailTemplate(): string {
    return "Αγαπητέ/ή κηδεμόνα,\n\nΣας ενημερώνουμε για τις παρακάτω απουσίες {student_title_gen} {student_genitive}:\n\nΗμερομηνία: {date}\nΏρες απουσίας: {hours}\n\nΓια οποιαδήποτε διευκρίνιση, μπορείτε να επικοινωνήσετε με το σχολείο.\n\nΜε εκτίμηση,\nΗ Διεύθυνση\n{school_name}\nΤηλ.: {school_phone}";
}
