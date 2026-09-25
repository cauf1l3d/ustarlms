const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM} = require('jsdom');

const root = path.resolve(__dirname, '../..');
const source = fs.readFileSync(
  path.join(root, 'moodle/local/ustar/amd/src/route_studio.js'),
  'utf8'
);
const executable = source
  .replace('export const init = () => {', 'globalThis.routeStudioInit = () => {');
const template = fs.readFileSync(
  path.join(root, 'moodle/local/ustar/templates/route_studio.mustache'),
  'utf8'
);

const dom = new JSDOM(`<!doctype html><body>
<form id="u-route-order">
  <input name="revision" value="revision-1">
</form>
<div id="u-route-list">
  <article id="point-1" class="u-route-editor__point" draggable="true" data-u-draggable="1" tabindex="0">
    <input form="u-route-order" name="pointids[]" value="1">
    <span class="u-route-item__order">10</span>
    <button type="button" data-u-move="up">up</button>
    <button type="button" data-u-move="down">down</button>
  </article>
  <article id="point-2" class="u-route-editor__point" draggable="true" data-u-draggable="1" tabindex="0">
    <input form="u-route-order" name="pointids[]" value="2">
    <span class="u-route-item__order">20</span>
    <button type="button" data-u-move="up">up</button>
    <button type="button" data-u-move="down">down</button>
  </article>
</div>
</body>`, {runScripts: 'outside-only'});

dom.window.eval(executable);
dom.window.routeStudioInit();

const list = dom.window.document.getElementById('u-route-list');
const first = dom.window.document.getElementById('point-1');
first.querySelector('[data-u-move="down"]').click();

assert.deepEqual(
  [...list.querySelectorAll('input[name="pointids[]"]')].map((input) => input.value),
  ['2', '1'],
  'keyboard control must change the submitted route order'
);
assert.deepEqual(
  [...list.querySelectorAll('.u-route-item__order')].map((node) => node.textContent),
  ['1', '2'],
  'visible order must be renumbered after a move'
);
assert.equal(
  list.firstElementChild.querySelector('[data-u-move="up"]').disabled,
  true,
  'first point cannot move above the route'
);
assert.equal(
  list.lastElementChild.querySelector('[data-u-move="down"]').disabled,
  true,
  'last point cannot move below the route'
);

const dragStart = new dom.window.Event('dragstart', {bubbles: true, cancelable: true});
list.lastElementChild.dispatchEvent(dragStart);
assert.equal(list.lastElementChild.classList.contains('is-dragging'), true);
list.lastElementChild.dispatchEvent(new dom.window.Event('dragend', {bubbles: true}));
assert.equal(list.querySelector('.is-dragging'), null);

assert.match(template, /name="revision" value="{{revision}}"/);
assert.match(template, /name="expectedmodified" value="{{expectedmodified}}"/);
assert.match(template, /name="action" value="make_override"/);
assert.match(template, /name="action" value="revert_parent"/);
assert.match(template, /data-u-move="up"/);
assert.match(template, /data-u-move="down"/);
assert.match(template, /Сотрудники продолжают видеть опубликованную версию/);

console.log('PASS Route Studio browser acceptance');
