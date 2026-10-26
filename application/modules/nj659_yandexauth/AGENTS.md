# nj659_yandexauth — вход через Яндекс (OAuth 2.0)

## Состояние работ
- **06.10.2026:** модуль собран заново (старый `yandexauth` удалён 28.09.2026 —
  не был зарегистрирован в bootstrap и указывал на чужой домен). Теперь это
  nj659: чистый server-side authorization code flow, никаких внешних скриптов
  в браузере (правило «ни одного внешнего хоста во вёрстке»).
- Настройка: `.env` → `YANDEX_OAUTH_CLIENT_ID`, `YANDEX_OAUTH_CLIENT_SECRET`,
  `YANDEX_OAUTH_REDIRECT_URI` (примеры — `.env.example`). Приложение создаётся
  на oauth.yandex.ru; redirect URI — `https://<домен>/auth/yandex/callback`.
- Пока ID/секрет не заданы, кнопка на `/auth/login` не показывается, а
  `/auth/yandex` отвечает страницей-подсказкой — сайт работает без Яндекса.

## Назначение
Кнопка «Войти через Яндекс» на странице входа + привязка Яндекса к существующему
аккаунту. Связки хранятся в таблице `users_oauth` ядра erp100
(UNIQUE(provider, provider_id)); вход — `Auth::instance()->force_login()`.

## Поток (server-side)
1. `GET /auth/yandex` — анти-CSRF `state` в сессию, redirect на
   `oauth.yandex.ru/authorize?response_type=code&client_id=…&state=…`.
2. `GET /auth/yandex/callback?code=…&state=…` — сверка state, обмен кода на
   access_token (`POST oauth.yandex.ru/token`), профиль
   (`GET login.yandex.ru/info?format=json`, заголовок `Authorization: OAuth …`).
3. Логика входа (`Service_Nj659_Yandexauth::login_with`):
   - есть связка → вход;
   - нет связки, но email профиля совпадает с существующим пользователем →
     привязка и вход;
   - новичок → создание аккаунта (random-пароль, роль `login`), если
     разрешено `autocreate` в конфиге.
4. Залогиненный пользователь, открывший `/auth/yandex`, привязывает Яндекс
   к своему аккаунту (результат — страница-сообщение).

## Ключевые классы
| Класс | Роль |
|---|---|
| `Service_Nj659_Yandexauth` | поток целиком: url, токен, профиль, связки, вход; HTTP — в защищённых `_post`/`_get` (мокаются тестами) |
| `Controller_Nj659_Yandexauth` | start/callback/unlink + `/nj659/admin` (право `nj659.yandexauth`) |
| вьюха `auth/login.php` | кнопка: `(new Service_Nj659_Yandexauth)->login_button()` через `class_exists` |

## Маршруты
`auth/yandex` · `auth/yandex/callback` · `auth/yandex/unlink` (POST+CSRF,
владелец или админ) · `nj659/admin` (диагностика связок, отвязка).

## Правила
- Секреты — ТОЛЬКО `.env`; в url токена секрет не попадает (POST-тело).
- `state` одноразовый, сравнение `hash_equals`.
- Ошибки потока для гостя — flash в сессию и редирект на `/auth/login`
  (показывает их блок ошибок формы входа).
- Колонки `users_oauth` — только реальные: `first_name`/`last_name` (НЕ
  real_name), `birthday` date (пусто → NULL); username в `users` — латиница
  ≤32 (правило Model_Auth_User), база — логин Яндекса или `yandex_<id>`
  (рекомендация source_data/yandexauth/manual.md).
- Email из профиля Яндекса считается подтверждённым: при привязке к
  существующему аккаунту закрывается висящее неподтверждение.
- Аватары Яндекса во вёрстку не выводятся (внешний хост) — только иконка
  `fa-yandex` из локального `fa-all.min.css`.
- `access_token` хранится в `users_oauth` (там же, где ядро планировало).

## Диагностика
- **«redirect_uri не совпадает с Callback URL, указанным при регистрации
  приложения»** — Яндекс сверяет адреса посимвольно. Причина 06.10.2026:
  приложение было зарегистрировано со старым путём `/yandexauth/callback`
  (наработка source_data), а модуль шлёт `/auth/yandex/callback`. Лечение:
  в приложении на oauth.yandex.ru указать `https://<домен>/auth/yandex/callback`
  — ровно то же значение, что в `YANDEX_OAUTH_REDIRECT_URI` (или переменную
  оставить пустой: модуль построит адрес от текущего хоста сам).
- Кнопки на `/auth/login` нет → пусты `YANDEX_OAUTH_CLIENT_ID/SECRET` в `.env`.
- Вход обрывается молча → смотреть `application/logs/` (`nj659:` в ERROR):
  обмен кода и профиль пишут туда причину.

## Проверки
- Стенд без сети и БД: `node phpwasm_stand.mjs .tools/nj659_yandexauth_stand.php`
  (сборка url, обмен токена с фейковым HTTP, нормализация профиля, ошибки,
  экранирование кнопки, секрет не утекает в url).
