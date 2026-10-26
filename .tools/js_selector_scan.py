#!/usr/bin/env python3
"""JS-селекторы не потеряли свои классы при переводе разметки на компоненты.

    python3 .tools/js_selector_scan.py [--base <ревизия>]

Зачем (21.10.2026, второй проход дизайна v6): разметку переводили на
компоненты (.n2j-btn-*, .n2j-badge, .n2j-modal-box …), а JS модулей искал
элементы по утилитам старого вида — `.bg-opacity-75` (подложка модалки
erp118), `.font-bold.uppercase.tracking-wide` (значок «загружена» в llm),
`.rounded-xl.border-blue-200.bg-blue-50` (плашка nj580). Классы исчезли —
JS молча перестал находить элементы. PHP-стенды этого не видят: они рендерят
вид, но не исполняют его скрипт.

Что делает: собирает селекторы из JS (querySelector/All, closest, matches,
$('…'), getElementsByClassName, classList.contains) — встроенные <script>
видов, script*.php/js/*.php модулей и public_html/assets/**/*.js. Для каждого
селектора считает «носителей»: class="…" в видах (с PHP внутри), строки PHP со
списками классов, className / classList.add / setAttribute('class') и
class="…" в JS-строках. Сравнивает с базовой ревизией:
  - классы селектора в базе встречались, а теперь — ни разу;
  - составной селектор (2+ класса) потерял часть носителей.
Чинить: искать элемент по data-* крючку, а не по утилитам оформления.

База по умолчанию — git merge-base HEAD origin/main (или main). Нет базы
(мелкий клон, нет origin/main) — проверка пропускается с пояснением.
"""
import os
import re
import subprocess
import sys
import tarfile
import tempfile
import io

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
TOK = re.compile(r'[A-Za-z_!-][\w:/\[\].%#-]*')


def git(*args):
    r = subprocess.run(['git', *args], cwd=ROOT, capture_output=True)
    return r.stdout.decode('utf-8', 'replace').strip() if r.returncode == 0 else None


def files(root):
    out = []
    for base, sub in (('application', '.php'), ('public_html/assets', '.js')):
        for dirpath, _dirs, names in os.walk(os.path.join(root, base)):
            if '/vendor' in dirpath or '/node_modules' in dirpath:
                continue
            out += [os.path.join(dirpath, n) for n in names if n.endswith(sub) and not n.endswith('.min.js')]
    return out


def js_parts(path, text):
    if path.endswith('.js'):
        return [text]
    parts = [m.group(1) for m in re.finditer(r'<script\b[^>]*>(.*?)</script>', text, re.S)]
    if re.search(r'/(script|[\w-]*_script|[\w-]*_js|js|script-[\w-]+)\.php$', path) or '/js/' in path:
        parts.append(text)
    return parts


def read(path):
    with open(path, encoding='utf-8', errors='ignore') as fh:
        return fh.read()


def carriers(root):
    """Множества классов у всех «носителей» дерева."""
    inst = []
    for f in files(root):
        t = read(f)
        if f.endswith('.php'):
            for m in re.finditer(r'class="((?:<\?php.*?\?>|[^"])*)"', t, re.S):
                inst.append(set(TOK.findall(re.sub(r"[<>?;()$=]|php|echo|\?|:", ' ', m.group(1)))))
            for m in re.finditer(r"'([a-z][\w:/\[\].-]*(?: [\w:/\[\].!-]+)+)'", t):
                inst.append(set(m.group(1).split()))
        for js in js_parts(f, t):
            for m in re.finditer(r"(?:className\s*\+?=|classList\.(?:add|toggle|replace)\(|setAttribute\(\s*'class'\s*,)\s*([^;\n]{0,400})", js):
                for a, b in re.findall(r"'([^']*)'|\"([^\"]*)\"", m.group(1)):
                    inst.append(set((a or b).split()))
            for m in re.finditer(r'class=\\?"([^"\\]*)\\?"', js):
                inst.append(set(m.group(1).split()))
    return inst


def selectors(root):
    out = []
    for f in files(root):
        t = read(f)
        for js in js_parts(f, t):
            off = 0 if f.endswith('.js') else max(0, t.find(js[:80]))
            for m in re.finditer(r"(?:querySelectorAll|querySelector|closest|matches|\$)\(\s*(['\"`])(.*?)\1", js):
                line = t.count('\n', 0, off + m.start()) + 1
                for part in m.group(2).split(','):
                    for comp in re.split(r'[\s>+~]+', part.strip()):
                        cls = frozenset(re.findall(r'\.([A-Za-z_][\w-]*)', re.sub(r'\[[^\]]*\]', '', comp)))
                        if cls:
                            out.append((f, line, cls))
            for m in re.finditer(r"(?:getElementsByClassName|classList\.contains)\(\s*['\"]([^'\"]+)['\"]", js):
                line = t.count('\n', 0, off + m.start()) + 1
                out.append((f, line, frozenset(m.group(1).split())))
    return out


def main():
    base = None
    if '--base' in sys.argv:
        base = sys.argv[sys.argv.index('--base') + 1]
    else:
        for ref in ('origin/main', 'main'):
            base = git('merge-base', 'HEAD', ref)
            if base:
                break
    if not base or not git('rev-parse', '--verify', base + '^{commit}'):
        print('js_selector_scan: базовая ревизия не найдена (нет origin/main) — пропуск')
        return 0
    with tempfile.TemporaryDirectory() as tmp:
        arch = subprocess.run(['git', 'archive', base, 'application', 'public_html/assets'], cwd=ROOT, capture_output=True)
        if arch.returncode != 0:
            print('js_selector_scan: git archive не удался — пропуск')
            return 0
        with tarfile.open(fileobj=io.BytesIO(arch.stdout)) as tar:
            tar.extractall(tmp)
        before, now = carriers(tmp), carriers(ROOT)
    hits, seen = 0, set()
    for f, line, cls in selectors(ROOT):
        key = (cls, f)
        if key in seen:
            continue
        seen.add(key)
        b = sum(1 for s in before if cls <= s)
        c = sum(1 for s in now if cls <= s)
        if (b > 0 and c == 0) or (len(cls) >= 2 and c < b):
            hits += 1
            print('%s:%d  .%s — носителей было %d, стало %d' % (os.path.relpath(f, ROOT), line, '.'.join(sorted(cls)), b, c))
    if hits:
        print('JS ищет элементы по классам, которых в разметке больше нет (%d). Перейдите на data-* крючки.' % hits)
        return 1
    print('js_selector_scan: селекторы JS на месте (база %s)' % base[:10])
    return 0


if __name__ == '__main__':
    sys.exit(main())
