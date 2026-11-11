// Offline checks of the real polling function; no application data or network.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
const appRoot = fs.existsSync(path.join(__dirname, '../public_html')) ? '../public_html' : '..';
const source = fs.readFileSync(path.join(__dirname, appRoot, 'admin/index.php'), 'utf8');
const poll = source.slice(source.indexOf('    async function pollAttendanceUpdates()'), source.indexOf('    function updateKpiBadges'));
const delays = [];
const ctx = vm.createContext({
    isLiveActive:true, livePollInFlight:false, livePollTimer:null, liveRetryButton:null,
    liveFailures:0, currentDate:'2026-11-11', currentGradeId:0, currentSectionId:20,
    currentStatusFilter:'all', currentLiveHash:'before', isFirstCheck:true,
    document:{hidden:false, getElementById:()=>null}, AbortController,
    setTimeout:(fn,delay)=>{delays.push(delay);return delays.length;}, clearTimeout:()=>{},
    updateLiveConnection:()=>{}, updateKpiBadges:()=>{}, updateAttendanceTable:()=>{},
    fetch:async()=>{throw new Error('timeout');}, Date, Set
});
vm.runInContext(poll, ctx);
(async()=>{
    for (const expected of [5000,10000,20000,40000,60000,60000]) {
        await ctx.pollAttendanceUpdates();
        assert.equal(delays.at(-1),expected);
        assert.equal(ctx.livePollInFlight,false);
    }
    ctx.fetch=async()=>({ok:true,json:async()=>({has_updates:false,state_hash:'before'})});
    await ctx.pollAttendanceUpdates();
    assert.equal(ctx.liveFailures,0);
    assert.equal(delays.at(-1),2500);
    ctx.fetch=async()=>({ok:true,json:async()=>({has_updates:true,state_hash:'after',student_attendances:{}})});
    ctx.updateAttendanceTable=()=>{throw new Error('render failed');};
    await ctx.pollAttendanceUpdates();
    assert.equal(ctx.currentLiveHash,'before','failed render must be retried');
    ctx.updateAttendanceTable=()=>{};
    await ctx.pollAttendanceUpdates();
    assert.equal(ctx.currentLiveHash,'after');
    assert.equal(ctx.liveFailures,0);
    ctx.livePollInFlight=true;
    const count=delays.length;
    await ctx.pollAttendanceUpdates();
    assert.equal(delays.length,count,'no overlapping client polls');
    const api=fs.readFileSync(path.join(__dirname,appRoot,'api/get_live_attendance.php'),'utf8');
    assert.ok(api.indexOf('session_write_close()') > api.indexOf("in_array($user['role']"));
    assert.ok(api.indexOf('session_write_close()') < api.indexOf('$db = getDB()'));
    console.log('PASS: failure backoff, recovery, render retry, no overlapping polls, session release after authorization');
})().catch(e=>{console.error(e);process.exitCode=1;});
