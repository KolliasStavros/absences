const studentCardsConfig = JSON.parse(document.getElementById('studentCardsConfig').textContent);
const studentCardsSchoolNode = document.createElement('span');
studentCardsSchoolNode.textContent = studentCardsConfig.schoolName;
const studentCardsSchoolHtml = studentCardsSchoolNode.innerHTML;
function openJustifyModal(studentId, studentName) {
    document.getElementById('justifyStudentId').value = studentId;
    document.getElementById('justifyStudentTitle').textContent = '⚖️ Αιτιολογίες απουσιών: ' + studentName;
    document.getElementById('justifyModal').style.display = 'flex';
    if (window.loadJustificationCalendar) window.loadJustificationCalendar(studentId);
    loadDayReasons();
}

let reasonRequest=0;
const justificationLabels=studentCardsConfig.justificationLabels;
const unjustifiedLabels=studentCardsConfig.unjustifiedLabels;
async function loadDayReasons() {
    const modal=document.getElementById('justifyModal'); if (!modal) return;
    const request=++reasonRequest;
    const buttons=[...modal.querySelectorAll('button[type="submit"]')];
    buttons.forEach(b=>b.disabled=true);
    const status=document.getElementById('reasonLoadStatus');status.textContent='Φόρτωση αποθηκευμένων αιτιολογιών…';
    try {
        const query=new URLSearchParams({student_id:document.getElementById('justifyStudentId').value,date:document.getElementById('justifyDateFrom').value});
        const response=await fetch('../api/get_absence_reasons.php?'+query,{cache:'no-store'});
        const data=await response.json();
        if (request!==reasonRequest) return;
        if (!response.ok || data.error) throw new Error('load');
        const values=data.reasons||{};
        document.getElementById('justificationReason').value=Object.keys(justificationLabels).find(k=>justificationLabels[k]===values.justification_reason)||'medical';
        document.getElementById('unjustifiedReason').value=Object.keys(unjustifiedLabels).find(k=>unjustifiedLabels[k]===values.unjustified_reason)||'';
        document.getElementById('justificationProtocol').value=values.protocol||'';
        status.textContent='Οι αιτίες και οι σημειώσεις αποθηκεύονται ανά μαθητή και ημερομηνία.';
        buttons.forEach(b=>b.disabled=false);
    } catch(e) {
        if(request===reasonRequest) status.textContent='Δεν φορτώθηκαν οι αιτιολογίες. Κλείστε και ανοίξτε ξανά την καρτέλα για επανάληψη.';
    }
}
document.getElementById('justifyDateFrom')?.addEventListener('change',loadDayReasons);

function closeJustifyModal() {
    document.getElementById('justifyModal').style.display = 'none';
}

async function openDetailsModal(studentId) {
    const modal = document.getElementById('detailsModal');
    const container = document.getElementById('detailsModalContent');
    modal.style.display = 'flex';
    container.innerHTML = '<div style="text-align:center; padding:30px; color:var(--text-muted);">⏳ Φόρτωση αναλυτικών στοιχείων...</div>';

    try {
        const res = await fetch('student_card_ajax.php?student_id=' + studentId);
        const html = await res.text();
        container.innerHTML = html;
    } catch (e) {
        container.textContent = 'Σφάλμα κατά τη φόρτωση. Παρακαλώ δοκιμάστε ξανά.';
    }
}

function closeDetailsModal() {
    document.getElementById('detailsModal').style.display = 'none';
}

function printStudentDossier() {
    const content = document.getElementById('detailsModalContent').innerHTML;
    const printWin = window.open('', '', 'width=800,height=600');
    printWin.document.write('<html><head><title>Ατομικό Δελτίο Απουσιών</title>');
    printWin.document.write('<link rel="stylesheet" href="../assets/css/style.css?v=security-step9-v2">');
    printWin.document.write('<style>body{padding:20px; font-family:sans-serif;} table{width:100%; border-collapse:collapse;} th,td{border:1px solid #ddd; padding:8px;} th{background:#f5f5f5;}</style>');
    printWin.document.write('</head><body>');
    printWin.document.write('<div style="text-align:center; margin-bottom:20px;"><h2>' + studentCardsSchoolHtml + '</h2><h3>Ατομικό Δελτίο Απουσιών Μαθητή</h3></div>');
    printWin.document.write(content);
    printWin.document.write('<div style="margin-top:40px; display:flex; justify-content:space-between;"><div>Ημερομηνία: ' + studentCardsConfig.printDate + '</div><div>Ο/Η Διευθυντής/ντρια<br><br><br>____________________</div></div>');
    printWin.document.write('</body></html>');
    printWin.document.close();
    printWin.focus();
    setTimeout(() => { printWin.print(); printWin.close(); }, 300);
}

// Close modals when clicking outside
window.onclick = function(e) {
    const jModal = document.getElementById('justifyModal');
    const dModal = document.getElementById('detailsModal');
    if (e.target === jModal) closeJustifyModal();
    if (e.target === dModal) closeDetailsModal();
};
