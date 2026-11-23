# Разворачивание PRX в закрытой песочнице — пошагово (по опыту 21–22.09.2026)

> Цель: следующий агент развернул среду за минуты, а не за квест.
> Проверено на Debian 12 (bookworm), gcc 12, python 3.11. Сеть песочницы:
> **доступны только github.com/codeload, api.github.com и PyPI**; apt-зеркала
> Debian/Sury, packagist.org, docker-registry, duckduckgo — НЕдоступны.

---

## 0. TL;DR — одна команда

```bash
bash bin/setup-sandbox.sh --serve      # соберёт всё и поднимет сайт :8080
bash bin/test                          # 46/46 дымовые+юнит (мини-раннер)
```

Скрипт идемпотентен: повторный запуск пересобирает только недостающее.
Префикс установки `/tmp/prx-opt` — **переживает между сессиями только пока
жив песочница**; после пересоздания песочницы запусти скрипт снова.

Что получится в итоге:

| Компонент | Как | Проверка |
|---|---|---|
| PHP 8.4 CLI | собран из исходников в `/tmp/prx-opt/php`, симлинк `/usr/local/bin/php` | `php -v` |
| PostgreSQL 16.2 | PyPI-пакет pgserver, данные `~/.prx/pgdata`, порт 5432 | `psql -h 127.0.0.1 -U prx -d prx -c 'select 1'` |
| Схема БД | `bin/console db:migrate` (32 миграции) | `bin/console db:migrate --status` |
| Админ | миграция prx102_004: `admin` / `admin` (только на пустой базе) | вход `/auth/login` |
| Сайт | `PORT=8080 bash bin/serve` (0.0.0.0 — виден в превью) | `curl -I localhost:8080/auth/login` |
| Тесты | `bash bin/test` (fallback на tests/mini_runner.php — без composer) | 46/46 |

---

## 1. Что в песочнице ДОСТУПНО / НЕдоступно (сначала проверь сеть!)

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://github.com          # 200
curl -s -o /dev/null -w "%{http_code}\n" https://pypi.org/simple/    # 200
curl -s -o /dev/null -w "%{http_code}\n" http://deb.debian.org/...   # 000 (нет)
curl -s -o /dev/null -w "%{http_code}\n" https://repo.packagist.org  # 000 (нет)
```

Из «доступно только GitHub+PyPI» следует:

| Нужное | Откуда брать |
|---|---|
| PHP-исходники, библиотеки C | codeload.github.com (`tar.gz refs/tags/...`) |
| autoconf/m4/bison 2.x/3.x/1.4.x | **tar-mirror/*** на GitHub — РАСПАКОВАННЫЕ release-тарболы GNU (есть готовый `configure`!) |
| re2c | PyPI-wheel `pip download re2c` → в wheel лежит бинарник `re2c/data/bin/re2c` |
| cmake/ninja/meson/pkgconf | pip install (cmake/ninja/meson — колёса; pkgconf — исходники с codeload) |
| PostgreSQL | PyPI `pgserver` (полный сервер + libpq + заголовки — против него собираем ext/pgsql) |
| composer/phpunit | НЕдоступны (packagist закрыт) → vendor-заглушка setup.sh + tests/mini_runner.php |

---

## 2. Цепочка сборки (если руками) и грабли каждой ступени

Порядок строгий: `re2c → m4 → autoconf → bison → pkgconf → zlib → openssl →
libxml2 → curl → php`. Все — `--prefix=/tmp/prx-opt`, статические (.a).

1. **m4 1.4.18** (из tar-mirror): 2 патча под glibc ≥2.28:
   - `lib/c-stack.c`: `#elif HAVE_LIBSIGSEGV && SIGSTKSZ < 16384` → `#elif 0`
   - `lib/*.c` с `_IO_ftrylockfile`: `defined _IO_ftrylockfile` → `(defined _IO_EOF_SEEN || defined _IO_ftrylockfile)`
   - `lib/stdio-impl.h` дописать: `#ifndef _IO_IN_BACKUP \n # define _IO_IN_BACKUP 0x100 \n #endif`
