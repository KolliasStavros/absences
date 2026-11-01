const coTeachingConfig=JSON.parse(document.getElementById('coTeachingConfig').textContent);
(() => {
 const dialog=document.getElementById('coTeachingModal'),grade=document.getElementById('coGrade'),sources=document.getElementById('coSources'),status=document.getElementById('coStatus');let selected=[],returnFocus;
 function render(){sources.replaceChildren();const available=coTeachingConfig.sections.filter(s=>Number(s.grade_id)===Number(grade.value)&&!coTeachingConfig.groups[s.id]);
  for(const s of available){const label=document.createElement('label'),input=document.createElement('input');input.type='checkbox';input.name='co_sources[]';input.value=s.id;input.checked=selected.includes(Number(s.id));label.append(input,document.createTextNode(s.section_name));sources.append(label);}
  status.textContent=!grade.value?'Επιλέξτε πρώτα τάξη.':available.length<2?'Η τάξη χρειάζεται τουλάχιστον δύο απλά τμήματα.':'Επιλέξτε τουλάχιστον δύο τμήματα.';
 }
 window.openCoTeachingModal=(section=null)=>{returnFocus=document.activeElement;document.getElementById('coTeachingForm').reset();document.getElementById('coSectionId').value=section?section.id:'';document.getElementById('coName').value=section?section.section_name:'';grade.value=section?section.grade_id:'';selected=section?coTeachingConfig.groups[section.id].map(Number):[];document.getElementById('coTeachingTitle').textContent=section?'Επεξεργασία συνδιδασκαλίας':'Νέα συνδιδασκαλία';render();dialog.showModal();};
 grade.addEventListener('change',()=>{selected=[];render();});dialog.addEventListener('close',()=>returnFocus?.focus());
 document.getElementById('coTeachingForm').addEventListener('submit',e=>{if(sources.querySelectorAll('input:checked').length<2){e.preventDefault();status.textContent='Επιλέξτε τουλάχιστον δύο διαφορετικά τμήματα.';}});
})();
