// Паритет JS↔PHP для nj506_units через страницу и публичный API.
//   npm install --no-save --package-lock=false jsdom
//   BASE=http://localhost:8080 node tests/Js/units_parity.mjs
let JSDOM;
try { ({ JSDOM } = await import('jsdom')); } catch { console.log('SKIP: jsdom не установлен'); process.exit(0); }
const base = process.env.BASE || 'http://localhost:8080';
const dom = await JSDOM.fromURL(base + '/units?c=temperature&v=32&from=f&to=c', { runScripts: 'dangerously', resources: 'usable', pretendToBeVisual: true });
const w = dom.window;
if (w.document.readyState !== 'complete') {
  await new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('страница не завершила загрузку')), 10000);
    w.addEventListener('load', () => { clearTimeout(timer); resolve(); }, { once: true });
  });
}
const prefill = w.document.getElementById('un-category').value === 'temperature'
  && w.document.getElementById('un-value').value === '32'
  && w.document.getElementById('un-from').value === 'f'
  && w.document.getElementById('un-to').value === 'c';
if (!prefill) throw new Error('GET-параметры единиц не восстановлены серверной страницей');
console.log('✔ GET-параметры восстановлены');
const unitInput = w.document.getElementById('un-value');
unitInput.value = '64';
unitInput.dispatchEvent(new w.Event('input', { bubbles: true }));
const uiResult = w.NJ506.calculate('temperature', '64', 'f', 'c');
if (w.document.getElementById('un-main').textContent.trim() !== uiResult.formatted + ' °C') throw new Error('UI единиц не обновляет результат при вводе');
console.log('✔ ввод в UI мгновенно обновляет результат');
const cases = [
  ['length', 1, 'verst', 'm'], ['mass', 1, 'pud', 'kg'], ['volume', 1, 'cup200', 'ml'],
  ['area', 1, 'ha', 'are'], ['speed', 90, 'kmh', 'mps'], ['temperature', 32, 'f', 'c'],
  ['temperature', 0, 'c', 'k'], ['pressure', 1, 'atm', 'mmhg'],
];
let failed = 0;
for (const [category, value, from, to] of cases) {
  const js = w.NJ506.calculate(category, value, from, to);
  const response = await fetch(base + '/nj506/api/calc', { method: 'POST', body: new URLSearchParams({ category, value, from, to }) });
  const php = (await response.json()).result;
  const okay = response.status === 200 && js.formatted === php.formatted && js.formula === php.formula && js.converted === php.converted;
  console.log(`${okay ? '✔' : '✘'} ${category}: ${js.formula} / ${php.formula}`);
  if (!okay) failed++;
}
console.log(`Итог: ${cases.length} сравнений, расхождений ${failed}`);
process.exit(failed ? 1 : 0);
