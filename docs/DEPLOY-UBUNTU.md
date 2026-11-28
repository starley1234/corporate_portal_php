# Эксплуатационный checklist PRX

Это checklist новой установки, не автоматическая миграция исходного сайта.
Целевая инфраструктура и политики ИБ должны быть согласованы отдельно.

1. Отдельный пользователь приложения, PHP 8.4 FPM/CLI, PostgreSQL, Composer,
   Python-venv, nginx с docroot `public_html/`. Запретить доступ к .env,
   application, workers, vendor, storage и .git через HTTP.
2. `bash bin/setup.sh`, production `KOHANA_ENV`, реальный HTTPS `APP_URL`,
   уникальные DB/AUTH/COOKIE/WORKER/MIGRATION секреты. Сменить dev-пароли БД.
3. `composer install --no-dev`, проверенный app.css из Git. Node на сервере
   не требуется. `storage` находится в корне проекта, не в `public_html`;
   настройте php-fpm-пользователю и CLI доступ к runtime и storage, не
   используйте chmod 777. Setup создаёт `storage/docflow/*` и
   `storage/prx212_file_storage/files` с приватными правами.
4. Новая БД: `bin/console db:migrate`; на чистой базе миграция создаёт
   admin/admin — сразу смените пароль. Для production задайте
   `PRX_DEFAULT_ADMIN=0` и создайте администратора через `make seed`.
   Для старых данных — только отдельный план MIGRATION.md.
5. Служба `python -m prx_workers` из workers/, отдельное окружение/секреты,
   разрешённые каналы и внутренние LLM/embedding endpoints.
6. Таймер `bin/console portal:maintain` раз в минуту с flock; nonzero — alert.
   Проверить тестовую доставку in-app/SMTP и отказы. Внешние мессенджеры — opt-in.
7. `bin/backup.sh`: БД + storage/ + старые пользовательские каталоги,
   защищённые права, отдельный диск/хост, retention и проверка restore.
   Нестандартные backend и S3 включите в резервирование отдельно.
8. HTTPS, secure cookies, reverse-proxy/trusted-host настройки, серверное
   ограничение доступа, rate limits, аудит, антивирус файлов и мониторинг.
   Мониторинг: `GET /health` (200/503, публично — балансировщику и uptime-роботу)
   и `GET /health/metrics` (Prometheus, `Authorization: Bearer $PRX_METRICS_TOKEN`):
   очередь по каналам, возраст старейшей задачи, токены и ₽ LLM, миграции.
   Антивирус: `apt install clamav-daemon`, пользователь PHP — в группе `clamav`,
   `PRX_CLAMAV_MODE=required` (или `soft` на время обкатки), при нестандартном
   сокете — `PRX_CLAMAV_ADDRESS`. Аудит действий администратора — `/prx100/audit`.
9. Утвердить юридические страницы, кадровые/анонимные политики и классификацию
   документов до приёма реальных данных. Имеющийся RAG-кэш для этого не подходит.
10. Выполнить HTTP-проверки на staging; не гонять fresh-check на production.

`bin/deploy.sh` не знает production-ветку: `DEPLOY_BRANCH` задаётся на сервере,
иначе используется текущая ветка checkout. Убедитесь, что remote указывает на
этот корпоративный репозиторий. Ветка и upstream не меняются скриптом.
Автоматический pull/deploy не настраивается этой задачей; коммит/push агента
не означает deployment на сервер организации. Если на существующем сервере
есть `public_html/storage`, **сначала** проведите переход в окне обслуживания
по [инструкции](ENVIRONMENT.md#перенос-существующего-public_htmlstorage):
обычный live-deploy переключит пути, но не скопирует прежние файлы. Настройте
запрет прямой раздачи старого дерева в фактическом конфиге веб-сервера и
проверьте действующий виртуальный маршрут `/storage/get/<id>`.

Проверено в песочнице — docs/VALIDATION.md. Docker build, внешний SMTP/LLM и
восстановление production-backup требуют отдельной инфраструктурной проверки.
