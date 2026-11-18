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

registry = (ROOT / 'application/config/modules.php').read_text()
registered = re.findall(r"'([^']+)' => (APP|MOD)PATH\.'([^']+)'", registry)
check(bool(registered), 'Пустой реестр модулей')
for name, kind, relative in registered:
    path = ROOT / ('application' if kind == 'APP' else 'modules') / relative
    check(path.is_dir(), f'Не найден модуль {name}: {path}')
check(len({name for name, _, _ in registered}) == len(registered), 'Дубликаты ключей модулей')
check(registry.index("'prx208_events'") < registry.index("'prx120_jobs'"), 'Шина должна предшествовать очереди')
check((ROOT / 'docs/architecture/model/meta.yaml').is_file(), 'Нет модели docs/architecture/model/meta.yaml')
check((ROOT / 'docs/architecture/dist/architecture.html').is_file(), 'Нет собранной docs/architecture/dist/architecture.html')
check((ROOT / 'docs/architecture/dist/architecture.json').is_file(), 'Нет собранной docs/architecture/dist/architecture.json')
check("config/modules.php" in (ROOT / 'application/bootstrap.php').read_text(), 'Bootstrap обходит реестр')

plan = (ROOT / 'application/config/portal.php').read_text()
plan_entries = re.findall(r"'module' => '(prx\d+_[a-z_]+)',\s*'title' => '[^']+',\s*'status' => '(planned|active|archived)'", plan)
planned = [name for name, _status in plan_entries]
active_services = [name for name, status in plan_entries if status == 'active']
archived_services = [name for name, status in plan_entries if status == 'archived']
check(len(planned) == 55, 'План должен содержать 55 сервисов')
check(len(set(planned)) == 55, 'Дубли в плане')
check(len({re.search(r'\d+', name)[0] for name in planned}) == 55, 'Дубли ID в плане')
for name, status in plan_entries:
    exists = (ROOT / 'application/modules' / name).exists()
    registered_service = name in {entry[0] for entry in registered}
    if status == 'active':
        check(exists and registered_service, f'Активный сервис не реализован/не подключён: {name}')
    elif status == 'archived':
        check(not exists and not registered_service, f'Архивный сервис подключён: {name}')
        check((ROOT / 'archive/docflow/application/modules' / name / 'config/prx_module.php').is_file(),
              f'Не найден архивный манифест: {name}')
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
        check(not old_prefix.search(path.read_text()), f'Старый префикс в {path.relative_to(ROOT)}')

# Autoload requires a single declaration per class (including across renamed modules).
classes = {}
for path in (ROOT / 'application').rglob('*.php'):
    # Scaffold contains class declarations inside strings, not actual loaded classes.
    if path.name == 'Scaffold.php' or 'PostgreSQL_PDO' in path.parts:
        continue
    for name in re.findall(r'^\s*(?:abstract\s+|final\s+)?class\s+(\w+)\s', re.sub(r'/\*.*?\*/', '', path.read_text(), flags=re.S), re.M):
        check(name.lower() not in classes, f'Дубликат PHP-класса {name}: {path}')
        classes[name.lower()] = path

handlers = ROOT / 'workers/prx_workers/handlers'
channel_names = []
for path in (ROOT / 'workers/prx_workers').rglob('*.py'):
    try:
        ast.parse(path.read_text(), filename=str(path))
    except SyntaxError as error:
        errors.append(str(error))
    if path.parent == handlers and path.name != '__init__.py':
        match = re.search(r'^CHANNEL = "([^"]+)"', path.read_text(), re.M)
        check(bool(match), f'Нет CHANNEL в {path.name}')
        if match:
            channel_names.append(match[1])
check(set(channel_names) == {'document.extract', 'echo', 'embeddings.index', 'llm.summarize', 'semantic.index'}, 'Лишние/потерянные worker-каналы')
manifest_text = '\n'.join(p.read_text() for p in (ROOT / 'application/modules').glob('*/config/prx_module.php'))
for channel in channel_names:
    check(f"'{channel}'" in manifest_text, f'Канал не объявлен в манифесте: {channel}')

rag_manifest = (ROOT / 'application/modules/prx560_knowledge/config/prx_module.php').read_text()
check("'search'" not in rag_manifest, 'Технический RAG не должен попадать в глобальный поиск')
check("'audience' => 'admin'" in rag_manifest, 'RAG должен быть административным')
check('auth_required = FALSE' not in (ROOT / 'application/classes/Controller/Welcome.php').read_text(), 'Главная не должна быть публичной')
check('action_register' in (ROOT / 'application/classes/Controller/Auth.php').read_text(), 'Не закрыта саморегистрация')
check('Disallow: /' in (ROOT / 'public_html/robots.txt').read_text(), 'Нет запрета индексации интранета')
check('portal:maintain' in (ROOT / 'docs/WORKERS.md').read_text(), 'Не описано обслуживание')

if errors:
    print('\n'.join('FAIL: ' + item for item in errors))
    sys.exit(1)
print(f'PRX contracts OK: {len(registered)} registered modules, {len(classes)} PHP classes, {len(active_services)} active + {len(archived_services)} archived + {len(planned) - len(active_services) - len(archived_services)} planned services, {len(channel_names)} worker channels')
