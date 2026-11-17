#!/usr/bin/env python3
"""Аудит вёрстки по эталону дизайна v6 (docs/DESIGN-SYSTEM.md).

Эталон — /pages/required_design_sample: строгая семантическая палитра
(emerald — главное действие, blue — процесс, rose — опасность, gray —
второстепенное), компоненты .prx-* из общего CSS и помощники window.PRX.
Скрипт показывает, сколько в каждом модуле осталось до эталона, и не даёт
приведённым модулям откатиться.

    python3 .tools/design_audit.py                  # сводка по всем модулям
    python3 .tools/design_audit.py --module prx592   # file:line по одному модулю
    python3 .tools/design_audit.py --check          # CI: храповик (см. ниже)
    python3 .tools/design_audit.py --json           # сводка для скриптов

ПРАВИЛА. «Ошибки» нарушают правила AGENTS.md (п. 10, 15, «полос нет нигде»)
или ломают вёрстку тихо; «замечания» — расхождения с палитрой и обликом v6.

--check падает, если:
  1. в любой вьюхе есть класс .prx-*, которого нет в собранном app.css
     (опечатка или удалённый компонент — стиль тихо пропадает);
  2. в приведённом модуле (DONE) или в общем файле (SHARED_DONE) есть хоть
     одно замечание любого уровня, не помеченное исключением;
  3. исключение записано без причины;
  4. в модуле из DARK_DONE есть цвет без dark:-пары (правило dark — свой
     храповик, остальным модулям показывается только в сводке).

ИСКЛЮЧЕНИЯ — в коде, рядом с местом, с причиной после тире:
    <?php /* design-audit: allow style — печатная форма A4 */ ?>    (эта и след. строка)
    // design-audit: allow-file palette — цвета категорий каталога  (весь файл)

Модуль приведён к эталону → добавьте его в DONE (список только растёт).
"""
import argparse
import json
import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parent.parent
CSS_FILE = ROOT / 'public_html/assets/css/app.css'
MODULES = ROOT / 'application/modules'
SHARED_VIEWS = ROOT / 'application/views'

# Модули, приведённые к эталону v6. Для них --check требует ноль замечаний.
# Проверяются сохранённые модули; старые сервисы удалены (docs/REMOVED-MODULES.md).
# Спринт v6 (20–21.10.2026): все модули прошли общие кодмоды (палитра, кегль,
# h1, свои <style>, взвод, корни, кнопки, поля, карточки, prx*) и чисты по
# аудиту — храповик держит это в CI. Дошлифовка по модулям — см. план.
DONE = {
    'cookie_consent',
    'llm',
    'prx100_platform',
    'prx110_catalog',
    'prx111_migrations',
    'prx115_semantic',
    'prx120_jobs',
    'prx208_events',
    'prx212_file_storage',
    'prx218_module_manager',
    'prx300_employee',
    'prx560_knowledge',
    'prx595_useradmin',
    'prx597_notifications',
    'prx599_account',
}

# Группы общих вьюх application/views/*, приведённые целиком (храповик, как DONE).
SHARED_DONE_GROUPS = {
    'views/(корень)',
    'views/auth',
    'views/errors',
    'views/pages',
    'views/pagination',
    'views/partials',
    'views/welcome',
}

# Отдельные общие файлы, которые уже соответствуют эталону.
SHARED_DONE = {
    'application/views/pages/required_design_sample.php',
    'application/views/partials/page_header.php',
}

# Классы .prx-*, которых нет в app.css законно (не стиль, а метка для JS).
KNOWN_HOOKS: dict[str, str] = {}

# Исключения, записанные в правилах владельца: модуль или файл → правило → почему.
ALLOW: dict[str, dict[str, str]] = {
    'prx520_poll': {'progress': 'доли голосов на /p/<код> — результат, а не ход (AGENTS.md, 04.10.2026)'},
    'application/views/template_tailwindcss.php': {'sticky': 'шапка сайта — часть макета, а не блок страницы'},
    'application/views/partials/topbar.php': {'sticky': 'шапка сайта — часть макета; вынесена из template_tailwindcss.php'},
}

