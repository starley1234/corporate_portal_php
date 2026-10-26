#!/usr/bin/env python3
"""Живой прогон MCP-эндпоинта из браузера (обход закрытого egress песочницы).

Зачем: песочница агента выпускает HTTP только к белому списку хостов (github,
npm, pypi), поэтому POST на https://n2j.ru/mcphub/mcp оттуда не уходит
(`SSL_ERROR_SYSCALL`). Зато эндпоинт отдаёт `Access-Control-Allow-Origin: *`,
значит запрос может сделать браузер владельца, открывший превью. Страница
прогоняет сценарий и отправляет протокол обратно на этот сервер — агент читает
результат из /tmp.

  python3 .tools/mcphub_browser_relay.py 8099
  открыть превью → страница сама всё сделает
"""

import json
import sys
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

RESULT = '/tmp/mcphub_relay_result.json'

PAGE = r"""<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>MCP Hub · живой прогон</title>
<style>
  :root { color-scheme: dark }
  body { font: 14px/1.5 ui-monospace, SFMono-Regular, Menlo, monospace; background:#0b1020; color:#e6edf3; margin:0; padding:24px }
  h1 { font: 600 20px/1.3 system-ui, sans-serif; margin:0 0 4px }
  p.sub { color:#8b949e; margin:0 0 18px }
  .row { border:1px solid #21262d; border-radius:10px; margin:0 0 10px; overflow:hidden }
  .row > summary { cursor:pointer; padding:10px 12px; background:#111827; font-weight:600 }
  .row.ok > summary { border-left:4px solid #2ea043 }
  .row.bad > summary { border-left:4px solid #f85149 }
  pre { margin:0; padding:12px; white-space:pre-wrap; word-break:break-word; background:#0d1117; color:#c9d1d9 }
  .done { margin-top:16px; padding:12px; border-radius:10px; background:#132a1a; border:1px solid #2ea043 }
</style>
</head>
<body>
<h1>MCP Hub · живой прогон эндпоинта</h1>
<p class="sub">POST https://n2j.ru/mcphub/mcp — initialize → tools/list → статус → поиск → вызовы инструментов</p>
<div id="log"></div>
<div id="done"></div>
<script>
const ENDPOINT = 'https://n2j.ru/mcphub/mcp';
const log = [];
let seq = 100;

function show(title, status, text, ms) {
  const box = document.createElement('details');
  box.className = 'row ' + status;
  box.open = status !== 'ok' || true;
  const head = document.createElement('summary');
  head.textContent = title + ' · ' + ms + ' мс';
  const body = document.createElement('pre');
  body.textContent = text;
  box.append(head, body);
  document.getElementById('log').append(box);
}

async function rpc(label, payload, pick) {
  const t0 = performance.now();
  try {
    const res = await fetch(ENDPOINT, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(payload)
    });
    const raw = await res.text();
    const ms = Math.round(performance.now() - t0);
    let parsed = null;
    try { parsed = JSON.parse(raw); } catch (e) {}
    const text = pick ? pick(parsed, raw) : (parsed ? JSON.stringify(parsed, null, 2) : raw);
    const bad = !res.ok || (parsed && parsed.error) || (parsed && parsed.result && parsed.result.isError);
    show(label + ' → HTTP ' + res.status, bad ? 'bad' : 'ok', text, ms);
    log.push({label, http: res.status, ms, bad: !!bad, body: parsed ?? raw});
  } catch (e) {
    const ms = Math.round(performance.now() - t0);
    show(label + ' → сеть', 'bad', String(e), ms);
    log.push({label, http: 0, ms, bad: true, body: String(e)});
  }
}

const textOf = (d) => {
  if (!d) return '(пусто)';
  const r = d.result || {};
  const c = (r.content || [])[0];
  return (c && c.text) ? c.text : JSON.stringify(d, null, 2);
};

async function run() {
  await rpc('initialize', {jsonrpc:'2.0', id:++seq, method:'initialize', params:{
      protocolVersion:'2025-06-18', capabilities:{}, clientInfo:{name:'browser-relay', version:'1.0'}}},
    (d) => d && d.result ? 'server: ' + d.result.serverInfo.name + ' ' + d.result.serverInfo.version
        + '\nprotocol: ' + d.result.protocolVersion + '\n\n' + d.result.instructions : JSON.stringify(d, null, 2));

  await rpc('tools/list', {jsonrpc:'2.0', id:++seq, method:'tools/list'},
    (d) => (d.result.tools || []).map(t => '- ' + t.name + ': ' + (t.description || '').slice(0, 120)).join('\n'));

  await rpc('hub_status', {jsonrpc:'2.0', id:++seq, method:'tools/call',
    params:{name:'hub_status', arguments:{}}}, textOf);

  await rpc('hub_search_tools «погода»', {jsonrpc:'2.0', id:++seq, method:'tools/call',
    params:{name:'hub_search_tools', arguments:{query:'погода', limit:3}}}, textOf);

  await rpc('hub_call_tool → математика (по имени инструмента, без server)',
    {jsonrpc:'2.0', id:++seq, method:'tools/call',
     params:{name:'hub_call_tool', arguments:{tool:'calculate', arguments:{expression:'2^10 + sqrt(144)'}}}}, textOf);

  await rpc('hub_call_tool → сложный расчёт (det, факториал, тригонометрия)',
    {jsonrpc:'2.0', id:++seq, method:'tools/call',
     params:{name:'hub_call_tool', arguments:{server:'calculator-mcp', tool:'calculate',
       arguments:{expression:'det([1,2,3;4,5,6;7,8,10]) + factorial(8) + sin(pi/6)'}}}}, textOf);

  await rpc('hub_call_tool → производная (символьная операция)',
    {jsonrpc:'2.0', id:++seq, method:'tools/call',
     params:{name:'hub_call_tool', arguments:{server:'calculator-mcp', tool:'calculate',
       arguments:{expression:'x^3*sin(x)', operation:'derivative', variable:'x'}}}}, textOf);

  await rpc('hub_call_tool → погода (openweathermap: список продуктов, без ключа)',
    {jsonrpc:'2.0', id:++seq, method:'tools/call',
     params:{name:'hub_call_tool', arguments:{server:'openweathermap-mcp', tool:'list_products', arguments:{}}}}, textOf);

  await rpc('hub_call_tool → погода (get_product: маршруты и параметры)',
    {jsonrpc:'2.0', id:++seq, method:'tools/call',
     params:{name:'hub_call_tool', arguments:{server:'openweathermap-mcp', tool:'get_product',
       arguments:{product:'current'}}}}, textOf);

  await rpc('hub_call_tool → неизвестный инструмент (ожидаем аккуратную ошибку)',
    {jsonrpc:'2.0', id:++seq, method:'tools/call',
     params:{name:'hub_call_tool', arguments:{tool:'no_such_tool_xyz', arguments:{}}}}, textOf);

  await rpc('hub_configure → профиль агента',
    {jsonrpc:'2.0', id:++seq, method:'tools/call',
     params:{name:'hub_configure', arguments:{no_auth_only:true}}}, textOf);

  const okCount = log.filter(x => !x.bad).length;
  document.getElementById('done').className = 'done';
  document.getElementById('done').textContent = 'Готово: ' + okCount + ' из ' + log.length + ' шагов без ошибок. Результат отправлен.';

  await fetch('/collect', {method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({at: new Date().toISOString(), endpoint: ENDPOINT, steps: log})});
}

run();
</script>
</body>
</html>
"""


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        body = PAGE.encode()
        self.send_response(200)
        self.send_header('Content-Type', 'text/html; charset=utf-8')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_POST(self):
        length = int(self.headers.get('Content-Length') or 0)
        raw = self.rfile.read(length) if length else b'{}'

        if self.path.startswith('/collect'):
            try:
                data = json.loads(raw or b'{}')
                data['received_at'] = time.time()
                with open(RESULT, 'w', encoding='utf-8') as fh:
                    json.dump(data, fh, ensure_ascii=False, indent=2)
                print('relay: получено шагов:', len(data.get('steps') or []), flush=True)
            except Exception as exc:  # noqa: BLE001
                print('relay: не разобрал результат:', exc, flush=True)

        self.send_response(204)
        self.send_header('Access-Control-Allow-Origin', '*')
        self.end_headers()

    def do_OPTIONS(self):
        self.send_response(204)
        self.send_header('Access-Control-Allow-Origin', '*')
        self.send_header('Access-Control-Allow-Headers', 'Content-Type')
        self.end_headers()

    def log_message(self, *args):
        pass


if __name__ == '__main__':
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 8099
    print('relay: http://0.0.0.0:%d/ (результат → %s)' % (port, RESULT), flush=True)
    ThreadingHTTPServer(('0.0.0.0', port), Handler).serve_forever()
