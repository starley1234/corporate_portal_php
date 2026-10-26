# Текст-инструменты (модуль 631) — памятка для агентов

Быстрые операции над строками: статистика (символы, слова, строки, время
чтения), смена регистра (верхний/нижний/как в предложении), удаление
дубликатов строк, сортировка, обрезка и замена подстрок. UTF-8.

- Страница: `/text`
- API: `GET /nj631/api/tool?tool=stats&text=Привет`

Без таблиц и очередей. Логика — в `classes/Service/Nj631/Text.php`,
JS — зеркало в `views/631/script.php` (Unicode-регулярки JS).

## Правила для агента

- Модуль **stateless**: таблиц, миграций, очередей нет.
- Сервис: `Service_Nj631_Text` (`tools`, `apply`, `stats`, `reading_human`).
- Точки HTTP: `/text` (страница), `GET /nj631/api/tool`.
- Регистр — через mbstring (mb_strtoupper/mb_convert_case), JS — toLocaleUpperCase('ru').
- JS в `views/631/script.php` — зеркало сервиса; статистика слов по Unicode-свойствам.
- Манифест: `config/nj_module.php` (slug `text`, группа «Текст»).
