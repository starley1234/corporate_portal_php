// Паритет JS↔PHP для nj513_datewords: одиночные даты, диапазоны и високосный день.
//   npm install --no-save --package-lock=false jsdom
//   BASE=http://localhost:8080 node tests/Js/datewords_parity.mjs
let JSDOM;
try { ({ JSDOM } = await import('jsdom')); } catch { console.log('SKIP: jsdom не установлен'); process.exit(0); }
const base = process.env.BASE || 'http://localhost:8080';
const dom = await JSDOM.fromURL(base + '/date-words?date=2026-09-01&end=2026-09-05', { runScripts: 'dangerously', resources: 'usable', pretendToBeVisual: true });
const w = dom.window;
if (w.document.readyState !== 'complete') {
  await new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('страница не завершила загрузку')), 10000);
    w.addEventListener('load', () => { clearTimeout(timer); resolve(); }, { once: true });
  });
}
if (w.document.getElementById('dw-date').value !== '2026-09-01'
  || w.document.getElementById('dw-end').value !== '2026-09-05'
  || w.document.getElementById('dw-range').textContent.trim() !== 'с первого по пятое сентября две тысячи двадцать шестого года') {
  throw new Error('GET-даты или диапазон не восстановлены серверной страницей');
}
console.log('✔ GET-даты и диапазон восстановлены');
const dateInput = w.document.getElementById('dw-date'), endInput = w.document.getElementById('dw-end');
dateInput.value = '2026-09-21'; endInput.value = '2026-09-30';
endInput.dispatchEvent(new w.Event('input', { bubbles: true }));
const dateUi = w.NJ513.convert(dateInput.value), rangeUi = w.NJ513.range(dateInput.value, endInput.value);
if (w.document.getElementById('dw-long').textContent.trim() !== dateUi.long
  || w.document.getElementById('dw-range').textContent.trim() !== rangeUi.long) throw new Error('UI дат не обновляет дату и диапазон');
console.log('✔ ввод в UI мгновенно обновляет дату и диапазон');
const dates = ['2026-09-01', '2026-09-21', '2024-02-29', '2000-05-05', '1999-12-31'];
let failed = 0;
for (const date of dates) {
  const js = w.NJ513.convert(date);
  const response = await fetch(base + '/nj513/api/convert', { method: 'POST', body: new URLSearchParams({ date }) });
  const php = (await response.json()).date;
  const okay = response.status === 200 && js.long === php.long && js.quoted === php.quoted && js.numeric === php.numeric;
  console.log(`${okay ? '✔' : '✘'} ${date}: ${js.long} / ${php.long}`);
  if (!okay) failed++;
}
const jsRange = w.NJ513.range('2026-12-31', '2027-01-02');
const rangeResponse = await fetch(base + '/nj513/api/convert', { method: 'POST', body: new URLSearchParams({ date: '2026-12-31', end: '2027-01-02' }) });
const phpRange = (await rangeResponse.json()).range;
const rangeOkay = rangeResponse.status === 200 && jsRange.long === phpRange.long;
console.log(`${rangeOkay ? '✔' : '✘'} переход года: ${jsRange.long} / ${phpRange.long}`);
if (!rangeOkay) failed++;
console.log(`Итог: ${dates.length + 1} сравнений, расхождений ${failed}`);
process.exit(failed ? 1 : 0);
