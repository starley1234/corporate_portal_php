@echo off
chcp 65001 >nul
cd /d "%~dp0"
title Архитектура: сборка страницы

where node >nul 2>nul
if errorlevel 1 (
  echo.
  echo Не найден Node.js. Установите LTS с https://nodejs.org/ru и запустите снова.
  echo.
  pause
  exit /b 1
)

if not exist node_modules (
  echo Первый запуск: устанавливаю зависимости...
  call npm install
  if errorlevel 1 (
    echo.
    echo Установка не удалась. Проверьте интернет и повторите.
    pause
    exit /b 1
  )
)

echo Проверяю модель...
call npm run check
if errorlevel 1 (
  echo.
  echo Проверки не прошли: исправьте ошибки выше и запустите снова.
  pause
  exit /b 1
)

echo Собираю страницу...
call npm run build
if errorlevel 1 (
  echo.
  echo Сборка не удалась. Текст ошибки выше.
  pause
  exit /b 1
)

echo.
echo Готово. Открываю dist\architecture.html
start "" "dist\architecture.html"
timeout /t 2 >nul
exit /b 0
