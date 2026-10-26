// Паритет JS↔PHP для nj514_translit; ввод остаётся локальным для клиента.
//   npm install --no-save --package-lock=false jsdom
//   BASE=http://localhost:8080 node tests/Js/translit_parity.mjs
let JSDOM;
try { ({ JSDOM } = await import('jsdom')); } catch { console.log('SKIP: jsdom не установлен'); process.exit(0); }
const base = process.env.BASE || 'http://localhost:8080';
const dom = await JSDOM.fromURL(base + '/translit', { runScripts: 'dangerously', resources: 'usable', pretendToBeVisual: true });
const w = dom.window;
if (w.document.readyState !== 'complete') {
  await new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('страница не завершила загрузку')), 10000);
    w.addEventListener('load', () => { clearTimeout(timer); resolve(); }, { once: true });
  });
}
const translitInput = w.document.getElementById('tl-input');
translitInput.value = 'Хрущёв';
translitInput.dispatchEvent(new w.Event('input', { bubbles: true }));
if (w.document.getElementById('tl-output').value !== 'Khrushchev' || w.location.search !== '') throw new Error('UI транслитерации неверно конвертирует или сохраняет текст в URL');
console.log('✔ UI работает локально, без записи текста в URL');
const cases = [
  ['Хрущёв', 'passport', 'to_latin'], ['цена и царь', 'gost_b', 'to_latin'],
  ['Иванов Иван', 'icao', 'to_latin'], ['E I', 'passport', 'to_cyrillic'], ['shhuka', 'gost_b', 'to_cyrillic'],
  ['😀 E', 'passport', 'to_cyrillic'],
];
let failed = 0;
for (const [text, standard, direction] of cases) {
  const js = w.NJ514.convert(text, standard, direction);
  const response = await fetch(base + '/nj514/api/convert', { method: 'POST', body: new URLSearchParams({ text, standard, direction }) });
  const php = (await response.json()).result;
  const okay = response.status === 200 && js.text === php.text && JSON.stringify(js.ambiguities) === JSON.stringify(php.ambiguities);
  console.log(`${okay ? '✔' : '✘'} ${standard}/${direction}: ${js.text} / ${php.text}`);
  if (!okay) failed++;
}
console.log(`Итог: ${cases.length} сравнений, расхождений ${failed}`);
process.exit(failed ? 1 : 0);
