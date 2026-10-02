const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM} = require('jsdom');
const Mustache = require('mustache');
const base = path.resolve(__dirname, '../../moodle/local/ustar');
const html = Mustache.render(fs.readFileSync(path.join(base, 'templates/reward_conditions.mustache'), 'utf8'), {
    url: '/local/ustar/reward_control.php', sesskey: 'fixture', revision: 'fixture',
    resourcejson: JSON.stringify({route: {'4': 'Точка маршрута'}, course: {'7': 'Реальный курс'}}),
    departments: [{id: 'retail', name: 'Розничный отдел'}], people: [{id: 3, name: 'Real Employee'}],
});
const dom = new JSDOM(html, {runScripts: 'outside-only'}), win = dom.window, doc = win.document;
win.eval(fs.readFileSync(path.join(base, 'reward_control.js'), 'utf8'));
doc.dispatchEvent(new win.Event('DOMContentLoaded'));
function change(selector, value) {
    const el = doc.querySelector(selector); el.value = value;
    el.dispatchEvent(new win.Event('change')); return el;
}
const coins = doc.querySelector('[name=conditioncoins]');
assert.equal(doc.querySelector('[name=conditionresource] option[value="4"]').textContent, 'Точка маршрута');
coins.value = '20'; change('[name=conditionkind]', 'course');
assert.equal(coins.value, '0'); assert.equal(coins.readOnly, true);
assert.equal(doc.querySelector('[name=conditionresource] option[value="7"]').textContent, 'Реальный курс');
assert.equal(doc.querySelector('[name=conditionresource] option[value="4"]'), null);
change('[name=conditionscope]', 'person');
assert.equal(doc.querySelector('[data-scope=person]').hidden, false);
assert.equal(doc.querySelector('[name=scope_person]').required, true);
assert.equal(doc.querySelector('[name=scope_department]').required, false);
change('[name=conditionscope]', 'company');
assert.equal(doc.querySelector('[data-scope=person]').hidden, true);
assert.equal(doc.querySelector('[name=scope_person]').required, false);
doc.querySelector('[data-open-condition]').click();
assert.equal(doc.querySelector('#reward-new-condition').open, true);
win.close();
console.log('REWARD_CONTROL_DOM_OK source scope learning-coins new-condition');
