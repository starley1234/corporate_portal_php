# NJ-640 · Камеры цеха (nj640_cameras)

## Состояние
- **Темы/пресеты**: `discipline` (трудовая дисциплина), `ppe` (СИЗ), `equipment`
  (загрузка оборудования). У каждой — готовый prompt и выходная JSON-схема;
  можно переопределить текст и схему под свою зону.
- **Ручной режим**: кадр + сценарий → прогон в очередь nj120 (канал
  `nj640.analyze`).
- **Авторежим** — камеры: IP-камера Snapshot URL / RTSP, ONVIF, HTTP-объект.
  Триггеры: `timer` (опрос N сек) и `webhook` (датчик/ПЛК по секретному URL).
  Дополнительно: локация, часы работы (вне окна — тик пропускается), cooldown
  между тревогами.
- **Журнал событий** `nj_640_events`: нарушение из вердикта → событие с
  дедупликацией по ключу `(uid, камера, ключ)` и окну `NJ640_DEDUPE_WINDOW_SEC`;
  cooldown отсекает поток из одной камеры. Подтверждение/ложная тревога —
  `nj_640_confirmations` (статистика срабатываний).
- **Уведомления** через `Service_Nj597_Notify::notify()` (паттерн nj605):
  новое событие → колокольчик зарегистрированному владельцу (kind
  `nj640_cameras`), не чаще раза в час.
- **Воркер** `workers/n2j_workers/handlers/nj640_cameras.py`: захват кадра →
  сжатие (Pillow до 512px) → VLM (`NJ640_VISION_MODEL`) с текстом сценария и
  схемой → валидация JSON-схемой → запись в `nj_640_runs` (pg) + POST
  `/cameras/api/worker_result` (X-Worker-Token).
- **Периодика** — cron nj603 (расписание `cameras` в `nj603_kicker`, каждые
  10 минут): воркер POST-ит `/cameras/api/cron_poll`, сайт ставит задачи
  созревшим timer-камерам.
- **08.10.2026 — замок «только по аккаунту»** (решение владельца): сервис работает по
  бесплатному аккаунту. Гость вместо рабочей формы видит плашку входа — общий partial
  `application/views/partials/auth_wall.php` через `Gate::wall('cameras')`
  (`application/classes/Gate.php`); API отдаёт 401 JSON
  `{ok:false,error:'auth',login:'/auth/login?return_url=cameras'}`
  (`application/classes/HTTP/Exception/401.php`). Воркерские точки (`worker-result`,
  `worker-upload`, `photo_file`, `cron-poll`, `webhook`) замок пропускает: у них свой
  `X-Worker-Token`, проверяют его сами. Страница результата и карточки камер — по аккаунту.

## Архитектура «событие → кадр → VLM → схема → ответ + БД»
Видео в VLM НЕ шлётся потоком (модели медленные/дорогие). Один кадр по триггеру:
1. триггер (таймер/вебхук/тест) → захват одного кадра;
2. воркер шлёт кадр + текст сценария + схему в vision-модель одним запросом;
3. ответ валидируется выходной JSON-схемой (jsonschema, fallback — свой валидатор);
4. вердикт — в `nj_640_runs.verdict` + доступен по `/cameras/api/result/<id>`;
5. если сценарий нашёл нарушение — событие в журнал (dedupe/cooldown) +
   уведомление владельцу.

## Безопасность (обязательный слой)
1. **SSRF**: кадры с камер на воркере проходят `_assert_capture_host`:
   запрет link-local/169.254.169.254 (метаданные облака) ВСЕГДА; private/
   loopback/reserved — только при `NJ640_ALLOW_LAN=1` (камеры в локальной сети).
2. **Rate limit** через `nj100_platform` по `nj_640_runs.client_ip`
   (`NJ640_RATE_PER_MINUTE`/`NJ640_RATE_PER_HOUR`).
3. **Без exec с пользовательским вводом**: единственный subprocess — `ffmpeg`
   (RTSP), список аргументов без shell + таймаут; прочие источники — httpx.
4. **Валидация схемы без eval**: подмножество draft-07 на чистых сравнениях
   типов (PHP) и `jsonschema` (Python, fallback — свой валидатор).
5. **Авторизация колбэков воркера**: `X-Worker-Token` = `WORKER_API_TOKEN`.
   Webhook камеры — секретный токен в `config.webhook_token`.
6. **Secrets в UI не отдаются**: пароли камер маскируются `***` при чтении.
7. Лимиты входа: размер кадра (`NJ640_MAX_IMAGE_MB`, 15), сценариев на аккаунт
   (`NJ640_MAX_SCENES`, 20), камер на аккаунт (`NJ640_MAX_CAMERAS`, 12).

## Классы и файлы
- `Service_Nj640_Cameras` — темы/пресеты, сценарии, камеры, прогоны,
  `record_event` (dedupe/cooldown), `confirm_event`, `notify_event`,
  `reconcile`, `cron_poll`, `parse_schema`/`validate_verdict`.
- `Controller_Nj640_Cameras` / `Controller_Nj640_Api` / `Controller_Nj640_Webhook`.
- `migrations_pg/pgMigration_Nj_640_001_Cameras.php` — 5 таблиц.
- `views/640/index.php` + `views/640/script.php`, карточка `views/nj640/card.php`.
- воркер: `workers/n2j_workers/handlers/nj640_cameras.py` (канал
  `nj640.analyze`, реестр `handlers/__init__.py`).

## Таблицы
| Таблица | Назначение |
|---|---|
| `nj_640_scenes` | сценарий: topic, name, rule_text, variants JSONB, schema JSONB |
| `nj_640_cameras` | камера: kind/config JSONB, scene_id, trigger, interval, work_hours, cooldown |
| `nj_640_runs` | прогоны: verdict JSONB, schema_ok, токены, job_id, client_ip (rate limit) |
| `nj_640_events` | журнал событий: key (dedupe), severity, dismissed, first/last_seen |
| `nj_640_confirmations` | подтверждение оператора: confirmed / false_alarm / ignored |

## API
- `POST /cameras/api/run` — ручная проверка кадра;
- `GET  /cameras/api/result/<id>` — статус/вердикт (reconcile nj120);
- `GET/POST /cameras/api/scenes` / `GET /cameras/api/scene/<id>` / `POST /cameras/api/scene-save`;
- `POST /cameras/api/camera` / `GET /cameras/api/cameras` / `POST /cameras/api/camera-fire` / `POST /cameras/api/camera-update`;
- `POST /cameras/api/confirm` — подтверждение/ложная тревога;
- `POST /cameras/api/worker-result` — приём результата воркера (X-Worker-Token);
- `POST /cameras/api/cron-poll` — тик созревших камер (X-Worker-Token);
- `POST /cameras/webhook/<token>` — внешний триггер камеры.