# ---------------------------------------------------------------- правила --
RULES = {
    # код: (уровень, что найдено, как исправить)
    'style': ('ошибка', 'инлайн-<style> во вьюхе',
              'компоненты .prx-* и утилиты; своё общее — в .tools/tailwind/input.css'),
    'progress': ('ошибка', 'полоса прогресса или метр',
                 'ход — спиннер + этап «2 из 3» + секундомер (PRX.run, data-prx-stopwatch); доля — числом или точками'),
    'sticky': ('ошибка', 'sticky-блок',
               'AGENTS.md п. 10: sticky запрещены — блок в общем потоке '
               '(можно только th/td/tr таблицы внутри её прокрутки)'),
    'confirm': ('ошибка', 'window.confirm',
                'строка списка — data-prx-arm (двойной клик); тяжёлое удаление — data-prx-confirm / PRX.confirm'),
    'alert': ('ошибка', 'window.alert',
              'ошибка поля — PRX.fieldError; короткий итог — PRX.toast или .prx-flash-*'),
    'container': ('ошибка', 'container mx-auto',
                  'корень — w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8, без центрирования'),
    'unknown': ('ошибка', 'класс .prx-* без определения в app.css',
                'опечатка или удалённый компонент — список в docs/DESIGN-SYSTEM.md'),
    'tiny': ('замечание', 'текст 9–11 px',
             'минимум для текста — text-xs (12 px), подписи — .prx-hint; 11 px только у подсказок клавиш (.prx-btn-kbd)'),
    'palette': ('замечание', 'цвет вне палитры v6',
                'emerald — главное действие, blue — процесс, rose — опасность, gray — второстепенное, amber — ожидание'),
    'redgreen': ('замечание', 'red-* / green-*',
                 'red → rose, green → emerald'),
    'shadow': ('замечание', 'тень md и крупнее',
               'карточки плоские: рамка + shadow-sm (.prx-card, .prx-panel)'),
    'h1': ('замечание', 'свой <h1> мимо шапки',
           "шапка — View::factory('partials/page_header', …) или класс .prx-h1"),
    'arm': ('замечание', 'своя логика «armed»',
            'двойной клик — data-prx-arm из app.js, без копии в модуле'),
    'dark': ('замечание', 'цвет без dark:-пары',
             'светлый цвет текста/фона/рамки нужен и ночью: text-gray-700 dark:text-gray-200, '
             'bg-*-50 dark:bg-*-950/40, border-gray-200 dark:border-gray-700 (или компонент .prx-*)'),
}

# Модули, где ночная тема выверена: для них --check требует ноль замечаний
# «dark». Отдельный храповик (25.10.2026, «ночью не видно» в /mcphub).
# 27.10.2026 к нулю приведены все production-модули и общие группы.
# prx140_design_lab сюда не входит: это автономная витрина альтернативных
# тем со своими CSS, а не страницы рабочего портала. Список только растёт.
DARK_DONE = {
    'cookie_consent',
    'llm',
    'prx100_platform',
    'prx110_catalog',
    'prx111_migrations',
    'prx115_semantic',
    'prx120_jobs',
    'prx208_events',
    'prx212_file_storage',
    'prx218_module_manager',
    'prx300_employee',
    'prx560_knowledge',
    'prx595_useradmin',
    'prx597_notifications',
    'prx599_account',
    'views/(корень)',
    'views/auth',
    'views/errors',
    'views/pages',
    'views/pagination',
    'views/partials',
    'views/welcome',
}
ERRORS = [k for k, v in RULES.items() if v[0] == 'ошибка']
WARNS = [k for k, v in RULES.items() if v[0] == 'замечание']

TW_PREFIX = r'(?<![\w\-\[])(?:[a-z0-9\-]+:)*'
COLOR_UTIL = r'(?:bg|text|border(?:-[xytrbl])?|ring(?:-offset)?|from|via|to|fill|stroke|divide|outline|decoration|shadow|accent|caret|placeholder)'
OFF_PALETTE = 'indigo|violet|purple|fuchsia|pink|cyan|teal|sky|lime|yellow|orange|slate|zinc|neutral|stone'

