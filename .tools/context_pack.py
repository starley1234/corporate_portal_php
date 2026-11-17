#!/usr/bin/env python3
"""Контекст-пак модуля — дешёвый вход в помодульную разработку.

Зачем этот файл. Работа над модулем начинается с чтения: агент открывает
корневой `AGENTS.md` (44 КБ), потом памятку модуля — а у `prx643_cad` она 33 КБ,
у `prx641_imagegen` 15 КБ, у `prx120_jobs` 13 КБ. Это 10–20 тысяч токенов ДО
первой строки кода, причём большая часть прочитанного к задаче не относится
(правила воркеров — в модуле без воркера, устройство PDF — в модуле про JSON).

Инструмент собирает по модулю ровно то, что нужно для работы, из файлов,
которые модуль и так держит актуальными:

    python3 .tools/context_pack.py pack prx643_cad           # карточка ≤ 8 КБ
    python3 .tools/context_pack.py pack prx643_cad --write   # в .context/<модуль>.md
    python3 .tools/context_pack.py section "Правила" --module prx643_cad
    python3 .tools/context_pack.py index AGENTS.md --write  # индекс разделов

Почему это не «ещё один реестр». Ничего не дублируется: таблицы берутся из
миграций модуля, классы и публичные методы — из файлов классов, маршруты — из
`init.php`, каналы очереди и прочие секции — из манифеста `config/prx_module.php`
(пятый контракт). Если модуль изменился, карточка меняется сама; расходиться
с кодом ей нечем. Единственное, что читается из прозы, — разделы памятки
(«Правила…», «Состояние…»), и они выводятся как есть, без пересказа.

Потолки и проверка — `.tools/doc_budget_lint.py` (в прогоне php_check_all.sh):
памятка модуля > 12 КБ обязана иметь индекс разделов, а карточка обязана
собираться по КАЖДОМУ модулю и влезать в бюджет.
"""
import argparse
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
MODULES_DIR = os.path.join(ROOT, 'application', 'modules')
WORKERS_DIR = os.path.join(ROOT, 'workers', 'prx_workers', 'handlers')

DEFAULT_MAX = 8192
HARD_MAX = 12288
RULES_LIMITS = (2200, 1200, 600, 0)

INDEX_BEGIN = '<!-- context-index:begin -->'
INDEX_END = '<!-- context-index:end -->'
INDEX_NOTE = ('<!-- Индекс разделов. Генерируется: '
              'python3 .tools/context_pack.py index %s --write. Руками не править. -->')

# Ключи манифеста, которые стоит показать в карточке.
MANIFEST_KEYS = ('id', 'slug', 'title', 'short', 'href', 'group', 'sort', 'status',
                 'family', 'auth', 'badge', 'card_view', 'api', 'keywords', 'admin')
MANIFEST_SECTIONS = ('worker', 'schedule', 'cleanup', 'health', 'recent', 'search')


# --------------------------------------------------------------------------
# 1. Разбор PHP-массивов (манифест `config/prx_module.php`)
# --------------------------------------------------------------------------

def _skip(text, i):
    """Пропустить пробелы и комментарии, вернуть новую позицию."""
    while i < len(text):
        c = text[i]

        if c in ' \t\r\n':
            i += 1
            continue

        if text.startswith('//', i) or c == '#':
            j = text.find('\n', i)
            i = len(text) if j < 0 else j + 1
            continue

        if text.startswith('/*', i):
            j = text.find('*/', i)
            i = len(text) if j < 0 else j + 2
            continue

        break

    return i


def _string(text, i):
    """PHP-строка в кавычках → значение. Интерполяция переменной = ошибка."""
    quote = text[i]
    i += 1
    out = []
    escapes = {'n': '\n', 't': '\t', 'r': '\r', '\\': '\\', "'": "'", '"': '"'}

    while i < len(text):
        c = text[i]

        if c == '\\' and i + 1 < len(text):
            out.append(escapes.get(text[i + 1], text[i + 1]))
            i += 2
            continue

        if c == quote:
            return ''.join(out), i + 1

        if quote == '"' and c == '$':
            raise ValueError('строка собирается в рантайме (интерполяция)')

        out.append(c)
        i += 1

    raise ValueError('незакрытая строка')


def _value(text, i):
    """Значение PHP: скаляр, строка, массив, склейка литералов (`'a' . 'b'`)."""
    value, i = _scalar(text, i)

    while True:
        j = _skip(text, i)

        # Склейка строк: в манифестах так собраны длинные пояснения
        # (первая часть точкой, вторая). Переменная в склейке —
        # значение больше не литерал, честно говорим об этом.
        if j < len(text) and text[j] == '.' and not text.startswith('..', j):
            j = _skip(text, j + 1)

            if j < len(text) and text[j] in '\'"':
                chunk, i = _scalar(text, j)

                if not isinstance(value, str) or not isinstance(chunk, str):
                    raise ValueError('склейка не строк')

                value += chunk
                continue

            raise ValueError('склейка с выражением (значение не литерал)')

        break

    return value, i


def _scalar(text, i):
    """Одиночное значение без склейки."""
    i = _skip(text, i)

    if i >= len(text):
        raise ValueError('неожиданный конец файла')

    c = text[i]

    if c in '\'"':
        return _string(text, i)

    if c == '[':
        items, j = _list(text, i + 1, ']')
        return _to_php(items), j

    if c.isdigit() or (c == '-' and i + 1 < len(text) and text[i + 1].isdigit()):
        m = re.match(r'-?\d+(\.\d+)?', text[i:])
        raw = m.group(0)
        return (float(raw) if '.' in raw else int(raw)), i + len(raw)

    m = re.match(r'[A-Za-z_][A-Za-z0-9_]*', text[i:])

    if m:
        word = m.group(0)
        low = word.lower()
        j = i + len(word)

        if low == 'array':
            j = _skip(text, j)

            if j < len(text) and text[j] == '(':
                items, k = _list(text, j + 1, ')')
                return _to_php(items), k

            raise ValueError('array( ожидался')

        if low == 'true':
            return True, j

        if low == 'false':
            return False, j

        if low == 'null':
            return None, j

        raise ValueError('значение не литерал: %s' % word)

    raise ValueError('неожиданный символ %r' % c)


def _list(text, i, close):
    """Элементы массива: [(ключ|None, значение), …]."""
    items = []

    while True:
        i = _skip(text, i)

        if i >= len(text):
            raise ValueError('незакрытый массив')

        if text[i] == close:
            return items, i + 1

        first, i = _value(text, i)
        i2 = _skip(text, i)

        if text.startswith('=>', i2):
            second, i = _value(text, i2 + 2)
            items.append((first, second))
        else:
            items.append((None, first))

        i = _skip(text, i)

        if i < len(text) and text[i] == ',':
            i += 1
            continue

        i = _skip(text, i)

        if i < len(text) and text[i] == close:
            return items, i + 1

        raise ValueError('ожидалась , или %s' % close)


