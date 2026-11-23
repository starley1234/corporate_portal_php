# Движок архитектуры

Этот каталог — рабочий набор для `docs/architecture/model`: сборка страницы,
проверка модели и вспомогательные команды. Он скопирован из демонстрационного
тулкита и урезан до того, что использует портал.

```bash
make architecture
node .tools/architecture/scripts/arch.mjs check --dir docs/architecture --strict
node .tools/architecture/scripts/arch.mjs status --dir docs/architecture
npm run test:architecture
```

`scripts/serve.mjs` — локальный просмотр собранной страницы из `docs/architecture/dist`.
Node нужен только для сборки и проверок: в production отдаётся готовый
`docs/architecture/dist/architecture.html`.
