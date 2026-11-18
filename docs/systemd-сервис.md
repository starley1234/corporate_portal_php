### Шаг 1. Подготовка виртуального окружения Python

Все действия выполняются в каталоге проекта от имени пользователя деплоя/сервера:

```bash
cd /opt/portal.toolforge.ru

# Создание venv (требуется Python 3.11+)
python3 -m venv workers/.venv

# Обновление pip и установка зависимостей
workers/.venv/bin/python -m pip install --upgrade pip
workers/.venv/bin/python -m pip install -r workers/requirements.txt
```

---

### Шаг 2. Конфигурация окружения воркера

Если файл `workers/.env` ещё не создан, скопируйте шаблон (если уже существует — **не перезаписывайте**, а отредактируйте):

```bash
test -f workers/.env || cp workers/.env.example workers/.env
chmod 600 workers/.env
```

Отредактируйте `workers/.env`, указав рабочие параметры:
* `WORKER_QUEUE_MODE=pg` и валидный `WORKER_PG_DSN` (или HTTP-режим с `PRX120_API_URL` и `WORKER_API_TOKEN`).
* `PRX120_API_BASE=https://portal.toolforge.ru` (реальный URL сайта, доступный с сервера).
* Токены взаимодействия: задайте `PRX390_SITE_TOKEN` (или `WORKER_SITE_TOKEN`), совпадающий с токеном в основном `.env` портала.
* `WORKER_CHANNELS`: убедитесь, что включены нужные очереди, например: `default,doc.normalize,doc.analyze,doc.draft`.

---

### Шаг 3. Подготовка каталогов и прав доступа

Воркер и PHP-FPM должны иметь доступ к соответствующим каталогам. Определите фактического пользователя веб-сервера (например, `www-data`):

```bash
ROOT=/opt/portal.toolforge.ru
WEB_USER=www-data
WEB_GROUP=www-data

# 1. Каталоги входящих документов (prx390) вне web-root (рекомендуемый вариант)
sudo install -d -o "$WEB_USER" -g "$WEB_GROUP" -m 0700 \
  "$ROOT/storage/prx390/inbox" \
  "$ROOT/storage/prx390/processed" \
  "$ROOT/storage/prx390/duplicates" \
  "$ROOT/storage/prx390/error"

# 2. Хранилище принятых файлов (prx212)
sudo install -d -o "$WEB_USER" -g "$WEB_GROUP" -m 0700 \
  "$ROOT/storage/prx212_file_storage/files"
```

> **Важно:** если prx390 переносится в `storage/prx390/`, пропишите соответствующие пути в основном корневом файле `/opt/portal.toolforge.ru/.env`:
> ```dotenv
> PRX390_INBOX_DIR=/opt/portal.toolforge.ru/storage/prx390/inbox
> PRX390_PROCESSED_DIR=/opt/portal.toolforge.ru/storage/prx390/processed
> PRX390_DUPLICATES_DIR=/opt/portal.toolforge.ru/storage/prx390/duplicates
> PRX390_ERROR_DIR=/opt/portal.toolforge.ru/storage/prx390/error
> ```

---

### Шаг 4. Создание unit-файла systemd

Создайте файл службы `/etc/systemd/system/prx-worker.service`:

```bash
sudo tee /etc/systemd/system/prx-worker.service > /dev/null << 'EOF'
[Unit]
Description=PRX Python Worker Service
After=network-online.target postgresql.service
Wants=network-online.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/opt/portal.toolforge.ru/workers
ExecStart=/opt/portal.toolforge.ru/workers/.venv/bin/python -m prx_workers

# Переменные окружения процесса
Environment=PYTHONUNBUFFERED=1
Environment=WORKER_LOG_ENABLED=0

# Безопасность и перезапуск
UMask=0077
NoNewPrivileges=true
Restart=always
RestartSec=5
TimeoutStopSec=90

[Install]
WantedBy=multi-user.target
EOF
```

*(Если воркер запускается под выделенным системным пользователем, замените `User` и `Group` на него, предоставив ему права на чтение `workers/` и исполнение `.venv/bin/python`).*

---

### Шаг 5. Запуск, включение автозагрузки и валидация

1. Зарегистрируйте и запустите службу:
   ```bash
   sudo systemctl daemon-reload
   sudo systemctl enable --now prx-worker.service
   ```

2. Проверьте статус:
   ```bash
   sudo systemctl status prx-worker.service
   ```

3. Проверьте логи в реальном времени:
   ```bash
   sudo journalctl -u prx-worker.service -f
   ```
   *Убедитесь, что нет ошибок подключения к PostgreSQL (`WORKER_PG_DSN`) или HTTP API портала.*

---

### Шаг 6. Настройка сопутствующих cron-задач

Python-воркер только обрабатывает задачи из очереди (нормализация, OCR, анализ), но **не сканирует входящие файлы самостоятельно** и **не очищает очереди**.

Добавьте задачи в `crontab` пользователя веб-сервера (`sudo crontab -u www-data -e`):

```cron
# Обслуживание очереди и отправка уведомлений (каждую минуту)
* * * * * /usr/bin/php /opt/portal.toolforge.ru/bin/console portal:maintain >/dev/null 2>&1

# Периодическое сканирование входящих документов (например, раз в 5 минут)
*/5 * * * * /usr/bin/php /opt/portal.toolforge.ru/bin/console prx390:scan >/dev/null 2>&1
```