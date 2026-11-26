"""Регрессия ParseError/RuntimeError в throw: класс исключения — только литералом или переменной-именем.

Две реальные аварии prx399 (26.11.2026):
  1. ``throw new $this->ERROR(...)`` — константа дёргалась как свойство:
     parse проходит, но в рантайме «Undefined property» поверх настоящей ошибки;
  2. ``throw new self::ERROR(...)`` — синтаксически НЕВАЛИДНО для PHP 7/8
     («unexpected identifier "ERROR", expecting variable or "$"»), модуль
     перестаёт загружаться вовсе. node-парсер php_lint этот синтаксис
     пропускает, поэтому ловим грепом здесь.

Правильные формы: ``throw new Prx399_Error(...)`` или
``$class = self::ERROR; throw new $class(...)``.
"""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]

# Любой из паттернов — ошибка:
#   new self::CONST( / new static::CONST( / new parent::CONST( — parse error PHP;
#   new $x->member( / new $x::member( — имя класса через член объекта (ловушка
#   «undefined property» на константе) и в prx399 легитимных применений нет.
BAD = re.compile(
    r'new\s+(?:self|static|parent)::[A-Za-z_]\w*\s*\(|'
    r'new\s+\$\w+(?:->|::)[A-Za-z_]\w*\s*\('
)

FILES = [p for p in (ROOT / 'application').rglob('*.php')]


class PhpThrowClassSyntaxTest(unittest.TestCase):

    def test_no_class_constant_fetch_in_new_expression(self):
        for path in FILES:
            with self.subTest(path=path):
                self.assertNoBadConstruct(path)

    def assertNoBadConstruct(self, path):
        source = path.read_text(encoding='utf-8')
        match = BAD.search(source)
        self.assertIsNone(
            match,
            'невалидный синтаксис new <class-ref>(...): %s — используйте '
            'литеральное имя класса исключения' % (match.group(0) if match else '?'),
        )


if __name__ == '__main__':
    unittest.main()
