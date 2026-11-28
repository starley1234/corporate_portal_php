# AGENTS.md — prx212_file_storage (файловое хранилище)

> Памятка агенту. Общие правила платформы — в корневом /AGENTS.md.

<!-- context-index:begin -->
<!-- Индекс разделов. Генерируется: python3 .tools/context_pack.py index application/modules/prx212_file_storage/AGENTS.md --write. Руками не править. -->
| Раздел | Строка | Размер |
|---|---|---|
|   Назначение | 19 | 0,2 КБ |
|   Состояние | 23 | 0,9 КБ |
|   Публичный контракт | 33 | 0,8 КБ |
|   Ключевые классы | 42 | 0,6 КБ |
|   Таблицы | 52 | 0,2 КБ |
|   Приватные артефакты внутренних пайплайнов | 56 | 1,0 КБ |
|   Маршруты и API | 67 | 1,6 КБ |
|   Не трогать / подводные камни | 85 | 1,4 КБ |
|   Долги и идеи (TODO) | 100 | 0,3 КБ |
<!-- context-index:end -->
## Назначение
Файловое хранилище платформы: загрузка/выдача/удаление файлов с бэкендами
(локальная ФС, S3), права доступа, публичные ссылки.

## Состояние
- Готовность: рабочий (API, скачивание с проверкой прав, бэкенды, cleanup-таск).
- 27.11.2026: антивирус до записи — `Service_Prx212_Antivirus` (clamd, INSTREAM) из
  `save()`, значит, для всего, что идёт через фасад. Режим `PRX_CLAMAV_MODE`:
  `off` (умолч.) / `soft` (нет clamd — принять, WARNING) / `required` (нет clamd —
  отказ); адрес `PRX_CLAMAV_ADDRESS`. Заражённый → `Prx212_Infected_Exception`
  (через фасад — `Storage_Exception`, исходное в `getPrevious()`), файла нет ни на
  диске, ни в БД; событие `security/file_infected` в журнале администратора.
  Тест — фейковый clamd `tests/Support/fake_clamd.py`.

## Публичный контракт
Модули работают с файлами только через фасад `Storage`
(`application/classes/Storage.php`): `save`, `put`, `bytes`, `metadata`, `url`,
`delete`, `read_owned`, `delete_owned`, `owned_ids_before`, `sweep_orphans`. Кто действует —
явный `$user_id` (NULL — гость); фасад не читает сессию, не решает права на
объект и не отдаёт `storage_path`/`backend_id`/ORM. Сервис `Service_Prx212_Storage`
снаружи не вызывается — `.tools/storage_lint.py` (в `make check`). prx212 можно
заменить, не трогая вызывающие модули; менять контракт фасада — решение владельца.

## Ключевые классы
| Класс | Роль |
|---|---|
| `Service_Prx212_Storage` | внутренняя логика: выбор бэкенда, метаданные, права; снаружи — только фасад `Storage` |
| `Controller_Prx212_Api` | REST: upload/metadata/delete |
| `Controller_Prx212_Download` | безопасная выдача файла |
| `Controller_Prx212_Link` | публичные ссылки на файлы |
| `Model_Prx212_File`, `_Backend`, `_Link`, `_Permission` | модели |
| `Task_Prx212_Storage_Cleanup` | cron: чистка протухших файлов/ссылок |

## Таблицы
- `prx_212_files`, `prx_212_storage_backends`, `prx_212_file_links`,
  `prx_212_file_permissions` — владелец: этот модуль.

## Приватные артефакты внутренних пайплайнов

`read_owned`/`delete_owned` (снаружи — `Storage::read_owned`, `delete_owned`,
`owned_ids_before`) ограничены файлами без пользователя, закрытыми для внешней
выдачи и принадлежащими префиксу модуля. Эти методы не должны
вызываться напрямую из публичного маршрута по клиентскому `file_id`. Сервис-
владелец проверяет объектные права и извлекает `file_id` из своей регистрации.
Инвентаризация `owned_file_ids_before` (от новых к старым, курсор `before_id`)
нужна для уборки после сбоев; модули зовут `Storage::sweep_orphans` с пакетной
проверкой ссылок — свой цикл по `owned_ids_before` застревал на живых файлах.

## Маршруты и API
- `/prx212_file_storage` — список, `/prx212_file_storage/upload` — форма;
  кнопка и API проверяют `prx212_storage.upload`. `/prx212` и `/prx212_index`
  ведут на каноническую страницу.
- `/prx212_file_storage/settings` (`prx212_storage.manage_settings`) — только
  просмотр активных бэкендов, эффективного пути/бакета и лимитов; без ключей S3.
- `POST /prx212_api/upload` (multipart `file` + CSRF; MIME проверяет сервер),
  `GET /prx212_api/file/<id>`, `DELETE /prx212_api/delete/<id>`.
- Скачивание: через контроллер Download (не прямые пути к диску).
- Временные ссылки `/prx212_link/create|list|delete/<id>` (`Controller_Prx212_Link`
  на `Controller_Base`): вход + CSRF; ссылкой делится только владелец, право
  `can_write` на файл или `prx212_storage.edit_all_files` (админ); чужой файл —
  404. TTL 60 с…7 суток (по умолчанию 3600). Выпуск/отзыв — журнал
  администратора (`prx212/share_link_created|share_link_revoked`, без токена).
  До 28.11.2026 контроллер наследовал голый `Controller` — аноним выпускал
  ссылки на любой файл; регресс ловит `tests/Contracts/controller_base_allowlist.php`.
- Свои контроллеры зовут сервис напрямую; `delete()` — с явным ID пользователя.

## Не трогать / подводные камни
- Локальный default — корневой `storage/prx212_file_storage/files`, вне
  `public_html`; корень и вложенные каталоги создаются при первом сохранении.
  `config_data.root_path` backend в БД имеет приоритет над default. Legacy
  `public_html/storage` перенаправляется в `storage`, но копируется оператором
  отдельно; произвольный относительный путь по-прежнему зависит от cwd процесса.
  Другие backend нельзя молча переключать. См. `docs/ENVIRONMENT.md`.
- Внутренний `storage_path` не должен содержать `.`/`..` или symlink; префикс
  нового модуля — буквы/цифры/`_`/`-`, до 50 символов. Существующие нестандартные
  значения необходимо проверить перед развёртыванием.
- Долгие операции (загрузка в S3, конвертация) — кандидаты на очередь prx120,
  не держать HTTP-запрос.
- Пользовательский контент: проверять mime/размер, отдавать с корректным
  Content-Disposition (XSS через HTML-файлы).

## Долги и идеи (TODO)
- Канал очереди `files.thumbnail` — через воркеров. Антивирус синхронный (до записи);
  большие файлы (> лимита clamd StreamMaxLength) дают ERROR → в `required` отказ.
