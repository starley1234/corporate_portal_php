#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Облегчённая проверка PHP-файлов БЕЗ php: баланс скобок и незакрытые строки.

Зачем: в песочнице нет php-cli, а правки в код вносятся регулярно. Скрипт ловит
самые частые поломки шаблонов и сервисов — незакрытую кавычку, лишнюю/недостающую
} ) ], в том числе в файлах вида view, где PHP перемешан с HTML.

Что умеет:
  * один проход по файлу: HTML-части пропускаются, PHP-блоки (<?php … ?>, <?= … ?>)
    разбираются с учётом строк '...', "...", heredoc/nowdoc <<<ID, комментариев
    // # /* */;
  * стек скобок ОБЩИЙ на весь файл: шаблоны вида
        <?php if ($x) { ?> html <?php } ?>
    (открытие в одном блоке, закрытие в другом) проверяются корректно;
  * сообщает строку первой ошибки.

Чего НЕ умеет (сознательно): полного синтаксического разбора PHP. Зелёный
результат — не гарантия валидности, но красный всегда означает реальную поломку.

Использование:
    python3 .tools/php_lint.py <файл|каталог> [...]
    python3 .tools/php_lint.py $(find application -name '*.php')
"""

import sys
import os
import re

PAIRS = {'{': '}', '(': ')', '[': ']'}
CLOSE = {v: k for k, v in PAIRS.items()}

HEREDOC_RE = re.compile(r"<<<[ \t]*(['\"]?)([A-Za-z_][A-Za-z0-9_]*)\1[^\n]*\n")


class LintError(Exception):
    def __init__(self, line, message):
        super().__init__(message)
        self.line = line
        self.message = message


class Parser:
    def __init__(self, src):
        self.src = src
        self.i = 0
        self.line = 1
        self.n = len(src)
        self.stack = []

    # -- служебное ---------------------------------------------------------
    def _advance_to(self, pos):
        """Передвинуть счётчик строк, пропуская текст до pos."""
        self.line += self.src.count('\n', self.i, pos)
        self.i = pos

    def _skip_string(self, quote):
        src, n = self.src, self.n
        j = self.i + 1

        while j < n:
            ch = src[j]

            if ch == '\\':
                j += 2
                continue

            if ch == quote:
                self._advance_to(j + 1)
                return

            j += 1

        raise LintError(self.line, "не закрыта строка %s…%s" % (quote, quote))

    def _skip_heredoc(self):
        m = HEREDOC_RE.match(self.src, self.i)

        if not m:
            raise LintError(self.line, 'не разобран heredoc <<<')

        ident = m.group(2)
        body_start = m.end()
        end = re.search(r'^[ \t]*' + re.escape(ident) + r'\b', self.src[body_start:], re.M)

        if not end:
            raise LintError(self.line, 'не закрыт heredoc %s' % ident)

        self._advance_to(body_start + end.start())

    # -- основной проход ---------------------------------------------------
    def run(self):
        src, n = self.src, self.n

        while self.i < n:
            ch = src[self.i]

            # вне PHP: ищем открывающий тег
            m = re.compile(r'<\?(?:php|=|php\s)').search(src, self.i)

            if self.stack and not m:
                break

            if not m:
                break

            self._advance_to(m.end())

            # внутри PHP: до закрывающего ?>
            self._php_block()

        if self.stack:
            opener, oline = self.stack[-1]
            raise LintError(oline, 'не закрыта %s (открыта на строке %d)' % (opener, oline))

    def _php_block(self):
        src, n = self.src, self.n

        while self.i < n:
            ch = src[self.i]

            if src.startswith('?>', self.i):
                self._advance_to(self.i + 2)
                return

            if ch == '\n':
                self.line += 1
                self.i += 1
                continue

            if src.startswith('//', self.i) or ch == '#':
                j = src.find('\n', self.i)
                self._advance_to(n if j < 0 else j)
                continue

            if src.startswith('/*', self.i):
                j = src.find('*/', self.i + 2)

                if j < 0:
                    raise LintError(self.line, 'не закрыт комментарий /* */')

                self._advance_to(j + 2)
                continue

            if src.startswith('<<<', self.i):
                self._skip_heredoc()
                continue

            if ch == "'" or ch == '"':
                self._skip_string(ch)
                continue

            if ch in PAIRS:
                self.stack.append((ch, self.line))
                self.i += 1
                continue

            if ch in CLOSE:
                if not self.stack:
                    raise LintError(self.line, 'лишняя закрывающая %s' % ch)

                opener, oline = self.stack.pop()

                if opener != CLOSE[ch]:
                    raise LintError(self.line, 'ожидалась %s (открыта на строке %d), а стоит %s'
                                    % (PAIRS[opener], oline, ch))

                self.i += 1
                continue

            self.i += 1

        # PHP-блок без закрывающего тега: остаток файла — PHP-код


def check_file(path):
    try:
        src = open(path, encoding='utf-8', errors='replace').read()
    except OSError as e:
        return False, 'не читается: %s' % e

    try:
        Parser(src).run()
    except LintError as e:
        return False, 'строка %d: %s' % (e.line, e.message)

    return True, ''


def collect(targets):
    out = []
    for t in targets:
        if os.path.isdir(t):
            for root, _dirs, files in os.walk(t):
                for f in files:
                    if f.endswith('.php'):
                        out.append(os.path.join(root, f))
        else:
            out.append(t)
    return sorted(set(out))


def main(argv):
    if len(argv) < 2:
        print(__doc__)
        return 2

    files = collect(argv[1:])
    bad = 0

    for f in files:
        ok, msg = check_file(f)
        if not ok:
            bad += 1
            print('[FAIL] %s — %s' % (f, msg))

    print('Проверено файлов: %d, с ошибками: %d' % (len(files), bad))

    return 1 if bad else 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
