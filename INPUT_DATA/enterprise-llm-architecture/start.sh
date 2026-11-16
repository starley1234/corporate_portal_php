#!/usr/bin/env bash
# Сборка страницы одной командой: проверка модели -> сборка -> открыть в браузере.
set -euo pipefail
cd "$(dirname "$0")"

if ! command -v node >/dev/null 2>&1; then
  echo "Не найден Node.js. Установите LTS: https://nodejs.org/ru" >&2
  exit 1
fi

if [ ! -d node_modules ]; then
  echo "Первый запуск: устанавливаю зависимости…"
  npm install
fi

echo "Проверяю модель…"
npm run check

echo "Собираю страницу…"
npm run build

PAGE="dist/architecture.html"
if command -v open >/dev/null 2>&1; then
  open "$PAGE"
elif command -v xdg-open >/dev/null 2>&1; then
  xdg-open "$PAGE" >/dev/null 2>&1 &
else
  echo "Готово: $PAGE"
fi