2. **bison 3.0.4**: тот же патч `_IO_EOF_SEEN` в `lib/fseterr.c`.
3. **pkgconf** (meson): ставит `.so` в `lib64/` и `lib/x86_64-linux-gnu/` —
   развернуть в `$OPT/lib` и сделать враппер `bin/pkgconf` c
   `LD_LIBRARY_PATH=$OPT/lib` + симлинк `pkg-config` (autoconf ищет именно
   такое имя).
4. **OpenSSL**: ставит `.a` в `lib64/pkgconfig` — скопировать `.a` и `.pc` в `lib`.
5. **PHP `./buildconf`** требует точного `autoconf 2.69` (нужен m4) и bison≥3.0,
   re2c≥1.0.3. Конфигурация: `--disable-all --enable-cli` + только нужное
   (см. setup-sandbox.sh): pgsql/pdo_pgsql от pgserver (`--with-pgsql=$PG_PREFIX`),
   `--enable-mbstring --disable-mbregex` (mb_ereg в платформе не используется),
   dom/xml/simplexml/xmlwriter/xmlreader, curl, openssl, zlib, opcache,
   session/ctype/tokenizer/filter/fileinfo/calendar/bcmath/sockets/pcntl/phar/iconv.
   - **Грабля:** заголовки OpenSSL не попадают в INCLUDES Makefile → добавить
     `-I/tmp/prx-opt/include` в `CPPFLAGS` и пересобрать.
6. **php.ini**: `zend_extension=opcache.so` (именно zend_, не extension),
   cafile `/etc/ssl/certs/ca-certificates.crt`, дата Europe/Moscow.

Всё это уже зашито в `bin/setup-sandbox.sh` — руками повторять не нужно.

---

## 3. Приложение после сборки (скрипт делает сам, тут — для понимания)

```bash
bash bin/setup.sh                    # .env (СО СЕКРЕТАМИ и токенами!) + vendor-заглушка
bin/console db:migrate               # 26 миграций на чистой БД
bin/console auth:user --email=... --password=... --username=admin --admin
PORT=8080 bash bin/serve             # dev-сервер (PHP built-in + bin/router.php)
bash bin/test                        # мини-раннер тестов
```

