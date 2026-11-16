#!/usr/bin/env python3
"""
Панель статуса среды разработки PRL (для песочницы/preview).

Показывает живое состояние PostgreSQL (очередь prl120_jobs), состав
инфраструктуры и команды разворачивания. Это НЕ приложение — приложение
на PHP 8.4 поднимается через docker compose / bin/serve (см. страницу).

Запуск: python3 bin/status-server.py [порт]
"""

import html
import json
import os
import re
import subprocess
import sys
from http.server import BaseHTTPRequestHandler, HTTPServer

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PGDATA = "/opt/pgdata"
DSN = "postgres://prl:prl@127.0.0.1:5432/prl"
PSQL = "/usr/local/lib/python3.11/dist-packages/pgserver/pginstall/bin/psql"


def psql(sql: str):
    """Запрос к БД; сервер при необходимости будится pgserver'ом."""
    try:
        import pgserver
        pgserver.get_server(PGDATA).ensure_postgres_running()
        r = subprocess.run([PSQL, DSN, "-At", "-F", "|", "-c", sql],
                           capture_output=True, text=True, timeout=15)
        if r.returncode == 0:
            return [line.split("|") for line in r.stdout.strip().splitlines() if line]
        return None
    except Exception:
        return None


def page() -> str:
    # --- PostgreSQL ---------------------------------------------------------
    ver = psql("SHOW server_version;")
    pg_ok = ver is not None
    stats = psql("""
        SELECT status, COUNT(*) FROM prl_120_jobs GROUP BY status ORDER BY status;
    """) if pg_ok else None
    last_jobs = psql("""
        SELECT id, channel, status, COALESCE(claimed_by,'—'),
               to_char(created_at, 'DD.MM HH24:MI:SS')
        FROM prl_120_jobs ORDER BY id DESC LIMIT 8;
    """) if pg_ok else None
    modules = sorted(
        d for d in os.listdir(os.path.join(ROOT, "application/modules"))
        if os.path.isdir(os.path.join(ROOT, "application/modules", d))
    ) if os.path.isdir(os.path.join(ROOT, "application/modules")) else []

    def esc(x):
        return html.escape(str(x))

    stat_cells = "".join(
        f"<div class='stat'><b>{esc(s[1])}</b><span>{esc(s[0])}</span></div>"
        for s in (stats or [])
    ) or "<div class='stat'><b>0</b><span>задач</span></div>"

    job_rows = "".join(
        "<tr>" + "".join(f"<td>{esc(c)}</td>" for c in row) + "</tr>"
        for row in (last_jobs or [])
    ) or "<tr><td colspan='5' class='muted'>Очередь пуста</td></tr>"

    mod_rows = "".join(f"<code>{esc(m)}</code>" for m in modules)

    pg_block = f"""
      <p>PostgreSQL <b>{esc(ver[0][0])}</b> — работает (127.0.0.1:5432, БД prl).</p>
      <div class='stats'>{stat_cells}</div>
      <table><tr><th>id</th><th>канал</th><th>статус</th><th>воркер</th><th>создана</th></tr>{job_rows}</table>
    """ if pg_ok else "<p class='bad'>PostgreSQL недоступен — python3 bin/status-server.py перезапустит при обращении.</p>"

    php_note = """
      <p><b>PHP 8.4 в этой песочнице недоступен</b>: сеть песочницы пропускает
      только GitHub и PyPI — репозитории Debian и php.net закрыты. Всё для
      разворачивания сохранено в проекте, на машине с обычной сетью среда
      поднимается двумя командами:</p>
      <pre>bash bin/restore-env.sh --serve    # нативно: PHP 8.4 (Sury) + PostgreSQL + миграции + админ
# или
docker compose up -d               # nginx + php-fpm 8.4 + postgres 17 + redis</pre>
      <p>Проверить PHP-файлы локально можно после разворачивания:
      <code>make lint && make test</code>.</p>
    """

    return f"""<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">
<title>PRL — статус среды</title>
<style>
  body {{ font-family: ui-sans-serif, system-ui, sans-serif; background:#0f172a; color:#e2e8f0; margin:0; }}
  .wrap {{ max-width: 880px; margin: 0 auto; padding: 32px 20px 60px; }}
  h1 {{ font-size: 22px; }} h2 {{ font-size: 16px; color:#93c5fd; margin-top: 34px; }}
  a {{ color:#60a5fa; }}
  .card {{ background:#1e293b; border:1px solid #334155; border-radius:12px; padding:18px 22px; margin:14px 0; }}
  .ok {{ color:#4ade80; font-weight:600; }} .bad {{ color:#f87171; font-weight:600; }}
  .muted {{ color:#64748b; }}
  table {{ border-collapse: collapse; width: 100%; margin-top: 10px; font-size: 13px; }}
  th, td {{ text-align:left; padding:6px 10px; border-bottom:1px solid #334155; }}
  th {{ color:#94a3b8; font-weight:600; }}
  .stats {{ display:flex; gap:14px; margin:14px 0; flex-wrap:wrap; }}
  .stat {{ background:#0f172a; border:1px solid #334155; border-radius:10px; padding:10px 16px; text-align:center; }}
  .stat b {{ display:block; font-size:20px; color:#4ade80; }}
  .stat span {{ color:#94a3b8; font-size:12px; }}
  code {{ background:#0f172a; padding:2px 6px; border-radius:6px; font-size:12.5px; margin:2px; display:inline-block; }}
  pre {{ background:#0f172a; padding:12px 14px; border-radius:10px; overflow-x:auto; font-size:12.5px; border:1px solid #334155; }}
  ul {{ margin: 6px 0; padding-left: 20px; }} li {{ margin: 3px 0; }}
</style></head><body><div class="wrap">
<h1>🚀 PRL — микромодульный монолит: статус среды</h1>

<div class="card">
  <h2>PostgreSQL + очередь задач (prl120_jobs)</h2>
  {pg_block}
</div>

<div class="card">
  <h2>PHP 8.4</h2>
  {php_note}
</div>

<div class="card">
  <h2>Модули платформы ({len(modules)})</h2>
  <p>{mod_rows}</p>
</div>

<div class="card">
  <h2>Что создано в этой загрузке</h2>
  <ul>
    <li><b>Инфраструктура:</b> docker-compose.yml (nginx, php-fpm 8.4, postgres 17 + pgvector, redis, воркеры, adminer, mailpit), docker/php, docker/nginx, docker/postgres, docker/worker</li>
    <li><b>Скрипты:</b> bin/restore-env.sh (полное разворачивание), bin/setup.sh (.env + секреты), bin/console, bin/serve, bin/test</li>
    <li><b>Очередь:</b> модуль <code>prl120_jobs</code> — таблицы, Service_Prl120_JobQueue, HTTP-API для воркеров, cron-задача RequeueStale</li>
    <li><b>Python-воркеры:</b> workers/ — asyncio-каркас, PgQueue (LISTEN/NOTIFY) и HttpQueue, обработчики echo / embeddings.index / llm.summarize. <span class="ok">Проверен end-to-end на живой БД ✓</span></li>
    <li><b>LLM/эмбеддинги:</b> в config/llm.php секция embedding + провайдер embedding_server (свой эндпоинт EMBEDDING_API_URL)</li>
    <li><b>Для агентов:</b> корневой AGENTS.md + памятка AGENTS.md в каждом модуле, docs/ (ARCHITECTURE, MODULE_GUIDE, WORKERS, FRONTEND, ENVIRONMENT), генератор модулей module:scaffold</li>
    <li><b>Тесты:</b> phpunit.xml.dist, tests/ (дымовые), Makefile: lint / test / doctor / migrate / fresh</li>
  </ul>
</div>

<div class="card">
  <h2>Прототип фронтенда</h2>
  <p>Левая панель навигации (уже внедрена в шаблон): <a href="/prototype"><b>/prototype</b></a>, калькулятор НДС: <a href="/prototype/vat"><b>/prototype/vat</b></a> — тема, сворачивание, мобильный drawer.</p>
</div>

<div class="card">
  <h2>Документация</h2>
  <ul>
    <li>README.md — быстрый старт</li>
    <li>docs/ARCHITECTURE.md — архитектура и дорожная карта</li>
    <li>docs/MODULE_GUIDE.md — как создавать модули prlXXX</li>
    <li>docs/WORKERS.md — асинхронные задачи и воркеры</li>
    <li>docs/FRONTEND.md — фронтенд</li>
    <li>docs/ENVIRONMENT.md — окружение и переменные</li>
  </ul>
</div>

<p class="muted">Панель — служебная (bin/status-server.py), в docker-стеке не используется.</p>
</div></body></html>"""


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        # Прототип левой панели навигации (статическая страница)
        path0 = self.path.split('?')[0]
        if path0 == '/prototype' or path0.startswith('/prototype/'):
            # /prototype            → sidebar.html
            # /prototype/<имя>.html → docs/prototypes/<имя>.html (только имя, без путей)
            name = path0[len('/prototype/'):].strip('/') or 'sidebar'
            if not re.fullmatch(r'[A-Za-z0-9_-]+', name):
                name = 'sidebar'
            proto = os.path.join(ROOT, 'docs', 'prototypes', name + '.html')
            try:
                body = open(proto, encoding='utf-8').read().encode('utf-8')
            except OSError:
                body = "<h1>Прототип не найден</h1>".encode("utf-8")
            self.send_response(200)
            self.send_header("Content-Type", "text/html; charset=utf-8")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            return

        body = page().encode("utf-8")
        self.send_response(200)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *args):
        pass


if __name__ == "__main__":
    port = int(sys.argv[1]) if len(sys.argv) > 1 else int(os.environ.get("PORT", "8080"))
    server = HTTPServer(("0.0.0.0", port), Handler)
    print(f"status-server: http://0.0.0.0:{port}")
    server.serve_forever()
