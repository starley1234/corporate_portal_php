# NJ-641 · Генератор изображений (nj641_imagegen)

<!-- context-index:begin -->
<!-- Индекс разделов. Генерируется: python3 .tools/context_pack.py index application/modules/nj641_imagegen/AGENTS.md --write. Руками не править. -->
| Раздел | Строка | Размер |
|---|---|---|
|   Состояние | 18 | 2,9 КБ |
|   Архитектура «запрос → очередь → воркер → внешний API → картинка» | 49 | 2,2 КБ |
|   Доставка: где картинка на самом деле (важно) | 72 | 2,0 КБ |
|   Статус-ряд и завершение (как в /trends) | 94 | 1,8 КБ |
|   Безопасность | 112 | 1,0 КБ |
|   Env | 125 | 1,6 КБ |
|   Классы и файлы | 145 | 0,5 КБ |
|   Таблицы | 154 | 0,3 КБ |
|   API | 159 | 1,1 КБ |
|   Модерация и библиотека изображений (26.09) | 173 | 3,4 КБ |
<!-- context-index:end -->
## Состояние
- **Страница** `/imagegen`: режимы `generate` (по описанию), `edit` (одна
  картинка + промпт + strength), `multi-reference` (до 3 референсов).
- **Серверная генерация**: прогон в очередь nj120 (канал `nj641.generate`) →
  воркер → OpenAI-совместимый image-API (`/v1/images/generations`,
  `/v1/images/edits`) → картинка.
- **Картинка — файлом, а не в БД** (правка 26.09, «изображение где-то
  теряется»): байты пишутся в `public_html/storage/nj641/nj641-<run>-<rand>.<ext>`
  (каталог в `.gitignore`, как `storage/nj570`), в `image_info` — `file`/`url`/
  `bytes`/`width`/`height`/`media_type`. Отдаёт их лёгкий `GET /imagegen/file/<имя>`
  (имя проверяется шаблоном — выйти из каталога нечем, ссылка капабилити).
  Крупные картинки сжимаются в WebP качества 88 (Pillow; нет — отдаём как есть).
  Колонка `image_b64` остаётся для старых прогонов и как запасной путь.
- **Переиспользование**: `Service_Nj641_Imagegen::enqueue()` ставит задачу,
  `result_of()` отдаёт картинку — для презентаций, статей, постов.
- **Проверка видеопамяти**: воркер перед генерацией спрашивает `GET /vram`
  внешнего image-API; при нехватке ждёт и переспрашивает (по умолчанию до
  4 минут). Включается/выключается флагами `NJ641_VRAM_CHECK`/`NJ641_VRAM_WAIT`
  и тонкими `NJ641_VRAM_MIN_FREE_MB`/`NJ641_VRAM_WAIT_SEC`/`NJ641_VRAM_POLL_SEC`.
- **Воркер** `workers/n2j_workers/handlers/nj641_imagegen.py`: каналы
  `nj641.generate` и `nj641.clear` (очистка кэша бекенда `POST /clear`).
- **08.10.2026 — замок «только по аккаунту»** (решение владельца): сервис работает по
  бесплатному аккаунту. Гость вместо рабочей формы видит плашку входа — общий partial
  `application/views/partials/auth_wall.php` через `Gate::wall('imagegen')`
  (`application/classes/Gate.php`); API отдаёт 401 JSON
  `{ok:false,error:'auth',login:'/auth/login?return_url=imagegen'}`
  (`application/classes/HTTP/Exception/401.php`). Воркерские точки (`worker-result`,
  `worker-upload`, `photo_file`, `cron-poll`, `webhook`) замок пропускает: у них свой
  `X-Worker-Token`, проверяют его сами. Экран прогона и галерея — тоже по аккаунту (галерея
  дополнительно `nj641.admin`).