def _to_php(items):
    """Список пар → dict, если все ключи строковые (манифест так и устроен)."""
    if items and all(isinstance(k, str) for k, _ in items):
        return {k: v for k, v in items}

    return [v for _, v in items]


def php_array(text):
    """Первый `return [ … ];` из PHP-файла → вложенные dict/list."""
    m = re.search(r'\breturn\s*[\[]', text)

    if not m:
        raise ValueError('в файле нет return [...]')

    items, _ = _list(text, m.end(), ']')
    return _to_php(items)


# --------------------------------------------------------------------------
# 2. Факты модуля (из файлов, которые модуль держит сам)
# --------------------------------------------------------------------------

def modules():
    """Имена модулей платформы (application/modules)."""
    if not os.path.isdir(MODULES_DIR):
        return []

    return sorted(n for n in os.listdir(MODULES_DIR)
                  if not n.startswith('.') and os.path.isdir(os.path.join(MODULES_DIR, n)))


def module_path(name):
    path = os.path.join(MODULES_DIR, name)
    return path if os.path.isdir(path) else None


def _text(path):
    with open(path, encoding='utf-8', errors='replace') as fh:
        return fh.read()


def _bytes(path):
    try:
        return os.path.getsize(path)
    except OSError:
        return 0


def plural(n, one, few, many):
    """Русское согласование: 1 раздел, 2 раздела, 5 разделов."""
    n10, n100 = n % 10, n % 100

    if n10 == 1 and n100 != 11:
        return one

    if 2 <= n10 <= 4 and not 12 <= n100 <= 14:
        return few

    return many


def _kb(n):
    """Байты → «12.3 КБ» (русская десятичная точка)."""
    return ('%.1f' % (n / 1024.0)).replace('.', ',') + ' КБ'


def manifest(name):
    """Манифест модуля (пятый контракт) или None."""
    path = os.path.join(MODULES_DIR, name, 'config', 'prx_module.php')

    if not os.path.isfile(path):
        return None

    try:
        data = php_array(_text(path))
    except ValueError as exc:
        return {'_error': str(exc)}

    return data if isinstance(data, dict) else {'_error': 'манифест не массив'}


def tables(name):
    """Таблицы модуля из его же миграций: создаёт / меняет."""
    base = os.path.join(MODULES_DIR, name, 'migrations_pg')
    created, touched = [], []

    if not os.path.isdir(base):
        return created, touched

    patterns = (
        (re.compile(r'CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?([A-Za-z0-9_."]+)', re.I), created),
        (re.compile(r'ALTER\s+TABLE\s+(?:IF\s+EXISTS\s+)?(?:ONLY\s+)?([A-Za-z0-9_."]+)', re.I), touched),
    )

    for fn in sorted(os.listdir(base)):
        if not fn.endswith('.php'):
            continue

        text = _text(os.path.join(base, fn))

        for rx, sink in patterns:
            for m in rx.finditer(text):
                t = m.group(1).strip('"')

                if t not in sink:
                    sink.append(t)

    return created, touched


def class_inventory(name):
    """Файлы классов модуля: путь, строки, классы, публичные методы."""
    base = os.path.join(MODULES_DIR, name, 'classes')
    found = []

    if not os.path.isdir(base):
        return found

    for dirpath, dirnames, filenames in os.walk(base):
        dirnames.sort()

        for fn in sorted(filenames):
            if not fn.endswith('.php'):
                continue

            path = os.path.join(dirpath, fn)
            rel = os.path.relpath(path, MODULES_DIR)
            text = _text(path)

            classes = re.findall(r'^\s*(?:abstract\s+|final\s+)?(?:class|interface|trait)\s+(\w+)',
                                 text, re.M)
            methods = re.findall(r'^\s*public\s+(?:static\s+)?function\s+(\w+)', text, re.M)

            found.append({
                'path': rel,
                'loc': text.count('\n') + 1,
                'classes': classes,
                'methods': methods,
            })

    return found


def routes(name):
    """Маршруты модуля из init.php."""
    path = os.path.join(MODULES_DIR, name, 'init.php')

    if not os.path.isfile(path):
        return []

    text = _text(path)
    found = []

    for m in re.finditer(r"Route::set\(\s*'([^']+)'\s*,\s*'([^']+)'", text):
        tail = text[m.end():m.end() + 600]
        nxt = tail.find('Route::set(')

        if nxt >= 0:
            tail = tail[:nxt]

        ctrl = re.search(r"'controller'\s*=>\s*'([^']+)'", tail)
        act = re.search(r"'action'\s*=>\s*'([^']+)'", tail)

        found.append({
            'name': m.group(1),
            'pattern': m.group(2),
            'controller': ctrl.group(1) if ctrl else '',
            'action': act.group(1) if act else '',
        })

    return found


def _grep_names(path, patterns):
    """Уникальные совпадения первого capture-неймспейса по списку приложений."""
    text = _text(path)
    out = []

    for rx in patterns:
        for m in rx.finditer(text):
            value = m.group(1)

            if value not in out:
                out.append(value)

    return out


def events(name):
    """Публикует / слушает (по тексту модуля)."""
    base = os.path.join(MODULES_DIR, name)
    published, listened = [], []
    rx_pub = (re.compile(r"publish\(\s*'([^']+)'"), re.compile(r"Event::fire\(\s*'([^']+)'"))

    for dirpath, dirnames, filenames in os.walk(base):
        for fn in filenames:
            if not fn.endswith('.php'):
                continue

            path = os.path.join(dirpath, fn)

            for value in _grep_names(path, rx_pub):
                if value not in published:
                    published.append(value)

            for value in _grep_names(path, [re.compile(r"listen\(\s*'([^']+)'")]):
                if value not in listened:
                    listened.append(value)

    return published, listened


def channels_used(name):
    """Каналы очереди, которые модуль ставит через JobQueue::push."""
    base = os.path.join(MODULES_DIR, name)
    out = []

    for dirpath, dirnames, filenames in os.walk(base):
        for fn in filenames:
            if not fn.endswith('.php'):
                continue

            for value in _grep_names(os.path.join(dirpath, fn),
                                     [re.compile(r"JobQueue::push\(\s*'([^']+)'")]):
                if value not in out:
                    out.append(value)

    return out


def env_keys(name):
    """Переменные окружения модуля: свои (PRX<id>_*) и общие."""
    base = os.path.join(MODULES_DIR, name)
    m = re.match(r'prx(\d+)_', name)
    own_prefix = ('PRX%s_' % m.group(1)) if m else None
    own, shared = [], []

    for dirpath, dirnames, filenames in os.walk(base):
        for fn in filenames:
            if not fn.endswith('.php'):
                continue

            for value in _grep_names(os.path.join(dirpath, fn),
                                     [re.compile(r"getenv\(\s*'([A-Z0-9_]+)'")]):
                if own_prefix and value.startswith(own_prefix):
                    if value not in own:
                        own.append(value)
                elif value not in shared:
                    shared.append(value)

    return sorted(own), sorted(shared)


