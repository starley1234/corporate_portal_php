# Конвертер цветов (модуль 629) — памятка для агентов

Переводит цвет между HEX (#RGB/#RRGGBB), RGB (rgb() или «r,g,b») и HSL,
считает яркость (luma), оттенки (светлее/темнее) и комплементарный цвет.

- Страница: `/color`
- API: `GET /nj629/api/convert?value=%233b82f6`

Без таблиц и очередей. Логика — в `classes/Service/Nj629/Color.php`,
JS — зеркало в `views/629/script.php` (полный дубль, включая hsl_to_rgb).

## Правила для агента

- Модуль **stateless**: таблиц, миграций, очередей нет.
- Сервис: `Service_Nj629_Color` (`parse`, `to_hex`, `to_hsl`, `hsl_to_rgb`, `shades`, `complement`, `report`).
- Точки HTTP: `/color` (страница), `GET /nj629/api/convert`.
- Принимает #RGB/#RRGGBB, rgb(r,g,b) и «r,g,b»; прочее → NULL.
- JS в `views/629/script.php` — зеркало сервиса; formulas (HSL↔RGB) не разъезжаются.
- Манифест: `config/nj_module.php` (slug `color`, группа «Разработчику»).