## Архитектура «запрос → очередь → воркер → внешний API → картинка»
1. сайт валидирует вход, пишет прогон в `nj_641_runs`, ставит задачу nj120;
2. воркер проверяет `GET /vram` (если включено), ждёт память при нехватке;
3. шлёт запрос в OpenAI-совместимый image-API (контракт OpenAI Images):
   - `POST /v1/images/generations` — JSON: `prompt`, `model`, `n`, `size`
     («1024x1024»), `response_format` (`b64_json`), `seed`, `steps`;
   - `POST /v1/images/edits` — multipart: `image[]` (1–3 файла), `prompt`,
     `model`, `n`, `size`, `response_format`, `seed`, `steps`. Правка и
     мульти-референсы идут одним эндпоинтом: OpenAI принимает массив
     картинок. Поля `strength` в схеме нет — сила правки задаётся описанием;
   - отдельного `negative_prompt` схема не принимает: «что исключить»
     дописывается к описанию строкой `Avoid: …`;
   - ответ — JSON `{"data": [{"b64_json": …} | {"url": …}]}`; старый ответ
     (бинарная картинка, `{image}` / `{images}`) воркер тоже принимает;
4. доставка результата — три пути, побеждает первый удавшийся:
   `_apply_pg` (pg-режим; при заданном `NJ641_MEDIA_DIR` кладёт ФАЙЛ и
   метаданные) → multipart `POST /imagegen/api/worker-upload` → JSON base64
   `POST /imagegen/api/worker-result` (только для картинок ≤ `NJ641_INLINE_MAX_MB`,
   по умолчанию 1.5 МБ: тело на 2-4 МБ не проходит лимиты nginx/PHP — именно
   так результат «терялся», а прогон оставался queued). Путь доставки виден в
   `image_info.source` (`pg` / `upload` / `json`);
5. задача закрывается сайтом через `mark_delivered` — прогон не «висит».

## Доставка: где картинка на самом деле (важно)
Порядок в `_finalize` (первый удавшийся канал не отменяет проверку следующих —
побеждает тот, что подтверждён сайтом):
1. **Файл в папку сайта** (`NJ641_MEDIA_DIR=/…/public_html/storage/nj641`) —
   работает в ЛЮБОМ режиме очереди и не зависит от HTTP: имя начинается с id
   прогона, поэтому сайт сам находит файл (`Service_Nj641_Imagegen::adopt_file()`)
   и помечает прогон готовым. Рекомендуемый режим, когда воркер и сайт на одной
   машине (у владельца именно так: воркер и сайт видят 127.0.0.1);
2. **pg** — прямой UPDATE `nj_641_runs` (когда `WORKER_QUEUE_MODE=pg`);
3. **multipart-загрузка** `POST /imagegen/api/worker-upload` (когда файла нет —
   сайт сохраняет картинку сам и делает `mark_delivered`);
4. **JSON** `POST /imagegen/api/worker-result` — только лёгкий (ссылка либо
   base64 ≤ `NJ641_INLINE_MAX_MB`).

Адрес сайта модуль берёт из `NJ641_API_BASE`, а если он не задан — из
`NJ120_API_URL` (режим http), `WORKER_SITE_URL`, `N2J_SITE_URL`; токен — из
`NJ641_SITE_TOKEN` → `WORKER_SITE_TOKEN` → `WORKER_API_TOKEN`. Именно из-за
незаданного адреса доставка молча выключалась, и прогон оставался без картинки.

У воркера есть и обратная страховка: если ни один канал не подтверждён, base64
остаётся в результате задачи, а сайт поднимет картинку через `reconcile()`.

## Статус-ряд и завершение (как в /trends)
- Страница поллит `GET /imagegen/api/status/<id>` каждые 2.5 с и показывает
  **что делает воркер** (`stage`: «Модель рисует 45 с (лимит 900 с)»),
  таймер по факту (`elapsed_sec`) и кнопку **«Остановить»**
  (`POST /imagegen/api/cancel/<id>` → job `cancel(force)` + status `cancelled`).
- Тишина воркера честно подписана: `silent_sec > 90` → «Воркер молчит N —
  похоже, image-API не отвечает».
- Незакрытые прогоны доводит `reconcile()`: задача failed/pending сверх
  `timeout_sec + 120` → прогон `error` с причиной; мёртвая задача (пульс
  старше 4 мин) снимается с очереди, живая — нет (картинка ещё может прийти).
