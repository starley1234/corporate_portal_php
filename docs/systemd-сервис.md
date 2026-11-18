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

**Если PHP и воркер на одном сервере**, достаточно `/opt/portal.toolforge.ru/.env`:

```dotenv
APP_URL=https://portal.toolforge.ru
# DB_DEFAULT_* — существующие настройки PostgreSQL сайта.
# WORKER_API_TOKEN — существующий токен сайта; не публикуйте его.
```

По умолчанию воркер работает через PostgreSQL и строит DSN из `DB_DEFAULT_*`.
Даже в этом режиме он скачивает PDF и сдаёт результат по HTTP через `APP_URL`.
Если нужен внутренний адрес, укажите `PRX120_API_BASE` без пути API.
`workers/.env` **не обязателен**. Приоритет: systemd → `workers/.env` → корневой `.env`.
Проверьте, что пользователь службы может читать нужный файл; не делайте его
общедоступным. На отдельном сервере используйте `workers/.env.example`.

**После обновления старого шаблона:** проверьте оба файла и unit. Старые
`PRX120_API_BASE`, `PRX120_API_URL`, `PRX390_API_BASE` и `PRX395_DOCUMENT_EXTRACT_API_BASE`
могут перекрывать `APP_URL`, особенно `http://localhost:8080`. Уберите только
ненужные переопределения, не стирайте файл с рабочими настройками. Для старого
контура `WORKER_SITE_TOKEN`/`PRX390_SITE_TOKEN` также перекрывают общий токен.

Из каталога воркера проверьте связь без постановки задач:

```bash
cd /opt/portal.toolforge.ru/workers
.venv/bin/python -m prx_workers --check-site
```

Запускайте от пользователя службы. Если в unit задано `Environment` или
`EnvironmentFile`, ручная команда может видеть другие значения; сравните адрес
со стартовой строкой в `journalctl -u prx-worker -n 60 --no-pager -o cat`.
`--check-site` выводит источник адреса и причину отказа, не выводит токен и PDF.

---

### Шаг 3. Подготовка каталогов и прав доступа

Для PDF-пайплайна только PHP-FPM и PHP-команда очистки имеют доступ к постоянному хранилищу; Python получает PDF по служебному HTTP и использует временный каталог. Определите фактического пользователя веб-сервера (например, `www-data`):

```bash
ROOT=/opt/portal.toolforge.ru
WEB_USER=www-data
WEB_GROUP=www-data

# Хранилище принятых файлов (prx212)
sudo install -d -o "$WEB_USER" -g "$WEB_GROUP" -m 0700 \
  "$ROOT/storage/prx212_file_storage/files"
```

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

Python-воркер обрабатывает активные каналы очереди, но не запускает обслуживание очереди.

Добавьте задачи в `crontab` пользователя веб-сервера (`sudo crontab -u www-data -e`):

```cron
# Обслуживание очереди и отправка уведомлений (каждую минуту)
* * * * * /usr/bin/php /opt/portal.toolforge.ru/bin/console portal:maintain >/dev/null 2>&1

```

Для существующего сервера: [остановка старых сканеров и воркеров](../archive/docflow/README.md).

### Контроль воркера и очистка PDF

После рестарта выполните `bin/console prx120:probe --wait=30` из каталога
проекта или запустите проверку в `/prx120/monitor`. Смотрите события конкретной
задачи и `journalctl -u prx-worker.service`.

Если включили `prx395`, добавьте отдельный timer либо cron (пример строки для
crontab пользователя PHP, замените путь на свой):

```cron
*/10 * * * * cd /opt/portal.toolforge.ru && flock -n storage/prx395-purge.lock bin/console prx395:purge
```

Не подавляйте ошибки вывода `prx395:purge`: это сигнал, что файл или backend
недоступен. `portal:maintain` обслуживает очередь/уведомления, не запускает OCR
и не удаляет PDF.
