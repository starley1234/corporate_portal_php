# Журнал проверок PRX

Свежие записи — сверху. Что не проверено на стенде, отмечено явно.

<!-- context-index:begin -->
<!-- Индекс разделов. Генерируется: python3 .tools/context_pack.py index docs/VALIDATION.md --write. Руками не править. -->
| Раздел | Строка | Размер |
|---|---|---|
|   23.11.2026 — спринт структуры тестов и HTTP-границ | 48 | 4,0 КБ |
|   23.11.2026 — убран лишний флаг документооборота | 86 | 1,4 КБ |
|   23.11.2026 — упрощение архитектурных границ | 100 | 3,4 КБ |
|   23.11.2026 — представления и остатки канонизации | 133 | 1,7 КБ |
|   23.11.2026 — статусы, поиск в другой раскладке, LDAP | 151 | 2,2 КБ |
|   23.11.2026 — каноническая структура и имена | 172 | 2,6 КБ |
|   23.11.2026 — канонический фасад `Storage::`, prx390/391/395 больше не зовут prx212 | 199 | 6,5 КБ |
|   23.11.2026 — единое `Event::publish`, legacy-диспетчер удалён | 266 | 5,5 КБ |
|   23.11.2026 — организация ключа, платформенная идемпотентность, prx395 на контуре prx150 | 332 | 4,0 КБ |
|   23.11.2026 — URL «префикс + слаг», API-доступ и OCR-шлюз | 380 | 3,2 КБ |
|   23.11.2026 — workers/ по владельцам, страницы событий/логов/памятки | 420 | 2,6 КБ |
|   23.11.2026 — чистка: перенос закладок, `archive/`, документация | 449 | 1,9 КБ |
|   23.11.2026 — фундамент платформы и удаление витрины дизайна | 472 | 2,0 КБ |
|   18.11.2026 — план предприятия и архив портала | 500 | 0,5 КБ |
|   18.11.2026 — документооборот включён для проверки | 507 | 0,5 КБ |
|   18.11.2026 — одна настройка документооборота в bootstrap | 514 | 0,8 КБ |
|   18.11.2026 — настройки воркера на одном хосте и проверка PHP-связи | 523 | 1,2 КБ |
|   18.11.2026 — подключение старого контура одним комментарием | 537 | 0,8 КБ |
|   18.11.2026 — проверка через UI и переключатель документооборота | 547 | 1,5 КБ |
|   18.11.2026 — PDF → текст: живой PHP и Python | 564 | 2,8 КБ |
|   18.11.2026 — архив docflow и фокус на ИИ-пайплайнах | 589 | 2,1 КБ |
|   Фактически выполнено 26.10.2026 | 611 | 3,6 КБ |
|   Дополнение 27.10.2026 — расширение и корректировка `prx140_design_lab` | 635 | 3,0 КБ |
|   Дополнение 27.10.2026 — рабочие страницы по эталону v6 | 654 | 2,6 КБ |
|   HTTP и права (основной прогон 26.10.2026) | 675 | 2,7 КБ |
|   Ограничения | 709 | 3,7 КБ |
|   Документооборот prx390–prx394 (16.11.2026) | 743 | 1,9 КБ |
|   Архитектура как код и первичный администратор (16.11.2026) | 768 | 1,5 КБ |
|   Исправления после production-проверки (16.11.2026) | 786 | 0,8 КБ |
|   Перестройка структуры (16.11.2026) | 796 | 0,7 КБ |
|   VLM-демо prx398 (16.11.2026) | 806 | 2,1 КБ |
|   OCR Provider для doc.normalize (16.11.2026) | 832 | 2,5 КБ |
|   PRX-префикс и структурные швы (16.11.2026) | 865 | 4,1 КБ |
|   Навигация, селекты и Design Lab №11 (17.11.2026) | 913 | 4,9 КБ |
|   Избранное аккаунта (17.11.2026) | 960 | 4,3 КБ |
|   Перенос storage из docroot (17.11.2026) | 999 | 3,8 КБ |
|   Диагностика приёма и `doc.normalize` (17.11.2026) | 1036 | 3,5 КБ |
|   Сроки и подписки событий (17.11.2026) | 1070 | 7,7 КБ |
<!-- context-index:end -->
## 23.11.2026 — спринт структуры тестов и HTTP-границ

- Все 8 PHP-стендов из `.tools/` перенесены в `tests/Contracts/`; 3 включаемых
  сценария уведомлений — в `tests/Contracts/fixtures/`. Вместе с foundation
  это 9 независимых контрактов. Имена короче, старых файлов-обёрток нет.
- E2E сгруппированы в `tests/E2e/`, Python-проверки инструментов — в
  `tests/Tooling/`, WASM и mini-runner — в `tests/Support/`. Обновлены вызовы
  в bin/Makefile, документация и подсказки. Mini-runner использует тот же
  `tests/bootstrap.php`, что и PHPUnit, вместо копии инициализации.
- `bin/test --contracts` / `make test-contracts` запускают все контракты
  отдельными процессами, даже при установленном PHPUnit. Ошибка или пустой
  набор дают ненулевой код; ошибка одного теста не скрывает следующие.
- Удалены неиспользуемая демонстрация AND/OR и пара PDF-проверок, обращавшихся
  к отсутствующим `Service_Prx634_*`. Удалён устаревший режим `bin/test --pdf`;
  действующий PDF проверяется worker-тестами и E2E/document_pipeline.py.
- Общий HTTP-контроллер больше не обслуживает очередь на GET и не содержит
  мёртвый метод записи диагностического кэша классов. Опрос semantic status
  также не запускает уборку. **Для эксплуатации нужен уже существующий
  `portal:maintain` по таймеру**, не посещения пользователей (WORKERS.md).
- Контекст-пак ищет PHP-контракты по новым путям и находит вложенные worker-тесты,
  в том числе по каталогу владельца. Добавлены 13 регрессий shell-runner,
  структуры, общего bootstrap и поиска тестов. UI E2E использует канонический
  `/prx395_document_extract` вместо старого адреса с редиректом.
- Проверено до и после переноса: все 9 контрактов на PHP/WASM 8.4 —
  **765 проверок успешно** (36/9/20/9/67/85/498/22/19). Существующие deprecated
  предупреждения PHP 8.4 в LLM/уведомлениях не исправлялись; ожидаемая запись
  rollback failure в foundation — сценарий проверки сохранения исходной ошибки.
- `make check`: 42 unittest, 1 пропуск; 2 предупреждения ACL. Дополнительно
  root unittest discovery также находит 42 теста. `make env-check`: 0 ошибок,
  25 прежних предупреждений. `php_check_all.sh application` — успешно;
  PHP parser tests — 34 файла без ошибок. Bash syntax и Python compileall — OK.
- Workers: 145 passed, 1 skipped; JS: 38; architecture: 125. Строгая проверка
  модели и сборка архитектуры успешны. Оба документных E2E запускают `--help`
  без ошибок импорта; их сетевое выполнение не проверено.
- Ограничения: нативного PHP CLI нет, PostgreSQL-стенд не поднимался.
  Полный PHPUnit, миграции, живой HTTP/worker E2E и работа целевого cron не
  подтверждены. WASM-контракты со стабами их не заменяют.

## 23.11.2026 — убран лишний флаг документооборота

- По замечанию владельца удалён добавленный env-флаг управления docflow.
  Четыре модуля снова подключены явным блоком в `application/bootstrap.php`;
  отключение — комментирование блока, без дополнительной конфигурации.
- Удалены связанные проверки положений флага и дублирование integration-job
  в CI. Актуализированы инструкции и архитектурная модель; историческая запись
  предыдущего этапа ниже описывает уже отменённое решение.
- `make check` — успешно (29 тестов, 1 пропуск); `make env-check` — 0 ошибок,
  25 предупреждений. PHP parser application/tests — без синтаксических ошибок.
  PHP/WASM 8.4: оставшиеся 36 проверок основы — успешно. Архитектура собрана,
  строгая проверка модели и 125 тестов архитектуры — успешно.
- Полный PHPUnit и миграции PostgreSQL в этой среде не запускались.

## 23.11.2026 — упрощение архитектурных границ

- `docs/ARCHITECTURE.md` сокращён до рабочего контракта: HTTP → Service →
  свои данные; три канала доступа; обязательная работа через Service/очередь,
  `Event` — только best effort. Новый event outbox не добавлялся.
- Подключение модулей — только `application/config/modules.php`. Старый
  документооборот выключен по умолчанию; opt-in `PRX_DOCFLOW_ENABLED=1`.
  Проверены оба положения флага; исправлены инструкции и YAML-карта, dist пересобран.
- CSRF UI больше не отключается произвольными Authorization/X-Worker-Token.
  Машинный канал явно объявляется контроллером; vectors проверяет worker-токен.
  Пустой секрет закрывает semantic/queue API и в development.
- Исправлены runtime-поломки: отсутствующий родитель `Prx395_Json` у PDF worker;
  лишнее protected-переопределение публичного `_json_response` у API Machine.
  Worker проверяет токен до действия, возвращает JSON ошибок и no-store.
- `Prx_Db::transaction` принимает соединение, проверяет begin/commit,
  запрещает вложенный вызов helper на том же соединении; ошибка rollback
  не скрывает исходное исключение. Ручные транзакции автоматически не обнаруживает.
- Добавлены `make test-contracts` и GitHub Actions: контракты, JS, Python,
  PHP lint, PostgreSQL/PHPUnit с docflow 0/1 и повторным запуском миграций.
  Workflow разобран YAML-парсером; успешный запуск GitHub Actions пока не подтверждён.
- Выполнено: `make check` (29 тестов, 1 пропуск, 2 предупреждения ACL);
  `make env-check` (0 ошибок, 25 предупреждений); `php_check_all.sh`
  (application — чисто), parser tests (24 файла); JS (38), architecture (125),
  workers (145, 1 пропуск). Сборка архитектуры — без ошибок/предупреждений.
- `tests/Contracts/foundation.php`: 41 проверка на PHP/WASM 8.4 (также 8.3).
  `prx_db: rollback failed` в stderr ожидается в тесте сбоя rollback.
- Ограничения: нативного PHP CLI нет; `make lint` и `bash bin/test` недоступны.
  Новая PostgreSQL, полный PHPUnit, миграции и HTTP E2E здесь не запускались.
  PHP/WASM со стабами не заменяет интеграционный прогон.
- Следующие отдельные работы: реальные конкурентные контракты PG/HTTP очереди,
  перевод legacy actor-массивов, воспроизводимые Composer/Python зависимости.
  Рефакторинг крупных сервисов и надёжный доменный outbox не объявляются готовыми.

## 23.11.2026 — представления и остатки канонизации

- Представления prx-модулей и scaffold используют `views/prxNNN/`; общий
  `partials/` и каталоги библиотек сохранены. Линтер проверяет расположение
  и литеральные ссылки внутри prx-модулей.
- Удалены только MySQL-миграции `llm`; действующие PostgreSQL-миграция и модель
  по-прежнему используют `llm_requests`. Поэтому `git grep -n llm_requests`
  не пуст: удалить эти ссылки без изменения схемы и функциональности нельзя.
