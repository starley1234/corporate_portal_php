// Паритет PHP↔JS для nj518_percent: на каждом режиме жмём «пример» в странице
// (JS-зеркало формул) и сверяем главный результат и формулу с API (PHP-истина).
//
//   PORT=8080 bash bin/serve &              # сайт запущен
//   npm i --no-save jsdom                    # один раз (в закрытой сети — из npm-зеркала)
//   BASE=http://localhost:8080 node tests/Js/percent_parity.mjs
//
// Без jsdom тест тихо пропускается (exit 0), как и прочие опциональные проверки.
let JSDOM;
try { ({ JSDOM } = await import('jsdom')); } catch (e) { console.log('SKIP: jsdom не установлен'); process.exit(0); }

const base = process.env.BASE || 'http://localhost:8080';
const dom = await JSDOM.fromURL(base + '/percent', { runScripts: 'dangerously', resources: 'usable', pretendToBeVisual: true });
const w = dom.window, d = w.document;
w.fetch = (u, o) => fetch(new URL(u, base), o);
// fromURL() resolves after the document response, not necessarily after its inline
// calculator script has installed event handlers. Wait for that public test hook
// instead of racing page load, especially when parity suites run concurrently.
const readyUntil = Date.now() + 10_000;
while (!w.NJ518 && Date.now() < readyUntil) await new Promise(r => setTimeout(r, 25));
if (!w.NJ518) {
    console.error('✘ калькулятор процентов не инициализировался за 10 секунд');
    dom.window.close();
    process.exit(1);
}

let bad = 0, n = 0;
for (const btn of d.querySelectorAll('.pc-mode')) {
    const mode = btn.getAttribute('data-mode');
    btn.click();
    d.getElementById('pc-example').click();
    const a = d.getElementById('pc-a').value, b = d.getElementById('pc-b').value;
    const js = { main: d.getElementById('pc-main').textContent.trim(), formula: d.getElementById('pc-formula').textContent.trim() };
    const res = await fetch(base + '/nj518/api/calc', { method: 'POST', body: new URLSearchParams({ mode, a, b }) });
    const php = (await res.json()).result || {};
    const phpMain = (php.main + (php.unit ? ' ' + php.unit : '')).trim();
    n++;
    if (js.main !== phpMain || js.formula !== php.formula) {
        bad++;
        console.log(`✘ ${mode} (${a}; ${b}): JS «${js.main}» / «${js.formula}»  ≠  PHP «${phpMain}» / «${php.formula}»`);
    } else {
        console.log(`✔ ${mode}: ${js.main}`);
    }
}
console.log(`Итог: режимов ${n}, расхождений ${bad}`);
process.exit(bad ? 1 : 0);
