#!/usr/bin/env python3
"""Реестр маршрутов: один список вместо grep по init.php.

Собирает `Route::set(...)` из `application/modules/*/init.php`,
`application/bootstrap.php` и `application/config/routes.php`, проверяет:

  - имя маршрута уникально;
  - класс контроллера существует (Controller_<Name> → classes/Controller/...);
  - у контроллера есть метод `action_<action>` (или `action_index` при пустом);
  - URI-шаблон не повторяется в двух модулях;
  - `href` и `api` манифестов доступны хотя бы одному маршруту.

Пишет `docs/architecture/dist/routes.json` (машина) и печатает сводку.
Запуск: python3 .tools/route_registry.py [--json] [--write]
"""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from php_literal import Raw, read_config  # noqa: E402

ROOT = Path(__file__).resolve().parent.parent
MODULES = ROOT / 'application/modules'
DIST = ROOT / 'docs/architecture/dist'

PAIR = re.compile(r"""['"](?P<key>[a-z_]+)['"]\s*=>\s*['"](?P<value>[^'"]*)['"]""")
ROUTE_CALL = re.compile(r'Route::set\s*\(')
DEFAULTS_CALL = re.compile(r'->\s*defaults\s*\(')


def balanced(source: str, start: int) -> tuple[int, str]:
    """`( … )` от позиции `start`: возвращает индекс после закрывающей и тело."""
    depth, index = 0, start
    in_string = ''
    while index < len(source):
        char = source[index]
        if in_string:
            if char == '\\':
                index += 2
                continue
            if char == in_string:
                in_string = ''
        elif char in '\'"':
            in_string = char
        elif char == '(':
            depth += 1
        elif char == ')':
            depth -= 1
            if depth == 0:
                return index + 1, source[start + 1:index]
        index += 1
    raise ValueError('несбалансированные скобки')


def split_args(body: str) -> list[str]:
    """Аргументы вызова через запятую на верхнем уровне."""
    args, depth, index, in_string, start = [], 0, 0, '', 0
    while index < len(body):
        char = body[index]
        if in_string:
            if char == '\\':
                index += 2
                continue
            if char == in_string:
                in_string = ''
        elif char in '\'"':
            in_string = char
        elif char in '([':
            depth += 1
        elif char in ')]':
            depth -= 1
        elif char == ',' and depth == 0:
            args.append(body[start:index])
            start = index + 1
        index += 1
    args.append(body[start:])
    return [arg.strip() for arg in args if arg.strip()]


def quoted(text: str) -> str:
    text = text.strip()
    if len(text) >= 2 and text[0] == text[-1] and text[0] in '\'"':
        return text[1:-1]
    return ''
PAIR = re.compile(r"""['"](?P<key>[a-z_]+)['"]\s*=>\s*['"](?P<value>[^'"]*)['"]""")

ERRORS: list[str] = []
WARNINGS: list[str] = []


def controller_file(controller: str) -> Path | None:
    """`Prx300_Api` → classes/Controller/Prx300/Api.php; `welcome` → Welcome.php."""
    parts = [part[:1].upper() + part[1:] for part in controller.split('_') if part]
    if not parts:
        return None
    relative = Path('classes') / 'Controller' / Path(*parts[:-1]) / f'{parts[-1]}.php'
    for base in list(MODULES.glob('*/')) + [ROOT / 'application']:
        candidate = base / relative
        if candidate.is_file():
            return candidate
    return None


def uri_pattern(template: str) -> re.Pattern:
    """`prx212/<action>(/<id>)` → регулярка для конкретного пути."""
    out, index = '', 0
    while index < len(template):
        char = template[index]
        if char == '<':
            out += '[^/]+'
            index = template.index('>', index) + 1
        elif char == '{':
            out += '[^/]+'
            index = template.index('}', index) + 1
        elif char == '(':
            out += '(?:'
            index += 1
        elif char == ')':
            out += ')?'
            index += 1
        else:
            out += re.escape(char)
            index += 1
    return re.compile(f'^/{out}/?$')


def regex_alternatives(regex: str) -> list[str]:
    """`['action' => '(telegram|max)']` → ['telegram', 'max']; иначе []."""
    found = re.search(r"['\"]action['\"]\s*=>\s*['\"]\(([^)|]+)\)['\"]", regex or '')
    if not found:
        return []
    return [item.strip() for item in found.group(1).split('|') if item.strip()]


def methods(path: Path) -> set[str]:
    source = path.read_text(encoding='utf-8', errors='ignore')
    return set(re.findall(r'function\s+action_([a-z0-9_]+)\s*\(', source))


