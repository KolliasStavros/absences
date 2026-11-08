/* Read-only annual calendar: never submits or changes absence records. */
(() => {
    const dialog=document.createElement('div'); dialog.className='app-dialog';
    dialog.setAttribute('role','dialog'); dialog.setAttribute('aria-modal','true'); dialog.setAttribute('aria-labelledby','attendanceCalendarTitle');
    dialog.innerHTML='<section class="app-dialog-panel app-dialog-panel-wide attendance-calendar-panel"><div class="app-dialog-heading"><h2 class="app-dialog-title" id="attendanceCalendarTitle">Ημερολόγιο απουσιών</h2><button type="button" class="app-dialog-close" aria-label="Κλείσιμο ημερολογίου">×</button></div><div class="attendance-calendar-content" role="status"></div><p class="attendance-calendar-total"></p></section>';
    document.body.append(dialog);
    const content=dialog.querySelector('.attendance-calendar-content'), total=dialog.querySelector('.attendance-calendar-total');
    const title=dialog.querySelector('h2'), closeButton=dialog.querySelector('button');
    const monthFormat=new Intl.DateTimeFormat('el-GR',{month:'long',year:'numeric'}), dayFormat=new Intl.DateTimeFormat('el-GR',{dateStyle:'full'});
    let month=new Date(), data=null, generation=0, controller=null, returnFocus;
    const node=(tag,text,className='')=>{const el=document.createElement(tag);el.textContent=text;el.className=className;return el;};
    function close() { generation++; controller?.abort(); dialog.style.display='none'; returnFocus?.focus(); }
    closeButton.addEventListener('click',close);
    dialog.addEventListener('keydown',e=>{
        if(e.key==='Escape') { e.preventDefault(); close(); }
        if(e.key==='Tab') {
            const buttons=[...dialog.querySelectorAll('button')],first=buttons[0],last=buttons[buttons.length-1];
            if(e.shiftKey&&document.activeElement===first) {e.preventDefault();last.focus();}
            else if(!e.shiftKey&&document.activeElement===last) {e.preventDefault();first.focus();}
        }
    });
    function render() {
        content.replaceChildren();
        const calendar=node('div','','absence-calendar'), heading=node('div','','absence-calendar-heading');
        for(const delta of [-1,0,1]) {
            if(delta===0) { heading.append(node('strong',monthFormat.format(month))); continue; }
            const button=node('button',delta<0?'‹':'›'); button.type='button';
            button.setAttribute('aria-label',delta<0?'Προηγούμενος μήνας':'Επόμενος μήνας');
            button.addEventListener('click',()=>{month=new Date(month.getFullYear(),month.getMonth()+delta,1);render();content.querySelector(delta<0?'button:first-child':'button:last-child')?.focus();});
            heading.append(button);
        }
        calendar.append(heading,node('p','Κόκκινο: μη δικαιολογημένες (μαζί με άδειες και αποβολές) · Μπλε: δικαιολογημένες · Διπλή ένδειξη: και οι δύο.'));
        const grid=node('div','','absence-calendar-grid');
        ['Δε','Τρ','Τε','Πε','Πα','Σα','Κυ'].forEach(day=>grid.append(node('span',day)));
        const offset=(new Date(month.getFullYear(),month.getMonth(),1).getDay()+6)%7;
        for(let i=0;i<offset;i++) grid.append(node('span',''));
        for(let day=1;day<=new Date(month.getFullYear(),month.getMonth()+1,0).getDate();day++) {
            const date=new Date(month.getFullYear(),month.getMonth(),day,12);
            const key=`${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(day).padStart(2,'0')}`;
            const counts=data.days[key]||{total:0,justified:0,unjustified:0};
            const cell=node('div','','absence-calendar-day attendance-calendar-day'); cell.dataset.date=key;
            cell.title=`${dayFormat.format(date)}: ${counts.total} απουσίες (${counts.justified} δικαιολογημένες)`;
            if(counts.unjustified) cell.classList.add('has-unjustified');
            if(counts.justified) cell.classList.add('has-justified');
            cell.append(node('span',String(day)),node('small',`${counts.total} απ.`,'calendar-absence-count'));grid.append(cell);
        }
        calendar.append(grid);content.append(calendar);
        total.textContent=`Σύνολο έτους ${data.year}: ${data.total} απουσίες`;
    }
    window.openStudentAbsenceCalendar=async id=>{
        returnFocus=document.activeElement; const request=++generation;
        controller?.abort();controller=new AbortController();const currentController=controller;
        const timeout=setTimeout(()=>currentController.abort(),15000);
        title.textContent='Ημερολόγιο απουσιών'; content.textContent='Φόρτωση…';total.textContent='';data=null;
        const date=document.getElementById('dateSelect')?.value;
        month=date?new Date(date+'T12:00:00'):new Date();month.setDate(1);
        dialog.style.display='flex';closeButton.focus();
        try {
            const response=await fetch('../api/student_absence_calendar.php?student_id='+encodeURIComponent(id),{cache:'no-store',signal:currentController.signal});
            const result=await response.json();if(request!==generation)return;
            if(!response.ok||result.error)throw Error(result.error||'Δεν φορτώθηκε το ημερολόγιο.');
            data=result;title.textContent='Ημερολόγιο απουσιών: '+result.name;render();
        } catch(error) {if(request===generation)content.textContent=error.name==='AbortError'?'Η φόρτωση καθυστέρησε. Κλείστε και δοκιμάστε ξανά.':error.message;}
        finally {clearTimeout(timeout);}
    };
})();
