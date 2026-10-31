// SPDX-License-Identifier: GPL-3.0-only
(() => {
 const dialog=document.createElement('div');dialog.className='email-compose-dialog';dialog.hidden=true;dialog.setAttribute('role','dialog');dialog.setAttribute('aria-modal','true');dialog.setAttribute('aria-labelledby','historyEmailTitle');
 dialog.innerHTML=`<form class="email-compose-panel"><h2 id="historyEmailTitle">Περίοδος ιστορικού απουσιών</h2><label><input type="checkbox" class="history-all" checked> Ολόκληρο το ενεργό σχολικό έτος</label><label>Από ημερομηνία<input type="date" class="form-control history-from" disabled></label><label>Έως ημερομηνία<input type="date" class="form-control history-to" disabled></label><p role="status"></p><footer><button type="submit" class="btn btn-primary">Σύνταξη email</button><button type="button" class="btn btn-secondary">Ακύρωση</button></footer></form>`;
 document.body.append(dialog);
 const all=dialog.querySelector('.history-all'),from=dialog.querySelector('.history-from'),to=dialog.querySelector('.history-to'),status=dialog.querySelector('[role="status"]');let studentId,trigger;
 function close(){dialog.hidden=true;trigger?.focus();}
 window.openHistoryEmail=id=>{studentId=id;trigger=document.activeElement;status.textContent='';dialog.hidden=false;all.focus();};
 all.addEventListener('change',()=>{from.disabled=to.disabled=all.checked;from.required=to.required=!all.checked;});
 dialog.querySelector('button[type="button"]').addEventListener('click',close);
 dialog.addEventListener('click',e=>{if(e.target===dialog)close();});
 dialog.addEventListener('keydown',e=>{if(e.key==='Escape')close();if(e.key==='Tab'){const items=[...dialog.querySelectorAll('input,button')].filter(x=>!x.disabled);const first=items[0],last=items[items.length-1];if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}}});
 dialog.querySelector('form').addEventListener('submit',e=>{e.preventDefault();if(!all.checked&&from.value>to.value){status.textContent='Η ημερομηνία Από πρέπει να προηγείται της Έως.';return;}close();window.openStudentEmailComposer(studentId,'history',{from:all.checked?null:from.value,to:all.checked?null:to.value});});
})();
