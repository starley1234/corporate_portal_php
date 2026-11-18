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
* `PRX120_API_BASE=https://portal.toolforge.ru` (реальный URL сайта, доступный **из процесса воркера**). `http://localhost:8080` работает только если сайт действительно слушает этот порт в том же сетевом пространстве.
* Если задан `PRX390_API_BASE`, он **перекрывает** общий адрес и должен включать полный путь API, например `https://portal.toolforge.ru/prx390/api`. Если он не нужен, оставьте его пустым: путь добавится к `PRX120_API_BASE` автоматически.
* Токены взаимодействия: задайте `PRX390_SITE_TOKEN` (или `WORKER_SITE_TOKEN`), совпадающий с токеном в основном `.env` портала. Не присылайте значения токенов.
* `WORKER_CHANNELS`: убедитесь, что включены нужные очереди, например: `default,doc.normalize,doc.analyze,doc.draft`.

Переменные из systemd-окружения имеют приоритет над `workers/.env`.

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

> **Важно:** пример выше использует альтернативный путь `storage/prx390/`. Умолчание проекта — `storage/docflow/`; Python-воркер получает PDF через API и сам папку не читает. Если выбрали `storage/prx390/`, пропишите соответствующие пути в основном корневом файле `/opt/portal.toolforge.ru/.env`:
> ```dotenv
> PRX390_INBOX_DIR=/opt/portal.toolforge.ru/storage/prx390/inbox
> PRX390_PROCESSED_DIR=/opt/portal.toolforge.ru/storage/prx390/processed
> PRX390_DUPLICATES_DIR=/opt/portal.toolforge.ru/storage/prx390/duplicates
> PRX390_ERROR_DIR=/opt/portal.toolforge.ru/storage/prx390/error
> ```
> Изменение пути само по себе не переносит существующие файлы. При миграции из старого `public_html/storage` соблюдайте [порядок переноса](ENVIRONMENT.md#перенос-существующего-public_htmlstorage).

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

---

### Шаг 7. Прогон одного PDF и сбор диагностики

1. Установите версию приложения с диагностикой и перезапустите службу:
   ```bash
   sudo systemctl restart prx-worker.service
   sudo systemctl is-active prx-worker.service
   ```
   Должно быть `active`. `WORKER_LOG_LEVEL=INFO` показывает все этапы;
   при `WARNING` останутся только предупреждения и ошибки. Для отправки логов
   не включайте DEBUG и не отключайте стандартное приглушение `httpx`: сторонние
   библиотеки могут писать полные URL. Проверьте, что `WORKER_LOG_SKIP` не
   исключает `doc.normalize`. В приведённом unit `WORKER_LOG_ENABLED=0`
   отключает **файл**, но не journalctl.
2. В той же терминальной сессии запомните время **до** прогона:
   ```bash
   SINCE="$(date '+%Y-%m-%d %H:%M:%S')"
   echo "Начало диагностики: $SINCE"
   ```
3. На `/inbox` либо нажмите значок повтора у ранее не распознанного входящего,
   либо загрузите **один новый тестовый PDF** через «Загрузить PDF». Повторная
   загрузка побайтно одинакового PDF считается дублем и не создаёт новую задачу;
   если текст уже сохранён, кнопка повтора тоже может не запускать воркер.
   Не запускайте повтор несколько раз подряд: у задачи до трёх автоматических
   попыток с паузами; после неудач дайте им завершиться.
4. Выведите только диагностические строки воркера за время прогона:
   ```bash
   sudo journalctl -u prx-worker.service --since "$SINCE" --no-pager -o short-iso \
     | grep -E 'prx\.(docflow|ocr): doc\.normalize'
   ```
   Если переменная `SINCE` потеряна при открытии нового терминала, подставьте
   запомненное время вручную вместо `"$SINCE"`. В строках будут `job_id`,
   `message_id`, `file_id`, этап, источник настроек, адрес **без** пути и
   секретов, а при ошибке — класс сети/системный код или HTTP-статус.
   `base_path=expected` означает стандартный `/prx390/api`; `root` или `other`
   позволяют заметить нестандартную настройку, но сами по себе не доказывают ошибку.
   Флаги `proxy_env` и `no_proxy_env` означают только наличие переменных, а не
   доказывают использование или обход прокси для данного запроса.
5. Дополнительно можно получить события PHP-сайта (если его файловый лог
   включён и используется путь по умолчанию):
   ```bash
   sudo grep -F 'prx390_diag' /opt/portal.toolforge.ru/application/logs/prx-site.log | tail -n 100
   ```
   При `PRX_LOG_FILE` используйте настроенный путь; при ротации нужные строки
   могут быть в `prx-site.log.1`. Сопоставьте их по `message_id`/`file_id` и
   пришлите только строки нужного прогона, время и итог в интерфейсе.

Новые диагностические события не содержат названий файлов, PDF, текста,
токенов, HTTP-тел или полных URL с userinfo/query. Адрес хоста и числовые ID
видны: при необходимости согласуйте их раскрытие. **Перед отправкой проверьте
все строки вручную:** общий journalctl и старые/другие журналы могут содержать
частные сведения. Не присылайте `.env`, DSN, токены, документы и необработанные
логи других задач. При пустой выборке сообщите время прогона, состояние службы
и видимый статус — отсутствие строк само по себе не означает успех.

Этот прогон проверяет существующий приём PDF и `doc.normalize`. Почтовый
IMAP-канал в текущей версии ещё не реализован; задача не проверяет чтение почты.
