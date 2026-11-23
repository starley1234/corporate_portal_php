# Удалённые прикладные модули

Основа портала создаётся для **новой БД**. Старые сервисы удалены из кода,
но эта операция не удаляет данные на серверах. Исходная версия доступна в Git
(базовый коммит `94b7b461b976e088ea94a5875dd43e92b7a09d23`).
Не подключайте новую версию к старой БД без отдельного плана переноса.

Удалено модулей: 111. Имена ниже приведены уже в едином пространстве PRX.

- `prx118_links`
- `prx222_forge`
- `prx130_agents`
- `prx140_design_lab`
- `prx160_mcp`
- `prx501_vat`
- `prx502_words`
- `prx503_salary`
- `prx504_vacation`
- `prx505_penalty`
- `prx506_units`
- `prx507_cooking`
- `prx508_calendar`
- `prx509_password`
- `prx510_qr`
- `prx511_size`
- `prx513_datewords`
- `prx514_translit`
- `prx516_metrics`
- `prx517_inn`
- `prx518_percent`
- `prx520_poll`
- `prx530_jump`
- `prx540_reply`
- `prx550_jobs`
- `prx570_models`
- `prx580_orgdocs`
- `prx590_design`
- `prx591_company_profile`
- `prx592_coldmail`
- `prx593_trends`
- `prx594_orgregistry`
- `prx596_guide`
- `prx598_dossier`
- `prx600_prefs`
- `prx601_billing`
- `prx602_content`
- `prx603_kicker`
- `prx604_news`
- `prx605_radar`
- `prx606_leads`
- `prx607_industry`
- `prx609_watchdog`
- `prx610_inbox`
- `prx611_landing`
- `prx612_touch`
- `prx613_digest`
- `prx614_json`
- `prx615_markdown`
- `prx616_convert`
- `prx617_uuid`
- `prx618_hash`
- `prx619_unixtime`
- `prx620_jwt`
- `prx621_cron`
- `prx622_regex`
- `prx623_roman`
- `prx624_base`
- `prx625_bytes`
- `prx626_unicode`
- `prx627_testdata`
- `prx628_checksum`
- `prx629_color`
- `prx630_ipcalc`
- `prx631_text`
- `prx632_csv`
- `prx633_nettools`
- `prx634_pdf`
- `prx635_scout`
- `prx636_pdfsign`
- `prx637_watermark`
- `prx638_pdfplan`
- `prx639_qc`
- `prx640_cameras`
- `prx641_imagegen`
- `prx642_vacancies`
- `prx643_cad`
- `prx644_military_pension`
- `prx645_llmlab`
- `prx646_label`
- `prx647_loan`
- `prx648_timezone`
- `prx649_diff`
- `prx650_receipt`
- `prx651_meal`
- `prx652_plant`
- `prx653_veclab`
- `prx654_similar`
- `prx655_nav3d`
- `prx656_meters`
- `prx657_alice`
- `prx658_geo`
- `prx659_yandexauth`
- `prx660_arch`
- `prx661_airadar`
- `prx662_dialog`
- `prx663_favicon`
- `prx664_jsondecode`
- `prx665_voice`
- `prx666_multivoice`
- `prx667_graph3d`
- `prx668_draw2d`
- `prx669_solid3d`
- `prx670_processhub`
- `prx671_embedbench`
- `prx672_aiscan`
- `prx673_aiwatch`
- `prx674_mcpguard`
- `prx675_ragguard`
- `prx676_mcphub`
- `prx677_talk`

## Удалено вне `application/modules/`

| Что | Когда | Почему |
|---|---|---|
| `archive/docflow/` (`prx394_replies` и старый воркер `doc.draft`) | 23.11.2026 | Архивный контур документооборота: решения и история — в `docs/VALIDATION.md`, текущий пайплайн — `docs/AI-DOCUMENT-PIPELINES.md` |
| `docs/PLAN-LLM-DOCFLOW.md` | 23.11.2026 | Исторический план контура, который признаётся пробой пера и будет удалён или переделан |
| `docs/GIT_HELP.md` | 23.11.2026 | Вставка из чата про Deploy Key GitHub; проект уходит с GitHub, деплой — `docs/DEPLOY-UBUNTU.md` |
| `docs/DESIGN-CATALOG-NAVIGATION.md` | 23.11.2026 | Дублировал `docs/FRONTEND.md`; уникальные правила перенесены туда |
| Перенос старых браузерных закладок в Избранное (`prx.nav.v1` → `/prx110/api/favorites_import`) | 23.11.2026 | Усложнение без ценности: избранным владеет аккаунт, старые закладки не имели владельца |
