# prx560_knowledge — техническая память платформы

## Состояние работ
Сохранён инфраструктурный RAG-кэш. Удалены импортёры старых сервисов и
источник глобального поиска. UI только admin. Не является HR/wiki-хранилищем.

## Контракт
`Service_Prx560_Knowledge`: remember, make_key, find_exact, search, recent,
stats, delete, cleanup. Таблица `prx_560_docs`, векторы — коллекция
`prx560_knowledge` через prx115/semantic.index.
`remember` с пустыми meta должен сохранять `{}` (регресс-тест PortalFoundationTest).

## Безопасность
Только технические несекретные материалы. Не складывать персональные данные,
корпоративные документы или содержимое разных организаций в общий кэш.
ACL-aware корпоративный RAG — отдельный контракт будущих модулей.

## Проверки
`bash bin/test PortalFoundation`; `make check`.
