const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM} = require('jsdom');
const Mustache = require('mustache');
const base = path.resolve(__dirname, '../../moodle/local/ustar');
const template = fs.readFileSync(path.join(base, 'templates/notebook.mustache'), 'utf8');
const source = fs.readFileSync(path.join(base, 'notebook.js'), 'utf8');
const tick = () => new Promise(resolve => setImmediate(resolve));
async function run() {
 const html = Mustache.render(template, {url: '/local/ustar/notebook.php', revision: 2, sesskey: 'test-key', hasnotes: true,
  notes: [{id: 10, x: 20, y: 40, color: 'neutral', titleplain: '<img src=x onerror=alert(1)>', description: '<p>Private</p>', version: 1, open: true}]});
 const dom = new JSDOM(html, {runScripts:'outside-only'}), win = dom.window;
 const calls = []; let serverRevision = 2;
 win.fetch = async (url, options) => {
  calls.push({url, params: Object.fromEntries(options.body)});
  assert.equal(Number(options.body.get('revision')), serverRevision);
  assert.equal(options.body.get('sesskey'), 'test-key');
  await tick();
  return {ok:true, json: async () => ({ok:true, revision: ++serverRevision})};
 };
 win.eval(source); win.document.dispatchEvent(new win.Event('DOMContentLoaded'));
 const card = win.document.querySelector('.un-card'), handle = card.querySelector('[data-drag]');
 assert.equal(card.querySelector('img'), null, 'A title must never become HTML');
 handle.dispatchEvent(new win.KeyboardEvent('keydown', {key:'ArrowRight', bubbles:true, cancelable:true}));
 handle.dispatchEvent(new win.KeyboardEvent('keydown', {key:'ArrowDown', bubbles:true, cancelable:true}));
 for (let i=0;i<8;i++) { await tick(); }
 assert.equal(calls.length, 2); assert.deepEqual(calls.map(c => c.params.revision), ['2','3']);
 assert.equal(calls[0].url, '/local/ustar/notebook.php');
 const saved = JSON.parse(calls[1].params.patch).items['10'];
 assert.equal(saved.x, 40); assert.equal(saved.y, 60);
 assert.equal(saved.width, 310);
 win.document.querySelector('[data-zoom=in]').click();
 assert.equal(win.document.querySelector('[data-zoom-label]').textContent, '110%');
 assert.equal(calls.length, 2, 'Zoom must not mutate notes');
 card.querySelector('[data-resize]').dispatchEvent(new win.KeyboardEvent('keydown', {key:'ArrowRight'}));
 for(let i=0;i<4;i++)await tick();
 assert.equal(JSON.parse(calls.at(-1).params.patch).items['10'].width,330);
 card.querySelector('[data-select]').checked=true;
 win.document.querySelector('[data-group]').click();
 for(let i=0;i<4;i++)await tick();
 const grouped=JSON.parse(calls.at(-1).params.patch);
 assert.equal(Object.keys(grouped.frames).length,1,'A frame must be serialized as an object');
 assert.ok(grouped.items['10'].frame);
 win.document.querySelector('select[data-background]').value='mint';
 win.document.querySelector('select[data-background]').dispatchEvent(new win.Event('change'));
 for(let i=0;i<4;i++)await tick();
 assert.equal(JSON.parse(calls.at(-1).params.patch).background,'mint');
 win.fetch = async () => ({ok:false, json:async () => ({ok:false, error:'Доска изменилась'})});
 handle.dispatchEvent(new win.KeyboardEvent('keydown', {key:'ArrowRight'}));
 for (let i=0;i<4;i++) { await tick(); }
 assert.match(win.document.querySelector('[data-save-status]').textContent, /Доска изменилась/);
 const before = card.dataset.x;
 handle.dispatchEvent(new win.KeyboardEvent('keydown', {key:'ArrowRight'}));
 assert.equal(card.dataset.x, before, 'A failed save must stop further drag edits');
 win.close();
 console.log('NOTEBOOK_DOM_OK escaping keyboard serial-revisions csrf endpoint zoom failure');
}
run().catch(error => {console.error(error); process.exit(1);});
