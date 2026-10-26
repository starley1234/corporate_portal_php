# Деплой N2J на Ubuntu: модульный монолит + Python-воркеры + автообновление из git

> Проверено для Ubuntu 22.04 / 24.04. Все команды — от root (или через `sudo`).
> Итоговая схема: **nginx → php-fpm (Konine/Kohana, `/var/www/n2j.ru`) → PostgreSQL 16**,
> воркеры — отдельный systemd-сервис, обновления прилетают из git автоматически каждую минуту.

---

## 0. Что куда ставится

| Компонент | Где | Кто обслуживает |
|---|---|---|
| Код проекта | `/var/www/n2j.ru` | git (деплой-скрипт) |
| Веб-вход | nginx, docroot = `public_html/` | `nginx` |
| PHP 8.4 | php-fpm (сокет) | `php8.4-fpm` |
| БД | PostgreSQL 16, база `n2j` | `postgresql` |
| Воркеры | `workers/`, вход `python -m n2j_workers` | `n2j-worker` |
| Автообновление | `bin/deploy.sh` по таймеру | `n2j-deploy.timer` |

## 1. Базовые пакеты и PHP 8.4

```bash
apt update && apt -y upgrade
apt -y install git curl unzip ca-certificates gnupg

add-apt-repository -y ppa:ondrej/php && apt update
apt -y install php8.4-fpm php8.4-cli php8.4-pgsql php8.4-mbstring \
               php8.4-xml php8.4-curl php8.4-intl php8.4-opcache

# composer (для vendor/; если проекту хватит заглушки — можно пропустить)
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
```

## 2. PostgreSQL 16 (+ pgvector опционально)

```bash
apt -y install postgresql postgresql-contrib
# pgvector нужен только модулю nj115_semantic (без него — режим real[]):
apt -y install postgresql-16-pgvector || true

sudo -u postgres psql <<'SQL'
CREATE USER n2j WITH PASSWORD 'СМЕНИТЬ_ПАРОЛЬ';
CREATE DATABASE n2j OWNER n2j;
SQL
```

Локальный доступ по паролю: в `pg_hba.conf` для `127.0.0.1/32` оставить `scram-sha-256`,
затем `systemctl reload postgresql`. Проверка:
`psql postgres://n2j:ПАРОЛЬ@127.0.0.1:5432/n2j -c 'select current_database()'`

## 3. Код из git + .env

```bash
mkdir -p /var/www && cd /var/www
git clone -b main git@github.com:starley1234/n2j.ru.git n2j.ru   # или https
cd n2j.ru

# .env с автогенерацией секретов (идемпотентно)
bash bin/setup.sh
```

Отредактируйте `/var/www/n2j.ru/.env` — минимум:

```ini
KOHANA_ENV=production
APP_URL=https://ваш-домен
DB_DEFAULT_HOST=127.0.0.1
DB_DEFAULT_NAME=n2j
DB_DEFAULT_USER=n2j
DB_DEFAULT_PASS=СМЕНИТЬ_ПАРОЛЬ
MIGRATIONS_TOKEN=случайная-строка      # аварийный доступ к /migrate_pg?token=…
WORKER_API_TOKEN=случайная-строка      # если воркеры будут ходить по HTTP
```

Остальное (LLM, эмбеддинги) — по желанию, модули подхватят настройки из `.env`.

## 4. Права на записываемые каталоги

```bash
cd /var/www/n2j.ru
mkdir -p application/logs application/cache application/sessions \
         public_html/uploads public_html/avatar public_html/img
chown -R www-data:www-data application/logs application/cache application/sessions \
                            public_html/uploads public_html/avatar public_html/img
```

(`bash bin/setup.sh` создаёт их сам; `bin/deploy.sh` — восстанавливает владельца при обновлениях.)

## 5. nginx

`/etc/nginx/sites-available/n2j.ru`:

```nginx
server {
    listen 80;
    server_name ваш-домен;            # + IP на время проверки
    root /var/www/n2j.ru/public_html;
    index index.php;
    charset utf-8;
    client_max_body_size 64m;

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_read_timeout 300;
    }

    # статика с кэшем
    location ~* ^/(assets|favicon)/.*\.(css|js|png|jpg|jpeg|gif|svg|ico|woff2?|ttf|webp)$ {
        try_files $uri =404;
        expires 7d;
        add_header Cache-Control "public";
    }

    # служебное наружу не отдаём
    location ~ /\.(?!well-known) { deny all; }
    location ~ ^/(bin|docker|docs|workers|system|modules|application|tests)/ { deny all; }
}
```