RE = {
    'style': re.compile(r'<style\b', re.I),
    'progress': re.compile(
        r'<progress\b|role\s*=\s*["\']progressbar'
        r'|\.style\.width\s*=\s*(?![\'"]\d+%[\'"]\s*[;,)]?\s*$)[^;\n]*[\'"`]%'
        r'|style\s*=\s*["\'][^"\']*\bwidth\s*:\s*(?:<\?|\$\{)', re.I),
    'sticky': re.compile(r'(?<![\w\-])sticky(?![\w\-])'),
    'confirm': re.compile(r'(?<![\w.$])confirm\s*\(|window\.confirm\s*\('),
    'alert': re.compile(r'(?<![\w.$\-])alert\s*\(|window\.alert\s*\('),
    'tiny': re.compile(r'text-\[(?:9|10|11)(?:\.\d+)?px\]|font-size\s*:\s*(?:9|10|11)(?:\.\d+)?px'),
    'palette': re.compile(TW_PREFIX + COLOR_UTIL + r'-(?:' + OFF_PALETTE + r')-\d{2,3}(?:/\d+)?(?![\w\-])'),
    'redgreen': re.compile(TW_PREFIX + COLOR_UTIL + r'-(?:red|green)-\d{2,3}(?:/\d+)?(?![\w\-])'),
    'shadow': re.compile(TW_PREFIX + r'shadow-(?:md|lg|xl|2xl|inner)(?![\w\-])'),
    'h1': re.compile(r'<h1\b(?![^>]*\bprx-(?:ds-)?h1\b)', re.I),
    'arm': re.compile(r'(?<![\w\-])(?:data-)?armed(?![\w\-])|dataset\.armed\b'),
}
# Светлые цвета, которые ночью тонут без пары: тёмный текст, светлый фон/рамка.
DARK_NEEDS = [
    ('text', re.compile(r'^text-(?:gray-(?:6|7|8|9)00|(?:blue|emerald|rose|amber)-(?:6|7|8|9)00)(?:/\d+)?$')),
    # bg-white/10 — полупрозрачная плёнка, одинакова в обеих темах; не считаем
    ('bg', re.compile(r'^bg-(?:white|(?:gray|blue|emerald|rose|amber)-(?:50|100|200)(?:/\d+)?)$')),
    ('border', re.compile(r'^(?:border(?:-[xytrbl])?|divide-[xy]?)-?(?:gray|blue|emerald|rose|amber)-(?:100|200|300)(?:/\d+)?$')),
]
DARK_BLOCK = re.compile(r'^(?:[a-z0-9\-]+:)*bg-(?:gray-(?:8|9)00|gray-950|black)(?:/\d+)?$')
VARIANTS = re.compile(r'^((?:[a-z0-9\-]+:)*)')
QUOTED = re.compile(r'"([^"\n]*)"|\'([^\'\n]*)\'')
CLASS_ATTR = re.compile(r'\bclass(?:Name)?\s*=\s*(?:"([^"]*)"|\'([^\']*)\')', re.S)
CLASS_LIST = re.compile(r'classList\.(?:add|remove|toggle|replace)\(([^)]*)\)')
PRX_CLASS = re.compile(r'(?<![\w\-.\[$])prx-[a-z0-9][a-z0-9\-]*(?![\w\-])')
PRAGMA = re.compile(r'design-audit:\s*allow(-file)?\s+([a-z0-9,\s]+?)\s*(?:[—–-]\s*(.*?))?\s*(?:\*/|-->|\?>|$)')
COMMENT_LINE = re.compile(r'^\s*(?://|\*|/\*|#(?!\{)|<!--)')


def css_classes() -> set[str]:
    css = CSS_FILE.read_text(encoding='utf-8')
    return set(re.findall(r'\.(prx-[a-zA-Z0-9_\-]+)', css))


def view_files():
    """(группа, путь) — вьюхи модулей и общие вьюхи application/views."""
    for path in sorted(MODULES.glob('*/views/**/*.php')):
        yield path.relative_to(MODULES).parts[0], path
    for path in sorted(SHARED_VIEWS.glob('**/*.php')):
        rel = path.relative_to(SHARED_VIEWS).parts
        yield 'views/' + (rel[0] if len(rel) > 1 else '(корень)'), path


def tiny_is_icon(line: str, start: int) -> bool:
    """Мелкий кегль на иконке (<i>, <svg>, fa-…) — это размер значка, не текст."""
    tag_start = line.rfind('<', 0, start)
    tag_end = line.find('>', start)
    tag = line[tag_start if tag_start >= 0 else 0: tag_end if tag_end >= 0 else len(line)]
    return bool(re.match(r'<(?:i|svg)\b', tag) or re.search(r'(?<![\w\-])fa[srlbd]?(?:-[a-z0-9\-]+)?(?![\w\-])', tag))


