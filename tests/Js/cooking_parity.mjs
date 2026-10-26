// Паритет JS↔PHP для nj507_cooking: диапазоны продуктов и пересчёт форм.
//   npm install --no-save --package-lock=false jsdom
//   BASE=http://localhost:8080 node tests/Js/cooking_parity.mjs
let JSDOM;
try { ({ JSDOM } = await import('jsdom')); } catch { console.log('SKIP: jsdom не установлен'); process.exit(0); }
const base = process.env.BASE || 'http://localhost:8080';
const dom = await JSDOM.fromURL(base + '/cooking/wheat-flour', { runScripts: 'dangerously', resources: 'usable', pretendToBeVisual: true });
const w = dom.window;
if (w.document.readyState !== 'complete') {
  await new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('страница не завершила загрузку')), 10000);
    w.addEventListener('load', () => { clearTimeout(timer); resolve(); }, { once: true });
  });
}
const productField = w.document.getElementById('cook-product');
if (!productField.disabled || productField.value !== 'wheat-flour'
  || !w.document.getElementById('cook-main').textContent.includes('100–128')) {
  throw new Error('SEO-страница продукта не восстановила фиксированный продукт и результат');
}
console.log('✔ карточка продукта восстановлена');
const amountInput = w.document.getElementById('cook-amount');
amountInput.value = '2';
amountInput.dispatchEvent(new w.Event('input', { bubbles: true }));
if (w.document.getElementById('cook-main').textContent.trim() !== w.NJ507.calculate('wheat-flour', 'cup200', '2', 'to_mass', 1, 1).formatted) throw new Error('UI кулинарии не обновляет результат при вводе');
console.log('✔ ввод в UI мгновенно обновляет результат');
const cases = [
  ['wheat-flour', 'cup200', 1, 'to_mass', 1, 1],
  ['granulated-sugar', 'cup250', 1, 'to_mass', 2, 4],
  ['water', 'tbsp', 3, 'to_mass', 1, 1],
  ['honey', 'tsp', 2, 'to_mass', 1, 1],
  ['water', 'cup200', 200, 'to_measure', 1, 1],
];
let failed = 0;
for (const [product, measure, amount, direction, from_servings, to_servings] of cases) {
  const js = w.NJ507.calculate(product, measure, amount, direction, from_servings, to_servings);
  const response = await fetch(base + '/nj507/api/convert', { method: 'POST', body: new URLSearchParams({ product, measure, amount, direction, from_servings, to_servings }) });
  const php = (await response.json()).result;
  const okay = response.status === 200 && js.formatted === php.formatted && js.formula === php.formula && js.min === php.min && js.max === php.max;
  console.log(`${okay ? '✔' : '✘'} ${product}/${measure}: ${js.formatted} / ${php.formatted}`);
  if (!okay) failed++;
}
const jsForm = w.NJ507.formFactor('round', 24, 0, 'square', 20, 0);
const formResponse = await fetch(base + '/nj507/api/form', { method: 'POST', body: new URLSearchParams({ from_shape: 'round', from_a: 24, from_b: 0, to_shape: 'square', to_a: 20, to_b: 0 }) });
const phpForm = (await formResponse.json()).result;
const formOkay = formResponse.status === 200 && jsForm.factor === phpForm.factor;
console.log(`${formOkay ? '✔' : '✘'} форма Ø24 → 20×20: ${jsForm.factor} / ${phpForm.factor}`);
if (!formOkay) failed++;
console.log(`Итог: ${cases.length + 1} сравнений, расхождений ${failed}`);
process.exit(failed ? 1 : 0);