```bash
ln -s /etc/nginx/sites-available/n2j.ru /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx
# HTTPS: apt -y install certbot python3-certbot-nginx && certbot --nginx -d ваш-домен
```

## 6. База: схема и первый админ

Вариант А (рекомендую первый раз) — штатный восстановитель окружения:
```bash
cd /var/www/n2j.ru && bash bin/restore-env.sh --app-only
```
Он применит миграции и создаст администратора из `.env` (`ADMIN_EMAIL/ADMIN_USERNAME/ADMIN_PASSWORD`).

Вариант Б — вручную:
```bash
php bin/console db:migrate            # схема: ядро + все модули (nj520, nj530 и т.д.)
php bin/console db:migrate --status   # проверить, что «новых миграций нет»
make seed                             # администратор из .env
```

Веб-консоль миграций: войдите админом на сайте → меню «Платформа → Миграции» (`/migrate_pg`).

## 7. Python-воркеры (systemd)

```bash
apt -y install python3-venv
cd /var/www/n2j.ru/workers
python3 -m venv .venv
.venv/bin/pip install -r requirements.txt

cp .env.example .env
# в workers/.env минимум:
#   WORKER_QUEUE_MODE=pg
#   WORKER_PG_DSN=postgres://n2j:ПАРОЛЬ@127.0.0.1:5432/n2j
#   WORKER_NAME=worker-1
#   WORKER_CHANNELS=            # пусто = все каналы
```

`/etc/systemd/system/n2j-worker.service`:

```ini
[Unit]
Description=N2J queue workers
After=network.target postgresql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/n2j.ru/workers
ExecStart=/var/www/n2j.ru/workers/.venv/bin/python -m n2j_workers
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
```

```bash
systemctl daemon-reload && systemctl enable --now n2j-worker
journalctl -u n2j-worker -f        # посмотреть логи
```

Воркеры слушают `pg_notify('nj120_jobs')` и опрашивают очередь — новых задач ждать долго не придётся.

## 8. Автообновление из git (каждую минуту)

Сам скрипт уже в репозитории: `bin/deploy.sh`. Без новых коммитов он не делает ничего,
с новыми — `git reset --hard origin/<ветка>`, setup, (опционально) миграции, права,
рестарт воркеров, reload php-fpm.

`/etc/default/n2j-deploy` (окружение для скрипта):

```ini
DEPLOY_BRANCH=<ветка, за которой следит этот сервер>
DEPLOY_WEB_USER=www-data
DEPLOY_AUTO_MIGRATE=1
```

> Имя ветки задаётся **только здесь**, на сервере. В репозиторий его не коммитим:
> `bin/deploy.sh` без `DEPLOY_BRANCH` берёт ту ветку, на которой
> сервер уже стоит, и падают с понятной ошибкой, если определить её нельзя.

`DEPLOY_WEB_USER` — владелец PHP-FPM и воркера. На Ubuntu без явного значения
root-деплой выбирает `www-data`; для другого пользователя укажите его здесь.
`setup.sh` и деплой держат `application/logs`, `application/cache`,
`application/sessions` и `DEPLOY_MODULE_STORAGE` закрытыми: каталоги `0700`,
файлы `0600`, владельцем становится веб-пользователь. В частности, временные
фото и приватные превью не получают world-readable права. Публичные каталоги в
`public_html/` остаются отдельными.

`/etc/systemd/system/n2j-deploy.service`:

```ini
[Unit]
Description=N2J git auto-deploy

[Service]
Type=oneshot
EnvironmentFile=/etc/default/n2j-deploy
ExecStart=/var/www/n2j.ru/bin/deploy.sh
```

`/etc/systemd/system/n2j-deploy.timer`:

```ini
[Unit]
Description=Check git every minute

[Timer]
OnBootSec=2min
OnUnitActiveSec=1min

[Install]
WantedBy=timers.target
```

```bash
systemctl daemon-reload && systemctl enable --now n2j-deploy.timer
systemctl list-timers | grep n2j     # убедиться, что таймер жив
tail -f /var/log/n2j-deploy.log      # лог обновлений
```

**Доступ к git:** для private-репозитория создайте deploy key
(`ssh-keygen -f /root/.ssh/n2j_deploy -N ''`, публичную добавьте в GitHub → Deploy keys, read-only)
и `git config core.sshCommand "ssh -i /root/.ssh/n2j_deploy"` в клоне.

