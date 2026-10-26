#!/usr/bin/env python3
"""Простая проверка сбалансированности скобок/кавычек и блоков в PHP-файле.

Не заменяет php -l (нет php в окружении), но ловит грубые разъехавшиеся
скобки, незакрытые строки и незакрытые блоки после правок.

Проверяет ДВЕ вещи:
  1) круглые/квадратные/фигурные скобки и кавычки;
  2) альтернативный синтаксис PHP (if/endif, for/endfor, foreach/endforeach,
     while/endwhile, switch/endswitch) — именно лишний `<?php endif; ?>` дал
     ParseError на /industry 26.09.2026: скобки были сбалансированы, а файл
     не компилировался.

Вызывать: python3 .tools/php_balance.py <file>...
Статус 0 = всё ок, 1 = есть подозрительный файл.
"""
import sys
import re


def strip_php(text):
    """Удаляем комментарии и строковые литералы, чтобы не путаться в скобках внутри них."""
    out = []
    i, n = 0, len(text)
    state = 'code'  # code | line_comment | block_comment | sq | dq | heredoc
    heredoc_id = ''
    qc = qs = 0
    while i < n:
        c = text[i]
        nxt = text[i + 1] if i + 1 < n else ''
        if state == 'code':
            if c == '/' and nxt == '/':
                state = 'line_comment'
                out.append(' ')
                i += 2
                continue
            if c == '/' and nxt == '*':
                state = 'block_comment'
                out.append(' ')
                i += 2
                continue
            if c == '#':
                state = 'line_comment'
                out.append(' ')
                i += 1
                continue
            if c == '<' and text[i:i + 3] == '<<<':
                # heredoc/nowdoc: <<<PHP ... PHP; и <<<'PHP' ... PHP;
                m = re.match(r"<<<[ \t]*['\"]?([A-Za-z_][A-Za-z0-9_]*)", text[i:])

                if m:
                    heredoc_id = m.group(1)
                    out.append(' ' * len(m.group(0)))
                    i += len(m.group(0))
                    state = 'heredoc'
                    continue

            if c == "'":
                state = 'sq'
                out.append("''")
                i += 1
                continue
            if c == '"':
                state = 'dq'
                out.append('""')
                i += 1
                continue
            out.append(c)
            i += 1
        elif state == 'line_comment':
            if c == '\n':
                state = 'code'
                out.append('\n')
            else:
                out.append(' ')
            i += 1
        elif state == 'block_comment':
            if c == '*' and nxt == '/':
                state = 'code'
                out.append('  ')
                i += 2
            else:
                out.append(' ' if c != '\n' else '\n')
                i += 1
        elif state == 'heredoc':
            # содержимое heredoc — это ТЕКСТ (в Scaffold.php внутри лежит готовый
            # PHP-код с фигурными скобками): ищем закрывающий идентификатор в
            # начале строки и вырезаем всё до него, сохраняя переводы строк.
            # закрывающий идентификатор может стоять и как «SQL);», поэтому
            # после него достаточно «не буквы/цифры/подчёркивания» (правило PHP)
            m = re.search(r'(?m)^[ \t]*' + re.escape(heredoc_id) + r'(?!\w)', text[i:])

            stop = (i + m.start()) if m else n

            out.append('\n' * text[i:stop].count('\n'))
            i = (i + m.end()) if m else n
            state = 'code'

        elif state == 'sq':
            if c == '\\':
                out.append('  ')
                i += 2
                continue
            if c == "'":
                state = 'code'
                out.append("'")
            else:
                out.append(' ')
            i += 1
        elif state == 'dq':
            if c == '\\':
                out.append('  ')
                i += 2
                continue
            if c == '"':
                state = 'code'
                out.append('"')
            else:
                out.append(' ' if c != '\n' else '\n')
            i += 1

    return ''.join(out), state


def extract_php(text):
    """Извлекает только PHP-блоки (<?php ?>, <?  ?>, ?= ) — HTML/CSS/JS вне них
    не должны влиять на проверку скобок."""
    out = []
    i, n = 0, len(text)
    while i < n:
        j = text.find('<?', i)
        if j == -1:
            break
        k = j + 2
        if k < n and text[k:k + 3].lower() == 'php':
            k += 3
        elif k < n and text[k] == '=':
            k += 1
        elif k < n and (text[k].isalpha() or text[k].isspace()):
            pass
        else:
            i = j + 2
            continue

        # ищем настоящий закрывающий ?>, пропуская ?-> внутри строк по кавычкам
        close = -1
        state = 'code'
        hd_id = ''
        m = k
        while m < n:
            c = text[m]
            nx = text[m + 1] if m + 1 < n else ''
            if state == 'code':
                if (c == '?' and nx == '>'):
                    close = m
                    break
                if c == '<' and text[m:m + 3] == '<<<':
                    hm = re.match(r"<<<[ \t]*['\"]?([A-Za-z_][A-Za-z0-9_]*)", text[m:])

                    if hm:
                        hd_id = hm.group(1)
                        state = 'hd'
                        m += len(hm.group(0))
                        continue
                if c == '/' and nx in ('/', '*'):
                    state = 'lc' if nx == '/' else 'bc'
                    m += 2
                    continue
                if c == '#':
                    state = 'lc'
                    m += 1
                    continue
                if c == "'":
                    state = 'sq'
                elif c == '"':
                    state = 'dq'
            elif state == 'lc':
                if c == '\n':
                    state = 'code'
            elif state == 'bc':
                if c == '*' and nx == '/':
                    state = 'code'
                    m += 2
                    continue
            elif state == 'hd':
                # heredoc: до закрывающего идентификатора в начале строки
                hm = re.search(r'(?m)^[ \t]*' + re.escape(hd_id) + r'(?!\w)', text[m:])

                if hm:
                    m += hm.end()
                    state = 'code'
                else:
                    m = n
                continue
            elif state == 'sq':
                if c == '\\':
                    m += 2
                    continue
                if c == "'":
                    state = 'code'
            elif state == 'dq':
                if c == '\\':
                    m += 2
                    continue
                if c == '"':
                    state = 'code'
            m += 1

        if close == -1:
            out.append(text[k:])
            break
        out.append(text[k:close])
        i = close + 2
    return '\n'.join(out)



