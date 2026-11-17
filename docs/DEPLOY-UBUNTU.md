# Эксплуатационный checklist PRX

Это checklist новой установки, не автоматическая миграция исходного сайта.
Целевая инфраструктура и политики ИБ должны быть согласованы отдельно.

1. Отдельный пользователь приложения, PHP 8.4 FPM/CLI, PostgreSQL, Composer,
   Python-venv, nginx с docroot `public_html/`. Запретить доступ к .env,
   application, workers, vendor, storage и .git через HTTP.
2. `bash bin/setup.sh`, production `KOHANA_ENV`, реальный HTTPS `APP_URL`,
   уникальные DB/AUTH/COOKIE/WORKER/MIGRATION секреты. Сменить dev-пароли БД.
3. `composer install --no-dev`, проверенный app.css из Git. Node на сервере
   не требуется. Настроить php-fpm-пользователю доступ к runtime и storage,
   не использовать chmod 777.
4. Новая БД: `bin/console db:migrate`; на чистой базе миграция создаёт
   admin/admin — сразу смените пароль. Для production задайте
   `PRX_DEFAULT_ADMIN=0` и создайте администратора через `make seed`.
   Для старых данных — только отдельный план MIGRATION.md.
5. Служба `python -m prx_workers` из workers/, отдельное окружение/секреты,
   разрешённые каналы и внутренние LLM/embedding endpoints.
6. Таймер `bin/console portal:maintain` раз в минуту с flock; nonzero — alert.
   Проверить тестовую доставку in-app/SMTP и отказы. Внешние мессенджеры — opt-in.
7. `bin/backup.sh`: БД + storage/ + публичные пользовательские файлы,
   защищённые права, отдельный диск/хост, retention и проверка restore.
8. HTTPS, secure cookies, reverse-proxy/trusted-host настройки, серверное
   ограничение доступа, rate limits, аудит, антивирус файлов и мониторинг.
9. Утвердить юридические страницы, кадровые/анонимные политики и классификацию
   документов до приёма реальных данных. Имеющийся RAG-кэш для этого не подходит.
10. Выполнить HTTP-проверки на staging; не гонять fresh-check на production.

`bin/deploy.sh` не знает production-ветку: `DEPLOY_BRANCH` задаётся на сервере,
иначе используется текущая ветка checkout. Убедитесь, что remote указывает на
этот корпоративный репозиторий. Ветка и upstream не меняются скриптом.
Автоматический pull/deploy не настраивается этой задачей; коммит/push агента
не означает deployment на сервер организации.

Проверено в песочнице — docs/VALIDATION.md. Docker build, внешний SMTP/LLM и
восстановление production-backup требуют отдельной инфраструктурной проверки.
