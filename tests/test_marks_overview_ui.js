// Browser-independent checks: overview filters stay optional and never auto-submit.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const field = values => ({
    value: '', listeners: {}, options: ['', ...values].map(value => ({ value })),
    addEventListener(key, fn) { this.listeners[key] = fn; },
    replaceChildren(...options) { this.options = options; },
    change(value) { this.value = value; this.listeners.change?.(); }
});
const fields = { grade_id: field(['1', '2']), section_id: field(['11', '21']), subject_id: field(['100', '200']), teacher_id: field(['5', '6']) };
const catalog = [
    { grade_id: '1', section_id: '11', subject_id: '100', teachers: ['5'] },
    { grade_id: '2', section_id: '21', subject_id: '200', teachers: ['6'] }
];
const form = { elements: { namedItem: key => fields[key] } };
vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname, '../public_html/assets/js/marks.js'), 'utf8'), {
    document: {
        querySelector: key => key === '[data-marks-overview]' ? form : null,
        getElementById: key => key === 'marks-overview-catalog' ? { textContent: JSON.stringify(catalog) } : null
    }, window: {}
});
fields.teacher_id.change('5');
assert.equal(fields.teacher_id.value, '5');
assert.equal(fields.grade_id.value, '');
fields.grade_id.change('2');
assert.equal(fields.teacher_id.value, '');
assert.deepEqual(fields.section_id.options.map(o => o.value), ['', '21']);
assert.deepEqual(fields.subject_id.options.map(o => o.value), ['', '200']);
fields.section_id.change('21');
fields.subject_id.change('200');
fields.teacher_id.change('6');
fields.grade_id.change('');
assert.equal(fields.section_id.value, '21');
assert.equal(fields.subject_id.value, '200');
assert.equal(fields.teacher_id.value, '6');
fields.section_id.change('');
assert.deepEqual(fields.subject_id.options.map(o => o.value), ['', '100', '200']);
assert.deepEqual(fields.teacher_id.options.map(o => o.value), ['', '5', '6']);
assert.ok(Object.values(fields).every(f => f.options[0].value === ''));
console.log('PASS: teacher alone, scoped choices, invalid choice reset, retained choices and optional All');
