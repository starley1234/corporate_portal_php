#!/usr/bin/env python3
"""Разбор PHP-массива-литерала без PHP.

Манифесты и конфиги проекта — `return [ ... ];` со строками, числами,
булевыми, NULL и редкими выражениями (`getenv('X') ?: 180`). Здесь enough
поддержки, чтобы читать их статически: выражения возвращаются как `Raw`
с исходным текстом, а не вычисляются.
"""
from __future__ import annotations

import re
from dataclasses import dataclass
from typing import Any


@dataclass(frozen=True)
class Raw:
    """Невычисленное PHP-выражение (getenv(...), max(...), константа)."""
    text: str


class ParseError(Exception):
    pass


_TOKEN = re.compile(r"""
    (?P<ws>\s+)
  | (?P<comment>//[^\n]*|\#[^\n]*|/\*.*?\*/)
  | (?P<sq>'(?:\\.|[^'\\])*')
  | (?P<dq>"(?:\\.|[^"\\])*")
  | (?P<num>-?\d+(?:\.\d+)?)
  | (?P<word>[A-Za-z_][A-Za-z0-9_]*)
  | (?P<arrow>=>)
  | (?P<punct>[\[\](),;?:.])
""", re.VERBOSE | re.DOTALL)


def _tokenize(text: str):
    pos = 0
    while pos < len(text):
        m = _TOKEN.match(text, pos)
        if not m:
            raise ParseError(f'не разобрать: {text[pos:pos+40]!r}')
        pos = m.end()
        kind = m.lastgroup
        if kind in ('ws', 'comment'):
            continue
        yield kind, m.group()
    yield 'eof', ''


def _unquote(token: str) -> str:
    quote = token[0]
    body = token[1:-1]
    if quote == "'":
        return re.sub(r"\\(['\\])", r"\1", body)
    return re.sub(r"\\(.)", r"\1", body)


class _Parser:
    def __init__(self, text: str):
        self.tokens = list(_tokenize(text))
        self.i = 0

    def peek(self):
        return self.tokens[self.i]

    def take(self, expected: str | None = None):
        kind, value = self.tokens[self.i]
        if expected is not None and value != expected:
            raise ParseError(f'ожидалось {expected!r}, получено {value!r}')
        self.i += 1
        return kind, value

    def parse(self):
        kind, value = self.peek()
        if value == ';':
            return None
        result = self.value()
        while self.peek()[1] == ';':
            self.take(';')
        return result

    def value(self):
        kind, value = self.peek()

        if value == '[':
            return self.array()
        if value == '(':
            self.take('(')
            inner = self.value()
            self.take(')')
            return self.concat(self._maybe_ternary(inner))

        if kind == 'sq':
            self.take()
            return self.concat(self._maybe_ternary(_unquote(value)))
        if kind == 'dq':
            self.take()
            return self.concat(self._maybe_ternary(_unquote(value)))
        if kind == 'num':
            self.take()
            return self.concat(self._maybe_ternary(float(value) if '.' in value else int(value)))
        if kind == 'word':
            self.take()
            upper = value.upper()
            if upper == 'TRUE':
                return self._maybe_ternary(True)
            if upper == 'FALSE':
                return self._maybe_ternary(False)
            if upper == 'NULL':
                return self._maybe_ternary(None)
            if upper == 'ARRAY' and self.peek()[1] == '(':
                self.take('(')
                inner = self.array_items(')')
                self.take(')')
                return inner
            # функция или константа: съедаем баланс скобок и возвращаем Raw
            call = value
            while self.peek()[1] == '(':
                call += self._balanced()
            if self.peek()[1] == '::':
                self.take('::')
                _, member = self.take()
                call += '::' + member
            return self.concat(self._maybe_ternary(Raw(call)))

        raise ParseError(f'неожиданный токен {value!r}')

    def concat(self, left):
        """Склейка строк оператором `.` ('a' . 'b')."""
        while self.peek()[1] == '.':
            self.take('.')
            right = self.value()
            if isinstance(left, str) and isinstance(right, str):
                left = left + right
            else:
                left = Raw(f'{left} . {right}')
        return left

    def _balanced(self) -> str:
        self.take('(')
        depth, out = 1, ['(']
        while depth:
            kind, value = self.take()
            out.append(value)
            if value == '(':
                depth += 1
            elif value == ')':
                depth -= 1
        return ' '.join(out)

    def _maybe_ternary(self, left):
        nxt = self.peek()[1]
        if nxt == '?':
            self.take('?')
            if self.peek()[1] == ':':
                self.take(':')
                self._skip_expression()
            else:
                self._skip_expression()
                if self.peek()[1] == ':':
                    self.take(':')
                    self._skip_expression()
            return Raw(str(left))
        return left

    def _skip_expression(self):
        """Пропускаем выражение до границы значения (, ] ) на глубине 0)."""
        depth = 0
        while True:
            kind, value = self.peek()
            if kind == 'eof':
                return
            if value in '[(':
                depth += 1
            elif value in '])':
                if depth == 0:
                    return
                depth -= 1
            elif value == ',' and depth == 0:
                return
            self.take()

    def array(self):
        self.take('[')
        return self.array_items(']')

    def array_items(self, closer: str):
        out: dict[str, Any] = {}
        index = 0
        while True:
            kind, value = self.peek()
            if value == closer:
                self.take(closer)
                return out
            if value == ',':
                self.take(',')
                continue
            key = self.value()
            if self.peek()[1] == '=>':
                self.take('=>')
                out[str(key)] = self.value()
            else:
                out[str(index)] = key
                index += 1


def parse_php_array(text: str) -> dict:
    """`return [ ... ];` → dict. Комментарий перед return допустим."""
    text = re.sub(r'^<\?php\s*', '', text.strip())
    match = re.search(r'\breturn\b', text)
    if match:
        text = text[match.end():]
    value = _Parser(text).parse()
    if value is None:
        return {}
    if not isinstance(value, dict):
        raise ParseError('ожидался массив')
    return value


def read_config(path) -> dict:
    """Читает PHP-файл с массивом; при ошибке возвращает {}."""
    from pathlib import Path
    try:
        return parse_php_array(Path(path).read_text(encoding='utf-8'))
    except (ParseError, IndexError, ValueError):
        return {}
