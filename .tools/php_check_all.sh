#!/usr/bin/env bash
# =============================================================================
# PRX — одна команда для проверки PHP перед пушем.
#
#   bash .tools/php_check_all.sh            # по умолчанию application/
#   bash .tools/php_check_all.sh .          # весь репозиторий
#
# Зачем: 28.09.2026 в Controller/Prx643/Cad.php метод action_archive() оказался
# объявлен дважды — и живой /cad отвечал 500 «Cannot redeclare …» на КАЖДЫЙ
# запрос модуля (PHP компилирует класс целиком). Разбор грамматикой такого не
# видит: дубликат — не синтаксическая ошибка. Поэтому в списке есть php_dupe_lint.
#
# Зачем: 26.09.2026 файл prx643_cad уехал на прод со склейкой
# `'…модели ('DOCROOT.'…'` — живой /cad падал ParseError, а .tools/php_lint.py и
# .tools/php_balance.py молчали (скобки и кавычки были целы). Поэтому первым в
# списке идёт НАСТОЯЩИЙ синтаксический разбор (.tools/php_parse_lint.py), а не
# эвристики.
# =============================================================================
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

TARGET="${1:-application}"   # application | . | путь к файлу
FAILED=0

run() { # run <заголовок> <команда…>
    local title="$1"; shift
    echo "==> $title"

    if "$@"; then
        return 0
    fi

    FAILED=1
    echo "    ^^ не прошло"
}

# 1. Грамматика PHP (ловят РАЗБОР, а не баланс скобок)
run "PHP-синтаксис: разбор грамматикой (php_parse_lint)" \
    python3 .tools/php_parse_lint.py "$TARGET"
run "PHP-синтаксис: склейки без оператора (php_adjacent_lint, резерв)" \
    python3 .tools/php_adjacent_lint.py "$TARGET"

# 2. Повторные объявления — фатально для PHP, но невидимо для разбора
run "PHP: повторные объявления (php_dupe_lint)" \
    python3 .tools/php_dupe_lint.py "$TARGET"

# 2б. PHP-код внутри текста SQL. Живая авария 28.09.2026: правило авто-остановки
#     зависших задач собиралось как "…error = 'молчал '.max(1, (int) round($x/60)).' мин…'"
#     — в PostgreSQL уходил этот самый PHP-код, запрос падал, а catch глушил ошибку.
#     Авто-остановка не работала вообще; ловится только этой проверкой.
run "PHP: PHP-код внутри SQL (php_sql_lint)" \
    python3 .tools/php_sql_lint.py "$TARGET"

# 2в. Ссылка на столбец, которого нет в подзапросе LATERAL. Живая авария
#     28.09.2026: /prx120/monitor отдавал 500 «столбец ev.created_at не
#     существует» — подзапрос отдавал только e.message, а снаружи читали ещё и
#     created_at. SQL синтаксически верен, PHP-кода внутри нет, колонка в
#     таблице есть — база отвергает запрос целиком, и падает вся страница.
run "PHP: ссылки на столбцы вне LATERAL (php_lateral_lint)" \
    python3 .tools/php_lateral_lint.py "$TARGET"

# 3. Исторические эвристики проекта
run "PHP: теги/скобки (php_lint)" python3 .tools/php_lint.py "$TARGET"
run "PHP: тернарники и AND (php_ternary_lint)" python3 .tools/php_ternary_lint.py "$TARGET"
# 3б. Ловушка Kohana: `Request::post($key, $value)` — второй аргумент это
#     ЗАПИСЬ поля, а не значение по умолчанию: `post('id', 0)` возвращает
#     объект Request, и `(int)` на нём роняет страницу 500-й (08.10.2026:
#     /airadar/idea). Живых мест было 13 — все переписаны на Arr::get($_POST…).
run "PHP: умолчание в Request::post/query (php_request_param_lint)" \
    python3 .tools/php_request_param_lint.py "$TARGET"
run "PHP: static-метод с \$this (php_static_this)" python3 .tools/php_static_this.py "$TARGET"
run "PHP: вызов нестатического метода статически (php_static_call_lint)" \
    python3 .tools/php_static_call_lint.py "$TARGET"
run "PHP: вызов метода, которого нет (php_method_lint)" \
    python3 .tools/php_method_lint.py "$TARGET"

# 4. Миграции и внешние CDN — только когда целим весь репозиторий или application
case "$TARGET" in
    application|.|application/*)
        run "Миграции PostgreSQL (php_migration_lint)" \
            python3 .tools/php_migration_lint.py application
        run "Внешние CDN во вьюхах (assets_cdn_lint)" \
            python3 .tools/assets_cdn_lint.py application public_html
        run "Контракты основы PRX" \
            python3 .tools/portal_check.py
        # Бюджет документации: потолки «всегда читаемых» доков, индекс у больших
        # памяток и сборка контекст-пака по каждому модулю (см. docs/CONTEXT.md).
        # Заодно прогоняет разбор манифестов config/prx_module.php по всем модулям.
        run "Документация в бюджете (doc_budget_lint)" \
            python3 .tools/doc_budget_lint.py
        run "Классы Tailwind есть в сборке (tailwind_classes_lint)" \
            python3 .tools/tailwind_classes_lint.py
        # Дизайн v6 (docs/DESIGN-SYSTEM.md): приведённые модули и общий слой не
        # откатываются (инлайн-стили, полосы, sticky, confirm/alert, чужие цвета),
        # а классов .prx-* без определения в сборке нет нигде.
        run "Дизайн v6: храповик приведённых модулей (design_audit)" \
            python3 .tools/design_audit.py --check
        # Перевод разметки на компоненты ломал JS-селекторы по утилитам
        # (.bg-opacity-75, .font-bold.uppercase…) — стенды этого не видят:
        # сравнение с merge-base origin/main, без базы — пропуск.
        run "JS-селекторы не потеряли свои классы (js_selector_scan)" \
            python3 .tools/js_selector_scan.py
        ;;
esac

if [[ $FAILED -eq 0 ]]; then
    echo "Всё чисто: $TARGET"
else
    echo "Есть замечания — смотрите выше" >&2
fi

exit $FAILED
