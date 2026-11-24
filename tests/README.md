# Проверки PRX

## Где лежат

| Каталог | Что проверяет | Зависимости |
|---|---|---|
| `Unit/` | PHP-правила, формат данных и публичные контракты | Приложение, PHPUnit; отдельные тесты используют БД |
| `Smoke/` | PHP-интеграции модулей | Новая PostgreSQL с миграциями |
| `Contracts/` | Изолированные PHP-сценарии с подменой БД/сети | Только PHP 8.4, без bootstrap приложения |
| `Contracts/fixtures/` | Дополнительные сценарии engine-контракта уведомлений | Подключаются из engine, отдельно не запускаются |
| `E2e/` | HTTP и полный документный конвейер | Выделенный dev/staging, БД, запущенный воркер |
| `Tooling/` | Линтеры, генераторы, shell-команды и структура проекта | Python unittest |
| `Js/` | Интерфейс и CSRF | Node + `npm ci` |
| `Support/` | Мини-раннер PHP и необязательный WASM-runner | Используются командами ниже |

Python-тесты самого воркера остаются в `workers/tests/` рядом с его runtime.
Новые проверочные стенды не размещаются в `.tools/` или `bin/`.

## Обычный запуск

```bash
make check                         # статические контракты + Tooling
make test-contracts                # все Contracts, каждый в отдельном PHP-процессе
php tests/Contracts/prx597_api.php  # один контракт
bash bin/test                      # PHPUnit; без него — Support/mini_runner.php
PYTHONPATH=workers python3 -m pytest workers/tests -q
npm test
npm run test:architecture
```

`bin/test --contracts` работает независимо от наличия PHPUnit. Ненулевой код
любого контракта делает весь прогон неуспешным; остальные контракты тоже запускаются.
Контракты используют свои заглушки, поэтому их нельзя подключать в один PHP-процесс.
Они не доказывают работу PostgreSQL, SMTP, LDAP или внешнего OCR.

Контракты `employee_access.php` и `employee_cli.php` проверяют доступ prx300:
общий ACL, организацию из актуальной связи, отказ массивам с `can_*`, явного
CLI-оператора и staging без автоматического применения. ORM/БД в них подменены;
реальную связь, её отзыв и запуск импорта отдельным CLI-процессом проверяет
`Smoke/EmployeeDirectoryTest.php`. CLI-тест создаёт оператора, назначает роль
через Auth и проверяет staging/apply и ID в аудите на реальной БД.

Контракты `extraction_access.php` и `machine_access.php` проверяют разделение
UI/API principal, scope и время жизни машинного контекста. PostgreSQL-тест
`Smoke/MachineExtractionAccessTest.php` диспетчеризует API-запросы prx395 внутри PHP,
проверяет изоляцию владельцев, ключи, лимит и журнал. Сетевой HTTP, загрузку
PDF, внешнюю OCR-службу и браузер такой внутренний dispatch не проверяет.

## CI с PostgreSQL

Workflow `.github/workflows/ci.yml` разделяет изолированные проверки и
интеграцию. Последняя запускает нативный PHP 8.4 с PostgreSQL 17/pgvector,
Composer, bootstrap, миграции дважды и полный PHPUnit. Это временная новая
БД CI, не production. Локальный аналог — `bash bin/check-fresh.sh`.

При падении PHPUnit JUnit преобразуется в аннотации GitHub Actions:

```bash
bash bin/test --log-junit /tmp/prx-phpunit.xml
python3 .tools/phpunit_report.py /tmp/prx-phpunit.xml
```

Вторая команда выводит ошибки с файлом/строкой и краткий итог (в том числе
пропуски); пустой, отсутствующий или повреждённый отчёт — ошибка, не успех.
При ненулевом коде PHPUnit CI также публикует ограниченный хвост вывода
процесса: JUnit не отражает все risky/warning. Аннотации не заменяют код
возврата PHPUnit. Отчёт может содержать значения из
проверок: использовать только синтетические тестовые данные, не production.

## Среда без PHP CLI

Необязательный WASM-runner не входит в production-зависимости. Из корня репозитория:

```bash
npm install --prefix /tmp/prx-php @php-wasm/node @php-wasm/universal
cp tests/Support/phpwasm.mjs /tmp/prx-php/phpwasm.mjs
PRX_ROOT="$PWD" node /tmp/prx-php/phpwasm.mjs tests/Contracts/foundation.php
```

Runner использует PHP 8.4. Сценарии и исходники копируются в виртуальную ФС;
это не замена интеграционному запуску на нативном PHP с PostgreSQL.

## E2E — только на выделенном стенде

Эти сценарии создают реальные задания и записи. Не запускать на production.
Ключи и пароли передаются через окружение, не аргументами командной строки.

```bash
# SMOKE_URL, ADMIN_USERNAME/ADMIN_PASSWORD — окружение тестового стенда.
python3 tests/E2e/http_smoke.py
# Для UI нужен ADMIN_PASSWORD; --legacy дополнительно проверяет старый docflow.
python3 tests/E2e/document_ui.py --url http://127.0.0.1:8080
# Нужны PRX_TEST_CLIENT_TOKEN и PRX_TEST_OTHER_CLIENT_TOKEN разных клиентов API.
python3 tests/E2e/document_pipeline.py --url http://127.0.0.1:8080
```

`bin/check-fresh.sh` вызывает актуальный HTTP smoke. Старый `bin/test --pdf`
и его генератор удалены: они обращались к отсутствующему модулю prx634.
Действующий PDF-конвейер проверяют worker-тесты и `E2e/document_pipeline.py`.