- Страница при открытии сама дожимает незавершённые прогоны (reconcile), поэтому
  найденный на диске файл показывается сразу, без ожидания поллинга.
- «Готово» без картинки — не результат: прогон честно становится `error`
  («Воркер не передал картинку»), а `showResult` больше НЕ выходит молча.
- В UI есть удаление прогона (`POST /imagegen/api/delete/<id>` — строка + файл).
- Ограничение задачи в очереди: `timeout_sec = NJ641_JOB_TIMEOUT` (по умолчанию
  900 с), а не 1800 — генерация дольше четверти часа это уже «бекенд завис».

## Безопасность
1. **SSRF**: воркер ходит только на `NJ641_IMAGE_API` (админ-конфиг из .env) —
   пользовательский ввод в URL не попадает.
2. **Rate limit** через `nj100_platform` по `nj_641_runs.client_ip`
   (`NJ641_RATE_PER_MINUTE`/`NJ641_RATE_PER_HOUR`); `/vram` тоже под лимитом.
3. **Без exec**: сеть — только httpx (воркер) и нативный cURL (сайт, для
   показа /vram); base64-данные не исполняются.
4. **Авторизация колбэков воркера**: `X-Worker-Token` = `WORKER_API_TOKEN`
   на `/worker-result` и `/clear`.
5. **Лимиты входа**: размер файла (`NJ641_MAX_INPUT_MB`, 10), число референсов
   (`NJ641_MAX_REFERENCES`, 3), длина промпта (`NJ641_MAX_PROMPT_CHARS`, 2000),
   результат > 30 МБ отбрасывается.

## Env
- Воркер: `NJ641_IMAGE_API` (внешний image-API; можно базу, базу с `/v1` или
  полный эндпоинт — хвост отрезается, локальному хосту ставится http),
  `NJ641_API_TOKEN` (HTTPBearer), `NJ641_IMAGE_MODEL` (по умолчанию
  `black-forest-labs/FLUX.2-klein-4B`), `NJ641_IMAGE_STEPS` (пусто — решает
  сервер), `NJ641_RESPONSE_FORMAT` (`b64_json` по умолчанию или `url`),
  `NJ641_API_BASE` (база сайта, напр. `https://n2j.ru`; полный адрес эндпоинта
  `https://n2j.ru/imagegen/api/worker-result`, ссылка из чата, кавычки, адрес
  без схемы — всё разбирается в `_clean_url()`), `NJ641_SITE_TOKEN`,
  `NJ641_VRAM_*`, `NJ641_GEN_TIMEOUT`
  (900), `NJ641_TICK_SEC` (15), `NJ641_INLINE_MAX_MB` (1.5).
- Воркер, файловый режим (рекомендуемый): `NJ641_MEDIA_DIR` — каталог сайта
  для картинок (например `/var/www/n2j.ru/public_html/storage/nj641`);
  `NJ641_MEDIA_URL_BASE` (по умолчанию `/imagegen/file`). Каталог должен быть
  доступен воркеру на запись; сайт держит его в `.gitignore`.
- Сайт (манифест-конфиг `config/nj641_imagegen.php`): `NJ641_RATE_*`,
  `NJ641_MAX_INPUT_MB`, `NJ641_MAX_REFERENCES`, `NJ641_MAX_PROMPT_CHARS`,
  `NJ641_VRAM_*` (для показа в UI), `NJ641_SCHEMA_AUTOCREATE` (автосоздание
  таблицы).

## Классы и файлы
- `Service_Nj641_Imagegen` — enqueue/result_of/recent_runs/apply_result,
  rate_ok, разбор data_url/файла, настройки vram.
- `Controller_Nj641_Imagegen` / `Controller_Nj641_Api`.
- `migrations_pg/pgMigration_Nj_641_001_Imagegen.php` — `nj_641_runs`.
- `views/641/index.php` + `views/641/script.php`, карточка `views/nj641/card.php`.
- воркер: `workers/n2j_workers/handlers/nj641_imagegen.py` (рейстр
  `handlers/__init__.py`), тесты `workers/tests/test_nj641_imagegen.py`.

