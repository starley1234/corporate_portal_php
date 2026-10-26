# AGENTS.md — nj570_models

> Памятка агенту. Правила платформы — в корневом AGENTS.md; здесь — только
> специфика модуля. Обновлять при каждом существенном изменении.

## Назначение
Зарегистрированный пользователь текстом (+эскиз) получает 3D-модель: LLM пишет
параметрический OpenSCAD, воркер рендерит STL/PNG локально, сайт показывает
и раздаёт файлы.

## Состояние
- Готовность: рабочий (вьюер стабилен с перехода на CPU-рендер Canvas 2D).
- Владелец: владелец проекта (задачи — из чата).

## Ключевые классы
| Класс | Роль |
|---|---|
| `Controller_Nj570_Models` | страницы /3d, /3d/<code>, скачивание файлов |
| `Controller_Nj570_Api` | статус для поллинга; приём файлов от воркера (токен) |
| `Controller_Nj570_Admin` | модерация публикаций, затраты LLM |
| `Service_Nj570_Models` | вся логика: модели, ревизии, очередь, файлы |
| handlers/nj570_build.py | каналы generate/clarify/render (LLM + локальный OpenSCAD) |

## Таблицы (полное владение)
`nj_570_models`, `nj_570_revisions`, `nj_570_files`, `nj_570_ratings`, `nj_570_usage`.

## Маршруты и API
- `GET/POST /3d` — конструктор (POST в action_index: маршрут без `<action>`)
- `GET /3d/<code>(/edit|answers|rerender|rate|publish)`, `GET /3d/<code>/file/<id>`
- `GET /3d/api/status/<code>` (капабилити-код), `POST /3d/api/upload` (X-Worker-Token),
  `GET /3d/api/storage` (токен)

## Каналы воркера
`nj570.generate` (код→рендер→vision→STL), `nj570.clarify` (вопросы до кода),
`nj570.render` (пересборка по ползункам; проверяет, что параметр реально
изменил габарит STL — иначе ревизия получает ошибку «код не параметричен»).

## Env
- сайт: `WORKER_API_TOKEN` (воркер пишет файлы с ним)
- воркер: `NJ570_CODE_MODEL/DESC_MODEL/VISION_MODEL` (спеки `провайдер::модель`),
  `NJ570_UPLOAD_URL`, `NJ570_SITE_TOKEN`, `NJ570_OPENSCAD_BIN/LIB`, `NJ570_*`

## Нюансы (не ломать)
- Вьюер — программный рендер Canvas 2D, three.js НЕ используется (на машинах
  владельца WebGL рисует пустоту). PNG-рендер — подложка до первого кадра.
- Страницы nj570 — `Cache-Control: no-cache` (инлайновый JS в views/570/script.php).
- `_finish_revision()`: ветка `rerendered` НЕ перезаписывает summary/описание.
- Rerender затирает... ничего: ползунки лежат в IndexedDB (база `nj570`),
  настройки вьюера — в куках `m3dui`.
- Рендер только локальный (scad.py). `NJ570_RENDER_URL/TOKEN` удалены — не возвращать.
- Вёрстка: контейнер `w-full px-4 pt-6 sm:px-6`, доли `md:grid-cols-2`;
  без display:contents/sticky.
