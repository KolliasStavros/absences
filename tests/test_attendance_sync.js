'use strict';
// Execute the real fetch wrapper AND real dashboard poll with controlled network ordering.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(__dirname + '/../public_html/admin/index.php', 'utf8');
const poll = source.slice(source.indexOf('    async function pollAttendanceUpdates()'), source.indexOf('    function updateKpiBadges'));
const defer = () => { let resolve, reject; const promise = new Promise((a,b) => { resolve=a; reject=b; }); return {promise,resolve,reject}; };
const requests = [], renders = [], delays = [];
const ctx = vm.createContext({
    window:{location:{href:'http://fixture.test/admin/index.php',origin:'http://fixture.test'}},
    URL, AbortController, Date, Set,
    isLiveActive:true,livePollInFlight:false,livePollTimer:null,liveRetryButton:null,liveFailures:0,
    currentDate:'2026-11-15',currentGradeId:0,currentSectionId:1,currentStatusFilter:'all',currentLiveHash:'before',isFirstCheck:true,
    document:{hidden:false,getElementById:()=>null},
    updateLiveConnection:()=>{},updateKpiBadges:()=>{},updateAttendanceTable:rows=>renders.push(rows),
    setTimeout:(fn,ms)=>{delays.push(ms);return delays.length;},clearTimeout:()=>{}
});
ctx.window.fetch=(url,options)=>{const d=defer();requests.push({url,options,...d});return d.promise;};
vm.runInContext(fs.readFileSync(__dirname+'/../public_html/assets/js/attendance-sync.js','utf8'),ctx);
ctx.fetch=(...args)=>ctx.window.fetch(...args);
vm.runInContext(poll,ctx);
const response = (hash,rows) => ({ok:true,json:async()=>({has_updates:true,state_hash:hash,student_attendances:rows})});
(async()=>{
    // Start old poll, finish a write, then deliver the old snapshot without that absence.
    const oldPoll=ctx.pollAttendanceUpdates();
    const write=ctx.fetch('../api/toggle_student_absence.php',{method:'POST',headers:{'X-CSRF-Token':'fixture'}});
    requests[1].resolve({ok:true});await write;
    requests[0].resolve(response('stale',{}));await oldPoll;
    assert.equal(renders.length,0,'stale poll cannot erase newly saved absence');
    assert.equal(ctx.currentLiveHash,'before','discarded snapshot must not be acknowledged');
    assert.equal(ctx.liveFailures,0,'discarding an old poll is not a network failure');
    assert.equal(delays.at(-1),2500,'another poll is scheduled after discarding');
    const freshPoll=ctx.pollAttendanceUpdates();requests[2].resolve(response('fresh',{1:[{hour:1}]}));await freshPoll;
    assert.equal(renders.length,1);assert.equal(ctx.currentLiveHash,'fresh');
    // Polls must not start during a write, even before it has reached the server.
    const pending=ctx.fetch('../api/toggle_student_absence.php',{method:'POST'});
    const count=requests.length;await ctx.pollAttendanceUpdates();assert.equal(requests.length,count);
    assert.equal(ctx.livePollInFlight,false);requests.at(-1).reject(new Error('network'));await assert.rejects(pending);
    assert.notEqual(ctx.window.attendanceSync.snapshot(),null,'failed writes release the gate');
    // Also reject a poll while its JSON body is being received, not just while fetch waits.
    const body=defer();const bodyPoll=ctx.pollAttendanceUpdates();requests.at(-1).resolve({ok:true,json:()=>body.promise});await Promise.resolve();
    const second=ctx.fetch('../api/toggle_student_absence.php',{method:'POST'});requests.at(-1).resolve({ok:true});await second;
    body.resolve({has_updates:true,state_hash:'old-body',student_attendances:{}});await bodyPoll;assert.equal(renders.length,1);
    // Two writes, unrelated reads, external destinations, headers and repeated module load.
    const a=ctx.fetch('../api/toggle_student_absence.php',{method:'POST'});const ai=requests.length-1;
    const b=ctx.fetch('../api/delete_attendance_hour.php',{method:'POST'});const bi=requests.length-1;
    requests[ai].resolve({ok:true});await a;assert.equal(ctx.window.attendanceSync.snapshot(),null);
    requests[bi].resolve({ok:true});await b;const snapshot=ctx.window.attendanceSync.snapshot();
    const read=ctx.fetch('../api/get_live_attendance.php');requests.at(-1).resolve({ok:true});await read;assert.ok(ctx.window.attendanceSync.accepts(snapshot));
    const external=ctx.fetch('https://other.test/api/toggle_student_absence.php',{method:'POST'});requests.at(-1).resolve({ok:true});await external;assert.ok(ctx.window.attendanceSync.accepts(snapshot));
    assert.equal(requests[1].options.headers['X-CSRF-Token'],'fixture');
    const once=ctx.window.fetch;vm.runInContext(fs.readFileSync(__dirname+'/../public_html/assets/js/attendance-sync.js','utf8'),ctx);assert.equal(ctx.window.fetch,once);
    // Verify the actual row renderer keeps both the saved hour and email-pending
    // controls on repeated fresh polls; a real deletion still clears them.
    const cells = {};
    for (const key of ['hours','total','check','email']) cells[key+'-cell-42']={innerHTML:'',textContent:'',style:{},querySelector(){return this.innerHTML.includes('student-checkbox')?{}:null;}};
    const row={querySelector:selector=>cells[selector.slice(1)]||null,remove:()=>{throw new Error('saved row was removed');}};
    const tbody={querySelectorAll:()=>[row]};
    const ui=vm.createContext({
        window:{allStudentsState:{}},studentsCatalog:{42:{student_id:42,primary_section_id:3,guardian_email:'guardian@example.test'}},currentStatusFilter:'all',
        document:{getElementById:id=>id==='attendanceTableBody'?tbody:id==='student-row-42'?row:cells[id]||null},
        escapeHtml:value=>String(value??''),attendanceTypeLabel:value=>value,
    });
    vm.runInContext(source.slice(source.indexOf('function renderInlineGridHtml('),source.indexOf('async function handleInlineHourClick(')),ui);
    vm.runInContext(source.slice(source.indexOf('    function updateAttendanceTable('),source.indexOf('    function createStudentRow(')),ui);
    for(let i=0;i<3;i++){
        ui.updateAttendanceTable({42:[{hour:1,absence_type:'absent',email_notified:0}]},new Set(),{},{42:'pending'});
        assert.match(cells['hours-cell-42'].innerHTML,/btn-hour-absent/);
        assert.equal(cells['total-cell-42'].textContent,1);
        assert.match(cells['email-cell-42'].innerHTML,/Εκκρεμεί/);
        assert.match(cells['check-cell-42'].innerHTML,/student-checkbox/);
    }
    ui.updateAttendanceTable({},new Set());
    assert.doesNotMatch(cells['hours-cell-42'].innerHTML,/btn-hour-absent/);
    assert.equal(cells['total-cell-42'].textContent,0);
    assert.doesNotMatch(cells['email-cell-42'].innerHTML,/Εκκρεμεί/);
    console.log('PASS: delayed polling cannot erase saved hours; hash, retry, body delays, overlapping/failed writes and fetch compatibility');
    console.log('PASS: actual row renderer preserves saved hour, pending email and recipient checkbox across fresh polls; real removal still works');
})().catch(e=>{console.error(e);process.exitCode=1;});
