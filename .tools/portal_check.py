#!/usr/bin/env python3
"""Контракты PRX без запущенных PHP/БД. Не заменяют runtime-тесты."""
import ast
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
errors = []

def check(condition, message):
    if not condition:
        errors.append(message)

registry = (ROOT / 'application/config/modules.php').read_text(encoding='utf-8')
bootstrap = (ROOT / 'application/bootstrap.php').read_text(encoding='utf-8')
# Проверяем базовый реестр и явный блок стендовых модулей в bootstrap.
registered = re.findall(r"'([^']+)' => (APP|MOD)PATH\.'([^']+)'", registry + '\n' + bootstrap)
check(bool(registered), 'Пустой реестр модулей')
for name, kind, relative in registered:
    path = ROOT / ('application' if kind == 'APP' else 'modules') / relative
    check(path.is_dir(), f'Не найден модуль {name}: {path}')
check(len({name for name, _, _ in registered}) == len(registered), 'Дубликаты ключей модулей')
check(registry.index("'prx208_events'") < registry.index("'prx120_jobs'"), 'Шина должна предшествовать очереди')
check((ROOT / 'docs/architecture/model/meta.yaml').is_file(), 'Нет модели docs/architecture/model/meta.yaml')
check((ROOT / 'docs/architecture/dist/architecture.html').is_file(), 'Нет собранной docs/architecture/dist/architecture.html')
check((ROOT / 'docs/architecture/dist/architecture.json').is_file(), 'Нет собранной docs/architecture/dist/architecture.json')
check("config/modules.php" in bootstrap, 'Bootstrap обходит базовый реестр')

plan = (ROOT / 'application/modules/prx112_portal_plan/config/portal.php').read_text(encoding='utf-8')
plan_entries = re.findall(r"'module' => '(prx\d+_[a-z_]+)',\s*'title' => '[^']+',\s*'status' => ('(?:planned|active|pilot|archived)'|\$docflow_status)", plan)
plan_entries = [(name, status.strip("'")) for name, status in plan_entries]
planned = [name for name, _status in plan_entries]
active_services = [name for name, status in plan_entries if status == 'active']
archived_services = [name for name, status in plan_entries if status == 'archived']
check(len(planned) == 55, 'План должен содержать 55 сервисов')
check(len(set(planned)) == 55, 'Дубли в плане')
check(len({re.search(r'\d+', name)[0] for name in planned}) == 55, 'Дубли ID в плане')
for name, status in plan_entries:
    exists = (ROOT / 'application/modules' / name).exists()
    registered_service = name in {entry[0] for entry in registered}
    if status in {'active', 'pilot'}:
        check(exists and registered_service, f'Активный сервис не реализован/не подключён: {name}')
    elif status == '$docflow_status':
        check(exists and registered_service, f'Нет условного модуля стенда: {name}')
    elif status == 'archived':
        # Архивная папка удалена: архивный сервис — это запись в плане,
        # которая не должна существовать в коде и не должна быть подключена.
        check(not exists and not registered_service, f'Архивный сервис подключён: {name}')
    else:
        check(not exists and not registered_service, f'Planned-сервис ошибочно подключён: {name}')

old_prefix = re.compile(r'(?<![a-zA-Z0-9])(?:n2j|nj(?=[0-9_X])|erp(?=[0-9_X]))', re.I)
for folder in ['application', 'workers', 'bin', 'tests']:
    for path in (ROOT / folder).rglob('*'):
        if not path.is_file() or any(x in path.parts for x in ['__pycache__', 'logs', 'cache', 'sessions']):
            continue
        if path.suffix not in {'.php', '.py', '.md', '.txt', '.toml'}:
            continue
        check(not old_prefix.search(path.name), f'Старый префикс в имени {path.relative_to(ROOT)}')
        check(not old_prefix.search(path.read_text(encoding='utf-8')), f'Старый префикс в {path.relative_to(ROOT)}')

# Autoload requires a single declaration per class (including across renamed modules).
classes = {}
for path in (ROOT / 'application').rglob('*.php'):
    # Scaffold contains class declarations inside strings, not actual loaded classes.
    if path.name == 'Scaffold.php' or 'PostgreSQL_PDO' in path.parts:
        continue
    for name in re.findall(r'^\s*(?:abstract\s+|final\s+)?class\s+(\w+)\s', re.sub(r'/\*.*?\*/', '', path.read_text(encoding='utf-8'), flags=re.S), re.M):
        check(name.lower() not in classes, f'Дубликат PHP-класса {name}: {path}')
        classes[name.lower()] = path

# Пакет обработчика называется как модуль-владелец (handlers/prx395_document_extract/),
# проба пера — handlers/<модуль>/. Канал — модуль, импортированный в реестре
# handlers/__init__.py; остальные файлы пакета — его помощники без CHANNEL.
handlers = ROOT / 'workers/prx_workers/handlers'
channel_modules = {}  # путь модуля канала → имя импорта
for imp in re.findall(r'^\s*from \.(\w+) import ([\w, ]+)' , (handlers / '__init__.py').read_text(encoding='utf-8'), re.M):
    package = imp[0]
    for part in [part.strip() for part in imp[1].split(',') if part.strip()]:
        name = part.split(' as ')[0].strip()
        channel_modules[handlers / package / f'{name}.py'] = name