> ⚠️ `DEPLOY_AUTO_MIGRATE=1` запускает `db:migrate` автоматически. Миграции проекта
> идемпотентны и транзакционны, но если хотите контролировать схему руками — оставьте `0`
> и применяйте через `/migrate_pg`.

## 9. Бэкапы (база И файлы)

Бэкапить только базу недостаточно: карточки организаций, 3D-модели и досье
лежат файлами на диске. Из дампа одной БД восстановится набор записей со
ссылками на несуществующие файлы — то есть битые карточки у пользователей.
Поэтому копию снимает `bin/backup.sh`: дамп базы плюс архив файловых хранилищ.

```bash
crontab -e
# ежедневно в 3:00
0 3 * * * cd /var/www/n2j.ru && bash bin/backup.sh >> /var/log/n2j-backup.log 2>&1
```

```bash
bash bin/backup.sh            # снять копию
bash bin/backup.sh --list     # что уже лежит
bash bin/backup.sh --check    # копии читаются? (gzip цел, дамп не пустой)
```

Бэкапы сами содержат персональные файлы и данные БД: `BACKUP_DIR` закрывается
правами `0700`, дамп и архив создаются с `0600` (`umask 077`). Не размещайте
каталог бэкапов в публичной директории; хранение/передачу копий на отдельный
сервер настраивайте с теми же ограничениями доступа.

Настройки — переменными окружения или в `/etc/default/n2j-deploy`:

| Переменная | По умолчанию | Что задаёт |
|---|---|---|
| `BACKUP_DIR` | `/var/backups/n2j` | куда класть |
| `BACKUP_KEEP_DAYS` | `14` | сколько хранить |
| `DEPLOY_MODULE_STORAGE` | `storage/nj580 storage/nj590 storage/nj651 storage/nj669 storage/nj670` | хранилища модулей — **тот же список, что у деплоя** |
| `BACKUP_PUBLIC_DIRS` | `public_html/uploads public_html/avatar public_html/storage` | файлы пользователей |

Список хранилищ намеренно общий с `bin/deploy.sh`: добавили модулю папку —
вписали один раз, и деплой её создаст, и бэкап заберёт. Для ProcessHub это
`storage/nj670`: источники схем (PDF/фото/аудио) и готовые регламенты DOCX/PDF.

> Копия, которую ни разу не прочитали, копией не является. `--check` стоит
> поставить в тот же cron через час после снятия.


## 10. Обновление по кнопке (вместо крона каждую минуту)

`bin/deploy.sh` умеет работать по пинку — GitHub не дёргается каждые 60 секунд,
systemd-таймер `n2j-deploy` можно отключить (`systemctl disable --now n2j-deploy.timer`):

- **Админка:** `/nj100/update` — кнопка «Пнуть обновление» + хвост журнала
  (`/tmp/n2j-deploy-kick.log`);
- **Агент/скрипты:** `GET /update/kick/<SITE_UPDATE_TOKEN>` — токен задаётся в
  `.env` (`SITE_UPDATE_TOKEN=…`), проверяется `hash_equals`, ответ JSON
  `{"ok":true,...}`; сменили токен в .env — ссылка сразу новая;
- **Что делает пинок:** `bin/deploy.sh --migrate` = git pull → composer →
  идемпотентные миграции (`bin/console db:migrate`; даже если коммитов нет) →
  `bin/setup.sh` → права → рестарт воркера → reload php-fpm;
- повторный пинок во время обновления безопасен (flock), параллель с таймером
  тоже.

## 11. Чек-лист после деплоя

- [ ] `https://домен/` — главная, в панели меню «Финансы/Опросы/Ссылки/Разработчику»
- [ ] `/vat`, `/summa-propisyu`, `/password`, `/poll`, `/jump` — работают
- [ ] `/migrate_pg` (админом) — «Схема актуальна»
- [ ] Создайте опрос на `/poll` — в БД появились строки (`nj_520_polls`)
- [ ] `systemctl status n2j-worker` — active (running)
- [ ] Закоммитьте тестовое изменение в ветку — через минуту `n2j-deploy` его привезёт

## 12. Альтернатива: Docker

Если проще контейнером — в репозитории есть `docker-compose.yml` (nginx + php + postgres + worker):
`.env` → `docker compose up -d`. Воркеры на отдельной машине: только контейнер `worker`
или пункт 7 этого гайда с `WORKER_QUEUE_MODE=http` и `WORKER_API_TOKEN` из `.env` сервера.
