/**
 * GX.formToObject (admin/js/admin.js) — the serializer behind every admin form.
 * Run: node tests/js/formToObject.test.mjs   (no dependencies; exits 1 on failure)
 *
 * Keys like weights[<order_item_id>] must stay keyed by that id; only "name[]" builds a list.
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const src = readFileSync(fileURLToPath(new URL('../../admin/js/admin.js', import.meta.url)), 'utf8');
const m = src.match(/GX\.formToObject = function \(form\) \{[\s\S]*?\n {4}return fix\(out\);\n {2}\};/);
if (!m) {
  console.error('✗ could not find GX.formToObject in admin/js/admin.js');
  process.exit(1);
}

class File {}
class FormData {
  constructor(entries) { this.list = entries; }
  entries() { return this.list[Symbol.iterator](); }
}
const ctx = { GX: {}, FormData, File, Object, Array };
vm.createContext(ctx);
vm.runInContext(m[0], ctx);
// Round-trip through JSON exactly like GX.api does, then compare plain objects
const ser = (entries) => JSON.parse(JSON.stringify(ctx.GX.formToObject(entries)));

let pass = 0;
let fail = 0;
const check = (name, fn) => {
  try { fn(); pass++; console.log('  \u001b[32m✓\u001b[0m ' + name); } catch (e) { fail++; console.log('  \u001b[31m✗\u001b[0m ' + name + ' — ' + e.message); }
};

console.log('\n\u001b[1mGX.formToObject\u001b[0m');
check('ranch finalise: weights[<item id>] keeps the item id', () => {
  assert.deepEqual(ser([['id', '9'], ['weights[57]', '812.5'], ['weights[59]', '300']]), { id: '9', weights: { 57: '812.5', 59: '300' } });
});
check('first-ever order: weights[1], weights[2] do not shift', () => {
  assert.deepEqual(ser([['weights[1]', '10'], ['weights[2]', '20']]).weights, { 1: '10', 2: '20' });
});
check('stock take: counts[<stock_take_items.id>] keeps the id', () => {
  assert.deepEqual(ser([['id', '3'], ['counts[101]', '4'], ['counts[102]', '7']]).counts, { 101: '4', 102: '7' });
});
check('role permissions: perm[<role id>][] keyed by role, list of ids', () => {
  assert.deepEqual(ser([['perm[2][]', '2'], ['perm[2][]', '3'], ['perm[3][]', '2']]).perm, { 2: ['2', '3'], 3: ['2'] });
});
check('push syntax name[] still builds a list', () => {
  assert.deepEqual(ser([['species[]', '1'], ['species[]', '4'], ['media_ids[]', '12']]), { species: ['1', '4'], media_ids: ['12'] });
});
check('repeat rows (0 and 1001+) keep their order and fields', () => {
  const out = ser([['variants[0][name]', '50kg'], ['variants[0][price]', '10'], ['variants[1001][name]', '25kg']]);
  assert.deepEqual(Object.keys(out.variants), ['0', '1001']);
  assert.deepEqual(Object.values(out.variants), [{ name: '50kg', price: '10' }, { name: '25kg' }]);
});
check('string keys (content blocks) unchanged', () => {
  assert.deepEqual(ser([['blocks[hero_title_1]', 'Hi'], ['s[site_name]', 'GrazeX']]), { blocks: { hero_title_1: 'Hi' }, s: { site_name: 'GrazeX' } });
});
check('File values are skipped', () => {
  assert.deepEqual(ser([['name', 'x'], ['photo', new File()]]), { name: 'x' });
});

console.log('\n' + (fail ? `\u001b[31m${fail} failed\u001b[0m, ` : '') + `\u001b[32m${pass} passed\u001b[0m`);
process.exit(fail ? 1 : 0);