def owner_module(service):
    """`Service_Prx120_JobQueue` → `prx120_jobs` (или None, если модуля нет)."""
    m = re.match(r'Service_(Prx|Prx)(\d+)_', service)

    if not m:
        return None

    prefix = ('prx' if m.group(1) == 'Prx' else 'prx') + m.group(2) + '_'

    for module in modules():
        if module.startswith(prefix):
            return module

    return None


def dependencies_out(name):
    """Кого зовёт модуль: чужие Service_<модуль>_* в его коде."""
    base = os.path.join(MODULES_DIR, name)
    rx = re.compile(r'\b(Service_(?:Prx\d+|Prx\d+)_\w+)')
    found = {}

    for dirpath, dirnames, filenames in os.walk(base):
        for fn in filenames:
            if not fn.endswith('.php'):
                continue

            for value in _grep_names(os.path.join(dirpath, fn), [rx]):
                owner = owner_module(value)

                if owner and owner != name:
                    found.setdefault(owner, set()).add(value)

    return found


def dependencies_in(name):
    """Кто зовёт модуль: `Service_Prx643_*` ищется в чужих модулях."""
    m = re.match(r'(prx|prx)(\d+)_', name)

    if not m:
        return {}

    needle = 'Service_N%s_' % m.group(2)
    found = {}

    for other in modules():
        if other == name:
            continue

        base = os.path.join(MODULES_DIR, other)
        hits = 0

        for dirpath, dirnames, filenames in os.walk(base):
            for fn in filenames:
                if not fn.endswith('.php'):
                    continue

                if needle in _text(os.path.join(dirpath, fn)):
                    hits += 1

        if hits:
            found[other] = hits

    return found


def workers(name):
    """Обработчики воркеров, относящиеся к модулю (по имени файла или каналу)."""
    if not os.path.isdir(WORKERS_DIR):
        return []

    m = re.match(r'prx(\d+)_', name)
    hits = []

    for fn in sorted(os.listdir(WORKERS_DIR)):
        if not fn.endswith('.py') or fn.startswith('__'):
            continue

        text = _text(os.path.join(WORKERS_DIR, fn))

        if (m and ('prx%s' % m.group(1)) in fn) or ('prx%s.' % (m.group(1) if m else '')) in text:
            hits.append(fn)

    return hits


def _needles(name):
    """Как модуль узнаётся в чужих файлах. Три разных набора, и путать их нельзя.

    * **precise** — имена его классов и таблиц (`Service_Prx120_`, `prx_120_`):
      по ним модуль узнаётся в тексте проверки;
    * **names** — `prx120` и `semantic` (id и slug модуля): по ним узнаётся ИМЯ
      файла (`test_semantic_index.py`, `cad_ui_smoke.mjs`). Адреса страниц сюда
      не входят: `/docs` — это и «Документы организации», и что угодно про
      документацию, поэтому по путям имя файла не угадываем;
    * **paths** — `/docs`, `/cad` и публичные сегменты маршрутов: по ним
      проверка узнаётся по СОДЕРЖИМОМУ (в дымовом скрипте нет ни классов, ни
      таблиц, зато есть адрес страницы). Здесь `_owns` обязателен.
    """
    m = re.match(r'(prx|prx)(\d+)_(.+)', name)
    precise, names, paths = [], [], []

    if m:
        precise += ['Service_N%s_' % m.group(2), 'Controller_N%s_' % m.group(2),
                    'Model_N%s_' % m.group(2), 'Prx%s_' % m.group(2),
                    'prx_%s_' % m.group(2)]
        names += ['%s%s' % (m.group(1), m.group(2)), m.group(3)]

    man = manifest(name)

    if isinstance(man, dict) and not man.get('_error'):
        href = man.get('href')

        if isinstance(href, str) and href.startswith('/'):
            paths.append(href)

    # публичный сегмент маршрута: 'cad' из 'cad/api(/<action>…)'
    for route in routes(name):
        head = route['pattern'].split('/')[0].strip('(<')

        if head and re.match(r'^[a-z0-9_-]+$', head) and not head.startswith('prx'):
            paths.append('/' + head)

    return (sorted(set(precise)), sorted(set(names)), sorted(set(paths)))


_IDENTITY_CACHE = {}


def identity(name):
    """Свои имена модуля: классы и таблицы. Ими он отличается от соседа с тем же номером.

    Номер не уникален (`prx100_platform` и `prx100_search` делят сотню), поэтому
    «файл упоминает наш номер» — ещё не «файл проверяет наш модуль».
    """
    if name in _IDENTITY_CACHE:
        return _IDENTITY_CACHE[name]

    own = []

    for item in class_inventory(name):
        own += item['classes']

    created, _touched = tables(name)
    own += created
    _IDENTITY_CACHE[name] = [x for x in own if x]

    return _IDENTITY_CACHE[name]


def _module_id_counts(text):
    """Сколько раз в файле упомянут каждый модуль (по префиксам классов, таблиц, id)."""
    counts = {}

    for m in re.finditer(r'(?:Service_N(?:j|prx)|Model_N(?:j|prx)|Controller_N(?:j|prx)|Prx|prx_)'
                         r'(\d{3,4})_|\b(?:prx|prx)(\d{3,4})[._/-]', text):
        mid = m.group(1) or m.group(2)
        counts[mid] = counts.get(mid, 0) + 1

    return counts


def _owns(text, mid):
    """Принадлежит ли файл модулю `mid`, а не платформе целиком.

    Правило одной строкой: модуль должен быть **самым упоминаемым** в файле и
    упомянут не меньше двух раз. Так отсеиваются платформенные проверки
    (`EnvironmentTest` называет 115, 130 и 120 — лидер не один) и тесты соседей,
    которые просто ставят задачу в нашу очередь или чистят нашу таблицу.
    """
    if not mid:
        return False

    counts = _module_id_counts(text)

    if counts.get(mid, 0) < 2:
        return False

    top = max(counts.values())

    return counts[mid] == top and sum(1 for v in counts.values() if v == top) == 1


def _touches(files, precise, names, mid, limit=8, own_names=None):
    """Файлы, относящиеся к модулю: по имени файла или по содержимому.

    Три условия сразу, потому что одного мало: точная строка ловит соседа с тем
    же номером, лидерство по номеру — платформенные тесты, перечисляющие все
    модули, имя модуля в пути — чужие стенды. Файл считается проверкой модуля,
    если модуль в нём ГЛАВНЫЙ (`_owns`), назван СВОИМ именем (класс или
    таблица) и файл не перечисляет полплатформы.
    """
    hits = []

    for rel in files:
        text = _text(os.path.join(ROOT, rel))
        stem = os.path.basename(rel)

        # Имя файла сравниваем по ТОКЕНАМ, а не подстрокой: 'cad_ui_smoke.mjs'
        # и 'test_semantic_index.py' — наши (cad, semantic), а 'test_watchdogs.py'
        # чужой: 'watchdog' из prx609_watchdog не равен токену 'watchdogs'.
        tokens = set(re.split(r'[^0-9a-z]+', stem.lower()))
        by_name = any(n.lower() in tokens for n in names if len(n) > 3)

        if not by_name:
            if not any(n in text for n in precise) or not _owns(text, mid):
                continue

            if own_names and not any(n in text for n in own_names):
                continue

        # платформенная проверка перечисляет много модулей — она не «наша»
        if len(_module_id_counts(text)) >= 5:
            continue

        hits.append(rel)

        if len(hits) >= limit:
            break

    return hits


