# AGENTS.md — nj673_aiwatch

- Назначение: сравнивать завершённые отчёты `/aiscan` для той же пары endpoint+model.
- Доступ: только `admin`; цель сканирует исключительно nj672 с отдельным явным согласием.
- Таблиц и собственного скоринга нет. Источник истории и оценок — публичный сервис `Service_Nj672_Scan`.
- `Service_Nj673_AiWatch::compare()` — чистое сравнение двух отчётов; не смешивать разные URL или модели.
- `Service_Nj672_Scan::brief()['risk']` — массив `label`/`tone`; для списка AI Watch брать строковый label через `Service_Nj673_AiWatch::risk_label()`, не кастовать массив в строку.
- Страница: `GET /aiwatch`; вход в аудит ведёт на `/aiscan/simple`.
- Проверка: `N2J_ROOT=$PWD node .tools/phpwasm_stand.mjs .tools/nj673_aiwatch_stand.php`.
- Следующий этап: расписания профилей и уведомления только после появления явного target allowlist и теста против наслоения задач.
