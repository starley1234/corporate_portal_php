# Аудит производительности БД и загрузки страниц

Дата: 2026-09-21

## Найденные причины задержек

### 1. Проверка аккаунта выполняла лишнее на каждом запросе
`Controller_Erp100_Base::before()` для авторизованного пользователя вызывал:

1. `session_valid()` — два SELECT;
2. `touch_session()` — INSERT ... ON CONFLICT на каждом просмотре страницы;
3. `ensure_tables()` — DDL `CREATE TABLE/COMMENT` в горячем пути.

Исправлено:

- DDL автосоздание схемы отключено по умолчанию; штатный путь — миграции;
- проверка сессии объединена в один indexed SELECT с `LEFT JOIN nj_599_kicks`;
- `last_seen` обновляется не чаще одного раза в 5 минут;
- отдельный `touch_session()` из базового контроллера убран;
- legacy-сессия регистрируется только один раз.

Аварийный режим, если нужно восстановить отсутствующую схему вручную:

```env
NJ599_SCHEMA_AUTOCREATE=1
```

После восстановления вернуть `0`.

### 2. nj600 писал аналитику на каждый GET
Вызов `Service_Nj600_Prefs::track_page()` выполнял DDL-проверку, INSERT сырого события, SELECT профиля и UPDATE/INSERT профиля практически на каждом просмотре страницы.

Исправлено:

- DDL по умолчанию отключён, схему устанавливают миграции;
- добавлен cookie-throttle: один браузер пишет событие не чаще одного раза в 60 секунд;
- интервал настраивается через `NJ600_TRACK_MIN_INTERVAL`.

Сбор аналитики всё ещё намеренный, но больше не должен участвовать в каждом переходе.

## Индексы

Добавлена миграция `erp100_base` 003:

- `users_confirmation_token_idx` — подтверждение регистрации;
- `users_reset_token_idx` — восстановление пароля;
- `roles_users_role_user_idx` — подсчёты ролей и JOIN администратора;
- `users_oauth_user_idx` — профиль OAuth пользователя.

Уже существующие ключи для основных путей в порядке:

- `users.id`, `users.email`, `users.username` — unique/PK;
- `roles_users.user_id`, `roles_users.role_id`;
- `nj_599_sessions.session_id` — PK;
- `nj_599_kicks.user_id` — PK;
- `nj_597_notifications(user_id, read_at, id DESC)`;
- остальные пользовательские таблицы обычно имеют `(user_id, id DESC)`.

## Что проверить в PostgreSQL после выкладки

```sql
SELECT indexrelname, idx_scan
FROM pg_stat_user_indexes
WHERE relname IN ('users', 'roles_users', 'nj_599_sessions', 'nj_599_kicks')
ORDER BY relname, idx_scan DESC;

EXPLAIN (ANALYZE, BUFFERS)
SELECT s.created_at, s.last_seen, k.after_ts
FROM nj_599_sessions s
LEFT JOIN nj_599_kicks k ON k.user_id = s.user_id
WHERE s.session_id = '<session_id>' AND s.user_id = <user_id>;

EXPLAIN (ANALYZE, BUFFERS)
SELECT u.id, u.email, u.username, u.logins, u.last_login,
       array_agg(r.name ORDER BY r.name)
FROM users u
LEFT JOIN roles_users ru ON ru.user_id = u.id
LEFT JOIN roles r ON r.id = ru.role_id
GROUP BY u.id ORDER BY u.id DESC LIMIT 30;
```

## Следующий уровень оптимизации

1. Собирать slow query log PostgreSQL с порогом 200–300 ms.
2. На `/nj595/admin` заменить поиск `ILIKE '%строка%'` на prefix-поиск или подключить `pg_trgm` с GIN-индексами после проверки доступности расширения.
3. Не добавлять новые `ensure_tables()` в контроллеры; миграции должны применяться deploy-пинком.
4. Для тяжёлой аналитики использовать очередь `nj120`, а не синхронный HTTP-запрос.
5. Не обновлять `last_seen` чаще 5 минут — точность «онлайн» для этого не нужна.