def _listdir(rel, exts):
    """Файлы каталога с нужными расширениями (относительные пути)."""
    base = os.path.join(ROOT, rel)

    if not os.path.isdir(base):
        return []

    return [rel + '/' + fn for fn in sorted(os.listdir(base))
            if fn.endswith(exts) and not fn.startswith('__')]


def smoke_pages():
    """Страницы HTTP-дыма из bin/check-fresh.sh (единственное место со списком)."""
    path = os.path.join(ROOT, 'bin', 'check-fresh.sh')

    if not os.path.isfile(path):
        return []

    pages = []

    for m in re.finditer(r'for path in ([^;]+);', _text(path)):
        pages += m.group(1).split()

    return pages


def own_channels(name):
    """Каналы очереди, объявленные модулем в манифесте (пятый контракт)."""
    man = manifest(name)

    if not isinstance(man, dict) or man.get('_error'):
        return []

    worker = man.get('worker')

    if not isinstance(worker, dict):
        return []

    chans = worker.get('channels')

    return sorted(chans.keys()) if isinstance(chans, dict) else []


def checks(name):
    """Чем модуль проверяется: тесты, тесты воркеров, стенды, HTTP-дым.

    Пустой список — это тоже ответ, и его надо показать честно: «покрытия нет»
    означает, что первый шаг работы — стенд или страница руками, а не правка
    вслепую (урок 28.09: /cad не был покрыт ничем, и рефакторинг отложили).
    """
    precise, names, paths = _needles(name)
    m = re.match(r'prx(\d+)_', name)
    mid = m.group(1) if m else None

    own_names = identity(name)
    tests = _touches(_listdir('tests/Unit', ('.php',)) + _listdir('tests/Smoke', ('.php',)),
                     precise, names, mid, own_names=own_names)
    # В python-тесте нет ни наших классов, ни таблиц, поэтому кроме содержимого
    # работает имя файла: test_prx643_cad.py, test_semantic_index.py, test_trends_*.
    worker_tests = _touches(_listdir('workers/tests', ('.py',)), precise, names, mid,
                            own_names=own_names)

    stands = []

    for rel in _listdir('.tools', ('.php', '.py', '.mjs')):
        stem = os.path.basename(rel)

        # Линтеры упоминают модули в пояснениях к проверкам (история инцидентов),
        # стендами они от этого не становятся.
        if 'lint' in stem or stem.startswith('context_pack') or stem.startswith('doc_budget'):
            continue

        if mid and ('prx%s' % mid) in stem:
            stands.append(rel)
            continue

        text = _text(os.path.join(ROOT, rel))

        # Стенд может не носить номер в имени: cad_ui_smoke.mjs и viewer_smoke.mjs
        # проверяют /cad/<code>. Но узнаём их строго — модуль должен быть
        # ГЛАВНЫМ в файле (_owns): иначе чужие стенды и линтеры, упомянувшие
        # наш адрес в пояснении, запишутся в проверки модуля.
        if not _owns(text, mid):
            continue

        if any(len(p) >= 4 and p in text for p in paths) or \
                any(n in text for n in precise):
            stands.append(rel)

    pages = smoke_pages()
    smoke = [p for p in paths if p in pages]

    return {
        'tests': tests,
        'worker_tests': worker_tests,
        'stands': stands,
        'smoke': smoke,
        'smoke_total': len(pages),
    }


TODO_RX = re.compile(r'долг|todo|идеи|развивать|дальше|бэклог|планы|задел', re.I)


def todo_items(name, limit=0, width=260):
    """Открытые пункты модуля из разделов «Долги», «Куда развивать» и т.п."""
    path = os.path.join(MODULES_DIR, name, 'AGENTS.md')

    if not os.path.isfile(path):
        return []

    items = []

    for level, title, line, size in sections(path):
        if level > 3 or not TODO_RX.search(title):
            continue

        current = None

        for raw in pick_section(path, level, line).split('\n'):
            m = re.match(r'^(\s*)(?:[-*]|\d+[.)])\s+(.+)$', raw)

            if m and len(m.group(1)) <= 2:
                if current:
                    items.append(current)

                current = m.group(2).strip().replace('**', '')
                continue

            # продолжение пункта набрано с отступом в два пробела — это ещё он
            if current and raw.strip() and len(raw) - len(raw.lstrip()) > 1:
                if len(current) < width:
                    current += ' ' + raw.strip().replace('**', '')

        if current:
            items.append(current)

    cleaned = []

    for item in items:
        item = re.sub(r'\s+', ' ', item).strip(' .;')

        if item in ('—', '-', '…', '...', '') or len(item) < 4:
            continue

        if len(item) > width:
            window = item[:width]
            cut = -1

            # режем по границе смысла, а не по символу: подробности остались в доке
            for sep in ('. ', ' — ', '; '):
                found = window.rfind(sep)

                if found > cut:
                    cut = found + (len(sep) if sep == '. ' else 0)

            if cut > 60:
                item = window[:cut].rstrip(' ,;:—-') + '…'
            else:
                item = window.rsplit(' ', 1)[0].rstrip(' ,;:—-') + '…'

        cleaned.append(item)

    return cleaned[:limit] if limit else cleaned


def mcp_tools(name):
    """MCP-инструменты модуля (config/mcp.php), если есть."""
    path = os.path.join(MODULES_DIR, name, 'config', 'mcp.php')

    if not os.path.isfile(path):
        return []

    text = _text(path)
    return re.findall(r"'name'\s*=>\s*'([^']+)'", text)


# --------------------------------------------------------------------------
# 3. Разделы Markdown — читать памятку по частям, а не целиком
# --------------------------------------------------------------------------

def sections(path):
    """Разделы файла: [(уровень, заголовок, номер строки, размер в байтах)]."""
    text = _text(path)
    lines = text.split('\n')
    heads = []
    fence = None   # ```…``` — внутри примера «## …» это не раздел документа

    for no, line in enumerate(lines, start=1):
        stripped = line.lstrip()

        if stripped.startswith('```') or stripped.startswith('~~~'):
            marker = stripped[:3]
            fence = None if fence == marker else (marker if fence is None else fence)
            continue

        if fence:
            continue

        m = re.match(r'^(#{1,6})\s+(.+?)\s*$', line)

        if m:
            heads.append([len(m.group(1)), m.group(2), no])

    out = []

    for i, (level, title, no) in enumerate(heads):
        end = len(lines)

        for level2, _t2, no2 in heads[i + 1:]:
            if level2 <= level:
                end = no2 - 1
                break

        body = '\n'.join(lines[no - 1:end])
        out.append((level, title, no, len(body.encode('utf-8'))))

    return out