- `make check`: 29 тестов, один пропуск; ошибок нет (два предупреждения ACL).
  `make env-check`: ошибок 0, предупреждений 25. Отдельные manifest и portal
  проверки прошли. PHP parser: application 465, tests 23, .tools 12, ошибок 0.
- `npm test`: 38 успешно; `npm run architecture` успешно;
  `npm run test:architecture`: 125 успешно. Workers pytest: 145 успешно,
  один пропуск. PHP/WASM стенды: Gate — 9, API prx597 — 67 успешно.
- `bash bin/test` не выполнен: отсутствует нативный PHP CLI; новая PostgreSQL
  для интеграционного прогона также недоступна. Статический парсер и WASM
  стенды не заменяют запуск PHPUnit и миграций на реальной базе.

## 23.11.2026 — статусы, поиск в другой раскладке, LDAP

- `/prx595/admin/activity`: статусы очереди локализованы через Kohana i18n;
  неизвестный статус остаётся экранированным. `/tasks`: убраны три ссылки на
  несуществующие сервисы, пустое состояние сохранено.
- Палитра и каталог используют общий поиск по русской/английской раскладке,
  включая регистр, смешанные слова и небольшие опечатки в перекладке; проверен
  пример `d\[jzobt` → «Входящие» в обоих интерфейсах.
- `ldap_auth` по умолчанию выключен; при `PRX_LDAP_ENABLED=1` работает LDAP
  bind по TLS для существующих локальных пользователей с ролью login. Локальные
  роли, подтверждение email и TOTP сохранены; локального fallback и создания
  аккаунтов нет. Установка, настройки, ограничения и откат — в README модуля.
- Проверки: `make check` (25 тестов, 1 пропуск), `php_check_all` (467 файлов),
  `npm test` (38), архитектура (125), workers (145, 1 пропуск) — успешно.
  PHP/WASM: LDAP mock — 20, интерфейс и настройка — 19 проверок; реальные
  шаблоны и bootstrap с LDAP как включённым, так и выключенным — успешно.
- Ограничения: реальный LDAP/AD, сертификаты и сборка Docker не проверены в
  этой среде; перед внедрением нужен стенд с PHP LDAP, FPM и PostgreSQL.
  `bin/test` недоступен без PHP CLI; полный WASM mini-runner по-прежнему имеет
  35 ошибок инфраструктуры/прежних тестов и 2 пропуска (см. запись ниже).

## 23.11.2026 — каноническая структура и имена

- `prx102_core` и `prx103_platform` имеют собственные префиксы классов;
  миграции Auth переименованы в `Prx_102_001…005`, общие таблицы не менялись.
  Модели явно сохраняют `users` и `roles`, включая экземпляры с префиксом.
- RBAC вынесен в `Prx_Acl` через actor; worker-токен — в `Prx_Gate`.
  Сохранены отдельные правила пустого токена, приоритета заголовка и query,
  site-токена и строгого сравнения. Для пяти конкретных HTTP-точек и 401
  линтер допускает прямой вызов `Prx_Gate`: это необходимые точки входа,
  остальные модули не могут звать его напрямую.
- Строгий манифест, библиотека `PostgreSQL_PDO`, проверка фасадов и миграций;
  PostgreSQL-миграции `llm` находятся в `migrations_pg/`.
- Условные обработчики docflow перемещены в пакеты владельцев, проверка PHP
  по health сохранена. Макеты перенесены в `docs/design/`, движок — в
  `.tools/architecture/`, systemd-инструкция — `docs/DEPLOY-SYSTEMD.md`.
- Проверки: `make check` (25 тестов, 1 пропуск), `php_check_all` (464 PHP-файла),
  `npm test` (35), `npm run architecture` и `npm run test:architecture` (125),
  workers pytest (145, 1 пропуск) — успешно. PHP/WASM: worker gate — 11,
  API уведомлений — 59, движок уведомлений — 498 проверок успешно. В WASM
  отдельно прошли smoke Auth-таблиц и unit worker-gate.
- Ограничения: `bash bin/test` не запускается без PHP CLI; PostgreSQL и полный
  HTTP-прогон не проверены. Пробный mini-runner под WASM: 69 успешно, 35 ошибок,
  2 пропуска; из ошибок 33 связаны с отсутствием `pg_pconnect`, одна — с сессией в WASM,
  ещё одна — прежнее несоответствие `HTTP_Exception_403::getCode()` (404)
  и HTTP-статуса ответа (403). Отдельный `php_class_ref_lint` по-прежнему
  сообщает 2 прежние ошибки (`Notify` в тексте и архивный `Prx394_Drafts`).

## 23.11.2026 — канонический фасад `Storage::`, prx390/391/395 больше не зовут prx212

**Фасад.** `application/classes/Storage.php` переписан начисто: `final class Storage`,
только статические методы — `save(array $file, string $module, ?int $user_id = NULL,
bool $is_public = FALSE): int`, `put(string $content, string $filename, string $module,
?int $user_id = NULL, bool $is_public = FALSE, ?string $mime_type = NULL): int`,
`bytes(int): ?string`, `metadata(int, ?int $user_id = NULL): ?array` (только `id, filename,
extension, mime_type, size, hash, is_public, module, created_at, url`), `url(int, ?int $user_id = NULL,
int $ttl = 3600): ?string`, `delete(int, ?int $user_id = NULL): bool`, `read_owned`, `delete_owned`,
`owned_ids_before(string, int, int $limit = 100): array` (`int[]` — в docblock: PHP не знает такого
типа, бриф перечислял его как тип). `upload()`, `path()`, возвраты ORM и `Auth::instance()` удалены.
Внутри — `Service_Prx212_Storage` через `class_exists`; модуль не подключён: записи (`save`/`put`/
`delete`/`delete_owned`) бросают `Storage_Exception` (`application/classes/Storage/Exception.php`,
`extends RuntimeException`), чтение возвращает `NULL`/`FALSE`/`[]`. Любая ошибка хранилища при записи
оборачивается в `Storage_Exception` (предыдущее исключение сохранено); сбой чтения — WARNING в
`Kohana::$log` и `NULL`. Фасад не решает права на объект: `file_id` вызывающий берёт из своей
авторизованной записи, файловые правила (владелец/публичность) применяет только `delete()`
(docblock, `docs/FOUNDATION.md` §5, корневой `AGENTS.md` п. 2).

**prx212.** `Service_Prx212_Storage::delete($file_id, ?int $user_id = NULL)` и
`_check_permission($file, $action, ?int $user_id)` без `Auth` (гость — отказ, владелец — да,
DEVELOPMENT-обход сохранён). Добавлен `read_bytes($file_id): ?string` (локальный диск и S3, без
проверки прав) и общий `_read_bytes()` для `read_owned`; форматы, MIME, лимиты, пути, S3 и
symlink-защита не менялись. `Controller_Prx212_Api::action_delete` передаёт ID пользователя.
Побочное: `Controller_Prx212_Index` бросал/ловил несуществующий `Service_Prx212_Storage_Exception`
(fatal при ошибке загрузки) — заменён на `Storage_Exception`.

**Вызывающие модули.** prx390 `Api::action_file` и prx391 `Documents::action_download` —
`Storage::bytes($file_id)` вместо `getFilePath()` + `file_get_contents()` (Content-Length по
`strlen`), то есть теперь работают и с S3; prx390 `Intake::_store_file` — `Storage::save($file,
'prx390', $user_id ?: NULL)` без `class_exists`; prx395 `Extraction` — без внедрённого сервиса:
`Storage::save/put/read_owned/delete_owned/owned_ids_before`. `grep 'Service_Prx212_\|Model_Prx212_\|
ORM::factory('Prx212'` по `application/` вне prx212 и фасада — пусто. `tests/Smoke/StorageIntegrationTest.php`
(+ одна проверка `Storage::bytes`) и `tests/Unit/StoragePathsTest.php` остаются тестами внутренностей
prx212 — сервис напрямую.

**Линтер.** `.tools/storage_lint.py` (вызывается из `portal_check.py`, т.е. в `make check`): в
`application/**` вне `prx212_file_storage/**` и `classes/Storage.php` запрещены `Service_Prx212_`,
`Model_Prx212_`, `ORM::factory('Prx212`; в `Storage.php` — `Auth::instance()`. Сообщение: «доступ
к файлам — только `Storage::` (см. `docs/FOUNDATION.md`)». Тест правила — `tests/Tooling/test_storage_lint.py`.

**Доки.** `docs/FOUNDATION.md` §5 «Файлы: фасад `Storage::`» (бывшие §5–8 стали §6–9) и правило имён
(`Event` и `Storage` — фасады, `Service_Prx212_*` — внутренности); корневой `AGENTS.md` п. 2;
`prx212_file_storage/AGENTS.md` (раздел «Публичный контракт»); `prx102_core/README.md` и
`prx212…/Service/Prx212/manual.md` — примеры на `Storage::save/put/bytes/url` без `Storage::upload`;
`docs/ARCHITECTURE.md`; памятка prx390; README prx212.

Проверено в песочнице без PHP и PostgreSQL:

- `make check` — 33 модуля, 242 класса, 26 манифестов, 93 маршрута, 69 документов, 19 python-тестов
  (10 storage-ops, 5 событий, 4 storage-линтер; 1 skipped), «files via Storage:: only».
- `bash .tools/php_check_all.sh` — 463 файла, 0 ошибок, 37 миграций, 0 замечаний; дизайн-аудит чист;
  документация в бюджете. `php_class_ref_lint` — те же 2 предсуществующие ошибки (prx208 `Notify::`,
  prx394 drafts).
- `npm test` — 35/35; `make architecture` — 0 ошибок, 0 предупреждений;
  `PYTHONPATH=workers python3 -m pytest workers/tests -q` — 145 passed, 1 skipped (workers не менялись).

Не проверено (нет PHP): phpunit `PrxFoundationTest` — новые `test_storage_save_bytes_metadata_and_delete_owned`
(без БД пропускается; с БД — транзакция, временный backend в `sys_get_temp_dir()`, уборка),
`test_storage_rejects_bad_module_with_storage_exception`,
`test_storage_without_prx212_throws_on_write_and_reads_return_null` (флаг `module_missing` через
Reflection — класс `final`); smoke-тесты; HTTP-выдача файлов из `/inbox` (prx390) и
`/prx391_documents/download` (prx391); `read_bytes` по S3. Нюанс поведения: `bytes()` возвращает NULL
и когда файла нет, и когда чтение не удалось (раньше prx390 отвечал 500 «Не удалось прочитать файл»,
теперь 404 «Физический файл не найден» плюс WARNING фасада в журнале); физический файл, чей размер
не совпал с `file_size`, тоже считается недоступным.

## 23.11.2026 — единое `Event::publish`, legacy-диспетчер удалён