# ---------------------------------------------------------------------------
# Альтернативный синтаксис PHP: if/endif, foreach/endforeach, ...
# ---------------------------------------------------------------------------

ALT_TOKEN = re.compile(
    r'\b(if|for|foreach|while|switch)\b(?=\s*\()'      # открывающие слова
    r'|\b(endif|endfor|endforeach|endwhile|endswitch)\b'  # закрывающие
    r'|\b(else|elseif)\b',                              # середина блока (не считаем)
    re.IGNORECASE)

ALT_PAIRS = {
    'if': 'endif', 'for': 'endfor', 'foreach': 'endforeach',
    'while': 'endwhile', 'switch': 'endswitch',
}


def _line_of(text, pos):
    return text.count('\n', 0, pos) + 1


def check_alt_syntax(code):
    """Лишний/незакрытый endif и компания — то, что PHP называет ParseError.

    Работает по уже очищенному коду (комментарии и строки вырезаны), поэтому
    слова из текстов не путаются с ключевыми.
    """
    errors = []
    stack = []

    for m in ALT_TOKEN.finditer(code):
        word = m.group(0).lower()

        if word in ('else', 'elseif'):
            continue

        if word in ALT_PAIRS:
            # ищем закрывающую скобку условия и смотрим, что идёт дальше
            i = m.end()
            n = len(code)
            depth = 0
            j = None

            while i < n:
                c = code[i]
                if c == '(':
                    depth += 1
                elif c == ')':
                    depth -= 1
                    if depth == 0:
                        j = i
                        break
                i += 1

            if j is None:
                continue  # незакрытую скобку уже поймала проверка скобок

            k = j + 1

            while k < n and code[k] in ' \t\r\n':
                k += 1

            # альтернативный синтаксис — только если после условия стоит ":"
            if k < n and code[k] == ':':
                stack.append((word, _line_of(code, m.start())))
        else:
            if not stack:
                errors.append(f'лишний "{word}" (стр. {_line_of(code, m.start())})')
                continue

            opened, line = stack.pop()

            if ALT_PAIRS.get(opened) != word:
                errors.append(
                    f'несовпадение блоков: "{opened}" (стр. {line}) закрыт через "{word}" '
                    f'(стр. {_line_of(code, m.start())})')

    for opened, line in stack:
        errors.append(f'незакрытый блок "{opened}:" (стр. {line}) — нет {ALT_PAIRS[opened]}')

    return errors


def check(path):
    with open(path, 'r', encoding='utf-8', errors='replace') as f:
        text = f.read()

    errors = []

    if '<?' not in text:
        # Файлы-скрипты модулей (views/*/script.php) часто ЧИСТЫЙ JS без PHP —
        # проверять в них скобки PHP смысла нет (JS-регулярки вида /^p\[/ давали
        # ложные «несовпадения»).
        print(f'[SKIP] {path} (без PHP-блоков)')
        return True

    code = extract_php(text)

    if not code.strip():
        print(f'[SKIP] {path} (PHP-блоков не найдено)')
        return True

    stripped, end_state = strip_php(code)
    # однострочный комментарий в конце файла без перевода строки — это норма
    if end_state == 'line_comment':
        end_state = 'code'
    if end_state != 'code':
        errors.append(f'незакрытая конструкция в конце файла: {end_state}')

    stack = []
    pairs = {')': '(', ']': '[', '}': '{'}
    line = 1
    for ch in stripped:
        if ch == '\n':
            line += 1
        if ch in '([{':
            stack.append((ch, line))
        elif ch in ')]}':
            if not stack:
                errors.append(f'лишняя "{ch}" на строке {line}')
                continue
            top, top_line = stack.pop()
            if top != pairs[ch]:
                errors.append(f'несовпадение: "{top}" (стр. {top_line}) закрыт через "{ch}" (стр. {line})')

    for ch, ln in stack:
        errors.append(f'незакрытая "{ch}" (открыта на стр. {ln})')

    errors.extend(check_alt_syntax(stripped))

    if errors:
        print(f'[FAIL] {path}')
        for e in errors:
            print(f'       {e}')
        return False
    print(f'[ OK ] {path}')
    return True


def main():
    files = sys.argv[1:]
    if not files:
        print('usage: php_balance.py <file>...')
        return 2
    # ВАЖНО: без генератора — all() по генератору останавливается на первом
    # FAIL и остальные файлы просто не проверяются.
    results = [check(p) for p in files]
    return 0 if all(results) else 1


if __name__ == '__main__':
    sys.exit(main())
