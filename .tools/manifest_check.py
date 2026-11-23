#!/usr/bin/env python3
"""Схема манифеста модуля `config/prx_module.php`.

Манифест — контракт модуля перед каталогом, поиском, админкой и агентом.
Проверка статическая (без PHP), поэтому запускается в любом окружении.

Три вида модулей (`kind`):

  module    (по умолчанию) — карточка витрины: id, slug, title, short,
                             status, audience, href, acl
  platform                — платформенный модуль с реестром, но без карточки:
                             id, slug, title, short
  library                 — инфраструктура без id (драйвер БД, фасад LLM):
                             title, short

Правила значений:
  id        целое; для `prxNNN_slug` совпадает с номером папки.
  slug      имя папки модуля
  status    draft | beta | stable | deprecated
  audience  public | auth | admin | agent
  acl       для audience=auth|admin — ключ из application/config/acl.php
            (или маска `prxNNN.*`); для public — отсутствует
  href      начинается с "/" и не повторяется между модулями
  api       каждый путь начинается с "/"

Запуск: python3 .tools/manifest_check.py [--json]
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
KINDS = {'module', 'platform', 'library'}
STATUSES = {'draft', 'beta', 'stable', 'deprecated'}
AUDIENCES = {'public', 'auth', 'admin', 'agent'}
ERRORS: list[str] = []


def fail(message: str) -> None:
    ERRORS.append(message)


def text(value) -> str:
    return value.text.strip("'\"") if isinstance(value, Raw) else str(value)


def acl_keys() -> set[str]:
    return {str(key) for key in read_config(ROOT / 'application/config/acl.php')}


def declared_acl(ability: str, keys: set[str]) -> bool:
    if ability in keys:
        return True
    return f"{ability.split('.', 1)[0]}.*" in keys


def check_module(path: Path, hrefs: dict[str, str], keys: set[str]) -> None:
    name = path.parent.parent.name
    manifest = read_config(path)

    if not manifest:
        fail(f'{name}: манифест не разобран')
        return

    kind = text(manifest.get('kind') or 'module')
    if kind not in KINDS:
        fail(f'{name}: `kind` = {kind!r}, ожидалось одно из {sorted(KINDS)}')

    if not text(manifest.get('title') or '').strip():
        fail(f'{name}: пустой `title`')
    if not text(manifest.get('short') or '').strip():
        fail(f'{name}: нет `short` — аннотация для каталога и агента')

    if kind == 'library' and name.startswith('prx'):
        fail(f'{name}: библиотека должна иметь имя без префикса prx')
    if kind != 'library' and not re.fullmatch(r'prx[0-9]+_[a-z0-9_]+', name):
        fail(f'{name}: для модуля требуется имя prxNNN_slug')

    if kind != 'library':
        mid = manifest.get('id')
        if not isinstance(mid, int):
            fail(f'{name}: `id` должен быть целым')
        else:
            folder = re.match(r'prx(\d+)_', name)
            if folder:
                number = int(folder.group(1))
                if number != mid:
                    fail(f'{name}: `id` = {mid}, а папка prx{number}')

        slug = text(manifest.get('slug') or '')
        if slug != name:
            fail(f'{name}: `slug` = {slug!r}, ожидалось {name!r}')

    if kind == 'library':
        for field in ('id', 'slug', 'href', 'audience', 'acl'):
            if field in manifest:
                fail(f'{name}: `kind=library` не должен объявлять `{field}`')
        return

    status = manifest.get('status')
    if status is not None and text(status) not in STATUSES:
        fail(f'{name}: `status` = {text(status)!r}, ожидалось одно из {sorted(STATUSES)}')

    audience = manifest.get('audience')
    if audience is not None and text(audience) not in AUDIENCES:
        fail(f'{name}: `audience` = {text(audience)!r}, ожидалось одно из {sorted(AUDIENCES)}')

    if kind == 'platform':
        for field in ('href', 'acl', 'audience'):
            if field in manifest:
                fail(f'{name}: `kind=platform` не должен объявлять `{field}` (это не карточка)')
        return

    # kind=module: карточка витрины
    if status is None:
        fail(f'{name}: карточка витрины требует `status`')
    if audience is None:
        fail(f'{name}: карточка витрины требует `audience`')

    acl = text(manifest.get('acl') or '')
    if text(audience) == 'public':
        if acl:
            fail(f'{name}: audience=public не должен объявлять `acl`')
    elif not acl:
        fail(f'{name}: audience={text(audience)} требует `acl`')
    elif not declared_acl(acl, keys):
        fail(f'{name}: `acl` = {acl!r} не объявлен в application/config/acl.php')

    href = text(manifest.get('href') or '')
    if not href.startswith('/'):
        fail(f'{name}: карточка витрины требует `href` начиная с "/": {href!r}')
    elif href in hrefs:
        fail(f'{name}: `href` = {href} уже занят модулем {hrefs[href]}')
    else:
        hrefs[href] = name

    api = manifest.get('api') or []
    if isinstance(api, dict):
        api = [value for value in api.values() if not isinstance(value, Raw)]
    for item in api:
        if not text(item).startswith('/'):
            fail(f'{name}: путь `api` должен начинаться с "/": {text(item)!r}')

    # api_scopes: права внешнего API, которые модуль отдаёт потребителям.
    # Ключ — prxNNN.<action> с ID модуля; значение — title и необязательный rate_limit.
    scopes = manifest.get('api_scopes')
    if scopes is None or scopes == []:
        scopes = {}
    if not isinstance(scopes, dict):
        fail(f'{name}: `api_scopes` должен быть массивом scope => описание')
        scopes = {}
    for scope, meta in scopes.items():
        scope_text = text(scope)
        if not re.fullmatch(r'prx\d+\.[a-z0-9_.]+', scope_text):
            fail(f'{name}: scope {scope_text!r} должен быть prxNNN.<action>')
            continue
        if isinstance(manifest.get('id'), int) and not scope_text.startswith(f'prx{manifest["id"]}.'):
            fail(f'{name}: scope {scope_text!r} не совпадает с ID модуля {manifest["id"]}')
        if not isinstance(meta, dict):
            fail(f'{name}: scope {scope_text!r} должен описываться массивом')
            continue
        if not text(meta.get('title') or '').strip():
            fail(f'{name}: scope {scope_text!r} без `title`')
        rate = meta.get('rate_limit', 60)
        if isinstance(rate, Raw) or not isinstance(rate, int) or rate < 1:
            fail(f'{name}: scope {scope_text!r}: `rate_limit` — целое ≥ 1')


def main() -> int:
    hrefs: dict[str, str] = {}
    keys = acl_keys()
    manifests = sorted(MODULES.glob('*/config/prx_module.php'))
    if not manifests:
        fail('манифесты не найдены')

    for folder in sorted(MODULES.iterdir()):
        if folder.is_dir() and not (folder / 'config/prx_module.php').is_file():
            fail(f'{folder.name}: отсутствует config/prx_module.php')
        if folder.is_dir() and folder.name.startswith('prx'):
            if not re.fullmatch(r'prx[0-9]+_[a-z0-9_]+', folder.name):
                fail(f'{folder.name}: неверное имя prx-модуля')
            if not (folder / 'AGENTS.md').is_file():
                fail(f'{folder.name}: отсутствует AGENTS.md')

    for path in manifests:
        check_module(path, hrefs, keys)

    if '--json' in sys.argv:
        print(json.dumps({'ok': not ERRORS, 'errors': ERRORS}, ensure_ascii=False, indent=2))
    else:
        for error in ERRORS:
            print(f'  ✗ {error}')
        print(f'манифестов: {len(manifests)}, ошибок: {len(ERRORS)}')

    return 1 if ERRORS else 0


if __name__ == '__main__':
    raise SystemExit(main())