**Фасад.** `application/classes/Event.php` переписан начисто:
`Event::publish(string $name, array $payload = [], ?int $initiator_id = NULL): void`.
Имя — `/^prx\d+[a-z0-9_]*\.[a-z0-9_.]+$/D`, payload рекурсивно только скаляры,
массивы и NULL (объект, ресурс, замыкание → `InvalidArgumentException` до журнала),
инициатор явный (`0` → NULL), сессия не читается; без `prx208_events` —
предупреждение в `Kohana::$log` и выход. `listen()`/`has()` оставлены тонкими
прокси шины (слушатели в `init.php` prx218/prx390/prx393 и smoke-тесты), `forget()`
удалён — вызовов нет. `fire()`/`queue()` и `application/classes/Prx/Event.php`
удалены; обход `E_DEPRECATED` при загрузке шины сохранён в `bus()`. Отклонение от
брифа: слушателей не 0, а 5 — поэтому `listen()` остался.

**Издатели.** Все 48 `Event::fire` и 6 прямых `Service_Prx208_EventBus::…->publish()`
(prx120 ×2, prx300 `_event()`, prx208 Api/Widget, `Model_Prx208_Event::trigger()`)
переведены на `Event::publish`; ORM-объекты заменены на ID и скаляры:
- prx218 (23): публикуют сервисы с явным инициатором (`?int $actor_id` добавлен
  в `ModuleManager`, `_actor_id()` в контроллерах); дубль `status_changed` в
  `Index::action_update_status` убран; слушатель `status_changed` читает
  `module_id/old_status/new_status` (раньше получал объект и падал), слушатель
  кэша переведён на `prx218.scanner.module_discovered` (`prx218.module.registered`
  никто не публиковал).
- prx212 (5): `prx212_storage.*` → `prx212.file.{uploaded,deleted,downloaded,
  shared_downloaded}`; в payload больше нет `storage_path`, email пользователя и
  токена share-ссылки. Форма загрузки звала несуществующий `upload_files()` (всегда
  500) — теперь сохраняет через `save()` по каждому файлу, событие публикует он.
- prx208 (7): `prx208_events.*` → `prx208.event.published`,
  `prx208.subscriber.{created,saved,deactivated,deleted}`; ручная публикация из
  API/виджета идёт через фасад (невалидное имя → 400). Мёртвый
  `Model_Prx208_EventPublisher` (публиковал мимо журнала) удалён; `Subscriber`
  проверяет имя по `Event::NAME_PATTERN`.
- prx390–prx393, prx450, prx300, prx120: имена и payload не менялись, добавлен
  явный инициатор (actor/`created_by`; cron и воркер — NULL).

**Линтер.** `.tools/event_lint.py` (вызывается из `portal_check.py`, т.е. в
`make check`): в `application/**` запрещены `Event::fire(`, `Event::queue(`,
`Prx_Event` и публикация `Service_Prx208_EventBus::instance()->publish()` /
`*event_bus*->publish(` вне фасада; сообщение показывает
`Event::publish('prxNNN.entity.action', [...], $initiator_id)`. Тест правила —
`tests/Tooling/test_event_lint.py`; `make check` теперь запускает `tests/test_*.py`.

**Доки.** `docs/FOUNDATION.md` §3 (+ правило имён: `Event` — фасад без префикса,
`Prx_*` — только фундамент, `Service_/Controller_/Policy_/Task_/Model_PrxNNN_*` —
код модулей; «пять классов» убрано), `ARCHITECTURE.md`, `ARCHITECTURE-REVIEW.md`
§1.5/§4/§5, `MODULE_GUIDE.md`, `README.md`, корневой `AGENTS.md`, памятки prx120
и prx208, scaffold, `/architecture/guide`, README prx102_core/prx212/prx208,
i18n и представления prx208, `.tools/context_pack.py` (события модуля из
`publish('…')`).

Проверено в песочнице без PHP и PostgreSQL:

- `make check` — 33 модуля, 241 класс, 26 манифестов, 38/74 ключей ACL,
  93 маршрута, 69 документов, 0 замечаний CSRF, 15 python-тестов (10 storage +
  5 событий), «events via Event::publish only».
- `bash .tools/php_check_all.sh` — 462 файла, 0 ошибок, 37 миграций, 0 замечаний;
  дизайн-аудит чист; JS-селекторы на месте.
- `npm test` — 35/35; `make architecture` — 0 ошибок, 0 предупреждений;
  `PYTHONPATH=workers python3 -m pytest workers/tests -q` — 145 passed, 1 skipped
  (workers не менялись).

Не проверено (нет PHP): phpunit `tests/Unit/PrxFoundationTest.php` — новые
`test_event_publish_*` (доставка слушателю, отказ по имени/payload, шина не
подключена) — и smoke-тесты; HTTP-публикация из `/prx208_events`. Журнал
`prx_208_events` хранит старые имена (`prx212_storage.*`, `prx208_events.*`) — это
история, не подписки. DB-подписки по умолчанию выключены; перед включением
`PRX208_DB_SUBSCRIBERS_ENABLED=1` сверить их имена с новыми.
## 23.11.2026 — организация ключа, платформенная идемпотентность, prx395 на контуре prx150

**Ключ и организация.** `prx_150_002` добавляет `organization_id BIGINT NULL` и
индекс. FK на `prx300` нет (инвариант 2): скаляр, существование проверяет
публичный `Service_Prx300_Directory::organization_exists()`; без подключённого
prx300 допустим только NULL. Новый `Prx150_Principal` (`id`, `name`,
`organization_id`, `scopes`) реализует интерфейс `Prx_Principal`, его реализует
и `Prx_Actor`. `Prx_Policy` теперь принимает `Prx_Principal`: одна объектная
политика проверяет и человека, и машинный ключ; RBAC-часть — только для
`Prx_Actor`, у машины ролей и `can()` нет. `Controller_Prx150_Machine::principal()`
отдаёт principal сервису; `respond()` не менялся.

**Идемпотентность.** `Prx_Idempotency` — платформенный ключ повтора:
`begin()` (advisory-lock + транзакция) → `new|replay|conflict|expired` →
`finish()` (результат + commit) / `abort()`. Плюс `run()` для простых случаев.
Таблица `prx_idempotency_keys` (`prx_110_005`), TTL `PRX_IDEMPOTENCY_TTL_HOURS`
(24), ключ `[A-Za-z0-9._:-]{1,128}`, тело не хранится — только sha256. Чистка —
`cleanup` манифеста `prx102_core` (7 дней после истечения).

**prx395 на контуре prx150.** `Controller_Prx395_Api` наследует
`Controller_Prx150_Machine`, scope `prx395.extract`; `Prx395_Error extends
Prx150_Error`. `Service_Prx395_Clients` и `PRX395_DOCUMENT_EXTRACT_CLIENTS`
удалены вместе с контроллером `Prx395_Json`. Миграция `prx_395_002`: владелец
задания — `api_client_id BIGINT NOT NULL` (положительные ID — ключи prx150,
отрицательные — задания админки), уникальность `(api_client_id, request_key)`.
Собственная логика идемпотентности из `Extraction::register` убрана —
`prx120::push` и ссылка на задание лежат в одной транзакции с ключом
`Prx_Idempotency`. Страница — `/prx395_document_extract` (+301 с
`/document-extract`). Worker-контур (`/prx395/worker/*`, `X-Worker-Token`) не
тронут. `prx450` (sync) и `prx395` (async) разведены в обоих `AGENTS.md` и
`docs/integrations/`; `ARCHITECTURE-REVIEW.md §1.3` приведён к фактическому
контракту (Bearer-ключ + scope, плоский успех, `X-Request-Id`, `no-store`).

Проверено в песочнице без PHP и PostgreSQL:

- `make check` — 33 модуля, 243 класса, 26 манифестов, 38/74 ключей ACL,
  93 маршрута, 69 документов, 0 замечаний CSRF, 10 storage-тестов.
- `bash .tools/php_check_all.sh` — 464 файла, 0 ошибок, 37 миграций, 0 замечаний;
  дизайн-аудит чист; JS-селекторы на месте.
- `npm test` — 35/35; `make architecture` — 0 ошибок, 0 предупреждений.
- `route_registry` видит 93 маршрута: новый `prx395_document_extract`, legacy
  `document-extract` и машинный `api/v1/document-extractions`.

Не проверено (нет PHP/PostgreSQL): миграции `prx_150_002`, `prx_110_005`,
`prx_395_002`; `Prx_Idempotency` на живой базе (тест `PrxIdempotencyTest`
пропускается без PostgreSQL); 401/403/429 и конфликт ключа на реальном API;
админка выбора организации. HTTP smoke ждёт стенд.

## 23.11.2026 — URL «префикс + слаг», API-доступ и OCR-шлюз

**URL.** Новое правило: страница модуля — `/prxNNN_slug`, а не короткий `/slug`;
подстраницы — `/prxNNN_slug/<action>`, внутренний API — `/prxNNN_slug/api/<action>`.
Внешний машинный контракт остаётся `/api/v1/<domain>/…`. Scaffold v2 и
`docs/MODULE_GUIDE.md` обновлены; `route_registry.py` требует префикс у модулей
с `created_at` от 23.11.2026. Пример: `/documents` → `/prx391_documents`, старый
адрес отдаёт 301 (все ссылки модулей 390–393, тесты и docs обновлены).

**prx150_api_hub.** Ключи внешних потребителей: SHA-256 ключа, префикс,
scope (`api_scopes` в манифесте модуля-владельца), лимит/мин, срок, отзыв.
`Controller_Prx150_Machine::respond()` проверяет ключ, scope и лимит, пишет
использование в `finally` и ставит `X-Request-Id`. Админка: `/prx150_api_hub`
(ключи), `/usage` (журнал с фильтрами), `/docs` (контракт для потребителя).
Миграция `prx_150_001`, чистка usage — 90 дней.

**prx450_document_ocr.** PDF/сканы → Markdown через внешний шлюз с контрактом
`POST /parse` (multipart `file`, query engine/output_format/llm_fix/…; ответ
`{file, content, cached, engine_used, llm_fixed, raw_result}`). API —
`/api/v1/document-ocr/parse`, scope `prx450.parse`. Настройки шлюза —
`PRX450_OCR_*`; потребитель может переопределить только `client_overrides`.
Страница проверки — `/prx450_document_ocr`. Событие `prx450.document.parsed`.
Локальная обёртка над Tesseract с тем же `/parse` — `services/tesseract-ocr/`
(FastAPI, 127.0.0.1:8090), инструкция — `docs/integrations/document-ocr.md`.

Проверено в песочнице без PHP и PostgreSQL:

- `make check` — 33 модуля, 238 классов, 26 манифестов, 38/74 ключей ACL,
  92 маршрута, 69 документов, 0 замечаний CSRF, 10 storage-тестов.
- `bash .tools/php_check_all.sh` — 458 файлов, 0 ошибок; 735 Tailwind-классов
  в сборке; дизайн-аудит чист (17 модулей); JS-селекторы на месте.
- `npm test` — 35/35; `make architecture` — 0 ошибок, 0 предупреждений.
- `manifest_check` проверяет `api_scopes` (префикс = ID модуля, title, rate_limit).
- `route_registry` проверяет URL-правило для новых модулей; legacy-алиас
  `/documents` помечен `legacy` и не считается нарушением.

