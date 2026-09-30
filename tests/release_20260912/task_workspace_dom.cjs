const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM} = require('jsdom');
const source = fs.readFileSync(path.resolve(__dirname, '../../moodle/local/ustar/task_workspace.js'), 'utf8');
const row = '<div class="uw-builder-row"><input type="hidden" name="fieldkey[]" value="existing"><input name="fieldlabel[]"><button type="button" data-move-up>Up</button><button type="button" data-remove-row>Remove</button></div>';
const dom = new JSDOM(`<body><div class="u-workspace"><form>
<select name="kind"><option value="task">Task</option><option value="checklist">Checklist</option><option value="retraining">Retraining</option></select>
<div data-kind="checklist"><select name="templateid"><option value="1">Template</option></select></div>
<div data-kind="retraining"><select name="policyid"><option value="2">Topic</option></select></div>
<div data-repeat-section><input name="repeat" type="checkbox"></div></form>
<div data-builder>${row}</div><button type="button" data-add-field>Add</button><template data-field-template>${row}</template>
<div data-rule-levels></div><button type="button" data-add-level>Add level</button><template data-level-template><div class="uw-builder-row"><select name="levelrecipient[]"><option>parent</option></select><button type="button" data-remove-row>Remove</button></div></template>
</div></body>`, {runScripts: 'outside-only'});
dom.window.eval(source); dom.window.document.dispatchEvent(new dom.window.Event('DOMContentLoaded'));
const doc = dom.window.document; const type = doc.querySelector('[name=kind]');
assert.equal(doc.querySelector('[name=templateid]').disabled, true);
assert.equal(doc.querySelector('[name=policyid]').disabled, true);
type.value = 'checklist'; type.dispatchEvent(new dom.window.Event('change'));
assert.equal(doc.querySelector('[name=templateid]').disabled, false);
type.value = 'retraining'; type.dispatchEvent(new dom.window.Event('change'));
assert.equal(doc.querySelector('[name=repeat]').disabled, true);
assert.equal(doc.querySelector('[name=policyid]').disabled, false);
doc.querySelector('[data-add-field]').click();
const keys = [...doc.querySelector('[data-builder]').querySelectorAll('[name="fieldkey[]"]')].map(el => el.value);
assert.equal(keys.length, 2); assert.notEqual(keys[0], keys[1]); assert.match(keys[1], /^f_[a-z0-9_]+$/);
const added = doc.querySelector('[data-builder]').lastElementChild;
added.querySelector('[data-move-up]').click(); assert.equal(doc.querySelector('[data-builder]').firstElementChild, added);
added.querySelector('[data-remove-row]').click();
doc.querySelector('[data-builder] [data-remove-row]').click(); assert.equal(doc.querySelector('[data-builder]').children.length, 1);
for (let i=0; i<7; i++) { doc.querySelector('[data-add-level]').click(); }
assert.equal(doc.querySelector('[data-rule-levels]').children.length, 5);
assert.equal(doc.querySelectorAll('form[action]').length, 0);
console.log('TASK_WORKSPACE_DOM_OK kind-fields builder stable-keys reorder limits');
