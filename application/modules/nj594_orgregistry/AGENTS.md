# nj594_orgregistry — заметки для агентов

* Таблицы nj_594_orgs / nj_594_links / nj_594_fns_calls: ensure_tables() + миграции
  001/002 (DDL идентичны). Третья — журнал запросов к ФНС: лимит + телеметрия.
* ЛЮБОЙ выход в ФНС идёт через fns_search()/refresh() с лимитом (fns_quota):
  окна по IP (NJ594_FNS_PER_MINUTE/PER_HOUR), общий кап (NJ594_FNS_TOTAL_PER_HOUR),
  пауза на повтор пустого ответа (NJ594_FNS_COOLDOWN) и журнал в nj_594_fns_calls.
  Сырой сетевой вызов — только `_fns_search_raw()` (внутренний, без лимита).
  Кнопки админки зовут `refresh($num, TRUE)` (обход лимита по IP).
* Публичные страницы (/orgs, /orgs/<ogrn>) — гостям; админка — только роль admin.
* ОГРН/ИНН всегда проверять ogrn_valid()/inn_valid() ДО запросов в ФНС.
* ФНС-lookup (fns_card) — копия проверенного потока nj580 Api; cookie-jar в tmp.
* seed_rows() — только проверенные данные (контрольные цифры сходятся);
  пусто = пусто: ничего не выдумывать, недостающее дополняет refresh из ФНС.
* Интеграция новых модулей: upsert + link — карточка сама соберёт материалы.
* modules/ и system/ ядра Kohana не править; манифест — config/nj_module.php,
  MCP — config/mcp.php (хаб nj160_mcp).
* Удаления в админке — two-click (armed), window.confirm запрещён.
* Вёрстка — по required_design_sample: крошки+h1, 2/3+1/3 (компаньон hidden
  md:block), ничего мельче text-xs (лейблы text-xs sm:text-sm), таблицы
  py-2.5/text-sm, кнопки min-h-[44px]/[36px].