def pick_section(path, level, line):
    """Тело раздела, начинающегося на указанной строке."""
    lines = _text(path).split('\n')
    end = len(lines)

    for l2, _t2, no2, _s2 in sections(path):
        if no2 > line and l2 <= level:
            end = no2 - 1
            break

    return '\n'.join(lines[line - 1:end]).rstrip()


def numbered_items(path, start, end):
    """Пункты «12. **Название.** …» внутри диапазона строк — навигация по прозе.

    В корневом `AGENTS.md` правила 10–20 не имеют заголовков: это нумерованный
    список в разделе «Обязательные практики». По одному индексу разделов их не
    найти (раздел весит 20 КБ), поэтому индекс показывает и такие пункты.
    """
    lines = _text(path).split('\n')
    found = []

    for no in range(start, min(end, len(lines)) + 1):
        m = re.match(r'^\s*(\d+)\.\s+\*\*(.{3,90}?)[.\*]*\*\*', lines[no - 1])

        if not m:
            continue

        title = m.group(2).strip().rstrip('.:')
        found.append((no, m.group(1), title))

    return found


def render_index(path, indent=''):
    """Таблица-индекс разделов Markdown (для вставки в файл и для карточки)."""
    rows = ['%s| Раздел | Строка | Размер |' % indent,
            '%s|---|---|---|' % indent]
    all_sections = sections(path)
    lines_total = _text(path).count('\n') + 1

    for i, (level, title, line, size) in enumerate(all_sections):
        # H1 — это весь файл, в индексе он бесполезен
        if level == 1:
            continue

        name = ('  ' * (level - 1)) + title
        rows.append('%s| %s | %d | %s |' % (indent, name.replace('|', '\\|'), line, _kb(size)))

        if size < 2048:
            continue

        end = lines_total

        for level2, _t2, no2, _s2 in all_sections[i + 1:]:
            if level2 <= level:
                end = no2 - 1
                break

        for no, number, item_title in numbered_items(path, line, end)[:14]:
            rows.append('%s| · %s | %d | |' % (indent, item_title.replace('|', '\\|'), no))

    return '\n'.join(rows)


def write_index(path, note_target):
    """Вставить (или обновить) индекс разделов в Markdown-файл.

    Проходов два: первый ставит блок, второй пересчитывает номера строк уже
    с ним (иначе индекс, записанный в файл, сразу же расходится с файлом —
    а его сверяет doc_budget_lint).
    """
    _write_index_once(path, note_target)
    added = _write_index_once(path, note_target)
    return added


def _write_index_once(path, note_target):
    text = _text(path)
    block = '\n'.join([
        INDEX_BEGIN,
        INDEX_NOTE % note_target,
        render_index(path),
        INDEX_END,
    ]) + '\n'

    if INDEX_BEGIN in text and INDEX_END in text:
        start = text.index(INDEX_BEGIN)
        end = text.index(INDEX_END) + len(INDEX_END) + 1
        new = text[:start] + block + text[end:]
    else:
        lines = text.split('\n')
        # индекс встаёт после вводного блока (H1, цитата, первый абзац): карта
        # файла — сразу за шапкой, до «|» и «---» начинается содержание.
        insert = 1

        while insert < len(lines) and (lines[insert].strip() == ''
                                       or lines[insert].lstrip().startswith('>')):
            insert += 1

        if (insert < len(lines) and lines[insert].strip()
                and not lines[insert].startswith(('#', '-', '*', '|', '---', '<!--'))):
            while insert < len(lines) and lines[insert].strip():
                insert += 1

            while insert < len(lines) and lines[insert].strip() == '':
                insert += 1

        new = '\n'.join(lines[:insert]) + '\n' + block + '\n'.join(lines[insert:])

    with open(path, 'w', encoding='utf-8') as fh:
        fh.write(new)

    return len(block.encode('utf-8'))


# --------------------------------------------------------------------------
# 4. Карточка модуля (pack)
# --------------------------------------------------------------------------

def _rules_excerpt(name):
    """Разделы памятки, которые читают «перед изменением»: правила и ловушки."""
    path = os.path.join(MODULES_DIR, name, 'AGENTS.md')

    if not os.path.isfile(path):
        return ''

    rx = re.compile(r'правил|не трогать|не делать|ограничен|контракт|подводн|ловушк|конвейер',
                    re.I)
    picked = []

    for level, title, line, size in sections(path):
        if level <= 2 and rx.search(title):
            picked.append(pick_section(path, level, line))

    return '\n\n'.join(picked)


def _state_excerpt(name):
    """Раздел «Состояние…» памятки — чтобы продолжать работу, а не выяснять её."""
    path = os.path.join(MODULES_DIR, name, 'AGENTS.md')

    if not os.path.isfile(path):
        return ''

    found = sections(path)

    # сначала честный раздел «Состояние…», и только потом «Готовность»/«Статус»:
    # в prx643 «Готовность к деплою» — 11 КБ инструкций, а не состояние.
    for degree, rx, cap in ((0, r'состояние', 1500), (1, r'готовност|статус', 700)):
        for level, title, line, size in found:
            if level <= 3 and re.search(rx, title, re.I):
                body = pick_section(path, level, line)
                raw = body.encode('utf-8')

                if len(raw) <= cap:
                    return body

                cut = raw[:cap].decode('utf-8', 'ignore')
                return cut.rsplit('\n', 1)[0] + '\n… (раздел целиком: section "%s")' % title[:24]

    return ''


def _doc_files(name):
    base = os.path.join(MODULES_DIR, name)
    out = []

    for fn in ('AGENTS.md', 'README.md'):
        path = os.path.join(base, fn)

        if os.path.isfile(path):
            out.append((fn, path, _bytes(path)))

    extra = os.path.join(base, 'docs')

    if os.path.isdir(extra):
        for fn in sorted(os.listdir(extra)):
            if fn.endswith('.md'):
                path = os.path.join(extra, fn)
                out.append(('docs/%s' % fn, path, _bytes(path)))

    return out