def tag_at(line: str, start: int) -> str:
    """Текст тега (от «<» до «>»), внутри которого стоит позиция start."""
    tag_start = line.rfind('<', 0, start)
    tag_end = line.find('>', start)
    return line[tag_start if tag_start >= 0 else 0: tag_end if tag_end >= 0 else len(line)]


def sticky_in_table(line: str, start: int) -> bool:
    """Липкая шапка/первый столбец таблицы в её прокрутке — разрешено (владелец, 20.10.2026)."""
    return bool(re.match(r'<(?:th|thead|td|tr)\b', tag_at(line, start), re.I))


def shadow_floating(line: str, start: int) -> bool:
    """Тень всплывающего слоя (absolute/fixed/z-*: меню, подсказка, окно) — не карточка."""
    return bool(re.search(r'(?<![\w\-])(?:absolute|fixed|z-\d+|z-\[\d+\])(?![\w\-])', tag_at(line, start)))


def class_tokens(text: str):
    """(смещение, имя) для каждого .prx-* внутри class=/className=/classList.*()."""
    for m in CLASS_ATTR.finditer(text):
        value = m.group(1) if m.group(1) is not None else m.group(2)
        base = m.start(1) if m.group(1) is not None else m.start(2)
        for t in PRX_CLASS.finditer(value):
            if value[t.end():t.end() + 2] in ('<?', '${') or value[t.end():t.end() + 1] == '{':
                continue  # имя склеивается динамически — проверяет tailwind_classes_lint
            yield base + t.start(), t.group(0)
    for m in CLASS_LIST.finditer(text):
        for q in QUOTED.finditer(m.group(1)):
            value = q.group(1) if q.group(1) is not None else q.group(2)
            for t in PRX_CLASS.finditer(value):
                yield m.start(1) + q.start() + 1 + t.start(), t.group(0)


def dark_gaps(text: str):
    """Смещения class-атрибутов, где светлый цвет остался без dark:-пары.

    Пара считается по семейству утилиты: любой dark:text-* закрывает все
    text-* в атрибуте (оттенок — дело вкуса, важно, что ночью цвет задан).
    Атрибут с тёмным фоном (bg-gray-800/900/950, bg-black) — тёмный блок в
    обеих темах, его не трогаем. Префиксы состояний (hover:, sm:) снимаются:
    hover:bg-gray-50 без dark:hover:bg-* — та же дыра.
    """
    for m in CLASS_ATTR.finditer(text):
        value = m.group(1) if m.group(1) is not None else m.group(2)
        value = re.sub(r'<\?.*?\?>|\$\{.*?\}', ' ', value, flags=re.S)  # PHP/JS-вставки — не классы
        tokens = [t for t in re.split(r'\s+', value) if t and '<?' not in t and '${' not in t]
        if any(DARK_BLOCK.match(t) for t in tokens):
            continue
        dark_fams, light_fams = set(), set()
        for t in tokens:
            prefix = VARIANTS.match(t).group(1)
            core = t[len(prefix):]
            fam = next((f for f, rx in DARK_NEEDS if rx.match(core)), None)
            if 'dark:' in prefix:
                u = re.match(r'^(text|bg|border|divide)', core)
                if u:
                    dark_fams.add('border' if u.group(1) == 'divide' else u.group(1))
            elif fam:
                light_fams.add(fam)
        if light_fams - dark_fams:
            yield m.start(1) if m.group(1) is not None else m.start(2)


