# prl101_architecture — документация архитектуры

Модуль не хранит данные и не имеет миграций. Он отдаёт собранную страницу:

- `architecture/arch/**/*.yaml` — источник истины;
- `make architecture` — проверка модели и сборка;
- `architecture/dist/architecture.html` и `.json` — результат, коммитится;
- `/architecture` — HTML после входа (`prl101.view`);
- `/architecture/data.json` — JSON для агентов и скриптов.

## Правила

1. Не править `architecture/dist/*` руками — перезапишется сборкой.
2. После изменения YAML: `make architecture` (это же делает `check --strict`).
3. Новый узел схемы должен иметь `id` и, если это компонент, запись в
   `architecture/arch/entities.yaml`; описание по клику — в `components.yaml`.
4. Страница доступна только авторизованным: не добавлять её в публичные маршруты.
5. Если сборки нет, страница показывает команду `make architecture`, а не 500.

## Проверки

```bash
make architecture
node INPUT_DATA/enterprise-llm-architecture/scripts/arch.mjs check --dir architecture --strict
```

## Состояние

- Готово: модель `architecture/arch`, сборка `make architecture`, страница
  `/architecture`, JSON `/architecture/data.json`, право `prl101.view`.
- Собранные `architecture/dist/*` коммитятся; Node на production не нужен.
- Открытых пунктов нет. При изменении YAML проверить `make architecture`.
