// HTTP smoke для новых публичных модулей: страницы, API, валидация и 404.
//   BASE=http://localhost:8080 node tests/Js/modules_http_smoke.mjs
import { JSDOM } from 'jsdom';

const base = process.env.BASE || 'http://localhost:8080';
let failures = 0;

async function check(label, expected, request) {
  try {
    const response = await request;
    if (response.status !== expected) {
      console.error(`✘ ${label}: ожидался HTTP ${expected}, получен ${response.status}`);
      failures++;
      return null;
    }
    console.log(`✔ ${label}: HTTP ${response.status}`);
    return response;
  } catch (error) {
    console.error(`✘ ${label}: ${error.message}`);
    failures++;
    return null;
  }
}

const pages = [
  ['/units', 200], ['/cooking', 200], ['/cooking/wheat-flour', 200],
  ['/cooking/no-such-product', 404], ['/size', 200], ['/date-words', 200],
  ['/translit', 200], ['/inn', 200], ['/s/company', 200], ['/s/docs', 200], ['/s/meri', 200],
  ['/s/daty', 200], ['/services', 200], ['/sitemap.xml', 200],
  ['/poll', 200], ['/p/zzzzzz', 404], ['/nj520/api/stats/zzzzzz', 404],
  ['/loan', 200], ['/timezones', 200], ['/diff', 200], ['/json-decode', 200],
  // VLM-сервисы: страница открывается и без подключённой модели,
  // чужой или несуществующий разбор — честный 404.
  ['/receipt', 200], ['/meal', 200], ['/plant', 200], ['/s/foto', 200],
  ['/receipt/999999', 404], ['/meal/999999', 404], ['/plant/999999', 404],
];
const landingLinks = {
  '/s/company': ['/inn'],
  '/s/docs': ['/date-words', '/translit'],
  '/s/foto': ['/receipt', '/meal', '/plant'],
};
for (const [path, status] of pages) {
  const response = await check(path, status, fetch(base + path));
  if (!response || !landingLinks[path]) continue;
  const dom = new JSDOM(await response.text(), { url: base + path });
  for (const href of landingLinks[path]) {
    if (!dom.window.document.querySelector(`main a[href="${href}"]`)) {
      console.error(`✘ ${path}: в основном содержимом нет ссылки ${href}`);
      failures++;
    } else {
      console.log(`✔ ${path} → ${href}: ссылка есть`);
    }
  }
  dom.window.close();
}

async function postJson(label, path, fields, validate) {
  const response = await check(label, 200, fetch(base + path, {
    method: 'POST',
    body: new URLSearchParams(fields),
  }));
  if (!response) return;
  try {
    const payload = await response.json();
    if (!payload.ok || !validate(payload)) {
      console.error(`✘ ${label}: неверный JSON-результат ${JSON.stringify(payload)}`);
      failures++;
    }
  } catch (error) {
    console.error(`✘ ${label}: некорректный JSON (${error.message})`);
    failures++;
  }
}

await postJson('API units', '/nj506/api/calc', {
  category: 'temperature', value: '32', from: 'f', to: 'c',
}, data => data.result.formatted === '0');
await postJson('API cooking', '/nj507/api/convert', {
  product: 'wheat-flour', measure: 'cup200', amount: '1', direction: 'to_mass',
  from_servings: '1', to_servings: '1',
}, data => data.result.formatted === '100–128 г');
await postJson('API size', '/nj511/api/convert', {
  type: 'women', measure: '92',
}, data => data.result.sizes.RU === '46');
await postJson('API date words', '/nj513/api/convert', {
  date: '2026-09-01', end: '2026-09-05',
}, data => data.range.long === 'с первого по пятое сентября две тысячи двадцать шестого года');
await postJson('API transliteration', '/nj514/api/convert', {
  text: 'Хрущёв', standard: 'passport', direction: 'to_latin',
}, data => data.result.text === 'Khrushchev');

await postJson('API loan', '/nj647/api/calc', {
  sum: '1000000', rate: '12', term: '1', term_unit: 'years',
}, data => Math.round(data.payment) === 88849 && data.months_real === 12);
await postJson('API diff', '/nj649/api/compare', {
  left: 'цена 100 рублей', right: 'цена 200 рублей',
}, data => data.stats.changed === 1 && data.unified.includes('+цена 200 рублей'));

// Владивосток на 7 часов впереди Москвы: общее рабочее окно 9:00–11:00 МСК
await check('API timezones', 200, fetch(base + '/nj648/api/slots?p%5B%5D=moscow&p%5B%5D=vladivostok&date=2026-10-05'))
  .then(async response => {
    if (!response) return;
    const payload = await response.json();
    const window = (payload.best || [])[0];
    if (!payload.ok || !window || window.from !== '09:00' || window.to !== '11:00') {
      console.error(`✘ API timezones: окно не совпало ${JSON.stringify(payload.best)}`);
      failures++;
    } else {
      console.log('✔ API timezones: общее окно 09:00–11:00');
    }
  });

await check('API loan rejects nonsense', 422, fetch(base + '/nj647/api/calc?sum=0&rate=10&term=5'));
await check('API diff needs texts', 422, fetch(base + '/nj649/api/compare'));

// Декодер JSON: экранированный вход узнаётся, вид глазами print_r совпадает
await postJson('API json decode escaped', '/nj664/api/decode', {
  input: '{\\"a\\":1,\\"b\\":[1,2]}',
}, data => data.mode === 'slashes' && data.result.includes('"a": 1'));

await postJson('API json decode php array', '/nj664/api/decode', {
  input: '{\\"a\\":1}', mode: 'array',
}, data => data.result.includes('[a] => 1'));

await check('API json decode needs input', 422, fetch(base + '/nj664/api/decode', {
  method: 'POST', body: new URLSearchParams({ input: '' }),
}));

// Общий API VLM-сервисов: статус несуществующего прогона и приёмка без токена.
for (const service of ['receipt', 'meal', 'plant']) {
  await check(`API ${service} status 404`, 404, fetch(`${base}/${service}/api/status?id=999999`));
  await check(`API ${service} worker_result needs token`, 403,
    fetch(`${base}/${service}/api/worker_result`, { method: 'POST' }));
}

await check('API transliteration rejects GET', 405, fetch(base + '/nj514/api/convert'));
await check('API INN lookup rejects GET', 405, fetch(base + '/nj517/api/lookup'));
await check('API transliteration rejects unknown standard', 400, fetch(base + '/nj514/api/convert', {
  method: 'POST', body: new URLSearchParams({ text: 'test', standard: 'unknown', direction: 'to_latin' }),
}));

console.log(`Итог: ошибок ${failures}`);
process.exit(failures ? 1 : 0);
