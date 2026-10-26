// Паритет JS↔PHP для nj511_size по всем типам мерок.
//   npm install --no-save --package-lock=false jsdom
//   BASE=http://localhost:8080 node tests/Js/size_parity.mjs
let JSDOM;
try { ({ JSDOM } = await import('jsdom')); } catch { console.log('SKIP: jsdom не установлен'); process.exit(0); }
const base = process.env.BASE || 'http://localhost:8080';
const dom = await JSDOM.fromURL(base + '/size?type=women&v=92', { runScripts: 'dangerously', resources: 'usable', pretendToBeVisual: true });
const w = dom.window;
if (w.document.readyState !== 'complete') {
  await new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('страница не завершила загрузку')), 10000);
    w.addEventListener('load', () => { clearTimeout(timer); resolve(); }, { once: true });
  });
}
if (w.document.getElementById('size-type').value !== 'women'
  || w.document.getElementById('size-measure').value !== '92'
  || w.document.getElementById('size-main').textContent.trim() !== '46') {
  throw new Error('GET-параметры подбора размера не восстановлены');
}
console.log('✔ GET-параметры восстановлены');
const sizeInput = w.document.getElementById('size-measure');
sizeInput.value = '94';
sizeInput.dispatchEvent(new w.Event('input', { bubbles: true }));
const sizeUi = w.NJ511.calculate('women', '94');
if (w.document.getElementById('size-main').textContent.trim() !== Object.values(sizeUi.sizes)[0]) throw new Error('UI размеров не обновляет результат при вводе');
console.log('✔ ввод в UI мгновенно обновляет результат');
const cases = [['women', 92], ['men', 100], ['children', 122], ['shoes', 25.5], ['gloves', 20.3], ['rings', 18]];
let failed = 0;
for (const [type, measure] of cases) {
  const js = w.NJ511.calculate(type, measure);
  const response = await fetch(base + '/nj511/api/convert', { method: 'POST', body: new URLSearchParams({ type, measure }) });
  const php = (await response.json()).result;
  const okay = response.status === 200 && js.matched_measure === php.matched_measure && JSON.stringify(js.sizes) === JSON.stringify(php.sizes) && JSON.stringify(js.details) === JSON.stringify(php.details);
  console.log(`${okay ? '✔' : '✘'} ${type} ${measure}: ${JSON.stringify(js.sizes)} / ${JSON.stringify(php.sizes)}`);
  if (!okay) failed++;
}
console.log(`Итог: ${cases.length} сравнений, расхождений ${failed}`);
process.exit(failed ? 1 : 0);
