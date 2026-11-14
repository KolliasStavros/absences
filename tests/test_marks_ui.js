// SPDX-License-Identifier: GPL-3.0-only
// Offline interaction checks for the grading controls; no browser or real data.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
class Control {
    constructor(value = '') { this.value = value; this.listeners = {}; this.dataset = {}; this.attributes = {}; this.hidden = true; this.disabled = false; this.textContent = ''; this.className = ''; this.validityMessage = ''; this.classes = new Set(); this.classList = { toggle: (key, on) => on ? this.classes.add(key) : this.classes.delete(key) }; }
    addEventListener(key, fn) { this.listeners[key] = fn; }
    fire(key, event = {}) { return this.listeners[key]?.(event); }
    setAttribute(key, value) { this.attributes[key] = value; }
    setCustomValidity(text) { this.validityMessage = text; }
    focus() { this.focused = true; }
}
function fixture() {
    const controls = { '[data-number]': new Control(), '[data-range]': new Control('0'), '[data-status]': new Control('ungraded'), '[data-clear]': new Control(), '[data-range-label]': new Control() };
    const row = new Control(); row.dataset = { active: '1', id: '7' }; row.querySelector = key => controls[key];
    const buttons = [new Control(), new Control()]; buttons[0].dataset.saveAction = 'save'; buttons[1].dataset.saveAction = 'submit';
    const form = new Control(); form.dataset = { min: '0', max: '2000', step: '20' }; form.action = '/api/save_marks.php';
    form.querySelectorAll = key => key === '[data-mark-row]' ? [row] : buttons;
    form.reportValidity = () => !controls['[data-number]'].validityMessage;
    const output = new Control(), progress = new Control();
    const doc = { querySelector: () => null, getElementById: key => ({ 'marks-entry': form, 'marks-message': output, 'marks-progress': progress })[key], querySelectorAll: () => [] };
    const win = new Control(); win.confirm = () => true; win.location = { href: 'http://localhost/absences/teacher/marks.php?view=entry&course_id=1', assign: url => { win.destination = url; } };
    const state = { error: null };
    const fetch = async (url, options) => { state.request = JSON.parse(options.body); return { ok: !state.error, json: async () => state.error ? { error: state.error } : { success: true } }; };
    const Fields = class { constructor() { return new Map(Object.entries({ csrf_token: 'fixture', year_id: '1', course_id: '1', period_id: '3', version: '0', course_version: '1', period_version: '1' })); } };
    vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname, '../public_html/assets/js/marks.js'), 'utf8'), { document: doc, window: win, fetch, FormData: Fields, URL });
    return { controls, row, form, buttons, output, progress, win, state };
}
(async () => {
    const f = fixture(); const number = f.controls['[data-number]'], range = f.controls['[data-range]'], status = f.controls['[data-status]'];
    assert.equal(number.value, ''); assert.equal(number.disabled, false); assert.ok(range.classes.has('marks-empty-range'));
    console.log('PASS: blank grade has no numeric default and remains editable');
    number.value = '15,6'; number.fire('input'); assert.equal(range.value, '1560'); assert.equal(status.value, 'value'); assert.match(f.progress.textContent, /1\/1/);
    console.log('PASS: decimal comma synchronizes the slider and completion count');
    number.value = '15,5'; number.fire('input'); assert.notEqual(number.validityMessage, ''); assert.match(f.progress.textContent, /0\/1/);
    console.log('PASS: off-step numeric input is visibly invalid');
    range.value = '1560'; range.fire('input'); assert.equal(number.value, '15,6'); assert.equal(number.validityMessage, '');
    f.controls['[data-clear]'].fire('click'); assert.equal(number.value, ''); assert.equal(status.value, 'ungraded'); assert.equal(number.disabled, false);
    console.log('PASS: slider synchronizes number and clear restores an editable blank');
    status.value = 'absent'; status.fire('change'); assert.equal(number.disabled, true); assert.equal(range.disabled, true);
    status.value = 'value'; status.fire('change'); assert.equal(number.disabled, false); assert.equal(range.disabled, false);
    number.value = '0'; number.fire('input'); assert.equal(range.value, '0'); assert.match(f.progress.textContent, /1\/1/);
    console.log('PASS: special statuses disable numbers; zero remains a real grade');
    let prevented = false; f.win.fire('beforeunload', { preventDefault() { prevented = true; } }); assert.equal(prevented, true);
    f.state.error = 'Η έκδοση άλλαξε';
    await f.form.fire('submit', { preventDefault() {}, submitter: f.buttons[0] });
    assert.equal(number.value, '0'); assert.equal(f.output.textContent, 'Η έκδοση άλλαξε'); assert.ok(f.buttons.every(b => !b.disabled));
    assert.equal(f.state.request.marks[0].value, '0'); assert.equal(f.state.request.marks[0].status, 'value');
    console.log('PASS: failed save preserves values and sends zero as a numeric grade');
    f.state.error = null;
    await f.form.fire('submit', { preventDefault() {}, submitter: f.buttons[1] });
    assert.equal(f.state.request.action, 'submit'); assert.match(f.win.destination, /saved=1/);
    prevented = false; f.win.fire('beforeunload', { preventDefault() { prevented = true; } }); assert.equal(prevented, false);
    console.log('PASS: successful final submission refreshes the sheet and clears dirty state');
})().catch(error => { console.error(error); process.exitCode = 1; });