channel_names = []
for path in (ROOT / 'workers/prx_workers').rglob('*.py'):
    try:
        ast.parse(path.read_text(encoding='utf-8'), filename=str(path))
    except SyntaxError as error:
        errors.append(str(error))
    if path in channel_modules:
        match = re.search(r'^CHANNEL = "([^"]+)"', path.read_text(encoding='utf-8'), re.M)
        check(bool(match), f'Нет CHANNEL в {path.relative_to(ROOT)}')
        if match:
            channel_names.append(match[1])
            parts = path.relative_to(handlers).parts
            owner = parts[0]
            check((ROOT / 'application/modules' / owner / 'config/prx_module.php').is_file(),
                  f'Пакет обработчика не называется как модуль-владелец: {path.relative_to(ROOT)}')
    elif handlers in path.parents and re.search(r'^CHANNEL = ', path.read_text(encoding='utf-8'), re.M):
        errors.append(f'Канал не зарегистрирован в handlers/__init__.py: {path.relative_to(ROOT)}')
check(set(channel_names) == {'doc.analyze', 'doc.normalize', 'document.extract', 'echo', 'embeddings.index', 'llm.summarize', 'semantic.index', 'pdf.chunks', 'invoice.extract', 'doc.route'}, 'Лишние/потерянные worker-каналы')
manifest_text = '\n'.join(p.read_text(encoding='utf-8') for p in (ROOT / 'application/modules').glob('*/config/prx_module.php'))
for channel in channel_names:
    check(f"'{channel}'" in manifest_text, f'Канал не объявлен в манифесте: {channel}')

rag_manifest = (ROOT / 'application/modules/prx560_knowledge/config/prx_module.php').read_text(encoding='utf-8')
check("'search'" not in rag_manifest, 'Технический RAG не должен попадать в глобальный поиск')
check("'audience' => 'admin'" in rag_manifest, 'RAG должен быть административным')
check('auth_required = FALSE' not in (ROOT / 'application/classes/Controller/Welcome.php').read_text(encoding='utf-8'), 'Главная не должна быть публичной')
check('action_register' in (ROOT / 'application/classes/Controller/Auth.php').read_text(encoding='utf-8'), 'Не закрыта саморегистрация')
check('Disallow: /' in (ROOT / 'public_html/robots.txt').read_text(encoding='utf-8'), 'Нет запрета индексации интранета')
check('portal:maintain' in (ROOT / 'docs/WORKERS.md').read_text(encoding='utf-8'), 'Не описано обслуживание')

# События: единственный вход — Event::publish() (application/classes/Event.php).
# Устаревшие fire/queue/Prx_Event и публикация мимо фасада — ошибка
# (docs/FOUNDATION.md §3). Само правило вынесено, чтобы его проверял тест.
sys.path.insert(0, str(ROOT / '.tools'))
from event_lint import errors as event_lint_errors  # noqa: E402

errors.extend(event_lint_errors(ROOT))

# Файлы: единственный вход — фасад Storage:: (application/classes/Storage.php).
# Service_Prx212_*/Model_Prx212_*/ORM::factory('Prx212…') вне prx212 и фасада — ошибка.
from storage_lint import errors as storage_lint_errors  # noqa: E402

errors.extend(storage_lint_errors(ROOT))

from classes_lint import errors as classes_lint_errors  # noqa: E402

errors.extend(classes_lint_errors(ROOT))

# Стиль SQL (docs/FOUNDATION.md §2, docs/SKELETON-ADR.md Д1): бизнес-код модулей
# идёт через Prx_Db, а не склеивает SQL с ручным $db->quote(). Это отчёт о долге
# миграции, а не ошибка: legacy приводим по мере захода, но новые модули должны
# быть чистыми, иначе список начнёт расти.
raw_sql_modules = set()
for path in (ROOT / 'application/modules').glob('*/classes/Service/**/*.php'):
    if '->quote(' in path.read_text(encoding='utf-8'):
        raw_sql_modules.add(path.relative_to(ROOT / 'application/modules').parts[0])

if errors:
    print('\n'.join('FAIL: ' + item for item in errors))
    sys.exit(1)
if raw_sql_modules:
    print('note: SQL-стиль — склейка/quote() вне Prx_Db (долг миграции, не ошибка): '
          + ', '.join(sorted(raw_sql_modules)))
optional_count = sum(status == '$docflow_status' for _, status in plan_entries)
planned_count = sum(status == 'planned' for _, status in plan_entries)
print(f'PRX contracts OK: {len(registered)} available modules, {len(classes)} PHP classes, '
      f'{len(active_services)} active + {sum(status == "pilot" for _, status in plan_entries)} pilot + {optional_count} optional + {len(archived_services)} archived + '
      f'{planned_count} planned services, {len(channel_names)} available worker channels, '
      f'events via Event::publish only, files via Storage:: only')