def collect() -> list[dict]:
    routes: list[dict] = []
    sources = sorted(MODULES.glob('*/init.php'))
    for extra in (ROOT / 'application/bootstrap.php', ROOT / 'application/routes.php'):
        if extra.is_file():
            sources.append(extra)

    for path in sources:
        source = path.read_text(encoding='utf-8', errors='ignore')
        module = path.parent.name if path.name == 'init.php' else 'application'

        for call in ROUTE_CALL.finditer(source):
            open_paren = call.end() - 1
            close, body = balanced(source, open_paren)
            args = split_args(body)
            if len(args) < 2:
                continue

            name, uri = quoted(args[0]), quoted(args[1])
            regex = args[2] if len(args) > 2 else ''

            defaults: dict[str, str] = {}
            tail = DEFAULTS_CALL.search(source, close)
            if tail:
                _, defaults_body = balanced(source, tail.end() - 1)
                defaults_body = re.sub(r'^\s*array\s*\((.*)\)\s*$', r'\1', defaults_body, flags=re.S)
                defaults = {m.group('key'): m.group('value') for m in PAIR.finditer(defaults_body)}

            directory = defaults.get('directory', '')
            controller = defaults.get('controller', '')
            full_controller = f'{directory}_{controller}' if directory else controller

            routes.append({
                'name': name,
                'uri': uri,
                'regex': regex,
                'controller': full_controller,
                'action': defaults.get('action', 'index'),
                'module': module,
            })
    return routes


def main() -> int:
    routes = collect()
    seen_names: dict[str, str] = {}
    seen_uris: dict[str, str] = {}
    cache: dict[str, set[str]] = {}
    patterns: list[re.Pattern] = []

    for route in routes:
        name, uri, module = route['name'], route['uri'], route['module']

        if name in seen_names:
            ERRORS.append(f'имя маршрута повторяется: {name} ({seen_names[name]} и {module})')
        seen_names[name] = module

        if uri in seen_uris:
            ERRORS.append(f'URI повторяется: {uri} ({seen_uris[uri]} и {module})')
        seen_uris[uri] = module

        patterns.append(uri_pattern(uri))

        if not route['controller']:
            ERRORS.append(f'{name}: маршрут без `controller`')
            continue

        path = controller_file(route['controller'])
        if path is None:
            ERRORS.append(f'{name}: нет контроллера {route["controller"]} (модуль {module})')
            continue

        if path not in cache:
            cache[path] = methods(path)
        actions = cache[path]
        dynamic = '<action>' in uri
        if dynamic:
            # Действие приходит из URI: проверяем перечисленные альтернативы.
            for alternative in regex_alternatives(route['regex']):
                if alternative not in actions:
                    ERRORS.append(
                        f'{name}: у {route["controller"]} нет action_{alternative} '
                        f'({path.relative_to(ROOT)})')
        else:
            action = route['action'].replace('-', '_')
            if action and action not in actions:
                ERRORS.append(
                    f'{name}: у {route["controller"]} нет action_{action} ({path.relative_to(ROOT)})')

        route['file'] = str(path.relative_to(ROOT))

    # Манифест: href и api должны быть достижимы хотя бы одним маршрутом
    for manifest_path in sorted(MODULES.glob('*/config/prx_module.php')):
        module = manifest_path.parent.parent.name
        manifest = read_config(manifest_path)
        if not manifest:
            continue
        paths = []
        href = manifest.get('href')
        if href and not isinstance(href, Raw):
            paths.append(str(href))
        api = manifest.get('api') or []
        if isinstance(api, dict):
            api = [str(v) for v in api.values() if not isinstance(v, Raw)]
        paths.extend(str(p) for p in api)

        for candidate in paths:
            path = candidate.split('?')[0].rstrip('/') or '/'
            if not any(pattern.match(path) for pattern in patterns):
                WARNINGS.append(f'{module}: `{candidate}` не совпадает ни с одним маршрутом')

    DIST.mkdir(parents=True, exist_ok=True)
    payload = {'ok': not ERRORS, 'count': len(routes), 'routes': routes}
    (DIST / 'routes.json').write_text(
        json.dumps(payload, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')

    for error in ERRORS:
        print(f'  ✗ {error}')
    for warning in WARNINGS:
        print(f'  ! {warning}')
    print(f'маршрутов: {len(routes)}, ошибок: {len(ERRORS)}, предупреждений: {len(WARNINGS)}')
    print(f'реестр: {DIST / "routes.json"}')

    return 1 if ERRORS else 0


if __name__ == '__main__':
    raise SystemExit(main())