def _block_facts(name, man):
    """Факты: таблицы, очередь, расписание, события, env, mcp."""
    lines = []
    created, touched = tables(name)

    if created:
        lines.append('* **Таблицы (владелец):** ' + ', '.join('`%s`' % t for t in created))

    foreign = [t for t in touched if t not in created]

    if foreign:
        lines.append('* Миграции меняют ещё: ' + ', '.join('`%s`' % t for t in foreign[:8])
                     + (' …' if len(foreign) > 8 else ''))

    if isinstance(man, dict) and not man.get('_error'):
        worker = man.get('worker') or {}
        chans = worker.get('channels') if isinstance(worker, dict) else None

        if isinstance(chans, dict) and chans:
            parts = []

            for key, value in chans.items():
                label = value.get('label') if isinstance(value, dict) else None
                parts.append('`%s`%s' % (key, ' (%s)' % label if label else ''))

            lines.append('* **Каналы очереди (манифест):** ' + '; '.join(parts))

        sched = man.get('schedule')

        if isinstance(sched, dict) and sched:
            parts = []

            for key, value in sched.items():
                every = value.get('every') if isinstance(value, dict) else None
                parts.append('`%s`%s' % (key, ' — %s' % every if every else ''))

            lines.append('* **Расписание (prx603):** ' + '; '.join(parts))

        for key in ('cleanup', 'health', 'recent', 'search'):
            section = man.get(key)

            if isinstance(section, dict) and section:
                lines.append('* **%s:** %s' % (key, ', '.join('`%s`' % k for k in section)))

    used = channels_used(name)

    if used:
        lines.append('* Ставит задачи в каналы: ' + ', '.join('`%s`' % c for c in used))

    published, listened = events(name)

    if published:
        lines.append('* Публикует события: ' + ', '.join('`%s`' % e for e in published[:6]))

    if listened:
        lines.append('* Слушает события: ' + ', '.join('`%s`' % e for e in listened[:6]))

    own_env, shared_env = env_keys(name)

    if own_env:
        lines.append('* Env модуля: ' + ', '.join('`%s`' % e for e in own_env) + ' (.env.example)')

    if shared_env:
        lines.append('* Общие env: ' + ', '.join('`%s`' % e for e in shared_env[:8]))

    tools = mcp_tools(name)

    if tools:
        lines.append('* MCP-инструменты: ' + ', '.join('`%s`' % t for t in tools))

    return '\n'.join(lines)


def _block_classes(name):
    """Классы и публичные методы: API модуля без чтения файлов."""
    inv = class_inventory(name)

    if not inv:
        return ''

    lines = []
    rank = {'Service': 0, 'Controller': 1, 'Model': 2, 'Task': 3, 'прочее': 4}

    def kind_of(path):
        for marker, label in (('/Service/', 'Service'), ('/Controller/', 'Controller'),
                              ('/Model/', 'Model'), ('/Task/', 'Task')):
            if marker in '/' + path.replace(os.sep, '/') and label in path:
                return label

        return 'прочее'

    # сначала Service — это внешний API модуля; крупные файлы — первыми
    for item in sorted(inv, key=lambda x: (rank[kind_of(x['path'])], -x['loc'], x['path'])):

        names = ', '.join(item['classes']) or '—'
        line = '* `%s` — %s, %d стр.' % (item['path'], names, item['loc'])

        if item['methods']:
            shown = item['methods'][:18]
            tail = '' if len(item['methods']) <= 18 else ', … +%d' % (len(item['methods']) - 18)
            line += '\n  - public: ' + ', '.join('`%s()`' % m for m in shown) + tail

        lines.append(line)

    return '\n'.join(lines)


def _block_checks(name):
    """Чем модуль проверяется — с готовыми командами, а не «поищите тесты»."""
    c = checks(name)
    lines = []

    for rel in c['tests']:
        lines.append('* Тест: `%s` → `make test filter=%s`' % (rel, rel))

    for rel in c['worker_tests']:
        lines.append('* Тест воркера: `%s` → `cd workers && python -m pytest %s -q`'
                     % (rel, rel[len('workers/'):] if rel.startswith('workers/') else rel))

    php_stands = [rel for rel in c['stands'] if rel.endswith('.php')]
    if php_stands:
        lines.append('* PHP-стенды: %s → через `.tools/phpwasm_stand.mjs` (PHP-in-WASM)'
                     % ', '.join('`%s`' % rel for rel in php_stands))

    for rel in c['stands']:
        if rel.endswith(('.mjs', '.py')):
            runtime = 'node' if rel.endswith('.mjs') else 'python3'
            lines.append('* Стенд: `%s` → `%s %s`' % (rel, runtime, rel))

    if c['smoke']:
        lines.append('* HTTP-дым: %s покрыты `bash bin/check-fresh.sh`'
                     % ', '.join('`%s`' % p for p in c['smoke']))

    if not lines:
        lines.append('* **Своего покрытия нет** (ни тестов, ни стендов, ни страницы в '
                     'HTTP-дыме). Значит первый шаг работы — стенд '
                     '`.tools/<модуль>_<тема>_stand.php` или проверка страницы руками: '
                     'править вслепую модуль без страховки нельзя.')

    return '\n'.join(lines)


def _block_todo(name):
    """Открытые пункты модуля: из чего выбирать следующую задачу."""
    items = todo_items(name, limit=6, width=200)

    if not items:
        return ''

    lines = ['* ' + i for i in items]

    if len(todo_items(name)) > len(items):
        lines.append('* … остальное — `context_pack.py backlog --module %s`' % name)

    return '\n'.join(lines)


def _block_routes(name):
    found = routes(name)
    lines = []

    for r in found:
        line = '* `%s` → `%s`' % (r['pattern'], r['name'])

        if r['controller']:
            line += ' (%s::%s)' % (r['controller'], r['action'] or 'index')

        lines.append(line)

    return '\n'.join(lines)


def _block_deps(name):
    lines = []
    out = dependencies_out(name)

    if out:
        parts = ['%s (%s)' % (', '.join('`%s`' % s for s in sorted(services)), owner)
                 for owner, services in sorted(out.items())]
        lines.append('* Зовёт: ' + '; '.join(parts[:8])
                     + (' … ещё %d' % (len(parts) - 8) if len(parts) > 8 else ''))

    inc = dependencies_in(name)

    if inc:
        top = sorted(inc.items(), key=lambda kv: -kv[1])[:10]
        lines.append('* Зовут меня: ' + ', '.join('%s (%d файлов)' % (k, v) for k, v in top)
                     + (' … ещё %d' % (len(inc) - 10) if len(inc) > 10 else ''))

    handlers = workers(name)

    if handlers:
        lines.append('* Воркеры: ' + ', '.join('`workers/prx_workers/handlers/%s`' % h
                                              for h in handlers))

    return '\n'.join(lines)


