# Архитектура портала как код

`docs/architecture/model/*.yaml` — источник истины. Из него одной командой собираются:

| Файл | Для кого |
|---|---|
| `docs/architecture/dist/architecture.html` | люди: схемы, слои, решения, поиск |
| `docs/architecture/dist/architecture.json` | агенты и скрипты |

Страница портала: **`/architecture`** (после входа, право `prl101.view`).
Машиночитаемая версия: **`/architecture/data.json`**.

## Сборка

```bash
npm ci                 # один раз: yaml для сборщика
make architecture      # check --strict + build
```

`make architecture` проверяет ссылки между узлами, дубли `id`, маршруты схем,
журнал решений и затем перезаписывает `dist/`. Собранные файлы коммитятся:
страница работает без Node и без интернета, а PHP только отдаёт готовый файл.

Движок — `engine/`. Это урезанный набор из демонстрационного тулкита:
`scripts/{arch,build}.mjs`, `scripts/lib/`, `src/` и тесты. Node нужен только
для сборки и проверок; в production достаточно `dist/`.

## Что где лежит

```text
docs/architecture/
  model/
    meta.yaml                 заголовок, разделы, палитра
    entities.yaml             один компонент — один id
    components.yaml           описание по клику на узел
    architecture/01-*.yaml    контекст
    architecture/02-*.yaml    слои выполнения
    architecture/03-*.yaml    карта модулей
    architecture/04-*.yaml    документооборот
    architecture/05-*.yaml    доступ и границы
    architecture/06-*.yaml    эксплуатация
    decisions/*.yaml          архитектурные решения (ADR)
  dist/                       собранные HTML и JSON (не править руками)
  engine/                     сборщик, шаблон страницы, тесты
```

## Как добавить вкладку

1. Создайте `model/architecture/NN-имя.yaml` с `title`, `overview.blocks` и
   `detail.blocks`.
2. Для схемы используйте `scheme` с `nodes` и `edges`; `id` узла должен
   совпадать с `entities.yaml`, если это компонент.
3. Добавьте описание в `components.yaml`, если узлу нужен `info`.
4. `make architecture` — проверка подскажет неизвестные id и разрывы маршрутов.

Полный формат блоков (схемы, последовательности, таблицы, карточки, решения)
описан в `engine/docs/FORMAT.md`.

## Правила

- `dist/` не правится руками: перезапишется.
- Новый компонент получает `owner` и `data_class` в `entities.yaml`.
- Страница не публичная: внутри описаны границы доверия и правила доступа.
- Если сборки нет, `/architecture` показывает команду, а не ошибку 500.
