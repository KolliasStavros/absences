<?php
// Included inside the existing admin settings form (login and CSRF handled by caller).
$templateSaveFailed=$_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['save_general_settings'])&&$error!=='';
?>
<?php foreach(['history'=>'Πρότυπο ιστορικού απουσιών','summary'=>'Πρότυπο μαζικής συγκεντρωτικής ενημέρωσης'] as $mode=>$title):
    $defaults=defaultReportEmailTemplate($mode); ?>
<details class="email-template-group">
    <summary><?= htmlspecialchars($title) ?></summary>
    <div class="email-template-group-body">
    <?php foreach(['subject'=>'Θέμα Email','body'=>'Κείμενο Email'] as $part=>$label):
        $field='email_'.$mode.'_'.$part;
        $value=$templateSaveFailed&&is_string($_POST[$field]??null)?$_POST[$field]:getSchoolSetting($field,$defaults[$part]); ?>
    <div class="form-group" style="margin-top:12px">
        <label for="<?= $field ?>"><?= $label ?></label>
        <?php if($part==='subject'): ?>
        <input id="<?= $field ?>" name="<?= $field ?>" class="form-control" maxlength="200" value="<?= htmlspecialchars($value) ?>" required>
        <?php else: ?>
        <textarea id="<?= $field ?>" name="<?= $field ?>" class="form-control" rows="12" maxlength="20000" required><?= htmlspecialchars($value) ?></textarea>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div></details>
<?php endforeach; ?>
<details class="email-template-group"><summary>Μεταβλητές ιστορικού και μαζικής συγκεντρωτικής ενημέρωσης</summary><div class="email-template-group-body">
    <p><code>{school_name}</code>: σχολείο · <code>{school_phone}</code>: τηλέφωνο · <code>{student_full_name}</code>: ονοματεπώνυμο με αστεράκια · <code>{am}</code>: Α.Μ. · <code>{academic_year}</code>: σχολικό έτος · <code>{period}</code>: περίοδος.</p>
    <p><code>{absence_details}</code>: σύνολα και ανάλυση απουσιών (στη μαζική ενημέρωση ακολουθεί την επιλογή «Ανάλυση ανά ημέρα»).</p>
    <p><code>{total}</code>: σύνολο ωρών · <code>{absent}</code>: κοινές/αδικαιολόγητες · <code>{justified}</code>: δικαιολογημένες · <code>{expulsion}</code>: αποβολές · <code>{leave}</code>: με άδεια.</p>
    <p><code>{pending_justifications}</code>: διευκρίνιση για δικαιολογητικά που εκκρεμούν να ελεγχθούν και να καταχωριστούν.</p>
</div></details>
<details class="email-template-group"><summary>Δικές σας μεταβλητές κειμένου</summary><div class="email-template-group-body">
<p style="font-size:13px">Διαθέσιμες και στα τρία πρότυπα. Γράψτε π.χ. <code>{justification_info}</code> στο σημείο όπου θέλετε να εμφανιστεί το αντίστοιχο κείμενο. Αν δεν τη χρησιμοποιήσετε, δεν προστίθεται στο email. Απλό κείμενο, χωρίς HTML ή άλλες μεταβλητές μέσα του.</p>
<p style="font-size:12px">Μπορείτε να αλλάξετε το όνομα ή το κείμενο και να διαγράψετε μεταβλητές. Πριν από μετονομασία ή διαγραφή ενημερώστε και τα πρότυπα που τις χρησιμοποιούν. Το αρχικό κείμενο δικαιολογήσεων είναι αυτό που δώσατε και μπορεί να ενημερώνεται από το σχολείο.</p>
<div id="custom-email-texts">
<?php
$customRows=[];
if($templateSaveFailed&&is_array($_POST['custom_text_name']??null)&&is_array($_POST['custom_text_value']??null)) {
    foreach($_POST['custom_text_name'] as $i=>$name) if(is_string($name)&&is_string($_POST['custom_text_value'][$i]??null)) $customRows[]=[$name,$_POST['custom_text_value'][$i]];
} else foreach(customEmailTexts() as $name=>$value) $customRows[]=[$name,$value];
foreach($customRows as [$name,$value]): ?>
    <div class="custom-email-text" style="border:1px solid var(--border);border-radius:8px;padding:12px;margin-bottom:12px">
        <label>Όνομα μεταβλητής (χωρίς αγκύλες)
            <input name="custom_text_name[]" class="form-control" pattern="[a-z][a-z0-9_]{0,49}" maxlength="50" value="<?= htmlspecialchars($name) ?>" placeholder="justification_info" required>
        </label>
        <label style="display:block;margin-top:8px">Κείμενο
            <textarea name="custom_text_value[]" class="form-control" rows="7" maxlength="12000"><?= htmlspecialchars($value) ?></textarea>
        </label>
        <button type="button" class="btn btn-secondary" data-remove-custom-text style="margin-top:8px">Διαγραφή μεταβλητής</button>
    </div>
<?php endforeach; ?>
</div>
<template id="custom-email-text-row">
    <div class="custom-email-text" style="border:1px solid var(--border);border-radius:8px;padding:12px;margin-bottom:12px">
        <label>Όνομα μεταβλητής (χωρίς αγκύλες)<input name="custom_text_name[]" class="form-control" pattern="[a-z][a-z0-9_]{0,49}" maxlength="50" placeholder="my_text" required></label>
        <label style="display:block;margin-top:8px">Κείμενο<textarea name="custom_text_value[]" class="form-control" rows="7" maxlength="12000"></textarea></label>
        <button type="button" class="btn btn-secondary" data-remove-custom-text style="margin-top:8px">Διαγραφή μεταβλητής</button>
    </div>
</template>
<button type="button" id="add-custom-email-text" class="btn btn-secondary" style="margin-bottom:16px">+ Προσθήκη μεταβλητής</button>
</div></details>
<script>
(() => {
    const list=document.getElementById('custom-email-texts');
    list.closest('form')?.addEventListener('invalid',event=>{
        for(let element=event.target.parentElement;element;element=element.parentElement) {
            if(element.tagName==='DETAILS') element.open=true;
        }
    },true);
    document.getElementById('add-custom-email-text').addEventListener('click',()=>{
        if(list.children.length>=30) { alert('Επιτρέπονται έως 30 μεταβλητές.'); return; }
        const row=document.getElementById('custom-email-text-row').content.cloneNode(true);
        list.appendChild(row);list.lastElementChild.querySelector('input').focus();
    });
    list.addEventListener('click',event=>{
        if(event.target.closest('[data-remove-custom-text]')) event.target.closest('.custom-email-text').remove();
    });
})();
</script>
