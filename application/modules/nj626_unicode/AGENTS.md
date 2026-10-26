# Unicode-анализатор (модуль 626) — памятка для агентов

Разбирает строку по кодовым точкам: для каждого символа показывает кодовую
точку (U+XXXX), UTF-8 байты, UTF-16 escape и \u{...} запись плюс HTML-сущность.
Обратно раскодирует блоки \\uXXXX и \\u{...} в текст.

- Страница: `/unicode`
- API: `GET /nj626/api/analyze?text=Привет`

Без таблиц и очередей. Логика — в `classes/Service/Nj626/Unicode.php`
(требует mbstring, есть в окружении), JS — зеркало в `views/626/script.php`.

## Правила для агента

- Модуль **stateless**: таблиц, миграций, очередей нет.
- Сервис: `Service_Nj626_Unicode` (`analyze`, `char_info`, `esc_utf16`, `esc_u_braces`, `decode_escapes`).
- Точки HTTP: `/unicode` (страница), `GET /nj626/api/analyze`.
- Зависит от mbstring (mb_ord/mb_chr); предусмотрен fallback для latin/cyrillic.
- JS в `views/626/script.php` — зеркало: `Array.from` перебирает кодовые точки.
- Манифест: `config/nj_module.php` (slug `unicode`, группа «Разработчику»).