def audit_file(path: pathlib.Path, known: set[str]):
    """Список (правило, номер строки, строка) и ошибки исключений."""
    text = path.read_text(encoding='utf-8', errors='replace')
    lines = text.split('\n')
    local = set(re.findall(r'\.(prx-[a-zA-Z0-9_\-]+)\s*[{,:\s]', text))  # свой <style> — уже правило style
    hits, bad_pragmas = [], []
    allow_file: set[str] = set()
    allow_line: dict[int, set[str]] = {}
    for i, line in enumerate(lines, 1):
        for p in PRAGMA.finditer(line):
            rules = {r.strip() for r in p.group(2).replace(',', ' ').split() if r.strip()}
            unknown_rules = rules - set(RULES)
            if unknown_rules or not (p.group(3) or '').strip() or len(p.group(3).strip()) < 3:
                bad_pragmas.append((i, line.strip()))
                continue
            if p.group(1):
                allow_file |= rules
            else:
                allow_line.setdefault(i, set()).update(rules)
                allow_line.setdefault(i + 1, set()).update(rules)

    def add(rule, lineno):
        if rule in allow_file or rule in allow_line.get(lineno, ()):
            return
        hits.append((rule, lineno, lines[lineno - 1].strip()))

    for i, line in enumerate(lines, 1):
        if COMMENT_LINE.match(line) or 'design-audit:' in line:
            continue
        for rule, rx in RE.items():
            for m in rx.finditer(line):
                if rule == 'tiny' and tiny_is_icon(line, m.start()):
                    continue
                if rule == 'sticky' and sticky_in_table(line, m.start()):
                    continue
                if rule == 'shadow' and shadow_floating(line, m.start()):
                    continue
                if rule in ('confirm', 'alert') and re.search(r'PRX\.(?:confirm|toast)', line[max(0, m.start() - 4):m.end()]):
                    continue
                if rule == 'arm' and 'prx-arm' in line:
                    continue
                # Скрытое поле name="armed" — серверная защита (без armed=1
                # удаление не выполнится), а не своя JS-логика взвода.
                if rule == 'arm' and re.match(r'armed["\']', line[m.end() - 5:m.end() + 1]) \
                        and re.search(r'name=["\']$', line[max(0, m.start() - 6):m.start()]):
                    continue
                add(rule, i)
        for q in QUOTED.finditer(line):
            value = (q.group(1) if q.group(1) is not None else q.group(2)) or ''
            words = value.split()
            if 'container' in words and 'mx-auto' in words:
                add('container', i)
    offsets = [0]
    for line in lines:
        offsets.append(offsets[-1] + len(line) + 1)
    for pos, name in class_tokens(text):
        if name in known or name in local or name in KNOWN_HOOKS:
            continue
        lineno = next(n for n in range(1, len(offsets)) if offsets[n] > pos)
        if not COMMENT_LINE.match(lines[lineno - 1]):
            add('unknown', lineno)
    seen_dark = set()
    for pos in dark_gaps(text):
        lineno = next(n for n in range(1, len(offsets)) if offsets[n] > pos)
        if lineno not in seen_dark and not COMMENT_LINE.match(lines[lineno - 1]):
            seen_dark.add(lineno)
            add('dark', lineno)
    return hits, bad_pragmas


def collect():
    known = css_classes()
    groups: dict[str, dict] = {}
    for group, path in view_files():
        hits, bad = audit_file(path, known)
        allowed = {**ALLOW.get(group, {}), **ALLOW.get(str(path.relative_to(ROOT)), {})}
        hits = [h for h in hits if h[0] not in allowed]
        g = groups.setdefault(group, {'files': 0, 'lines': 0, 'counts': {r: 0 for r in RULES}, 'hits': [], 'bad': []})
        g['files'] += 1
        g['lines'] += path.read_text(encoding='utf-8', errors='replace').count('\n') + 1
        rel = str(path.relative_to(ROOT))
        for rule, lineno, src in hits:
            g['counts'][rule] += 1
            g['hits'].append((rel, lineno, rule, src))
        for lineno, src in bad:
            g['bad'].append((rel, lineno, src))
    return groups


def report(groups):
    cols = ERRORS + WARNS
    short = {'style': 'стиль', 'progress': 'полос', 'sticky': 'стик', 'confirm': 'конф', 'alert': 'алрт',
             'tiny': 'мелк', 'container': 'конт', 'unknown': 'неизв', 'palette': 'цвет', 'redgreen': 'кр/зл',
             'shadow': 'тень', 'h1': 'h1', 'arm': 'армд', 'dark': 'ночь'}
    head = f"{'модуль':<28}{'вьюх':>5}{'строк':>7} │" + ''.join(f'{short[c]:>6}' for c in ERRORS) + ' │' + \
        ''.join(f'{short[c]:>6}' for c in WARNS) + ' │ ошиб  зам'
    print(head)
    print('─' * len(head))
    total = {c: 0 for c in cols}
    files = lines = 0
    for name in sorted(groups):
        g = groups[name]
        c = g['counts']
        mark = ' ✓' if name in DONE else ''
        errs = sum(c[r] for r in ERRORS)
        warns = sum(c[r] for r in WARNS)
        print(f"{(name + mark)[:28]:<28}{g['files']:>5}{g['lines']:>7} │" + ''.join(f'{(c[r] or "·"):>6}' for r in ERRORS)
              + ' │' + ''.join(f'{(c[r] or "·"):>6}' for r in WARNS) + f' │{errs:>5}{warns:>5}')
        for r in cols:
            total[r] += c[r]
        files += g['files']
        lines += g['lines']
    print('─' * len(head))
    print(f"{'ИТОГО':<28}{files:>5}{lines:>7} │" + ''.join(f'{total[r]:>6}' for r in ERRORS) + ' │'
          + ''.join(f'{total[r]:>6}' for r in WARNS))
    clean = [n for n in groups if not any(groups[n]['counts'].values())]
    print(f'\nПриведено (DONE): {len(DONE)} из {sum(1 for n in groups if not n.startswith("views/"))} модулей; '
          f'без замечаний сейчас: {len(clean)}.')
    print('Легенда: ' + '; '.join(f'{short[r]} — {RULES[r][1]}' for r in cols))
    print('Подробно по модулю: python3 .tools/design_audit.py --module <имя или префикс>')