## Таблицы
| Таблица | Назначение |
|---|---|
| `nj_641_runs` | прогоны: mode, prompt/negative, width/height/seed/strength, image_b64 (legacy/запасной), image_info JSONB (file/url/bytes/width/height/media_type/source), status (queued/done/error/cancelled), model, duration_ms, error, job_id, client_ip |

## API
- `POST /imagegen/api/run` — поставить генерацию (mode, prompt, size, seed, …);
- `GET  /imagegen/api/result/<id>` — статус/картинка (`image_url`; base64 — только
  для старых прогонов);
- `GET  /imagegen/api/status/<id>` — лёгкий статус-ряд (stage/elapsed/silent);
- `POST /imagegen/api/cancel/<id>` — остановить свой прогон (CSRF);
- `POST /imagegen/api/delete/<id>` — удалить прогон и файл (CSRF);
- `GET  /imagegen/file/<имя>` — картинка прогона (файл из storage/nj641);
- `GET  /imagegen/api/runs` — последние прогоны;
- `GET  /imagegen/api/vram` — параметры проверки видеопамяти + занятость бекенда;
- `POST /imagegen/api/clear` — очистка кэша бекенда (X-Worker-Token);
- `POST /imagegen/api/worker-result` — приём результата воркера JSON'ом (X-Worker-Token);
- `POST /imagegen/api/worker-upload` — приём ФАЙЛА картинки (multipart, X-Worker-Token).

## Модерация и библиотека изображений (26.09)

- Страница `GET /imagegen/gallery` («Изображения») — доступ только по праву
  `nj641.admin` (`application/config/acl.php`, по умолчанию роль admin);
  для остальных 403. На странице генератора чип «Изображения» показывается
  только тем, кто может модерировать, и ведёт на неё же.
- «Публикация» = `review_status = 'approved'`: картинка попадает в библиотеку,
  откуда её берут другие сервисы —
  `Service_Nj641_Imagegen::library_pick($limit, $offset, $q)` или
  `GET /imagegen/api/library`. Значения: `pending` (по умолчанию, на модерации),
  `approved`, `rejected`.
- Хранилище модерации — ОТДЕЛЬНАЯ таблица `nj_641_review` (`run_id` PK,
  `status`, `reviewed_at`, `reviewed_by`; миграция `pgMigration_Nj_641_003_Review_Store`).
  Почему не колонки в `nj_641_runs` (как в миграции 002): `ADD COLUMN` требует
  прав владельца таблицы, и на проде (26.09) колонок не появилось — запрос
  списка падал, и страница «Изображения» показывала «ничего нет» при живых
  картинках. Таблицу приложение создаёт само (`ensure_review_table()`),
  поэтому модерация работает сразу после обновления кода. Колонки из 002
  остаются в схеме как есть — код на них не опирается.
- Если хранилища нет даже после попытки создать (нет прав `CREATE`), страница
  «Изображения» работает в упрощённом режиме: картинки видны, все «На
  модерации», сверху — понятная подсказка (`library_note()`), а при пустом
  списке — диагностика (`library_diag()`: прогонов, с картинкой, есть ли
  хранилище). Молчаливая пустая страница недопустима.
- API модерации: `POST /imagegen/api/review/<id>` `{status, csrf}` (одна
  картинка), `POST /imagegen/api/review-many` `{ids[], status, csrf}` (пакетно).
  Оба требуют права модерации и CSRF; ответ отдаёт `counts` для чипов.
- Удаление: `POST /imagegen/api/delete/<id>` — владелец удаляет свой прогон,
  администратор любой (`delete_run(..., $force)`), файл картинки удаляется
  вместе со строкой.
- Интерфейс страницы: чипы статусов со счётчиками, поиск (описание/режим/номер),
  «Строк:» 20|50|100 с памятью браузера `n2j:per:/imagegen/gallery`, пагинация,
  «Отметить все на странице» и пакетные кнопки «В библиотеку / Отклонить /
  На модерацию», действия без перезагрузки; удаление — в два клика.
