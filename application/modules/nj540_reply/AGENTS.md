# nj540_reply — «Отзыв 2 Ответ» (Review 2 Reply)

Публичный ИИ-сервис: владелец магазина вставляет отзыв клиента — python-воркер
(LangChain) готовит публичный ответ нужным тоном. Без регистрации.

- Страницы: `/review` (форма), `/review/<code>` (статус/результат, поллинг)
- API: `GET /nj540/api/status/<code>`, `POST /nj540/api/regen/<code>` (CSRF)
- Очередь: канал `nj540.reply` (см. `workers/n2j_workers/handlers/review_reply.py`)

## Состояние работ

- **08.10.2026 — замок «только по аккаунту»** (решение владельца): сервис работает по
  бесплатному аккаунту. Гость вместо рабочей формы видит плашку входа — общий partial
  `application/views/partials/auth_wall.php` через `Gate::wall('review')`
  (`application/classes/Gate.php`); API отдаёт 401 JSON
  `{ok:false,error:'auth',login:'/auth/login?return_url=review'}`
  (`application/classes/HTTP/Exception/401.php`). Воркерские точки (`worker-result`,
  `worker-upload`, `photo_file`, `cron-poll`, `webhook`) замок пропускает: у них свой
  `X-Worker-Token`, проверяют его сами. Страница результата по коду (`/review/<code>`) остаётся
  доступной по ссылке — ею делятся.

## Как течёт данных

1. `POST /review` → валидация → лимит по IP → INSERT `nj_540_replies` (status=new)
   → `Service_Nj120_JobQueue::push('nj540.reply', …)` → редирект на `/review/<code>`.
2. Воркер: LangChain LCEL — шаг 1 анализ (JSON: sentiment/topics/escalation/language),
   шаг 2 генерация ответа в тоне. Pg-режим — UPDATE `nj_540_replies`;
   http-режим — результат в `nj_120_jobs.result`.
3. `GET /nj540/api/status/<code>` (и страница результата) вызывает
   `Service_Nj540_Reply::reconcile()`: если воркер http-режима оставил результат
   в задаче — дозаписывает его в отзыв. Идемпотентно.
4. Страница поллит статус раз в 2.5 с и перезагружается на готовом статусе.

## Настройки

Всё изменяемое — в `config/nj540_reply.php` (лимиты по IP, длины отзыва,
префикс/длина кода, параметры задачи очереди, тоны, глубина истории).
Константы в `Service_Nj540_Reply` — только дефолты на случай отсутствия
ключа в конфиге. Ключи тонов синхронны со словарём TONES в воркере
(`review_reply.py`) — менять парой.

## Правила для агентов

- **Деградация без LangChain**: если пакетов нет — воркер делает один запрос
  через `ctx.llm` (engine=`plain`, без анализа). Не «чинить» на жёсткую
  зависимость: конвейер уже обёрнут в try/ImportError.
- **Не увеличивать лимиты** (`RATE_PER_HOUR/RATE_PER_DAY`) без нужды: публичная
  форма = платные LLM-запросы.
- `meta.history` — последние 5 перегенераций (`regen()`); не чистить.
- Промпты живут в воркере (`review_reply.py`); тона — синхронно в двух местах
  (PHP `TONES` и Python `TONES`): менять парно, ключи не переименовывать.
- Модель: `NJ540_MODEL` в `workers/.env` (пусто = `TOOLFORGE_DEFAULT_MODEL`).
- Миграция 001 применена — не редактировать, только новая поверх.
