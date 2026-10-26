# AGENTS.md — nj598_dossier

## Состояние работ

- **08.10.2026 — замок «только по аккаунту»** (решение владельца): сервис работает по
  бесплатному аккаунту. Гость вместо рабочей формы видит плашку входа — общий partial
  `application/views/partials/auth_wall.php` через `Gate::wall('dossier')`
  (`application/classes/Gate.php`); API отдаёт 401 JSON
  `{ok:false,error:'auth',login:'/auth/login?return_url=dossier'}`
  (`application/classes/HTTP/Exception/401.php`). Воркерские точки (`worker-result`,
  `worker-upload`, `photo_file`, `cron-poll`, `webhook`) замок пропускает: у них свой
  `X-Worker-Token`, проверяют его сами. Страница досье по коду (`/dossier/<code>`) остаётся
  доступной по ссылке — ею делятся; быстрая проверка `/check` публична.

## Инварианты

- Префикс env воркера — `NJ598_DOSSIER_*` (полный слаг модуля); токен сайта
  Воркер→Сайт: `NJ598_DOSSIER_SITE_TOKEN` → `WORKER_SITE_TOKEN` →
  `WORKER_API_TOKEN` (см. `_site_token()` в обработчике).
- Сырой HTML в промпт НЕ передавать: только webread-Markdown с капом.
- `window.confirm` запрещён: удаление и повтор — двойное нажатие `data-n2j-arm` (app.js).
- Своего JS нет: живой статус — общий `partials/live_status` + `live_status_js`.
- Контент от левого края (`w-full px-3.5 …`), сетки `md:grid-cols-N`
  (`xl:grid-cols-2` для карточек); без display:contents/sticky/2xl-aside.
- Канал очереди — `nj598.dossier` (регистрация в handlers/__init__.py).
- reconcile() может писать в БД при просмотре — держать идемпотентным.
- Кнопка «Досье» в /jobs (nj550 result.php) ссылается на
  `/dossier?company=…` — не ломать слаг параметра.

## Проверки

- PHP: `tree-sitter` phpcheck (php CLI в песочнице нет).
- Python: `python3 -m py_compile workers/n2j_workers/handlers/nj598_dossier.py`.
