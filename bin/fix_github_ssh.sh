#!/usr/bin/env bash
# N2J: починка «Host key verification failed» при git fetch на сервере деплоя.
#
# Симптом: update.sh (пинок /update/kick/<token>) падает на шаге git fetch:
#   «Host key verification failed. fatal: Could not read from remote repository.»
# Причина: у пользователя, под которым работает деплой, в known_hosts нет
# (или устарел) ключ хоста github.com.
#
# Что делает скрипт:
#   1) кладёт ОФИЦИАЛЬНЫЕ ключи github.com в known_hosts — системный
#      /etc/ssh/ssh_known_hosts (если root, покрывает всех пользователей,
#      включая www-data под которым пинок выполняет git) или ~/.ssh/known_hosts;
#   2) проверяет SSH-соединение с github.com;
#   3) проверяет git fetch в каталоге приложения;
#   4) если SSH чинить нечем — переключает origin на HTTPS (репозиторий
#      публичный, fetch по HTTPS работает без ключей и known_hosts).
#
# Запуск на сервере:  sudo bash bin/fix_github_ssh.sh [/opt/n2j]
# После OK — один пинок деплоя: GET /update/kick/<token>
#
# Отпечатки ключей GitHub (для сверки, docs.github.com):
#   RSA      SHA256:uNiVztksCsDhcc0u9e8BujQXVUpKZIDTMczCvj3tD2s
#   ECDSA    SHA256:p2QAMXNIC1TJYWeIOttrVc98/R1BUFWu3/LiyKgUfQM
#   Ed25519  SHA256:+DiY3wvvV6TuJJhbpZisF/zLDA0zPMSvHdkr4UvCOqU
set -u

APP_DIR="${1:-/opt/n2j}"

KEYS='github.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl
github.com ecdsa-sha2-nistp256 AAAAE2VjZHNhLXNoYTItbmlzdHAyNTYAAAAIbmlzdHAyNTYAAABBBEmKSENjQEezOmxkZMy7opKgwFB9nkt5YRrYMjNuG5N87uRgg6CLrbo5wAdT/y6v0mKV0U2w0WZ2YB/++Tpockg=
github.com ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABgQCj7ndNxQowgcQnjshcLrqPEiiphnt+VTTvDP6mHBL9j1aNUkY4Ue1gvwnGLVlOhGeYrnZaMgRK6+PKCUXaDbC7qtbW8gIkhL7aGCsOr/C56SJMy/BCZfxd1nWzAOxSDPgVsmerOBYfNqltV9/hWCqBywINIR+5dIg6JTJ72pcEpEjcYgXkE2YEFXV1JHnsKgbLWNlhScqb2UmyRkQyytRLtL+38TGxkxCflmO+5Z8CSSNY7GidjMIZ7Q4zMjA2n1nGrlTDkzwDCsw+wqFPGQA179cnfGWOWRVruj16z6XyvxvjJwbz0wQZ75XK5tKSb7FNyeIEs4TT4jk+S4dhPeAUC5y+bDYirYgM4GC7uEnztnZyaVWQ7B381AK4Qdrwt51ZqExKbQpTUNn+EjqoTwvqNj4kqx5QUCI0ThS/YkOxJCXmPUWZbhjpCg56i+2aB6CmK2JGhn57K5mj0MNdBXA4/WnwH6XoPWJzK5Nyu2zB3nAZp+S5hpQs+p1vN1/wsjk='

if [ "$(id -u)" = "0" ]; then
    KH_DIR="/etc/ssh"
    KH_FILE="/etc/ssh/ssh_known_hosts"   # системный: действует и для www-data/root/любого юзера
else
    KH_DIR="$HOME/.ssh"
    KH_FILE="$HOME/.ssh/known_hosts"
fi

mkdir -p "$KH_DIR" && chmod 700 "$KH_DIR" 2>/dev/null
touch "$KH_FILE" && chmod 644 "$KH_FILE"

# прежние записи github.com убираем (если ключ менялся — чтобы не было конфликта)
ssh-keygen -R github.com -f "$KH_FILE" >/dev/null 2>&1 || true

printf '%s\n' "$KEYS" >> "$KH_FILE"
echo "[1/3] known_hosts обновлён: $KH_FILE"

echo "[2/3] Проверка SSH до github.com (в ответе «Hi …» или «Permission denied (publickey)» — значит ключ хоста принят):"
ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -T git@github.com 2>&1 | head -1 || true

echo "[3/3] Проверка git fetch в $APP_DIR:"
if git -C "$APP_DIR" fetch origin; then
    echo "OK: git fetch работает. Сделайте один пинок деплоя (GET /update/kick/<token>)."
    exit 0
fi

echo "SSH не спас — переключаю origin на HTTPS (репозиторий публичный, fetch без ключей и known_hosts):"
if git -C "$APP_DIR" remote set-url origin https://github.com/starley1234/n2j.ru.git \
   && git -C "$APP_DIR" fetch origin; then
    echo "OK: fetch по HTTPS работает. Сделайте один пинок деплоя (GET /update/kick/<token>)."
    exit 0
fi

echo "НЕ ПОМОГЛО: покажите вывод «git -C $APP_DIR remote -v» и «ssh -vT git@github.com 2>&1 | tail -20»."
exit 1