def module(groups, query):
    names = [n for n in groups if n == query or n.startswith(query)]
    if not names:
        print(f'Модуль «{query}» не найден', file=sys.stderr)
        return 2
    for name in names:
        g = groups[name]
        errs = sum(g['counts'][r] for r in ERRORS)
        warns = sum(g['counts'][r] for r in WARNS)
        print(f"== {name}: вьюх {g['files']}, строк {g['lines']}; ошибок {errs}, замечаний {warns}")
        for rule in ERRORS + WARNS:
            rows = [h for h in g['hits'] if h[2] == rule]
            if not rows:
                continue
            level, what, fix = RULES[rule]
            print(f'\n[{rule}] {what} — {len(rows)} ({level})\n    как: {fix}')
            for rel, lineno, _, src in rows:
                print(f'  {rel}:{lineno}: {src[:110]}')
        for rel, lineno, src in g['bad']:
            print(f'  {rel}:{lineno}: исключение без причины или с неизвестным правилом: {src[:90]}')
        print()
    return 0


# Самопроверка правил: строка → какие правила обязаны сработать (пусто — ни одно).
SELFTEST = [
    ('<style>.x{color:red}</style>', {'style'}),
    ("bar.style.width = pct + '%';", {'progress'}),
    ('<div style="width: <?php echo $p; ?>%"></div>', {'progress'}),
    ('<progress value="3" max="5"></progress>', {'progress'}),
    ('<aside class="space-y-4 lg:sticky lg:top-6">', {'sticky'}),
    ("if (!confirm('Удалить?')) return;", {'confirm'}),
    ("window.confirm('Удалить?')", {'confirm'}),
    ("alert('Ошибка');", {'alert'}),
    ('<div class="container mx-auto px-4">', {'container'}),
    ('<span class="text-[10px] text-gray-600 dark:text-gray-300">мелко</span>', {'tiny'}),
    ('<b class="hover:bg-indigo-600 dark:text-violet-300">', {'palette'}),
    ('<b class="text-red-600 dark:text-green-400">', {'redgreen'}),
    ('<div class="rounded-xl shadow-lg">', {'shadow'}),
    ('<h1 class="text-2xl font-bold">Заголовок</h1>', {'h1'}),
    ("btn.dataset.armed = '1';", {'arm'}),
    ('<span class="prx-btn-ctaa">', {'unknown'}),
    ('<p class="text-sm text-gray-700">ночью не видно</p>', {'dark'}),
    ('<div class="rounded border border-gray-200 bg-gray-50 p-3 dark:border-gray-700">', {'dark'}),
    ('<a class="hover:bg-gray-50 text-blue-600 dark:text-blue-400">', {'dark'}),
    ('<div class="bg-white p-4">', {'dark'}),
    # не должно срабатывать
    ("PRX.confirm({ title: 'Удалить?' }).then(go);", set()),
    ("PRX.toast('Скопировано');", set()),
    ('<i class="fas fa-stop text-[11px]"></i>', set()),
    ("el.style.width = '100%';", set()),
    ('<h1 class="prx-h1">Заголовок</h1>', set()),
    ('<div role="alert" class="prx-flash prx-flash-ok">', set()),
    ('<button class="prx-btn-icon-danger" data-prx-arm="Удалить?">', set()),
    ('<input type="hidden" name="armed" value="0">', set()),
    ('<b class="bg-emerald-600 text-rose-600 shadow-sm border-blue-200 text-amber-700 dark:text-rose-400 dark:border-blue-800">', set()),
    ('<p class="text-sm text-gray-700 dark:text-gray-200">ок</p>', set()),
    ('<div class="rounded-xl bg-gray-900 p-3 text-gray-100 border border-gray-200">тёмный блок</div>', set()),
    ('<span class="text-white bg-blue-600">кнопка</span>', set()),
    ('<p class="text-xs text-gray-400">серый, читается в обеих</p>', set()),
    ('<i class="fas fa-gem text-emerald-600 dark:text-emerald-400"></i>', set()),
    ('<div class="container">', set()),
    ('<th class="sticky left-0 z-10 bg-white dark:bg-gray-900">Узел</th>', set()),
    ('<thead class="sticky top-0 bg-gray-50 dark:bg-gray-800">', set()),
    ('<div class="absolute right-0 z-20 mt-2 rounded-xl shadow-lg">', set()),
    ('<div class="sticky top-0">', {'sticky'}),
    ("<?php /* design-audit: allow style — проверка исключения */ ?>\n<style>.y{}</style>", set()),
]


