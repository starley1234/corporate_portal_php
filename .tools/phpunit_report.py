#!/usr/bin/env python3
"""Публикует ошибки JUnit PHPUnit как аннотации GitHub Actions (без зависимостей)."""
import sys
import xml.etree.ElementTree as ET
from pathlib import Path


def escape(value: str, property_value: bool = False) -> str:
    value = value.replace('%', '%25').replace('\r', '%0D').replace('\n', '%0A')
    return value.replace(':', '%3A').replace(',', '%2C') if property_value else value


def report(path: Path) -> int:
    try:
        root = ET.parse(path).getroot()
    except (OSError, ET.ParseError) as error:
        print('::error title=PHPUnit report::' + escape(f'JUnit недоступен: {error}'))
        return 1

    failed = 0
    cases = list(root.iter('testcase'))
    for case in cases:
        for problem in list(case):
            if problem.tag not in ('failure', 'error'):
                continue
            failed += 1
            title = f"{case.get('classname', '')}::{case.get('name', '')}"
            # Не вставляем произвольные XML-атрибуты в workflow-команду.
            properties = ['title=' + escape(title, True)]
            file = case.get('file')
            if file:
                try:
                    file = str(Path(file).resolve().relative_to(Path.cwd().resolve()))
                except ValueError:
                    file = ''
                if file:
                    properties.append('file=' + escape(file, True))
                    line = case.get('line', '')
                    if line.isdigit() and int(line) > 0:
                        properties.append('line=' + line)
            message = (problem.text or problem.get('message') or problem.tag).strip()
            print('::error ' + ','.join(properties) + '::' + escape(message[:6000]))
    print(f'PHPUnit JUnit: {len(cases)} tests, {failed} failures/errors')
    if not cases:
        print('::error title=PHPUnit report::JUnit не содержит тестов')
    return int(failed > 0 or not cases)


if __name__ == '__main__':
    if len(sys.argv) != 2:
        sys.exit('usage: phpunit_report.py <junit.xml>')
    sys.exit(report(Path(sys.argv[1])))
