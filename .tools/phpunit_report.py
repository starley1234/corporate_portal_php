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
    skipped = sum(1 for case in cases if case.find('skipped') is not None)
    print(f'::notice title=PHPUnit::PHPUnit JUnit: {len(cases)} tests, {failed} failures/errors, {skipped} skipped')
    if not cases:
        print('::error title=PHPUnit report::JUnit не содержит тестов')
    return int(failed > 0 or not cases)


def report_output(path: Path) -> int:
    """JUnit не содержит всех risky/warning PHPUnit; сохраняем диагностику процесса."""
    try:
        text = path.read_text(errors='replace')[-12000:]
    except OSError as error:
        text = f'Вывод PHPUnit недоступен: {error}'
    print('::error title=PHPUnit process::' + escape(text))
    return 0  # код PHPUnit остаётся у шага запуска, это только диагностика


if __name__ == '__main__':
    if len(sys.argv) == 3 and sys.argv[1] == '--output':
        sys.exit(report_output(Path(sys.argv[2])))
    if len(sys.argv) != 2:
        sys.exit('usage: phpunit_report.py <junit.xml> | --output <phpunit.log>')
    sys.exit(report(Path(sys.argv[1])))