**Известные точки** (починены 22.09, но помни о них):
- `config/url.php`: доверенные хосты — в development разрешены
  localhost/127.0.0.1/*.e2b.app (иначе «Untrusted host» 500 на каждой странице).
- Минион-флаги: голый `--admin` приходит как **NULL** (не TRUE).
- `.env`: setup.sh генерирует WORKER_API_TOKEN и др. — раньше inline-комментарий
  становился значением (воркеры ловили 401). Не оставляй `KEY=   # коммент` в .env.

---

## 4. Python-воркеры (асинхронный контур)

```bash
pip install asyncpg httpx            # базовые; LLM-каналы требуют остальное из workers/requirements.txt
cd workers && WORKER_PG_DSN="postgresql://prx:prx@127.0.0.1:5432/prx" \
  WORKER_QUEUE_MODE=pg WORKER_CHANNELS=echo python3 -m prx_workers
```

Проверка сквозняка: `php -r '...(bootstrap)... Service_Prx120_JobQueue::push("echo",["hello"=>"world"]);'`
→ воркер логирует «Задача N [echo] выполнена», в `prx_120_jobs.result` — ответ.

Для LLM/эмбеддингов без GPU в проекте есть моки:
`workers/mock/mock_llm_server.py` (:1234) и `mock_embed_server.py` (:1235) —
подними их и направь `LOCAL_API_URL`/`EMBEDDING_API_URL` на localhost.
Мок отвечает детерминированно и умеет три режима: `MOCK_LLM_REPLY_FILE`
(один ответ на всё), `MOCK_LLM_REPLY_FILES="a.json,b.json"` (очередь: у
prx646.label это проход строк и проход состава) и
`MOCK_LLM_ROUTES="/tmp/study.json=справочник по ингредиентам;/tmp/compose.json=редактор состава"`
— ответ по подсказке в промпте. Третий нужен, когда в одном моке живут разные
каналы (`prx646.label` + `prx646.study`): очередь файлов общая, и запрос изучения
получил бы файл, заготовленный для фото. В файле-шаблоне `%NAME%` заменяется
названием вещества из промпта («Вещество: …»).

**`php -S` не перечитывает `.env`:** значения попадают в `getenv()` при первом
запросе и живут до перезапуска `bin/serve`. Правки лимитов (`PRX646_…` и других)
проверяйте после перезапуска сервера — иначе легко принять кэш за баг.

### Логи контура (что прислать при разборе инцидента)

Обе стороны пишут плоские ротируемые логи — их видно глазами и можно прислать
целиком, в отличие от PHP-логов Kohana:

```bash
tail -f application/logs/prx-site.log      # сайт: приёмка результата воркера,
                                           # 403/404/500, постановка фото в очередь
tail -f workers/logs/prx-worker.log        # воркер: задачи, сбои модели,
                                           # ответы сайта («HTTP 500: приёмка упала: …»)
```

Проверка, что лог доходит до файла: `curl -s localhost:8080/label` → в логе сайта
появляется запись `label: фото принято…`, а после прогона — `label: результат
прогона N принят`. Настройки — `PRX_LOG_*` в `.env` сайта и `WORKER_LOG_*`
в `workers/.env`, оба файла перечислены в `workers/.env.example`/`.env.example`.

---

## 5. Чек-лист «среда готова»

- [ ] `php -v` → 8.4.x, `php -m` содержит pgsql, pdo_pgsql, mbstring, dom, curl, openssl, Zend OPcache
- [ ] `psql -h 127.0.0.1 -U prx -d prx -c 'select 1'` — без пароля не спросит (trust из pgserver)
- [ ] `bin/console db:migrate --status` → «применено N, новых нет»
- [ ] `curl -s localhost:8080/auth/login` → 200; `/search/api` гостю → 401 JSON
- [ ] `bash bin/test` → 46/46
- [ ] вход админа на /auth/login работает (email_confirmed=1 у сид-админа)
- [ ] воркер echo отработал задачу из очереди

## 6. Если что-то пошло не так — быстрый диагноз

| Симптом | Причина | Лечение |
|---|---|---|
| `Untrusted host` 500 | хост не в `config/url.php` | добавить в dev-блок (уже есть для localhost/e2b) |
| `Call to undefined pg_pconnect()` | PHP без ext/pgsql | пересобрать с `--with-pgsql=$PG_PREFIX` |
| `Please enable the Minion module` | shadowing-копия класса или модуль не в bootstrap | проверить `application/classes/Minion/` нет лишних копий |
| `db:migrate` пишет «ОШИБКА» | см. лог deploy/`bin/console db:migrate` на месте | свежая БД ловит порядок миграций: prx208 ДО prx120 — не менять |
| воркер не забирает задачи | DSN/QUEUE_MODE | `WORKER_QUEUE_MODE=pg WORKER_PG_DSN=...`; таблица prx_120_jobs |
| worker POST на сайт → 401 | токены в .env сайта и workers/.env разные | совмещать WORKER_API_TOKEN (см. §3) |
| `make fresh` / чистая БД | порядок модулей в bootstrap = порядок миграций | prx208_events раньше prx120_jobs |
| PG умирает сразу после setup-скрипта | `pgserver.get_server()` по умолчанию `cleanup_mode='stop'` → atexit-остановка при выходе python | всегда `get_server(pgdata, cleanup_mode=None)` (уже в setup-sandbox.sh/restore-env.sh) |
| PG жив, но TCP 5432 «connection refused» | `ensure_postgres_running()` pgserver слушает только unix-сокет (`-h ''`) | стартовать через `pg_ctl -o "-h 127.0.0.1 -p 5432 -k /tmp"` (см. pg_start-блок в setup-sandbox.sh); скрипт сам перезапускает сокетный инстанс |
| задача prx120 навсегда `processing` | старый баг asyncpg: лишний параметр в SQL `fail()` (IndeterminateDatatypeError) — починен 22.09 | обновить `workers/prx_workers/queue.py`; разлипить: `UPDATE prx_120_jobs SET status='pending', claimed_by=NULL WHERE status='processing'` после стопа воркера |