def build_pack(name, max_bytes=DEFAULT_MAX, full=False):
    """Собрать контекст-пак модуля. Возвращает (текст, список обрезанных блоков)."""
    base = module_path(name)

    if not base:
        raise SystemExit('Неизвестный модуль: %s\nМодули: python3 .tools/context_pack.py pack --list'
                         % name)

    man = manifest(name)
    docs = _doc_files(name)
    agents = os.path.join(base, 'AGENTS.md')
    dropped = []

    title = short = ''

    if isinstance(man, dict) and not man.get('_error'):
        title = str(man.get('title') or '')
        short = str(man.get('short') or '')
    elif isinstance(man, dict):
        short = 'манифест не разобран: %s' % man.get('_error')

    sections_count = len(sections(agents)) if os.path.isfile(agents) else 0
    head = ['# Контекст-пак: %s%s' % (name, ' — «%s»' % title if title else ''),
            '',
            '_Карточка собрана из файлов модуля: `python3 .tools/context_pack.py pack %s`. '
            'Памятка целиком — %s (%d %s): нужен раздел — `context_pack.py section '
            '"<часть заголовка>" --module %s`._'
            % (name,
               _kb(_bytes(agents)) if os.path.isfile(agents) else 'отсутствует',
               sections_count, plural(sections_count, 'раздел', 'раздела', 'разделов'),
               name)]

    if short:
        head.append('')
        head.append(short if len(short) <= 400 else short[:400].rsplit(' ', 1)[0] + '…')

    if isinstance(man, dict) and not man.get('_error'):
        facts = []

        for key in ('href', 'group', 'status', 'auth', 'family', 'card_view'):
            if man.get(key) not in (None, '', []):
                value = man[key]
                facts.append('%s: %s' % (key, 'да' if value is True else
                                         ('нет' if value is False else value)))

        api = man.get('api')

        if isinstance(api, list) and api:
            facts.append('api: ' + ', '.join('`%s`' % a for a in api[:6]))

        if facts:
            head.append('')
            head.append('* ' + '; '.join(str(f) for f in facts))

    state = _state_excerpt(name)

    if state:
        head.append('')
        head.append(state)

    blocks = []
    facts_block = _block_facts(name, man)

    if facts_block:
        blocks.append(('Факты (из миграций и манифеста)', facts_block, False))

    classes = _block_classes(name)

    if classes:
        blocks.append(('Классы и публичные методы', classes, False))

    rts = _block_routes(name)

    if rts:
        blocks.append(('Маршруты (init.php)', rts, False))

    checks_block = _block_checks(name)

    if checks_block:
        blocks.append(('Проверки модуля', checks_block, False))

    rules = _rules_excerpt(name)

    if rules:
        blocks.append(('Правила модуля (фрагмент памятки)', rules, True))

    deps = _block_deps(name)

    if deps:
        blocks.append(('Зависимости и воркеры', deps, True))

    todo_block = _block_todo(name)

    if todo_block:
        blocks.append(('Открытые пункты (из памятки)', todo_block, True))

    if docs:
        lines = ['* %s — %s' % (fn, _kb(size)) for fn, path, size in docs]
        lines.append('* Читать раздел, а не файл: `python3 .tools/context_pack.py section '
                     '"<часть заголовка>" --module %s`' % name)
        blocks.append(('Документы модуля', '\n'.join(lines), True))

    # ссылки на платформенные доки — по фактам модуля, а не «на всякий случай»
    manual = ['* Правила платформы перед правкой: `AGENTS.md` §2 (практики), §5 (чек-лист).']

    if 'migrations_pg' in os.listdir(base):
        manual.append('* Миграции: `docs/MODULE_GUIDE.md` §4; применённые не редактируются.')

    if workers(name) or channels_used(name):
        manual.append('* Воркеры и очередь: `docs/WORKERS.md`.')

    if os.path.isdir(os.path.join(base, 'views')):
        manual.append('* Вёрстка: `AGENTS.md` §10; CSS — `npm run build:css` после правки вьюх.')

    blocks.append(('Что читать дальше', '\n'.join(manual), True))

    # --- бюджет ------------------------------------------------------------
    # Порядок жертв: сначала то, что агент добудет одной командой (список доков,
    # подсказки «что читать дальше», зависимости), затем фрагмент правил,
    # и только потом маршруты и классы. Факты и состояние не режутся никогда.
    # Порядок жертв: сначала то, что агент добудет одной командой (список
    # документов, подсказки «что читать дальше»), потом сжимается фрагмент
    # правил, и только потом уходят маршруты и классы. Факты, состояние,
    # проверки и открытые пункты не режутся: без них карточка перестаёт
    # отвечать на вопрос «что делать в этом модуле».
    sacrifice = [('drop', 'Документы модуля'),
                 ('drop', 'Что читать дальше'),
                 ('rules', RULES_LIMITS[1]),
                 ('drop', 'Зависимости и воркеры'),
                 ('rules', RULES_LIMITS[2]),
                 ('drop', 'Маршруты (init.php)'),
                 ('rules', 0),
                 ('drop', 'Открытые пункты (из памятки)'),
                 ('drop', 'Классы и публичные методы')]

    limit = 10 ** 9 if full else max_bytes
    rules_limit = 10 ** 9 if full else RULES_LIMITS[0]
    chosen = [[t, b] for t, b, _rank in blocks]

    def render(items):
        out = list(head)

        for title_b, body in items:
            shown = body[:rules_limit] if title_b.startswith('Правила') else body
            out.append('\n\n## %s\n%s' % (title_b, shown))

        out.append('\n---\n' + _footer())
        return '\n'.join(out)

    step = 0

    while len(render(chosen).encode('utf-8')) > limit and step < len(sacrifice):
        kind, value = sacrifice[step]
        step += 1

        if kind == 'rules':
            rules_limit = value
            dropped.append('фрагмент правил (полностью: section "Правила")')
            continue

        for i, (title_b, _body) in enumerate(chosen):
            if title_b == value:
                dropped.append(title_b)
                del chosen[i]
                break

    return render(chosen), dropped


def _footer():
    """Подвал карточки: чем она является и чем не является."""
    return ('_Карточка производная: пересказ кода и SQL здесь не держим — они в файлах '
            'модуля (список выше). Знание пишем обратно в `AGENTS.md` модуля, '
            'раздел «Состояние работ» — 3–5 строк._')


# --------------------------------------------------------------------------
# 5. Команды
# --------------------------------------------------------------------------

def cmd_pack(args):
    if args.list:
        for n in modules():
            path = os.path.join(MODULES_DIR, n, 'AGENTS.md')
            print('%-26s памятка %s' % (n, _kb(_bytes(path)) if os.path.isfile(path) else '—'))
        return 0

    if not args.module:
        print('Использование: pack <модуль> [--max N] [--write] | pack --list', file=sys.stderr)
        return 2

    text, dropped = build_pack(args.module, max_bytes=args.max, full=args.full)

    if dropped:
        text += '\n_Не поместилось в бюджет: %s. Читать: `context_pack.py section "<раздел>" --module %s`._\n' \
                % (', '.join(dropped), args.module)

    size = len(text.encode('utf-8'))

    if args.write or args.out:
        out = args.out or os.path.join(ROOT, '.context', '%s.md' % args.module)
        os.makedirs(os.path.dirname(os.path.abspath(out)), exist_ok=True)

        with open(out, 'w', encoding='utf-8') as fh:
            fh.write(text)

        print('%s → %s (%s)' % (args.module, os.path.relpath(out, ROOT), _kb(size)))
    else:
        print(text)

    agents = os.path.join(MODULES_DIR, args.module, 'AGENTS.md')
    full_doc = _bytes(agents)
    print('', file=sys.stderr) if not (args.write or args.out) else None

    if full_doc and size:
        if full_doc < size * 1.2:
            # Маленькому модулю карточка ничего не экономит: памятка короче неё.
            print('— памятка модуля (%s) не больше карточки (%s): такой модуль дешевле '
                  'изучать по памятке целиком' % (_kb(full_doc), _kb(size)), file=sys.stderr)
        else:
            print('— контекст-пак: %s; памятка модуля целиком: %s (экономия ≈ %.1f×)'
                  % (_kb(size), _kb(full_doc), full_doc / float(size)), file=sys.stderr)

    return 0


