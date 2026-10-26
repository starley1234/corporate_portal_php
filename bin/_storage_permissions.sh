#!/usr/bin/env bash
# =============================================================================
# Общая политика прав для приватных данных приложения.
#
# Каталоги runtime/cache/session и хранилища модулей находятся вне docroot,
# но это само по себе не делает их приватными: права задаются ОС.
# =============================================================================

# В Ubuntu сайт и воркеры работают как www-data. На другом хосте задайте
# DEPLOY_WEB_USER явно; для непривилегированного запуска остаётся текущий владелец.
prl_storage_owner() {
    if [[ -n "${DEPLOY_WEB_USER:-}" ]]; then
        printf '%s' "$DEPLOY_WEB_USER"
    elif [[ "$(id -u)" = "0" ]] && id -u www-data >/dev/null 2>&1; then
        printf 'www-data'
    fi
}

# Создать/закрыть дерево: каталоги 0700, обычные файлы 0600.
# Путь должен быть выделен только под данные приложения; symlink в корне
# отклоняется, чтобы ошибочная настройка не изменила права чужого каталога.
prl_secure_private_tree() {
    local path="${1:-}"
    local owner="${2:-}"
    local foreign

    if [[ -z "$path" || "$path" != /* || "$path" = "/" \
        || ( -n "${ROOT:-}" && "$path" = "$ROOT" ) \
        || "$path" = *"/../"* || "$path" = *"/.." ]]; then
        printf 'storage: небезопасный путь: %s\n' "${path:-<empty>}" >&2
        return 2
    fi

    if [[ -L "$path" ]]; then
        printf 'storage: корень хранилища не может быть symlink: %s\n' "$path" >&2
        return 2
    fi

    mkdir -p "$path" || return 1

    if [[ -L "$path" ]]; then
        printf 'storage: корень хранилища стал symlink: %s\n' "$path" >&2
        return 2
    fi

    if [[ -n "$owner" ]]; then
        if ! id -u "$owner" >/dev/null 2>&1; then
            printf 'storage: пользователь не найден: %s\n' "$owner" >&2
            return 1
        fi

        if [[ "$(id -u)" = "0" ]]; then
            chown -R "$owner:$owner" "$path" || return 1
        else
            foreign="$(find "$path" ! -user "$owner" -print -quit 2>/dev/null || true)"

            if [[ -n "$foreign" ]]; then
                printf 'storage: %s должен принадлежать %s (не хватает прав на chown)\n' \
                    "$path" "$owner" >&2
                return 1
            fi
        fi
    fi

    find "$path" -type d -exec chmod 0700 {} + || return 1
    find "$path" -type f -exec chmod 0600 {} + || return 1

    return 0
}