Не проверено (нет PHP/PostgreSQL): миграция `prx_150_001`, создание/отзыв ключа,
401/403/429 на живом API, реальный OCR-шлюз и Tesseract-обёртка. HTTP smoke
обновлён (`/prx391_documents`, 301 `/documents`); ждёт стенд.

## 23.11.2026 — workers/ по владельцам, страницы событий/логов/памятки

`workers/`: обработчики разложены по пакетам-владельцам (`handlers/prx120_jobs`,
`llm`, `prx115_semantic`, `prx395_document_extract`), проба пера — в пакетах владельцев `handlers/prx390_intake/` и `handlers/prx392_analysis/`;
runtime (`__main__`, `site_check`) больше не знает о документообороте: хук включения
и `SITE` каналов объявляют сами обработчики. Удалён мёртвый `webread.py` (901 строка,
ни одного импортира) с тестами и зависимостями. Тесты зеркалят структуру.
`portal_check.py` сверяет канал ↔ пакет ↔ модуль-владелец по реестру.

Страницы v6: `/prx208_events` (сводка, «как идёт событие», журнал с фильтрами,
карточка; create/edit/delete журнала удалены), `/prx100/logs` (журнал сайта и
php-лог Kohana по дням/уровням, «где что пишется»), полоса «как идёт уведомление»
в `/prx597/admin`, `/architecture/guide` (памятка программиста). Ссылки — в
«Администрировании», «Хвосте ошибок» и на собранной схеме `/architecture`.

Проверено в песочнице без PHP и PostgreSQL:

- `pytest workers/tests` — 145 passed, 1 skipped (было 151: −7 тестов webread, +1
  на неизвестный `doc.draft`); после каждого шага переезда — зелёные.
- `make check` — чисто: 31 модуль, 226 классов, 24 манифеста, 36/72 ключей ACL,
  88 маршрутов, 65 документов, 0 замечаний CSRF, 10 storage-тестов.
- `bash .tools/php_check_all.sh` — 436 файлов, 0 ошибок; 727 Tailwind-классов в
  сборке (`npm run build:css` пересобран); дизайн-аудит чист; JS-селекторы на месте.
- `npm test` — 35/35.

Не проверено (нет PHP): страницы не открывались в браузере — только `design_audit`
по модулям prx103_platform, prx208_events, prx101_architecture, prx597 (0/0);
HTTP smoke обновлён (`/prx100/logs`, `/architecture/guide`, `/prx208_events`) и ждёт стенд.

## 23.11.2026 — чистка: перенос закладок, `archive/`, документация

Удалено: предложение переноса старых браузерных закладок в Избранное
(вьюха, `navpalette.js`, `Prx110/Api.php` + `Favorites.php` `import`, тесты JS/PHP/HTTP-smoke);
папка `archive/` целиком (21 файл, `prx394_replies` и воркер `doc.draft`); документы
`PLAN-LLM-DOCFLOW.md`, `GIT_HELP.md`, `DESIGN-CATALOG-NAVIGATION.md` (дублировал
`FRONTEND.md`, правила перенесены). `docs/ARCHITECTURE-REVIEW.md` переписан: контракт
модуля, стиль, решения с владельцами, что сделано и что осталось. `.tools/portal_check.py`
не требует больше файлы архивных манифестов.

Проверено в песочнице без PHP и PostgreSQL:

- `make check` — чисто: 31 модуль, 226 классов, 24 манифеста (0 ошибок), 36/78 ключей ACL
  (0 ошибок), 87 маршрутов (0 ошибок), 65 документов (0 мёртвых ссылок), 0 замечаний CSRF,
  10 storage-тестов.
- `npm test` — 35/35 (2 теста переноса закладок удалены вместе с кодом).
- `bash .tools/php_check_all.sh` — 0 синтаксических ошибок (tree-sitter), дизайн-аудит чист,
  JS-селекторы на месте, Tailwind-классы в сборке, бюджеты документации в норме.
- `pytest workers/tests` (pytest, httpx, pypdf, bs4 поставлены в песочницу) — 151 passed, 1 skipped;
  код воркеров в этой чистке не менялся.

Не проверено (нет PHP в окружении): phpunit, HTTP smoke, `make new-module` вживую.

## 23.11.2026 — фундамент платформы и удаление витрины дизайна

Удалено: модуль `prx140_design_lab`, `/design-lab`, его тесты и упоминания.
Добавлено: `Prx_Actor`, `Prx_Policy`, `Prx_Db`, `Prx_Event`, `Prx_PostGuard`,
page-kit (`partials/filters`, `empty_state`, `flash`), scaffold v2, шесть
статических гейтов в `make check`.

Проверено в песочнице без PHP и PostgreSQL:

- `make check` — чисто: 31 модуль, 226 классов, 24 манифеста (0 ошибок),
  36 объявленных и 78 используемых ключей ACL (0 ошибок), 87 маршрутов
  (0 ошибок), 68 документов (0 мёртвых ссылок), 0 замечаний CSRF, 10 storage-тестов.
- `bash .tools/php_check_all.sh` — 436 PHP-файлов, 0 синтаксических ошибок
  (tree-sitter), 33 миграции без замечаний, дизайн-аудит чист, JS-селекторы на месте.
- `npm test` — 37/37 (5 новых — CSRF-хук: fetch, Request с body, XHR, гость, чужой origin); `npm run build:css` пересобран и закоммичен
  (`app.css` + `.prx-empty-title`).
- `.tools/route_registry.py` нашёл реальный дефект: маршрут `prx391/api`
  ссылался на несуществующий `Prx391_Api`; исправлен на общий `Prx39x_Api`.

Не проверено (нет PHP в окружении): phpunit с новым `tests/Unit/PrxFoundationTest.php`,
`make new-module` вживую, HTTP smoke. Первый запуск на стенде — обязательный.

---


> 23.11.2026: альтернативная витрина `prx140_design_lab` удалена из проекта.
> Записи ниже о ней — история проверок, а не текущее состояние.

## 18.11.2026 — план предприятия и архив портала

- HTTP smoke проверил главную, отдельный `/portal-plan`, редирект гостя на
  вход, разделение списков и сохранение кнопок тестовых сервисов.
- `check-fresh.sh`: 33 миграции, 85 PHP-тестов, 154 HTTP-проверки.
  Сборка Tailwind, 32 JS-теста и `make check` прошли.

## 18.11.2026 — документооборот включён для проверки

- Новый синтетический PDF из «Входящих» прошёл `doc.normalize` и создал
  карточку `/prx391_documents/2`. Боевой ИИ-разбор не проверялся.
- Отдельное распознавание PDF прошло для образца и загрузки (`#86`, `#87`).
  Чистая БД: 33 миграции, 85 PHP-тестов и 152 HTTP-проверки.

## 18.11.2026 — одна настройка документооборота в bootstrap

- PHP и воркер согласовали `FALSE` и `TRUE` через аутентифицированный health;
  противоположное значение в переменной окружения на результат не повлияло.
  Новый UI распознавания доступен администратору в обоих режимах.
- 85 PHP-тестов в каждом режиме; HTTP smoke 150/151 запрос; 152 Python-теста.
  Регрессия: старые выключенные каналы в смешанном списке не останавливают
  `echo` и `document.extract`. Состояние сервера заказчика не проверено.

## 18.11.2026 — настройки воркера на одном хосте и проверка PHP-связи

- На стенде без `workers/.env` проверены реальные PHP-маршруты `document.extract`
  и `doc.normalize`: `--check-site` выбрал `APP_URL` и получил ответы с верным
  токеном. С неправильным токеном показал 401/403; на закрытом порту —
  `ConnectError`, `connection_refused`. Токены, PDF и тела ответов не выводятся.
