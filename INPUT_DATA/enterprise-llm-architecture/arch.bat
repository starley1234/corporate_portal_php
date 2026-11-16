@echo off
chcp 65001 >nul
cd /d "%~dp0"
if not exist node_modules call npm install >nul || exit /b 1
node scripts\arch.mjs %*