def cmd_section(args):
    needle = re.compile(args.needle, re.I)
    targets = []

    if args.path:
        targets = [args.path]
    elif args.module:
        base = module_path(args.module)

        if not base:
            print('Неизвестный модуль: %s' % args.module, file=sys.stderr)
            return 2

        for fn, path, size in _doc_files(args.module):
            targets.append(path)
    else:
        print('Укажите --module <модуль> или --path <файл.md>', file=sys.stderr)
        return 2

    printed = 0
    budget = args.max

    for path in targets:
        rel = os.path.relpath(path, ROOT)
        found = [s for s in sections(path) if needle.search(s[1])]

        if not found:
            continue

        if args.list:
            for level, title, line, size in found:
                print('%-32s стр. %-5d %s' % (title, line, _kb(size)))
            printed += len(found)
            continue

        for level, title, line, size in found:
            body = pick_section(path, level, line)

            if budget and len(body.encode('utf-8')) > budget:
                body = body.encode('utf-8')[:budget].decode('utf-8', 'ignore')
                body = body.rsplit('\n', 1)[0] + '\n… (обрезано: --max)'

            print('── %s › %s (стр. %d, %s)' % (rel, title, line, _kb(size)))
            print(body)
            print()
            printed += 1

    if not printed:
        print('Ничего не найдено по «%s». Разделы в файле:' % args.needle, file=sys.stderr)

        for path in targets:
            for level, title, line, size in sections(path):
                print('  %-32s стр. %-5d %s' % (title, line, _kb(size)), file=sys.stderr)

        return 1

    return 0


def cmd_backlog(args):
    """Очередь работы: открытые пункты модулей, чтобы выбирать задачу, а не искать её."""
    if args.module:
        base = module_path(args.module)

        if not base:
            print('Неизвестный модуль: %s' % args.module, file=sys.stderr)
            return 2

        names = [args.module]
    else:
        names = modules()

    shown_modules = 0
    shown_items = 0
    empty = []

    for name in names:
        items = todo_items(name, limit=args.limit)

        if not items:
            if args.module:
                empty.append(name)
            continue

        shown_modules += 1
        shown_items += len(items)

        man = manifest(name)
        title = ''

        if isinstance(man, dict) and not man.get('_error'):
            title = ' — «%s»' % man['title'] if man.get('title') else ''

        print('## %s%s' % (name, title))

        for item in items:
            print('  - %s' % item)

        total = len(todo_items(name))

        if total > len(items):
            print('  … ещё %d (показать все: backlog --module %s --limit 0)' % (total - len(items), name))

        print()

    print('— модулей с открытыми пунктами: %d, пунктов показано: %d (до %s на модуль)'
          % (shown_modules, shown_items, args.limit if args.limit else '∞'))

    if empty:
        print('— у %s открытых пунктов нет: либо всё сделано, либо не записано. '
              'Раздел «Состояние работ» — место, куда пишут следующий шаг.'
              % ', '.join(empty))

    if not args.module:
        print('— источник: разделы «Долги», «Куда развивать», TODO в памятках модулей. '
              'Это очередь для выбора задачи, а не замена решению владельца.')

    return 0


def cmd_index(args):
    for target in args.files:
        path = target if os.path.isabs(target) else os.path.join(ROOT, target)

        if not os.path.isfile(path):
            print('Нет файла: %s' % target, file=sys.stderr)
            return 2

        rel = os.path.relpath(path, ROOT)

        if args.write:
            added = write_index(path, rel)
            print('%s: индекс разделов обновлён (+%s)' % (rel, _kb(added)))
        else:
            print('# %s — индекс разделов (%s)' % (rel, _kb(_bytes(path))))
            print(render_index(path))

    return 0


def main(argv=None):
    parser = argparse.ArgumentParser(
        prog='context_pack.py',
        description='Контекст-пак модуля: дешёвый вход в помодульную разработку')
    sub = parser.add_subparsers(dest='cmd')

    p_pack = sub.add_parser('pack', help='карточка модуля (таблицы, классы, маршруты, правила)')
    p_pack.add_argument('module', nargs='?', help='имя модуля, например prx643_cad')
    p_pack.add_argument('--max', type=int, default=DEFAULT_MAX, help='бюджет в байтах (по умолчанию 8192)')
    p_pack.add_argument('--full', action='store_true', help='не подрезать блоки')
    p_pack.add_argument('--write', action='store_true', help='писать в .context/<модуль>.md')
    p_pack.add_argument('--out', help='куда писать (по умолчанию .context/<модуль>.md)')
    p_pack.add_argument('--list', action='store_true', help='список модулей и размеров памяток')
    p_pack.set_defaults(func=cmd_pack)

    p_sec = sub.add_parser('section', help='только нужный раздел памятки модуля')
    p_sec.add_argument('needle', help='часть заголовка (регулярное выражение, без учёта регистра)')
    p_sec.add_argument('--module', help='модуль')
    p_sec.add_argument('--path', help='произвольный Markdown-файл (например AGENTS.md)')
    p_sec.add_argument('--list', action='store_true', help='только заголовки с размерами')
    p_sec.add_argument('--max', type=int, default=0, help='обрезать вывод до N байт')
    p_sec.set_defaults(func=cmd_section)

    p_back = sub.add_parser('backlog', help='очередь работы: открытые пункты модулей')
    p_back.add_argument('--module', help='только один модуль')
    p_back.add_argument('--limit', type=int, default=5,
                        help='пунктов на модуль (0 — все, по умолчанию 5)')
    p_back.set_defaults(func=cmd_backlog)

    p_idx = sub.add_parser('index', help='индекс разделов Markdown-файла (+ запись в файл)')
    p_idx.add_argument('files', nargs='+', help='Markdown-файлы')
    p_idx.add_argument('--write', action='store_true', help='вписать индекс в файл (между маркерами)')
    p_idx.set_defaults(func=cmd_index)

    args = parser.parse_args(argv)

    if not args.cmd:
        parser.print_help()
        return 2

    return args.func(args)


if __name__ == '__main__':
    sys.exit(main())
