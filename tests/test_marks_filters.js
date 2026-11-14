// Offline checks of cascading selectors, including direct course links.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const option = (value, dataset = {}) => ({ value, dataset });
function select(options, initial = '') {
    return {
        options: [option(''), ...options], dataset: {}, listeners: {}, selected: initial,
        get value() { return this.selected; },
        set value(value) { this.selected = this.options.some(o => o.value === value) ? value : ''; },
        replaceChildren(...options) { this.options = options; this.value = this.selected; },
        addEventListener(event, callback) { this.listeners[event] = callback; },
        change(value) { this.value = value; this.listeners.change(); }
    };
}
function fixture(linked = false) {
    const grade = select([option('1'), option('2')], linked ? '1' : '');
    const section = select([option('10', { grade: '1' }), option('11', { grade: '1' }), option('20', { grade: '2' })], linked ? '10' : '');
    const course = select([option('100', { section: '10' }), option('110', { section: '11' }), option('200', { section: '20' })], linked ? '100' : '');
    const fields = { '[data-marks-grade]': grade, '[data-marks-section]': section, '[data-marks-course]': course };
    vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname, '../public_html/assets/js/marks.js'), 'utf8'), {
        document: { querySelector: key => fields[key] || null, getElementById: () => null }, window: {}
    });
    return { grade, section, course };
}
const empty = fixture();
assert.equal(empty.section.disabled, true);
assert.equal(empty.course.disabled, true);
empty.grade.change('1');
assert.deepEqual(empty.section.options.map(o => o.value), ['', '10', '11']);
assert.equal(empty.course.disabled, true);
empty.section.change('11');
assert.deepEqual(empty.course.options.map(o => o.value), ['', '110']);
empty.course.value = '110';
empty.grade.change('2');
assert.equal(empty.section.value, '');
assert.equal(empty.course.value, '');
empty.section.change('20');
assert.deepEqual(empty.course.options.map(o => o.value), ['', '200']);
empty.grade.change('1');
empty.section.change('10');
assert.deepEqual(empty.course.options.map(o => o.value), ['', '100']);
const linked = fixture(true);
assert.equal(linked.grade.value, '1');
assert.equal(linked.section.value, '10');
assert.equal(linked.course.value, '100');
console.log('PASS: empty state, cascading choices, reset, return to earlier grade and direct links');