def selftest() -> list[str]:
    import tempfile
    known = {'prx-btn-cta', 'prx-h1', 'prx-flash', 'prx-flash-ok', 'prx-btn-icon-danger'}
    fails = []
    with tempfile.TemporaryDirectory() as tmp:
        for i, (src, expect) in enumerate(SELFTEST):
            f = pathlib.Path(tmp) / f'case{i}.php'
            f.write_text(src + '\n', encoding='utf-8')
            hits, bad = audit_file(f, known)
            got = {h[0] for h in hits}
            if got != expect or bad:
                fails.append(f'самопроверка: {src!r}: ждали {sorted(expect)}, получили {sorted(got)}')
    return fails


def check(groups):
    problems = selftest()
    for name, g in groups.items():
        for rel, lineno, rule, src in g['hits']:
            strict = name in DONE or name in SHARED_DONE_GROUPS or rel in SHARED_DONE
            if rule == 'dark':
                strict = name in DARK_DONE  # свой храповик: см. DARK_DONE (модули и группы views/*)
            if rule == 'unknown' or strict:
                problems.append(f'{rel}:{lineno}: [{rule}] {RULES[rule][1]} — {RULES[rule][2]}\n    {src[:110]}')
        for rel, lineno, src in g['bad']:
            problems.append(f'{rel}:{lineno}: исключение без причины или с неизвестным правилом\n    {src[:110]}')
    missing = [m for m in DONE if m not in groups]
    problems += [f'DONE: модуля {m} нет в application/modules' for m in missing]
    problems += [f'DARK_DONE: модуля или группы {m} нет' for m in DARK_DONE if m not in groups]
    problems += [f'SHARED_DONE: файла {f} нет' for f in SHARED_DONE if not (ROOT / f).exists()]
    problems += [f'SHARED_DONE_GROUPS: группы {g} нет' for g in SHARED_DONE_GROUPS if g not in groups]
    if problems:
        print('Дизайн-аудит: замечания в приведённых файлах (docs/DESIGN-SYSTEM.md):')
        print('\n'.join(problems))
        return 1
    print(f'Дизайн-аудит: чисто (приведено модулей: {len(DONE)}, общих групп: {len(SHARED_DONE_GROUPS)}, общих файлов: {len(SHARED_DONE)}; '
          f'классов .prx-* без определения нет)')
    return 0


def main():
    ap = argparse.ArgumentParser(description='Аудит вёрстки по эталону дизайна v6')
    ap.add_argument('--module', metavar='ИМЯ', help='подробно по модулю (имя каталога или префикс: prx592)')
    ap.add_argument('--check', action='store_true', help='храповик для CI')
    ap.add_argument('--json', action='store_true', help='сводка в JSON')
    ap.add_argument('--selftest', action='store_true', help='только самопроверка правил')
    args = ap.parse_args()
    if args.selftest:
        fails = selftest()
        print('\n'.join(fails) or f'Самопроверка правил: {len(SELFTEST)} случаев — ок')
        return 1 if fails else 0
    groups = collect()
    if args.check:
        return check(groups)
    if args.module:
        return module(groups, args.module)
    if args.json:
        print(json.dumps({n: {'files': g['files'], 'lines': g['lines'], 'counts': g['counts'], 'done': n in DONE}
                          for n, g in sorted(groups.items())}, ensure_ascii=False, indent=1))
        return 0
    report(groups)
    return 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except BrokenPipeError:  # вывод обрезали через head — не ошибка
        sys.exit(0)
