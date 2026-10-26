# Контрольные цифры (модуль 628) — памятка для агентов

Проверка и расчёт контрольных сумм: алгоритм Луна (банковские карты, IMEI),
ИНН (10/12), ОГРН/ОГРНИП, СНИЛС, штрихкоды UPC-A/EAN-13 и CRC32 строки.

- Страница: `/checksum`
- API: `GET /nj628/api/check?a=inn&value=7707083893`

Без таблиц и очередей. Логика — в `classes/Service/Nj628/Checksum.php`
(CRC32 — PHP `crc32`, в JS тот же IEEE-алгоритм), JS — зеркало в `views/628/script.php`.

## Правила для агента

- Модуль **stateless**: таблиц, миграций, очередей нет.
- Сервис: `Service_Nj628_Checksum` (`algorithms`, `check`, `luhn`, `inn`, `ogrn`, `snils`, `ean`, `crc32`).
- Точки HTTP: `/checksum` (страница), `GET /nj628/api/check`.
- Проверка = корректность контрольной цифры, НЕ факт существования в реестре.
- JS в `views/628/script.php` — зеркало сервиса; EAN-веса и ИНН-веса совпадают с PHP.
- Манифест: `config/nj_module.php` (slug `checksum`, группа «Разработчику»).
