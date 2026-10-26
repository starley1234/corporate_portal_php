# -*- coding: utf-8 -*-
"""Ссылки на классы `Foo::` в PHP: ищем имена, которых в проекте нет.

Зачем: в песочнице PHP не запускается (`php -l` нет, tree-sitter проверяет
только синтаксис), а опечатка в имени класса падает лишь в бою. Так вышло с
`Store::cfg(...)` вместо `Service_Nj646_Store::cfg(...)` в
`Service_Nj646_Label::product_kind()` — разбор этикетки упал с
«Class "Store" not found», хотя синтаксис файла был безупречен.

Что делает: собирает имена классов/интерфейсов/трейтов из `application/`,
`system/`, `modules/` и сверяет с ними каждый вызов `Foo::…` в `application/`
и `public_html/`. Строки, комментарии (PHP и HTML) и встроенные классы PHP
из проверки исключены — иначе SQL-касты (`NULL::timestamptz`) и упоминания в
докблоках дают шум.

Запуск: python3 .tools/php_class_ref_lint.py [пути…]
Код возврата 1, если есть ОШИБКИ (незнакомое имя). `KNOWN_LEGACY` — имена из
мёртвого кода, они печатаются предупреждением и сборку не роняют.
"""

import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# Где искать объявления классов и где — их использования.
DECL_DIRS = ('application', 'system', 'modules')
USE_DIRS = ('application', 'public_html')

# Встроенные классы и интерфейсы PHP, которых в коде проекта нет.
PHP_BUILTIN = {
    'PDO', 'PDOStatement', 'DateTime', 'DateTimeImmutable', 'DateTimeZone',
    'DateInterval', 'DatePeriod', 'Closure', 'Exception', 'ErrorException',
    'Error', 'Throwable', 'TypeError', 'ValueError', 'ArgumentCountError',
    'ReflectionClass', 'ReflectionMethod', 'ReflectionProperty',
    'ReflectionFunction', 'ReflectionObject', 'ReflectionException',
    'SimpleXMLElement', 'DOMDocument', 'DOMElement', 'DOMXPath', 'ZipArchive',
    'SplFileObject', 'SplFileInfo', 'SplObjectStorage', 'SplQueue', 'SplStack',
    'SplFixedArray', 'SplSubject', 'SplObserver', 'ArrayObject', 'ArrayIterator',
    'RecursiveIteratorIterator', 'DirectoryIterator', 'FilesystemIterator',
    'GlobIterator', 'LimitIterator', 'CachingIterator', 'stdClass', 'Generator',
    'Fiber', 'WeakMap', 'JsonException', 'RuntimeException', 'LogicException',
    'InvalidArgumentException', 'UnexpectedValueException', 'OutOfBoundsException',
    'DomainException', 'RangeException', 'LengthException', 'OverflowException',
    'UnderflowException', 'BadMethodCallException', 'BadFunctionCallException',
    'DivisionByZeroError', 'ArithmeticError', 'Stringable', 'Traversable',
    'Iterator', 'IteratorAggregate', 'Countable', 'ArrayAccess', 'Serializable',
    'JsonSerializable', 'SessionHandlerInterface', 'SessionHandler',
    'IntlDateFormatter', 'NumberFormatter', 'Collator', 'Transliterator',
    'Normalizer', 'Locale', 'MessageFormatter', 'CurlHandle', 'CURLFile',
    'Finfo', 'GdImage', 'ResourceBundle', 'SQLite3', 'SoapClient',
}

# Известный легасый хвост: имена из мёртвого кода (Yii-порт erp212, старый
# реестр erp227). Никто их не вызывает — проверено grep-ом по проекту; держим
# списком, чтобы линт видел НОВЫЕ ошибки, а не тонул в старых.
KNOWN_LEGACY = {
    'Registry', 'Service_Erp227_Registry', 'Service_Erp227_Search',
    'Yii', 'CLogger', 'CDbCriteria', 'TempLink',
}

# Живой код, где класса нет, а правильной замены в проекте не видно: это
# заготовки ERP-порта (сообщения после redirect). Чинить нечем, пока владелец
# не скажет, как показывать сообщение, — поэтому печатаем отдельным разрядом
# и сборку не роняем. Новые имена сюда НЕ добавлять без пометки в журнале.
NEEDS_OWNER = {
    'Message': 'нет класса Message в проекте; кнопки/редиректы llm и erp217 молча падают',
}

