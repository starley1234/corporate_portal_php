#!/usr/bin/env bash
# =============================================================================
# PRX — совместимая обёртка над bin/deploy.sh.
#
# Раньше этот скрипт был ВТОРЫМ путём деплоя: сам делал fetch/checkout/reset,
# ставил зависимости воркеров, перезапускал сервис — но НЕ применял миграции.
# Отсюда типовая авария «код обновился, схема отстала» → 500 на /prx602/admin,
# /prx643/cad. Второго пути больше нет: вся логика в bin/deploy.sh, здесь только
# вызов с --migrate.
#
# Ветка задаётся на сервере (DEPLOY_BRANCH в /etc/default/prx-deploy или .env)
# и в репозитории не хранится — см. AGENTS.md §0.
# =============================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# .env рядом с проектом мог задавать DEPLOY_BRANCH — пробрасываем в deploy.sh
if [ -z "${DEPLOY_BRANCH:-}" ]; then
    for f in "$ROOT/.env" "$ROOT/workers/.env"; do
        [ -f "$f" ] || continue
        v=$(grep -E '^[[:space:]]*DEPLOY_BRANCH=' "$f" | head -n 1 | cut -d '=' -f2- | tr -d ' "\r\n')
        [ -n "$v" ] && { export DEPLOY_BRANCH="$v"; break; }
    done
fi

exec bash "$ROOT/bin/deploy.sh" --migrate "$@"
