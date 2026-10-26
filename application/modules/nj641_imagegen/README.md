# nj641_imagegen — Генератор изображений

Серверная генерация изображений по описанию, редактирование по картинке и
мульти-референсы. Выполняется воркером через канал очереди nj120 —
`nj641.generate` — с обращением к внешнему image-API и проверкой видеопамяти.

Страница: `/imagegen`. JSON API: `/imagegen/api/*`.

## Режимы

| mode             | вход                                               |
|------------------|----------------------------------------------------|
| `generate`       | `prompt`, `size`, `seed`, `negative_prompt`        |
| `edit`           | одно изображение + `prompt`, `seed` (в API Images поля `strength` нет — сила правки задаётся описанием) |
| `multi-reference`| до 3 изображений + `prompt`, `seed`                |

## Переиспользование из других модулей

Генерацию можно ставить из любого серверного кода (презентации, статьи):

```php
$r = Service_Nj641_Imagegen::enqueue(Visitor::uid(), [
    'mode'   => 'generate',
    'prompt' => '…',
    'size'   => 1024,
    'seed'   => 0,
]);

if (isset($r['run_id'])) {
    // дождаться завершения, затем:
    $run = Service_Nj641_Imagegen::result_of($r['run_id']);
    $image_b64 = $run['image_b64']; // PNG base64
}
```

## Проверка видеопамяти

На сервере память может быть занята другими процессами. Воркер перед
генерацией спрашивает `GET /vram` внешнего image-API; при нехватке свободной
памяти ждёт и переспрашивает (по умолчанию до 4 минут). Включается/выключается
флагами `.env` воркера:

- `NJ641_VRAM_CHECK=1|0` — включить/выключить проверку;
- `NJ641_VRAM_WAIT=1|0` — ждать или сразу ошибаться при нехватке;
- `NJ641_VRAM_MIN_FREE_MB=4096` — порог свободной памяти, МБ;
- `NJ641_VRAM_WAIT_SEC=240` — суммарное время ожидания, сек;
- `NJ641_VRAM_POLL_SEC=20` — пауза между проверками, сек.

## Env воркера

- `NJ641_IMAGE_API` — адрес OpenAI-совместимого image-API, обязателен. Подходит
  база (`http://127.0.0.1:8000`), база с `/v1` или полный эндпоинт
  (`.../v1/images/generations`) — лишний хвост воркер отрежет сам;
- `NJ641_API_TOKEN` — токен внешнего image-API (HTTPBearer; если нужен);
- `NJ641_IMAGE_MODEL` — модель генерации (по умолчанию
  `black-forest-labs/FLUX.2-klein-4B`);
- `NJ641_IMAGE_STEPS` — число шагов (пусто — не передаём, решает сервер);
- `NJ641_RESPONSE_FORMAT` — `b64_json` (по умолчанию) или `url`;
- `NJ641_API_BASE` — URL приёма результата сайтом: база сайта (`https://n2j.ru`) либо полный адрес эндпоинта (`.../imagegen/api/worker-result`, аналог `NJ640_API_BASE`); путь модуля дописывает воркер;
- `NJ641_SITE_TOKEN` — токен для `X-Worker-Token` при отправке результата на сайт.

## Страницы и API

- `GET /imagegen` — генератор (по описанию, правка, мульти-референсы);
- `GET /imagegen/gallery` — «Изображения»: модерация и библиотека (право `nj641.admin`);
- `GET /imagegen/api/result/<id>` — статус и результат прогона;
- `POST /imagegen/api/review/<id>`, `POST /imagegen/api/review-many` — модерация;
- `GET /imagegen/api/library` — библиотека одобренных картинок для сервисов
  (то же, что `Service_Nj641_Imagegen::library_pick()`).

## Таблицы

`nj_641_runs` — журнал прогонов (миграция `pgMigration_Nj_641_001_Imagegen`,
автосоздание при `NJ641_SCHEMA_AUTOCREATE=1`). Rate limit по `client_ip`
через `Service_Nj100_Ratelimit`.

`nj_641_review` — статус модерации картинки (`run_id` PK → `nj_641_runs.id`,
`status` = pending|approved|rejected, `reviewed_at`, `reviewed_by`;
миграция `pgMigration_Nj_641_003_Review_Store`, приложение создаёт её само).
Отдельная таблица, а не колонки в `nj_641_runs`, потому что `ADD COLUMN`
требует прав владельца таблицы — на проде их не было, и страница модерации
пустела.