DECL_RX = re.compile(r'\b(?:abstract\s+|final\s+)?(?:class|interface|trait)\s+([A-Za-z_][A-Za-z0-9_]*)')
CLASS_ALIAS_RX = re.compile(r"class_alias\(\s*'([^']+)'\s*,\s*'([^']+)'")
USE_RX = re.compile(r'(?<![$A-Za-z0-9_\\])([A-Z][A-Za-z0-9_]*)::')
USE_PHP_RX = re.compile(r'\.php$', re.I)


def _php_files(root, dirs):
    for d in dirs:
        base = os.path.join(root, d)

        if not os.path.isdir(base):
            continue

        for path, dirs_here, files in os.walk(base):
            dirs_here[:] = [x for x in dirs_here if x not in ('.git', 'vendor', 'node_modules')]

            # application/cache — рантайм-каталог, а modules/cache — код Kohana
            if os.path.abspath(path) == os.path.join(root, 'application', 'cache'):
                dirs_here[:] = []

            for name in files:
                if USE_PHP_RX.search(name):
                    yield os.path.join(path, name)


def declared_classes(root):
    """Имена классов/интерфейсов/трейтов, объявленных в проекте."""
    names = set()

    for path in _php_files(root, DECL_DIRS):
        text = _read(path)

        for m in DECL_RX.finditer(text):
            names.add(m.group(1))

        for _orig, alias in CLASS_ALIAS_RX.findall(text):
            names.add(alias.split('\\')[-1])

    return names


def strip_code(text):
    """Вырезать строки и комментарии — остаётся «голый» PHP-код.

    Наивно, но для сверки имён классов достаточно: важно не принять текст
    строки (`'NULL::timestamptz'`) или абзац докблока за вызов класса.
    Переводы строк сохраняем — иначе номера в отчёте съезжают.
    """
    out = []
    i, n = 0, len(text)
    block = False      # /* … */
    html = False       # <!-- … -->

    while i < n:
        ch = text[i]
        two = text[i:i + 2]

        if block or html:
            if block and two == '*/':
                block = False
                i += 2
                continue

            if html and text[i:i + 3] == '-->':
                html = False
                i += 3
                continue

            if ch == "\n":
                out.append("\n")

            i += 1
            continue

        if two == '/*':
            block = True
            i += 2
            continue

        if text[i:i + 4] == '<!--':
            html = True
            i += 4
            continue

        if two == '//' or ch == '#':
            while i < n and text[i] != "\n":
                i += 1
            continue

        if ch in ('"', "'"):
            quote = ch
            i += 1
            while i < n:
                if text[i] == '\\':
                    i += 2
                    continue

                if text[i] == quote:
                    i += 1
                    break

                if text[i] == "\n":
                    out.append("\n")

                i += 1
            out.append('""')
            continue

        out.append(ch)
        i += 1

    return ''.join(out)


def check(root, paths):
    declared = declared_classes(root)
    errors, legacy, owner = [], [], []

    if paths:
        files = [p for p in paths if os.path.isfile(p) and USE_PHP_RX.search(p)]
    else:
        files = list(_php_files(root, USE_DIRS))

    for path in files:
        rel = os.path.relpath(path, root)

        for num, line in enumerate(strip_code(_read(path)).splitlines(), 1):
            for name in USE_RX.findall(line):
                if name in declared or name in PHP_BUILTIN:
                    continue

                row = (rel, num, name, line.strip()[:110])

                if name in KNOWN_LEGACY:
                    legacy.append(row)
                elif name in NEEDS_OWNER:
                    owner.append(row)
                else:
                    errors.append(row)

    return files, errors, legacy, owner


def _read(path):
    try:
        return open(path, encoding='utf-8', errors='ignore').read()
    except OSError:
        return ''


def main():
    paths = sys.argv[1:]
    files, errors, legacy, owner = check(ROOT, paths)

    print('== Ссылки на классы без объявления (php_class_ref_lint) ==\n')

    for rel, num, name, line in errors:
        print('  ОШИБКА  %s:%d  %s::  | %s' % (rel, num, name, line))

    if owner:
        print('\n  Требует решения владельца (класса нет, замены в проекте нет):')

        for rel, num, name, line in owner:
            print('    %s — %s  (%s:%d)' % (name, NEEDS_OWNER[name], rel, num))

    if legacy:
        print('\n  Легасый хвост (мёртвый код, никто не вызывает — проверено grep-ом):')

        for rel, num, name, line in legacy:
            print('    %s  (%s:%d)' % (name, rel, num))

    print('\nПроверено файлов: %d, ошибок: %d, легасых: %d, требуют решения: %d'
          % (len(files), len(errors), len(legacy), len(owner)))

    return 1 if errors else 0


if __name__ == '__main__':
    sys.exit(main())
