#!/usr/bin/env python3
"""Ловит ловушку Kohana: второй аргумент `Request::post()`/`query()` — это ЗАПИСЬ.

    $this->request->post('id', 0);     // ← вернёт Request, а не 0!

В ядре (`system/classes/Kohana/Request.php`) подпись такая:

    public function post($key = NULL, $value = NULL)
    {
        ...
        if ($key === NULL)      return $this->_post;       // весь массив
        elseif ($value === NULL) return Arr::path(...);     // ЧТЕНИЕ поля
        $this->_post[$key] = $value;                        // ЗАПИСЬ поля
        return $this;                                       // ← и вот сюрприз
    }

То есть «значение по умолчанию» вторым аргументом не поддерживается вообще:
`post('id', 0)` — это setter, он возвращает сам объект Request. Дальше `(int)`
по объекту даёт фатал «Object of class Request could not be converted to int»
(живая авария 08.10.2026: `/airadar/idea` — кнопка борда идей отвечала 500).

Правильно:

    $id     = (int) Arr::get($_POST, 'id', 0);          // значение по умолчанию
    $status = (string) $this->request->post('status', NULL);  // NULL — «просто читать»

`Request::param($key, $default)` — наоборот, второй аргумент И есть значение по
умолчанию, поэтому его этот линтер не трогает.

Запуск:  python3 .tools/php_request_param_lint.py [путь]   (по умолчанию application/)
Выход:   0 — чисто, 1 — есть такие вызовы.
"""

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

# Комментарии вырезаем: в памятках ловушку как раз и показывают примером,
# и повторять её «находкой» линтера не нужно.
COMMENT = re.compile(r"/\*.*?\*/|//[^\n]*", re.S)

# Второй аргумент не NULL → это запись в Request, а не чтение с умолчанием.
CALL = re.compile(
    r"->(post|query)\(\s*((?:'[^']*'|\"[^\"]*\"))\s*,\s*(?!NULL\s*\))([^)]*)",
    re.S,
)

SKIP_DIRS = {".git", "vendor", "node_modules", "logs"}


def files(target):
    path = (ROOT / target).resolve()

    if path.is_file():
        yield path
        return

    for p in sorted(path.rglob("*.php")):
        if SKIP_DIRS & set(p.parts):
            continue
        yield p


def problems_in(source: str):
    for m in CALL.finditer(COMMENT.sub(" ", source)):
        call = m.group(1)
        key = m.group(2).strip()
        value = " ".join(m.group(3).split())

        yield (f"`->{call}({key}, …)` — второй аргумент это ЗАПИСЬ, а не значение "
               f"по умолчанию: вызов вернёт Request (сейчас передан «{value}»). "
               f"Нужно Arr::get($_POST/$_GET, {key}, …) или `->{call}({key}, NULL)`")


def main(argv: list[str]) -> int:
    target = argv[1] if len(argv) > 1 else "application"
    bad = 0
    checked = 0

    for path in files(target):
        checked += 1

        try:
            source = path.read_text(encoding="utf-8", errors="replace")
        except OSError:
            continue

        for problem in problems_in(source):
            rel = path.relative_to(ROOT) if path.is_relative_to(ROOT) else path
            print(f"{rel}: {problem}")
            bad += 1

    print(f"Файлов: {checked}, вызовов с «умолчанием»: {bad}")

    return 1 if bad else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
