<?php
// Shared plain-text templates and reusable school text. No sending or database writes.
function absencePendingJustificationsText(): string {
    return 'Αν έχετε ήδη υποβάλει δικαιολογητικά και οι αντίστοιχες απουσίες δεν εμφανίζονται ακόμη ως δικαιολογημένες, η ενημέρωση των στοιχείων θα γίνει τις επόμενες ημέρες, μετά την ολοκλήρωση του ελέγχου και της καταχώρισής τους.';
}
function defaultReportEmailTemplate(string $mode): array {
    $history=$mode==='history';
    $intro=$history?'Ιστορικό απουσιών: {student_full_name} (Α.Μ. {am})':'Σας ενημερώνουμε συγκεντρωτικά για τις καταχωρισμένες απουσίες του/της {student_full_name} (Α.Μ. {am}).';
    return ['subject'=>'{school_name} — '.($history?'Ιστορικό απουσιών':'Συγκεντρωτική ενημέρωση απουσιών').' — {student_full_name}',
        'body'=>"Αγαπητέ/ή κηδεμόνα,\n\n".$intro."\n".($history?'':"Σχολικό έτος: {academic_year}\n")."Περίοδος: {period}\n\n{absence_details}\n\n{pending_justifications}\n\nΜε εκτίμηση,\n{school_name}"];
}
function defaultCustomEmailTexts(): array {
    return ['justification_info'=><<<'TEXT'
Πώς και Πόσες Απουσίες Δικαιολογούνται

Ανάλογα με τον λόγο και τη διάρκεια της απουσίας, ακολουθείται διαφορετική διαδικασία:

- Δικαιολόγηση από γονέα/κηδεμόνα. Οι γονείς μπορούν να δικαιολογήσουν συνολικά έως 5 ημέρες σε όλη τη διάρκεια του σχολικού έτους. Κάθε φορά μπορούν να δικαιολογούν 1 ή 2 συνεχόμενες ημέρες (για ασθένεια ή σοβαρούς οικογενειακούς λόγους), υποβάλλοντας Υπεύθυνη Δήλωση (μέσω gov.gr: https://www.gov.gr/ipiresies/polites-kai-kathemerinoteta/psephiaka-eggrapha-gov-gr/ekdose-upeuthunes-deloses ή αυτοπροσώπως στο σχολείο).
- Δικαιολόγηση με ιατρική βεβαίωση. Αν ο μαθητής λείψει πάνω από 2 συνεχόμενες ημέρες λόγω ασθένειας (ή αν έχει εξαντλήσει τις 5 ημέρες του γονέα), απαιτείται βεβαίωση από δημόσιο νοσοκομείο, ιδιωτική κλινική ή ιδιώτη γιατρό. Η ιατρική βεβαίωση πρέπει να αναφέρει το είδος και τη διάρκεια της ασθένειας.
- Προθεσμία 10 ημερών. Όλα τα δικαιολογητικά (μαζί με σχετική αίτηση) πρέπει να κατατεθούν στο σχολείο μέσα σε 10 εργάσιμες ημέρες από την ημέρα που ο μαθητής θα επιστρέψει στα μαθήματα. Αν περάσει αυτή η προθεσμία, οι απουσίες μένουν αδικαιολόγητες.

Απουσίες που Δεν Προσμετρώνται

Υπάρχουν ειδικές περιπτώσεις όπου οι απουσίες καταγράφονται, αλλά δεν υπολογίζονται καθόλου στο όριο των 114. Αυτές περιλαμβάνουν σοβαρά ιατρικά περιστατικά (π.χ. χειρουργεία, νοσηλεία, χημειοθεραπείες), συμμετοχή σε επίσημους πανελλήνιους ή διεθνείς αθλητικούς αγώνες, καθώς και έκτακτες περιόδους έξαρσης εποχικών ιώσεων/γρίπης, εφόσον εκδοθεί σχετική εγκύκλιος από το Υπουργείο Παιδείας.
TEXT];
}
function customEmailTexts(): array {
    if(!function_exists('getSchoolSetting')) return defaultCustomEmailTexts();
    $data=json_decode(getSchoolSetting('email_custom_texts',json_encode(defaultCustomEmailTexts(),JSON_UNESCAPED_UNICODE)),true);
    return is_array($data)?$data:[];
}
function emailTemplateVariables(string $mode): array {
    $common=['school_name','school_phone','student_full_name'];
    return array_merge($common,$mode==='daily'
        ?['student_genitive','student_title','student_title_gen','student_noun','student_article','student_article_gen','guardian_name_voc','date','hours']
        :['am','academic_year','period','absence_details','pending_justifications','total','absent','justified','expulsion','leave']);
}
function validateCustomEmailTexts(mixed $names,mixed $values): array {
    if(!is_array($names)||!is_array($values)||count($names)!==count($values)||count($names)>30) throw new DomainException('Επιτρέπονται έως 30 μεταβλητές κειμένου.');
    $out=[];$reserved=array_unique(array_merge(emailTemplateVariables('daily'),emailTemplateVariables('history')));
    foreach($names as $i=>$name) {
        if(!is_string($name)||!isset($values[$i])||!is_string($values[$i])) throw new DomainException('Μη έγκυρη μεταβλητή κειμένου.');
        $name=trim($name);$value=trim($values[$i]);if($name===''&&$value==='') continue;
        if(!preg_match('/^[a-z][a-z0-9_]{0,49}$/D',$name)||in_array($name,$reserved,true)||array_key_exists($name,$out)) throw new DomainException('Χρησιμοποιήστε μοναδικά ονόματα με λατινικά πεζά, αριθμούς και _, χωρίς δεσμευμένα ονόματα.');
        if(mb_strlen($value)>12000||str_contains($value,"\0")||preg_match('/\{[a-z][a-z0-9_]*\}/',$value)) throw new DomainException('Κείμενο έως 12.000 χαρακτήρες, χωρίς άλλες μεταβλητές μέσα του.');
        $out[$name]=$value;
    }
    if(strlen(json_encode($out,JSON_UNESCAPED_UNICODE))>55000) throw new DomainException('Τα κείμενα των μεταβλητών είναι συνολικά πολύ μεγάλα.');
    return $out;
}
function validateSchoolEmailTemplate(string $text,string $mode,array $custom,bool $subject=false): void {
    if(trim($text)===''||mb_strlen($text)>($subject?200:20000)||strlen($text)>60000||str_contains($text,"\0")||($subject&&preg_match('/[\r\n]/',$text))) throw new DomainException('Συμπληρώστε θέμα έως 200 και κείμενο έως 20.000 χαρακτήρες.');
    preg_match_all('/\{([a-z][a-z0-9_]*)\}/',$text,$matches);
    $allowed=array_merge(emailTemplateVariables($mode),array_keys($custom));
    foreach($matches[1] as $key) if(!in_array($key,$allowed,true)) throw new DomainException('Η μεταβλητή {'.$key.'} δεν είναι διαθέσιμη σε αυτό το πρότυπο. Αφαιρέστε την από το πρότυπο πριν τη διαγράψετε.');
}
function renderSchoolEmailText(string $template,array $values,?array $custom=null): string {
    $replacements=[];
    foreach(($custom??customEmailTexts()) as $key=>$value) if(is_string($value)) $replacements['{'.$key.'}']=$value;
    foreach($values as $key=>$value) $replacements['{'.$key.'}']=(string)$value;
    return strtr($template,$replacements); // One pass: text variables cannot inject other substitutions.
}
function renderReportEmail(string $mode,array $values): array {
    $defaults=defaultReportEmailTemplate($mode);$out=[];
    foreach($defaults as $part=>$default) {
        $template=function_exists('getSchoolSetting')?getSchoolSetting('email_'.$mode.'_'. $part,$default):$default;
        $out[$part]=renderSchoolEmailText($template,$values);
    }
    $out['subject']=preg_replace('/[\r\n]+/u',' ',$out['subject']);
    return $out;
}