- `asyncpg` подключился к PostgreSQL с DSN из корневого `DB_DEFAULT_*`, без ручного
  `WORKER_PG_DSN`. В этой же конфигурации прошли `echo` (#65), пробный PDF (#66)
  и загрузка через UI (#67). Доступ к серверу
  `portal.toolforge.ru` не проверялся. Точная причина отказа на нём неизвестна
  до проверки адреса из процесса systemd.
- 150 Python-тестов с PostgreSQL, HTTP smoke 151 запрос, `make check`,
  `make env-check` и PHP lint прошли. Docker Compose не запускался на стенде.

## 18.11.2026 — подключение старого контура одним комментарием

- `check-fresh.sh` прошёл с закомментированным и раскомментированным блоком
  в `application/bootstrap.php`: 29/33 миграции, 85/85 PHP-тестов,
  151/152 HTTP-проверки, включая состояние в `/prx395/worker/health` с токеном.
- При выключенном старом контуре стендовый воркер вернул текст встроенного
  PDF (`#84`) и загруженного из UI PDF (`#85`). Проверка не затрагивала production.
- PHP lint, `make check`, `make env-check`, `make architecture` и 14 Python-тестов
  конфигурации и регистрации воркера прошли.

## 18.11.2026 — проверка через UI и переключатель документооборота

- На PHP 8.4.25 + PostgreSQL 16.2 + Python 3.11 с реальным воркером
  `arena-doc-ui`: [тест кнопок](../tests/E2e/document_ui.py) получил текст
  для встроенного PDF (`#56`) и для загрузки через форму (`#57`). POST без
  CSRF отклонён. Машинный контракт отдельно прошёл регрессионный E2E (`#58–60`).
- «Входящие → Принять пробное письмо» поставило `doc.normalize` (`#48`),
  создало карточку `/prx391_documents/1` (`ВХ-2026-00001`), назначение и срок.
  `/analysis` доступна; обработчик проверен с mock-LLM, не с боевой моделью.
  Оба демо используют текстовый слой, нативный OCR скана не проверялся.
- `check-fresh.sh` проверил оба положения `PRX_DOCFLOW_ENABLED`: 29/33
  миграции, повтор без новых миграций, 85 PHP-тестов в каждом режиме.
  HTTP smoke проверил старые страницы и callbacks при `0` и `1`, отсутствие
  «Ответов» и VLM-демо. Python — 145 тестов с PostgreSQL; JS — 32.

Быстрый сценарий: [проверка через интерфейс](QUICK-CHECK.md).

## 18.11.2026 — PDF → текст: живой PHP и Python

Стенд: PHP 8.4.25 CLI, PostgreSQL 16.2, Python 3.11; одноразовая БД и
синтетические PDF, два отдельных ключа интеграции. Не сервер заказчика.

| Проверка | Результат |
|---|---|
| Миграции с нуля / повтор | 29 применены / 0 новых |
| PHP-тесты (`bin/test`, мини-раннер) и `make lint` | 85 прошли, PHP-синтаксис чист |
| Python (`PRX_TEST_PG_DSN` задан) | 106 прошли, включая PostgreSQL |
| `tests/E2e/http_smoke.py`, `npm test` | 149 HTTP-запросов и 32 JS-теста прошли |
| `make check`, `make env-check`, `make architecture` | 28 модулей, 5 каналов, 10 вкладок схемы; проверки storage 10 из 10 |
| `prx120:probe`, PHP → PG → Python | `#40`: `pending → processing → done`, воркер `arena-pg`; кнопка в мониторе: `#46`, карточка показала ответ этого воркера |
| `prx120:probe`, PHP → HTTP-очередь → Python | `#29`: `pending → processing → done`, воркер `arena-http` |
| `tests/E2e/document_pipeline.py`, PG | `#41`: цифровой PDF → текст через pypdf; `#43`: `encrypted_pdf` сразу, одна попытка; чужой ключ видит 404, повтор ключа и байтов возвращает тот же job_id |
| Тот же тест, HTTP + тестовый `/parse` | `#30`: шлюз ответил 503 → `queued` → результат второй попытки (backoff 15 с); `#32`: `encrypted_pdf` без повтора. Шлюз проверил query, Bearer и multipart `file` |
| Приватность и TTL | `#16`: в payload/result нет документа или текста; после перевода срока в прошлое GET вернул 404; `prx395:purge` удалил и исходный PDF, и файл текста из локального prx212, а также регистрации в БД |

Реальный прогон выявил два дефекта на границе: PHP SAPI превращал `202` в `302`
из-за `Location` (теперь URL статуса в JSON), а в HTTP-ветке `prx120::fail`
не был задан `$delay` (добавлены backoff и регрессия). Скан и смешанный PDF
проверены на уровне выбора страниц с подменой OCR; нативные Poppler/Tesseract
и боевой `/parse` не запускались. Docker-образ OCR не собирался в песочнице.
Политика очистки требует явно настроить таймер, см. [инструкцию API](integrations/document-extractions.md).

## 18.11.2026 — архив docflow и фокус на ИИ-пайплайнах

- `make check`: реестр подтверждает 27 подключённых модулей, 4 worker-канала,
  1 активный + 5 архивных + 49 запланированных позиций портальной карты.
  Проверки storage: 9 прошли, встроенный PHP HTTP-сценарий пропущен (нет PHP CLI).
- `make env-check` и `make architecture`: без ошибок и предупреждений.
  `npm run build:css` завершился; `npm test` — 32 теста прошли.
- Pytest воркеров: 93 прошли, 1 пропущен (`PRX_TEST_PG_DSN` не задан).
  Есть регрессия: явный архивный канал останавливает запуск до доступа к очереди.
- Tree-sitter PHP: `application`, `tests` и архивные исходники — без ошибок
  разбора. Это не `php -l`. PHPUnit и полный HTTP smoke с PHP/PostgreSQL
  не запускались; проверки 404 и каталога добавлены в smoke для staging.
- прототипы `docs/design/` изучены статически. Реальный OCR/LLM, Tesseract и
  новый документный API не проверялись; реализация API в эту работу не входит.

Проверена новая установка, а не обновление исходной БД.
Среда основного прогона 26.10.2026: PHP 8.4.25 CLI, PostgreSQL 16.2,
Python 3.11, Node.js. Сайт проверялся на работающем `0.0.0.0:8080`.
Для второй итерации `prx140` 27.10.2026 см. отдельное дополнение ниже:
в восстановленной песочнице не было нативных PHP/PostgreSQL/`.env`, поэтому
результаты основного нативного прогона не приписываются новым изменениям.

## Фактически выполнено 26.10.2026

| Проверка | Результат |
|---|---|
| `make check` | 26 подключённых модулей, 183 PHP-класса, 1 active + 49 planned сервисов, 4 канала |
| Нативный `php -l` для application | 348 файлов, без синтаксических ошибок |
| `bash .tools/php_check_all.sh application` | Грамматика, дубликаты, SQL-эвристики, методы/классы, миграции, CDN, doc budget, Tailwind, дизайн и JS-селекторы — успешно |
| `bash bin/test` | 51 passed, 0 failed, 0 skipped; из них по 5 тестов `prx300_employee` и `prx140_design_lab` |
| `SMOKE_URL=http://127.0.0.1:8080 bash bin/check-fresh.sh` | Временная БД: 26 миграций; повтор — 0 новых; 51 тест; CLI maintenance и HTTP smoke на 61 запрос — успешно; БД удалена |
| `cd workers && python3 -m pytest tests -q` | 74 passed, 1 skipped без PG DSN; с отдельной test-схемой — 75 passed, 0 skipped |
| `npm test` | 14 passed |
| `npm audit` | 0 vulnerabilities |
| `make doctor` | PHP/extensions, секреты, права runtime, PostgreSQL, миграции, очередь, памятки — OK |
| `php tests/Contracts/prx597_engine.php` | 498 проверок шаблонов, очереди, настроек, админских операций — успешно |
| Остальные notification-стенды: api / delivery / hook | 59 / 85 / 22 проверок — успешно |
| `php tests/Contracts/llm_providers.php` | 9 проверок — успешно |
| `prx300_employee` service/DB | Импорт структуры и сотрудников, идемпотентный повтор, отклонение пакета с ошибкой без частичного применения, откат цикла с существующим графом, изоляция организаций, identity/link и CSV allowlist — успешно |
| `prx300:import` CLI | Один сотрудник: staged/validated без `--apply`, затем тот же пакет применён с `--apply=1`; тестовая организация удалена |
| `prx140_design_lab` unit/контракты | 7 уникальных вариантов, прямой `Controller`, одинаковое содержание, автономные CSS, `data-theme`, invalid theme без отражения, собственная 404 и антикэш-заголовки — успешно |
| `prx140_design_lab` CSS/JS | Все 8 CSS разобраны css-tree; в JSDOM проверены переключение темы, сохранение выбора, мобильное меню, demo-поиск/поддержка и счётчики секций |
| `prx140_design_lab` Chromium | Галерея и все 7 вариантов в light/dark на 1440×1000; все варианты на 390×844 с открытием меню; горизонтального overflow и pageerror не найдено; отдельно просмотрены секции сервисов, задач, людей, знаний и поддержки |
| Реальный Python echo-worker, PostgreSQL transport | Задача выполнена, результат сохранён; воркер остановлен |
| Реальный Python echo-worker, HTTP transport | Authenticated API, claim/complete, результат сохранён; release_mine проверен после исправления SQL; воркер остановлен |

## Дополнение 27.10.2026 — расширение и корректировка `prx140_design_lab`

| Проверка | Результат |
|---|---|
| Реестр и статический рендер | 10 вариантов без `aurora`, `sample-workbench` и `sample-reading`; PHP-WASM выполнил реальные `config`, `Service`, три view и все CSS модуля, сформировав галерею, 10 страниц вариантов и standalone 404 |
| PHP-синтаксис | Token-parse lint PHP 8.4 WASM для изменённых сервиса, конфигов, `show.php` и `DesignLabTest` — успешно; контроллер и остальные view проверялись в предшествующем прогоне этой итерации |
| CSS и Python smoke | Все 11 CSS витрины (галерея и 10 вариантов) разобраны css-tree без ошибок; `python3 -m py_compile tests/E2e/http_smoke.py` — успешно |
| Chromium, статический рендер | 37 сценариев: галерея light/dark/mobile; каждый из 10 вариантов в light и dark на 1440×1000; каждый на 390×844 с открытым меню; три акцента компании и клик-переключение компании/темы. Horizontal overflow, `pageerror` и console error не найдены; мобильный viewport точного эталона подтверждён как 390 px |
| Inline-SVG точного эталона | У `required-design-sample` найден спрайт и 49 иконок; у остальных вариантов — 0; Font Awesome и удалённые slug в статическом рендере отсутствуют |
| Размеры H1 в Chromium | Swiss — 48,8 px, Product — 42,4 px, Editorial — 48,8 px; три `sample-*` — 27,2–30 px; `required-design-sample` — ровно 30 px |
| Обновлённые автоматические тесты | `DesignLabTest` и `tests/E2e/http_smoke.py` переведены на 10 вариантов, 404 для `aurora`, `sample-workbench` и `sample-reading`, `company=orange|yellow`, company-селекторы CSS, H1-предохранители и контракт inline-SVG |

Нативные `bash bin/test DesignLabTest`, полный `bash bin/test` и живой
HTTP smoke для этой корректировки в этой песочнице **не запускались**: отсутствуют
PHP CLI, PostgreSQL и `.env`, а полный Kohana bootstrap под PHP-WASM не
поддерживает нужное окружение/`pdo_pgsql`. Их нужно выполнить в восстановленной
нативной среде до production-развёртывания; статический Chromium-рендер не
заменяет проверку реального HTTP-контроллера и маршрутизации.

## Дополнение 27.10.2026 — рабочие страницы по эталону v6

| Проверка | Результат |
|---|---|
| Объём выравнивания | Пользовательские и административные production-страницы, общие partials/widgets, standalone Search 410/Unsub, cookie popup и генератор новых модулей; автономная витрина `prx140_design_lab` намеренно не переводилась на общий шаблон |
| `python3 .tools/design_audit.py` | 96 views, 15 888 строк; все колонки замечаний — 0 |
| `python3 .tools/design_audit.py --check` / `--selftest` | Чисто; 40 из 40 самопроверок |
| `make check` | Контракты основы: 26 модулей, 183 PHP-класса, 1 active + 49 planned сервисов, 4 канала — OK |
| `bash .tools/php_check_all.sh application` | Грамматика, дубликаты, SQL-эвристики, методы/классы, миграции, CDN, doc budget, Tailwind, дизайн и JS-селекторы — всё чисто |
| `npm run build:css` + `python3 .tools/tailwind_classes_lint.py` | CSS пересобран; все 705 Tailwind-классов вёрстки есть в сборке |
| `npm test` | 14 passed |
| Синтаксис изменённых PHP | Официальный `.tools/php_parse_lint.py`: 347 файлов, 0 ошибок; независимо `php-parser` проверил 71 изменённый PHP-файл, 0 ошибок. Это не заменяет нативный `php -l` |
| Дополнительные ручные сканы | Шапки/H1, CTA/Process/Secondary, поля и checkbox/radio, таблицы с overflow, flash, `.prx-badge` с `.prx-dot`, метаданные через `.prx-count`, raw-карточки и кнопки |

В текущей песочнице по-прежнему отсутствуют PHP CLI, PostgreSQL и `.env`,
поэтому нативные PHPUnit, HTTP smoke и живой браузерный прогон рабочих
страниц для этого изменения **не запускались**. Для контроля отсутствия побочных эффектов worker-тесты запущены во
временном venv: 74 passed, 1 skipped без PostgreSQL DSN.
Перед production-развёртыванием нужен нативный `bash bin/test`, HTTP smoke и
визуальный обход ключевых административных экранов на реальных данных.

## HTTP и права (основной прогон 26.10.2026)

В основном живом прогоне 26.10.2026 `tests/E2e/http_smoke.py` выполнял
**61 запрос**: гость, публичная галерея `/design-lab`, все семь тогдашних
вариантов с `theme=dark` и
неизвестный slug 404 до входа; CSRF входа, саморегистрация 404 (оба маршрута),
недоступные старые сервисы, вход администратора, сохранённые экраны, каталог
и поиск, гостевой 401 и административные маршруты `/employee*` и
`/prx300/admin/*`, шаблон импорта и `/prx100/errors`. GET загрузки модели LLM
возвращает 405 с Allow; POST без CSRF — 403. Отдельно проверено, что ответы
витрины содержат `no-store`, revision-ссылки и не выставляют `Set-Cookie`.
Запуск (локальные секреты не печатать):

```bash
source bin/_env.sh
prx_load_env .env
python3 tests/E2e/http_smoke.py
```

Дополнительно выполнены **13 запросов** под временным сотрудником только с
ролью `login`: вход и доступ к services/dashboard/tasks/account/notifications;
403 для admin, LLM-admin, технического RAG, semantic-admin, управления
пользователями и миграций. Для prx300 отдельно выполнены **22 HTTP-запроса**
под обычным сопоставленным сотрудником, `hr_viewer` и `hr_admin`: своя
организация видна, чужая карточка/организация закрыты, viewer не получает
форму загрузки, hr_admin ограничен своей организацией. Multipart-загрузка
CSV и применение пакета из UI проверены ещё **7 запросами**. Все временные
пользователи, организации, сотрудники и пакеты удалены после проверок.

В PHP-тестах проверены в том числе правила доступа, отсутствие саморегистрации,
сохранённые каналы, RAG с NULL-полями, изоляция получателя in-app-уведомления,
отказ чужому claimant очереди и SQL восстановления после перезапуска воркера.
Данные тестовых пользователей/уведомлений/заданий убираются.

## Ограничения

- Из 50 корпоративных сервисов реализованы `prx300_employee` и домен
  `prx390`–`prx394` (вне карты); остальные 49 карты запланированы. Реальная внешняя HRIS/ERP-интеграция не проверялась:
  проверены CSV/CLI-контракт и тестовые данные. Перед production нужны
  согласование источника/расписания выгрузок, законные основания и сроки
  хранения staging, назначение HR-ролей и процедура сопоставления аккаунтов.
  ACL отдельного документа ещё нет; технический RAG доступен администраторам,
  его нельзя выдавать за готовую общекорпоративную базу знаний. Сотрудники
  намеренно не добавлены в общий поиск, пока он не передаёт оргконтекст.
- Docker build/Compose, PHP-FPM/nginx и production-развёртывание не запускались:
  registry и часть внешних хостов недоступны. Сетевые адреса Docker и права
  bind-mount описаны отдельно, это конфигурационный review, не runtime-проверка.
- Для второй итерации `prx140` от 27.10.2026 обновлённые PHPUnit/HTTP-тесты
  ещё не прогнаны нативно из-за отсутствия PHP/PostgreSQL в песочнице.
  Фактически выполнены PHP-WASM lint/рендер, css-tree, `py_compile` и
  Chromium-проверка статического рендера; это ограничение среды, а не
  результат «51 passed» для нового кода.
- Полный Symfony Mailer через Composer в закрытой сети не устанавливался;
  sandbox shim не заменяет зависимости. SMTP и реальные Telegram/VK/MAX,
  внешние LLM/embeddings не проверялись. Почта по умолчанию выключена.
- Обычный Python-прогон пропускает одну DB-проверку без `PRX_TEST_PG_DSN`.
  Дополнительный прогон с DSN выполнил её в случайной изолированной схеме,
  удалённой после теста. Рабочие таблицы не заменяются. Unit/HTTP-моки
  не означают проверку GPU или реальной модели.
- Не выполнены production backup/restore, нагрузочные испытания, аудит ИБ и
  полный цикл TOTP. Рабочие страницы статически выровнены по v6 и проходят
  полный дизайн-аудит, но для этого изменения ещё нужен нативный живой
  браузерный обход всех ключевых экранов на реальных данных.
- Тексты privacy/terms — заготовки; необходимы согласование и реквизиты.
- Старые данные автоматически не переносятся: см. MIGRATION.md. Неактуальные
  сервисные фикстуры удалены; сохранённые notification/LLM-стенды работают
  нативно и не требуют WASM; актуальные проверки перечислены выше.

## Документооборот prx390–prx394 (16.11.2026)

Проверки в песочнице:

- `python3 -m pytest workers/tests -q` — **83 passed, 1 skipped** (skip — тест,
  которому нужен `PRX_TEST_PG_DSN`). В том числе три сквозных теста
  `workers/tests/test_docflow_e2e.py`: настоящий `mock_llm_server.py` с
  `MOCK_LLM_ROUTES`, настоящие PDF из `application/modules/prx390_intake/demo/` и подмена только
  `site.get_json/post_json`.
- WASM-стенд `.tools/prx392_normalize_stand.php` — **11 ок, 0 фейл**:
  нормализация ИНН/даты/суммы, HTML в summary, неизвестный тип, пустой ответ.
- WASM-стенд `.tools/prx393_routing_stand.php` — **6 ок, 0 фейл**: первое
  правило по приоритету, домен/ИНН важнее типа, «суд» в претензии не меняет
  маршрут, отсутствие совпадения → канцелярия.
- `python3 .tools/portal_check.py` — 31 модуль, 205 классов, **6 active + 49
  planned**, 55 сервисов, 7 каналов.
- `bash .tools/php_check_all.sh` — 392 PHP-файла без синтаксических ошибок,
  31 миграция, Tailwind 734 класса (все в сборке), дизайн-аудит чист.
- `npm test` — 14/14; `npm run build:css` пересобран.

Не проверено и остаётся ограничением: живой PostgreSQL (в песочнице нет
`pdo_pgsql`), HTTP-страницы под реальной сессией, настоящая LLM/GPU, OCR
(`pdftoppm`/`tesseract` отсутствуют), IMAP и реальная отправка письма.
Демо-прогон и оффлайн-мок относились к архивному контуру `prx390`–`prx393`; архив удалён 23.11.2026.

## Архитектура как код и первичный администратор (16.11.2026)

- `make architecture` — **0 ошибок, 0 предупреждений**; собраны
  `docs/architecture/dist/architecture.html` (110 КБ, 9 вкладок) и `.json` (50 КБ).
- `python3 .tools/portal_check.py` — 32 модуля, 208 PHP-классов, **6 active + 49
  planned**, 55 сервисов, 7 каналов.
- `bash .tools/php_check_all.sh` — 398 PHP-файлов без синтаксических ошибок,
  32 миграции, Tailwind 735 классов, дизайн-аудит чист.
- `npm test` — 14/14; `PYTHONPATH=workers pytest -q` — 83 passed, 1 skipped.
- WASM-стенд сервиса архитектуры: HTML и JSON читаются, ссылка «Вернуться в
  портал» вставляется перед `</body>`.
- WASM-стенд миграции prx102_004: HMAC совпадает с `Auth::hash`, SQL содержит
  условие пустой базы, профиль и роли; при `PRX_DEFAULT_ADMIN=0` запросов нет.

Не проверено: живой PostgreSQL (нет `pdo_pgsql`), поэтому фактическая вставка
admin/admin и вход под сессией — на стенде с БД. Пароль по умолчанию предназначен
только для чистой локальной базы; после первого входа его нужно сменить.

## Исправления после production-проверки (16.11.2026)

- `/prx391_documents`: KPI «Новые» читает `new_count` из `Service_Prx391_Documents::stats()`;
  раньше view обращался к несуществующему ключу `new` и отдавал Warning.
- На тот момент prx390 временно использовал `public_html/storage/docflow/*`.
  Это исторический результат от 16.11: последующий перенос обратно в корневой
  `storage` и необходимые шаги для данных описаны в дополнении ниже.
- `make docflow-demo` создаёт каталоги по текущим `PRX390_*_DIR` и копирует PDF
  в новую папку входящих.

## Перестройка структуры (16.11.2026)

- `architecture/` переехал в `docs/architecture/`: YAML — `model/`, собранные
  файлы — `dist/`, движок отрисовки — `.tools/architecture/`. `INPUT_DATA` удалён после
  переноса нужных файлов; `prx101_architecture` читает `docs/architecture/dist`.
- `examples/docflow` переехал в `application/modules/prx390_intake/demo/`;
  `make docflow-demo` и ссылки в документации обновлены.
- Проверки: `make architecture` — 0 ошибок/0 предупреждений, 9 вкладок;
  `npm test` — 14/14; `npm run test:architecture` — 124/124; `php_check_all` — чисто.

## VLM-демо prx398 (16.11.2026)

- Добавлен `prx398_vlm_demo`: `/vlm-demo`, браузерный PDF.js, AJAX
  `POST /vlm-demo/analyze`, вызов vision-модели через `Llm`, без БД, очереди
  и worker-каналов. PDF на сервер не загружается — только JPEG/PNG страниц.
- PDF.js `6.5.438` положен локально в `public_html/assets/vendor/pdfjs/`;
  для `.mjs` добавлен MIME в `public_html/.htaccess` и
  `docker/nginx/default.conf`.
- Принятые страницы копируются в уникальный каталог `prx398_*` в системном
  tmp с правами `0700` и удаляются в `finally` (включая ошибки LLM).
- `python3 .tools/portal_check.py` — 33 модуля, 210 PHP-классов, 6 active +
  49 planned, 7 каналов.
- `bash .tools/php_check_all.sh` — 404 PHP-файла без синтаксических ошибок,
  32 миграции, Tailwind 744 класса, дизайн-аудит чист.
- `make architecture` — 0 ошибок, 0 предупреждений; `npm test` — 14/14;
  `npm run test:architecture` — 124/124.
- WASM-стенд `.tools/prx398_normalize_stand.php` — 13 ок, 0 фейл: JSON в
  markdown-обёртке, ИНН, проценты уверенности, неизвестный тип, страницы.
  Отдельный (временный) стенд `analyze()` с mock-LLM проверил сборку
  `image_url` как data URI; view рендерится с заглушками `View/HTML/URL`.
- Отдельный WASM-стенд служебного каталога: `prx398_*` создаётся с правами
  `0700`, после `_cleanup_tmp_dir()` файл и каталог удалены.
- Не проверено: живой вызов VLM (в песочнице нет LLM-шлюза), реальный
  браузерный рендер PDF.js (нет Chromium), HTTP под сессией и PostgreSQL.
  Для запуска нужна загруженная vision-модель и `LOCAL_API_URL/KEY`.

## OCR Provider для doc.normalize (16.11.2026)

- Логика OCR вынесена из `workers/prx_workers/handlers/doc_normalize.py` в
  `workers/prx_workers/ocr_client.py`: единый `OcrResult`, локальный
  `TesseractOcrProvider` и `RemoteOcrProvider`.
- Выбор — `OCR_PROVIDER=tesseract|remote`; URL и таймаут —
  `OCR_SERVICE_URL`, `OCR_TIMEOUT` (по умолчанию 120 с). Добавлены в
  `workers/.env.example`.
- Remote: PDF уходит `multipart/form-data` (поле `file`, filename
  `document.pdf`, `application/pdf`) через `httpx`; параметры парсинга —
  `OCR_EXTRA_PARAMS`, заголовки — `OCR_EXTRA_HEADERS`, авторизация —
  `OCR_API_KEY` (`Authorization: Bearer` или `OCR_AUTH_HEADER_NAME`).
  Принимается JSON `text/pages/engine/confidence`. Сеть, таймаут, 4xx/5xx и
  невалидный JSON логируются как инцидент и дают `engine="failed", text=""`.
- Tesseract оставлен локальным fallback: `pdftoppm` + `tesseract`,
  `OMP_THREAD_LIMIT=1`, PNG удаляются через `TemporaryDirectory`.
- `doc_normalize.extract_text()` сначала пробует pypdf; при наличии >= 100
  осмысленных символов и отсутствии мусора OCR не вызывается. Иначе
  используется активный провайдер; тело для
  `POST /prx390/api/text/<message_id>` сохраняет
  `file_id/text/pages/engine/confidence`.
- `POST /prx390/api/text/<id>` теперь действительно сохраняет тело
  `file_id/text/pages/engine/confidence` через `store_text` (раньше действие
  отдавало только чтение). Ветка проверена WASM-стендом с заглушками
  `Request/Service/Log`: статус 200 и переданные поля.
- `PYTHONPATH=workers python3 -m pytest workers/tests -q` — **103 passed,
  1 skipped**. Новые тесты: `workers/tests/test_ocr_client.py` (20).
- `bash .tools/php_check_all.sh` — чисто после правки API; `portal_check` —
  33 модуля, 210 классов, 7 каналов.
- Не проверено в песочнице: реальный внешний OCR-сервис и локальный
  `pdftoppm`/`tesseract` (бинарей нет); HTTP-контракт проверен
  `httpx.MockTransport`, локальный провайдер — подменой `subprocess.run`.

## PRX-префикс и структурные швы (16.11.2026)

- Префикс `PRL` заменён на `PRX` целиком: 404 пути, 547 файлов содержимого,
  0 коллизий; сторонние `assets/vendor/**`, собранные `app.css` и
  `docs/architecture/dist/**` не переписывались, а пересобраны.
- `.env.example` снова человекочитаемый контракт: 10 секций с владельцем и
  обязательностью; канонические `LLM_DEFAULT_PROVIDER`, `DEFAULT_LLM_MODEL`,
  `LOCAL_API_KEY`, `LOCAL_DEFAULT_MODEL`. `DEFAULT_LLM_MODEL` — выбор для
  модулей без своей `<PRX>_MODEL` (`prx392`, `prx394`), vision-демо сохраняет
  собственный дефолт. Добавлен `make env-check` — сверка с
  `getenv()`/`os.environ`, 0 ошибок и 0 предупреждений.
- `Service_Prx120_JobQueue` 2 658 → 1 492 строки: обслуживание очереди
  (`reconcile_outcomes`, `requeue_stale`, авто-остановки, уборка) вынесено в
  трейт `Maintenance`, монитор (`monitor_health`, `diagnose`) — в трейт
  `Monitor`; публичные методы не менялись.
- `Service_Prx597_Notify` 2 319 → 1 944 строки: чтение ленты и отписка
  вынесены в трейты `Service_Prx597_Notify_Inbox` и `_Unsubscribe`;
  публичные методы и DDL не менялись.
- Швы worker-runtime: сторожа вынесены в `prx_workers/watchdogs.py`
  (`WatchdogMixin`), реестр провайдеров — в `providers.py`; `base.py`
  932 → 751 строк, `llm.py` 811 → 592. Публичные импорты сохранены через
  реэкспорт, тесты 108 passed / 1 skipped.
- Памятки модулей — один экран: датированные разборы `prx120` и `prx597`
  вынесены в `docs/history/`, контракт интеграции `prx597` — в
  `docs/integrations/prx597-events.md`. Размеры: 20,1 → 10,6 КБ и
  35,6 → 10,4 КБ; `doc_budget_lint` в бюджете.
- LLM-провайдеры без двойственности: `local` читает только `LOCAL_*`,
  `toolforge` — отдельный провайдер с `TOOLFORGE_*`, подстановки между ними
  удалены; `local_lms` в конфиге больше нет, а сохранённые спеки
  `local_lms::…` один релиз разрешаются в `local`.
- Worker-API: общий `Controller_Prx39x_Api` (X-Worker-Token, JSON-тело,
  `_halt`); `Prx390/392/393/394` сокращены до action-методов.
- OCR: `OcrSettings.from_env()` и `ExtractedText.as_payload(file_id)`;
  `OcrResult` оставлен псевдонимом, `RemoteOcrProvider` принимает settings.
- prx391: чтение вынесено в `Service_Prx391_Registry`, запись — в
  `Service_Prx391_Card`; `Documents` остался фасадом (303 строки вместо 865).
  Карточка документа собрана из `views/391/partials/*`.
- prx392: чтение прогонов/предложений — в `Service_Prx392_Runs`.
- prx597: `admin.php` — каркас вкладок, содержимое — в
  `views/597/partials/*`; шапка и `<head>` вынесены из
  `template_tailwindcss.php` в `views/partials/{head,topbar}.php`.
- Проверки: `portal_check` — 33 модуля, 214 классов, 7 каналов;
  `php_check_all` — 426 PHP-файлов, 0 ошибок, дизайн-аудит чист;
  `PYTHONPATH=workers python3 -m pytest workers/tests -q` — **106 passed,
  1 skipped**; `npm test` — 14/14; `make architecture` — 0 ошибок.
- Не проверено в песочнице: живой рендер вкладок/карточки под сессией и
  PostgreSQL; PHP CLI недоступен, синтаксис проверен php-parser и WASM-стендом.

## Навигация, селекты и Design Lab №11 (17.11.2026)

- Новая PostgreSQL 16.15 с локалью `C.utf8`, PHP 8.4.27; `bin/check-fresh.sh`
  создал отдельную БД: **32 миграции**, повтор — 0, **55 PHP-тестов** (mini
  runner), `portal:maintain` и HTTP smoke работающего сайта — **78 запросов**.
  Временная БД удалена. HTTP-сервер использовал отдельную локальную тестовую БД.
- `make check` — 33 модуля, 214 классов, 6 active + 49 planned, 7 каналов;
  `make env-check` и `make architecture` — 0 ошибок/предупреждений;
  `make lint`, PHP parser (431 файл), Tailwind lint, design audit и JS selector
  scan — успешно. `npm ci`, сборка CSS и `npm test` — **23/23**;
  `npm run test:architecture` — **124/124**.
- `npm audit` на дату проверки: **10 high** в дереве dev-зависимостей сборки,
  связанных с ReDoS в `picomatch` через Tailwind; `npm audit --omit=dev` — 0.
  Автоматическое обновление с потенциально несовместимой мажорной версией не
  выполнялось. Сборка также предупреждает об устаревшем `caniuse-lite`.
- `PYTHONPATH=workers pytest workers/tests -q` — **109 passed** с отдельной
  тестовой схемой в PostgreSQL; без `PRX_TEST_PG_DSN` функциональный тест
  свипера пропускается (108 passed, 1 skipped). Есть предупреждения pytest о
  будущем scope asyncio и необязательной зависимости SOCKS.
- Chromium на реальном локальном PHP-сервере с PostgreSQL: вход администратором,
  закрепление/удаление звёзд, фильтр и переходы Избранного, подсветка маршрутов,
  мобильная шторка и строка раздела, вкладки пользователей/ролей. После смены
  на временного пользователя только с `login` закрытый `/prx560` не показан
  из старого Избранного/палитры, `/prx560` и `/prx595/admin` отвечают 403;
  пользователь и тестовые данные удалены.
- На `/inbox`, `/prx391_documents`, `/vlm-demo` проверены обе темы, реальные select и
  значения фильтров, размеры 320/390/540/768/1280 без горизонтального overflow
  страницы; таблицы прокручиваются в своих контейнерах. В синтетическом HTML
  с собранным CSS проверены 3 акцента × 2 темы, включая компактный select и
  сохранение семантических цветов кнопок. Нативный popup списка зависит от
  ОС и вручную на разных устройствах не проверялся.
- В `/design-lab/sidebar-navigation` Chromium проверил 7 ширин от 320 до 1920
  в светлой/тёмной теме, раскрытие и фильтр мобильного меню, порядок раздела и
  Избранного, прокрутку действий и три брендовых акцента в двух темах. Это
  демонстрация, она не переносит новый каркас в рабочие страницы.
- При чистом прогоне в журнале PostgreSQL обнаружена **неисправленная ранее
  существовавшая ошибка** `Service_Prx208_EventBus::_load_subscribers_from_db`:
  сравнение булева `is_active` с числом `1` даёт `boolean = integer`, ошибка
  перехватывается, но подписчики из БД не загружаются. Это не проверяется
  успешным статусом smoke; нужны отдельное исправление и интеграционный тест
  загрузки активных/неактивных подписчиков. Регистрации через `Event::listen`
  из `init.php` — отдельный путь.
- Риски `webread` (SSRF и лимит тела), рекомендации по конфигурации и дальнейшим
  структурным швам Python зафиксированы в [docs/WORKERS.md](WORKERS.md#приоритеты-обзора-python-17112026);
  код воркера в этой задаче не менялся. Реальный LLM/OCR, разные ОС и нативные
  мобильные браузеры не проверялись.

## Избранное аккаунта (17.11.2026)

- PostgreSQL 16.15 (`C.utf8`), PHP 8.4.27: `bin/check-fresh.sh` создал
  отдельную БД, применил **33 миграции**, повторно — 0; **61 PHP-тест** через
  mini runner, maintenance и HTTP smoke — успешно. HTTP использовал отдельно
  работающий сайт с локальной тестовой БД, не временную БД миграций. Обновление
  уже существующей тестовой схемы также применило новую миграцию один раз.
- HTTP smoke — **99 запросов**: первый HTML и звезда карточки, idempotent POST,
  откат добавленной тестовой записи, ограничения метода, CSRF и чужого
  аккаунта, гостевой 401, недопустимые адреса и импорт. В PHP-тестах проверены
  изоляция пользователей, каскадное удаление, изменение видимости, явное
  объединение, экранирование HTML и активность закладки по пути/алиасу.
- В отдельной временной БД без таблицы Избранного `/services` отвечает 200,
  показывает ошибку и выключает звёзды; API возвращает 503 JSON. Это не
  заменяет миграцию: для существующей установки выполнить `bin/console db:migrate`.
- `npm ci`, сборка CSS, `npm test` — **32/32**, `npm run test:architecture` —
  **124/124**. JS-тесты покрывают отсутствие подмены первого DOM, ошибки,
  импорт по подтверждению, синхронизацию вкладок, смену сессии и запоздавшие
  ответы. `npm audit --omit=dev` — 0; в dev-дереве остаются 10 high из
  предыдущей проверки Tailwind/picomatch. Есть предупреждение Browserslist.
- Chromium с настоящими HTTP-сессиями: два временных аккаунта только с ролью
  `login`, две вкладки, независимый браузерный контекст без JS, явный импорт
  и пропуск недоступного пункта, ошибка 503 без ложного успеха. На 320 px в
  светлой/тёмной теме нет горизонтального overflow, звёзды имеют область не
  меньше 40×40 px и не перекрываются. Смена аккаунта в общем браузере не
  изменяет новый аккаунт; старая вкладка скрывает список и каталог. Временные
  аккаунты и тестовые записи удалены.
- При проверке принудительного выхода обнаружено, что общий `catch` поглощал
  исключение редиректа после `logout(TRUE)`. Редирект вынесен из `try`: HTML
  прерывается, API каталога/Избранного возвращает 401 JSON. Проверено PHP-тестом
  и Chromium с действием «Выйти везде»; устаревшая вкладка больше не показывает
  закладки. Сбой самого учёта сессий по-прежнему обрабатывается как раньше.
- `make check`, `make env-check`, `make architecture`, настоящий `make lint`,
  `.tools/php_check_all.sh`, Tailwind и дизайн-аудит — успешно; в бюджете
  документации остались прежние предупреждения для пяти памяток. Python с
  отдельной тестовой схемой PostgreSQL — **109 passed**, с предупреждениями
  asyncio/PySocks. Production-развёртывание и другие браузерные движки не
  проверялись; автоматический импорт прежнего `localStorage` намеренно отключён.

## Перенос storage из docroot (17.11.2026)

- PHP 8.4.27 и PostgreSQL 16.15 (`C.utf8`): `bin/check-fresh.sh` создал
  отдельную БД, применил **33 миграции**, повтор — 0; **75 PHP-тестов**,
  maintenance и HTTP smoke работающего сайта — **99 запросов**, успешно.
  HTTP использовал другую изолированную тестовую БД.
- Из них 12 unit-тестов путей/прав и 2 интеграционных теста БД проверили
  стандартные и legacy-пути, совместимость произвольного относительного
  backend с cwd, создание недостающих каталогов, отказ при ошибке и symlink,
  отсутствие перезаписи, файлы разных модулей, приём и дедупликацию PDF,
  директории обработки и постановку `doc.normalize`. `dev:doctor` не создаёт
  отсутствующие папки; после их подготовки показал доступность типовых путей.
- `make check`: **10 операционных тестов**, включая реальный вызов CLI
  мигратора с dry-run/apply/verify и повтором, конфликт, symlink, FIFO,
  частичную копию, приватные права setup и роутер встроенного PHP-сервера.
  Дополнительно реальный legacy backend в тестовой БД был скопирован из
  `public_html/storage` в `storage`, проверен по содержимому и скачан по
  прежнему file_id; исходник остался на месте.
- Живые HTTP-проверки на встроенном PHP и отдельно на nginx 1.27.5 + PHP-FPM
  (конфиг репозитория с тестовыми путями/портом/upstream): прямой доступ к
  старому файлу запрещён, физическое совпадение `/storage/get/<id>` не обходит
  контроллер, гость отклонён, администратор скачивает свой файл, worker API
  отдаёт PDF только с токеном. `make docflow-demo` дважды не перезаписал
  существующий файл. Тестовый `bin/backup.sh` сохранил БД, корневой storage и
  старые storage/img; проверены содержимое архива и `--check`, не restore.
- `make env-check`, `make architecture` (0 ошибок), архитектурные тесты
  **124/124**, нативный `make lint`, PHP parser и `php_check_all` — успешно;
  в документационном бюджете остаются прежние предупреждения пяти памяток.
  Python workers с отдельной PG-схемой — **109 passed**, npm-тесты — **32/32**;
  `npm audit --omit=dev` — 0, в dev-зависимостях остаются 10 high и
  предупреждение об устаревшем Browserslist.
- Это не deployment и не перенос production-данных. Apache/.htaccess, Docker
  Compose, сторонние backend, публичные legacy uploads/avatar/img, восстановление
  backup и реальные права серверных пользователей требуют проверки на staging.
  Существующий дефект загрузки DB-подписчиков prx208, описанный выше, не
  исправлялся. Переход и ограничения мигратора — в [ENVIRONMENT.md](ENVIRONMENT.md#перенос-существующего-public_htmlstorage).

## Диагностика приёма и `doc.normalize` (17.11.2026)

- PostgreSQL 16.15 и PHP 8.4.27, отдельная временная БД: 33 миграции,
  повтор — 0; **75 PHP-тестов**, включая проверку приватных событий приёма,
  дедупликации, ошибки сканирования и сохранения текста. Maintenance — OK.
- Реальный pg-воркер сначала получил отказ соединения с незанятым портом:
  журнал показал `job_id`, `message_id`, используемую базу API и
  `connection_refused/ECONNREFUSED`. Затем независимые PDF прошли через
  встроенный PHP-сервер и отдельно через nginx + PHP-FPM: получены конверт,
  файл, текст pypdf и карточка в реестре. На nginx проверены загрузка через
  авторизованную форму, отказ неверного файла и ответы API 200/403/404/422.
  PHP-события сопоставлены по ID, без имени файла и текста; общий HTTP smoke —
  **99 запросов**.
- Python workers с тестовой PG-схемой — **133 passed**. Дополнительные тесты
  проверяют параллельную корреляцию, настройки/переопределения, безопасную
  обработку HTTP-тел и исключений, реальные отказы TCP и самоподписанный TLS,
  классификацию DNS/таймаута/прокси, OCR-контекст и отсутствие тестовых
  секретов в диагностических событиях. `make check` (10 ops-тестов),
  `make env-check`, `make architecture`, PHP parser/lint и `php_check_all`
  прошли; npm — **32/32**, архитектурные тесты — **124/124**. Сохраняются
  прежние предупреждения документационного бюджета, PHP 8.4 deprecation и
  optional PySocks в worker-тестах.
- При регистрации выявлен **существующий отдельный дефект prx393**: в SQL
  upsert срока включены PHP-комментарии `//`, PostgreSQL отклоняет запрос,
  а слушатель перехватывает исключение. Карточка создаётся, но автоматическое
  назначение срока не подтверждено. Также повторился ранее описанный отказ
  загрузки DB-подписчиков prx208 (`boolean = integer`). Оба дефекта не являются
  установленной причиной исходного `ConnectError` и в этом изменении не исправлены.
- Это изолированный тест, не развёртывание на рабочем сервере. Systemd и
  journald в production, реальные настройки сети, Tesseract и внешний OCR не
  проверялись; сетевую причину исходного инцидента ещё предстоит установить по
  журналам. IMAP-канал не реализован. Инструкция сбора —
  [systemd-сервис, шаг 7](DEPLOY-SYSTEMD.md#шаг-7-прогон-одного-pdf-и-сбор-диагностики).

## Сроки и подписки событий (17.11.2026)

- PostgreSQL 16.15 (`C.utf8`), PHP 8.4.27: отдельная свежая БД,
  `bin/check-fresh.sh` — **33 миграции**, повторно 0, **92 PHP-теста** через
  mini runner и maintenance без ошибок. HTTP-сайт работал на другой
  изолированной БД; обе тестовые БД удалены. Новых миграций в этой задаче нет.
- Исправлены SQL upsert срока и чтение PostgreSQL BOOLEAN. Интеграционные
  проверки сверили календарные часы, рабочие дни с праздником, оба вида явной
  подсказки, снимок в карточке и отсутствие дублей. Последовательный цикл
  регистрации, закрытия, ручного пересчёта закрытой карточки и возврата в
  работу сохраняет единственный срок и согласованный снимок. Статус `met`
  означает снятие с контроля, а не доказательство закрытия в срок.
- Локальный PDF прошёл загрузку, реальный pg-воркер `doc.normalize` с pypdf,
  регистрацию и автоматическое назначение срока. Дополнительно через HTTP
  проверены CSRF, сообщения для закрытой карточки и отсутствующего срока,
  закрытие и повторное открытие; после этого срок остался единственным.
- Проверены права: сессия только с ролью `login` получает 404 на персональную
  и коммерческую карточку и не видит их реквизиты на `/sla` или в ленте
  `/prx391_documents`; администратор видит тестовые карточки. Тесты адресатов
  эскалации проверяют роль и доступ к каждой карточке. На `/sla` фильтрация
  производится среди 60 ближайших сроков и 20 последних эскалаций; общие
  показатели скрываются при неполном доступе.
- **Оставшаяся утечка в других модулях:** локальная сессия только с ролью
  `login` получала 404 синтетической персональной/коммерческой карточки, но
  видела тему конверта на `/inbox`, тему и содержание предложения на `/analysis`
  и тему и текст черновика на `/replies`. Эти страницы не исправлены в рамках
  задачи и не подходят для реальных защищённых документов при смешанных правах.
  Синтетические записи и пользователь удалены; это не аудит остальных маршрутов.
- `/prx393/api/health`: на локальном сайте с настроенным worker-токеном ответ
  без токена — 403, с токеном — 200. В production проверяется worker-токен,
  но в development без токена доступ разрешён; ответ содержит глобальную
  статистику и не использует права сессии.
- DB-подписки prx208 теперь загружаются только при явном
  `PRX208_DB_SUBSCRIBERS_ENABLED=1` после аудита; кодовые слушатели работают
  независимо. PostgreSQL-тесты проверяют активные/неактивные записи, checkbox,
  некорректные callback, изоляцию ошибок и диагностические сообщения без текста
  исключения. Мост проверен на дубли строк, явных адресатов, инициатора, роли,
  личные настройки и некорректные поля. Включение исторического моста без
  аудита ранее воспроизвело дубли с независимым sweep и остаётся небезопасным.
- Сессионные API публикации и управления подписками требуют POST + CSRF;
  старые клиенты без CSRF нужно обновить. Публикация возвращает `event_id: null`
  и не гарантирует успех обработчиков. HTTP smoke — **140 запросов**, включая
  ограничения ролей/методов, CSRF, ошибки валидации, флажки и CRUD. Smoke
  изменяет тестовую БД и оставляет синтетические события в журнале; его нельзя
  запускать на production-данных. Chromium на 390 px проверил предупреждение,
  создание и двухшаговое удаление подписки без переполнения страницы и ошибок JS.
- Повторный последовательный sweep тестовых задач не создал дублей; проверены
  `created_by` и `visitor_id=u:<id>`. Прямой хук очереди при передаче массива
  payload ранее падал с `Array to string conversion`; уведомление в тесте
  появилось после sweep. Конкурентные sweep, ошибки с уже выставленным
  `notified_at`, реальная доставка и повторная проверка прав после постановки
  outbox не гарантированы. Уже записанные эскалации не переигрываются. На
  чистой БД `/prx100/errors` пропускает outbox из-за запроса к отсутствующему
  полю `error` вместо `last_error`, поэтому на эту ленту нельзя полагаться
  как на единственный индикатор проблем доставки.
- `make check` — **10/10**, `make env-check`, `make architecture`, настоящий
  `make lint`, parser, `php_check_all`, Tailwind и дизайн-аудит — успешно;
  Python workers с отдельной PG-схемой — **133 passed**, npm — **32/32**,
  архитектурные тесты — **124/124**. Остались прежние предупреждения бюджета
  документации, pytest/optional PySocks и Browserslist; `npm ci` сообщает о
  10 high в dev-зависимостях. Действия по восстановлению старых сроков и аудит
  подписок описаны в [ENVIRONMENT.md](ENVIRONMENT.md#существующие-db-подписки-и-сроки-документов).
- Production не развёрнут и не проверен; первопричина исходного `ConnectError`
  не установлена. Живые systemd/journald, Tesseract, внешний OCR и доставка
  уведомлений требуют отдельной проверки. Нельзя распространять общие/SQL-логи
  без ручной проверки: они могут содержать данные документов.
