// Проверяет, что ИНН сверяется локально, а запрос уходит только после явного клика.
//   BASE=http://localhost:8080 node tests/Js/inn_privacy.mjs
let JSDOM;
try { ({ JSDOM } = await import('jsdom')); } catch (error) {
  console.error('FAIL: jsdom отсутствует; выполните npm ci перед UI-тестом', error.message);
  process.exit(1);
}

const base = process.env.BASE || 'http://localhost:8080';
const response = await fetch(base + '/inn');
if (!response.ok) throw new Error(`/inn вернул HTTP ${response.status}`);
const html = await response.text();
const calls = [];
const dom = new JSDOM(html, {
  url: base + '/inn',
  runScripts: 'dangerously',
  pretendToBeVisual: true,
  beforeParse(window) {
    window.fetch = (url, options = {}) => {
      calls.push({url: String(url), options});
      return Promise.resolve({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ok: true, result: {card: {
          inn: '7707083893', ogrn: '1027700132195', short_name: '<img src=x onerror=alert(1)>',
          full_name: 'Проверочная компания', status: 'Действующая', url: '/orgs/1027700132195'
        }}})
      });
    };
  }
});
const w = dom.window, d = w.document;
const input = d.getElementById('inn-number');

function enter(value) {
  input.value = value;
  input.dispatchEvent(new w.Event('input', {bubbles: true}));
}

enter('500100732259');
if (!d.getElementById('inn-result').textContent.includes('Физлицо или ИП')) throw new Error('12 цифр не прошли локальную проверку');
if (d.getElementById('inn-lookup')) throw new Error('для 12 цифр показан удалённый поиск');
if (calls.length !== 0) throw new Error('при вводе 12-значного ИНН ушёл сетевой запрос');

enter('7707083893');
if (!d.getElementById('inn-result').textContent.includes('Контрольные цифры верны')) throw new Error('10 цифр не прошли локальную проверку');
if (!d.getElementById('inn-lookup')) throw new Error('для организации не показана отдельная кнопка ФНС');
if (calls.length !== 0) throw new Error('при простом вводе 10-значного ИНН ушёл сетевой запрос');
if (w.location.search || w.localStorage.length) throw new Error('ИНН попал в URL или localStorage');

d.getElementById('inn-lookup').click();
await new Promise(resolve => setTimeout(resolve, 0));
if (calls.length !== 1 || calls[0].url !== '/nj517/api/lookup') throw new Error('явная кнопка не вызвала ожидаемый endpoint');
const body = new URLSearchParams(calls[0].options.body);
if (body.get('inn') !== '7707083893' || !body.get('csrf')) throw new Error('POST не содержит только проверенный ИНН и CSRF');
if (w.location.search || w.localStorage.length) throw new Error('ИНН был сохранён в URL или localStorage после запроса');
if (d.querySelector('#inn-result img')) throw new Error('поле ФНС вставлено как HTML, а не экранировано');
if (!d.getElementById('inn-result').textContent.includes('Открыть карточку организации')) throw new Error('карточка ФНС не отрисована');

console.log('✔ локальная проверка не отправляет/не сохраняет ИНН; сетевой запрос только по кнопке и только для 10 цифр');
dom.window.close();
