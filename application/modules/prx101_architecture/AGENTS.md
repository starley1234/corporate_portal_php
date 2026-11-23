# prx101_architecture — документация архитектуры

`/architecture/guide` — памятка программиста (view `101/guide.php`): философия, путь запроса,
анатомия модуля, правила именования, проверки перед коммитом, «что дальше». Меняется
вместе с AGENTS.md / MODULE_GUIDE / DESIGN-SYSTEM: страница — карта, не источник истины.

<!-- context-index:begin -->
<!-- Индекс разделов. Генерируется: python3 .tools/context_pack.py index application/modules/prx101_architecture/AGENTS.md --write. Руками не править. -->
| Раздел | Строка | Размер |
|---|---|---|
|   Правила | 23 | 0,7 КБ |
|   Проверки | 32 | 0,1 КБ |
|   Состояние | 39 | 0,4 КБ |
<!-- context-index:end -->
Модуль не хранит данные и не имеет миграций. Он отдаёт собранную страницу:

- `docs/architecture/model/**/*.yaml` — источник истины;
- `make architecture` — проверка модели и сборка;
- `docs/architecture/dist/architecture.html` и `.json` — результат, коммитится;
- `/architecture` — HTML после входа (`prx101.view`);
- `/architecture/data.json` — JSON для агентов и скриптов.

## Правила

1. Не править `docs/architecture/dist/*` руками — перезапишется сборкой.
2. После изменения YAML: `make architecture` (это же делает `check --strict`).
3. Новый узел схемы должен иметь `id` и, если это компонент, запись в
   `docs/architecture/model/entities.yaml`; описание по клику — в `components.yaml`.
4. Страница доступна только авторизованным: не добавлять её в публичные маршруты.
5. Если сборки нет, страница показывает команду `make architecture`, а не 500.

## Проверки

```bash
make architecture
node docs/architecture/engine/scripts/arch.mjs check --dir docs/architecture --strict
```

## Состояние

- Готово: модель `docs/architecture/model`, сборка `make architecture`, страница
  `/architecture`, JSON `/architecture/data.json`, право `prx101.view`.
- Собранные `docs/architecture/dist/*` коммитятся; Node на production не нужен.
- Открытых пунктов нет. При изменении YAML проверить `make architecture`.
