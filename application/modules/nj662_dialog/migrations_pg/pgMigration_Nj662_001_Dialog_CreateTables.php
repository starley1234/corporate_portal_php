<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция NJ-662 (1): таблицы «Диалога двух ИИ».
 *
 * Наработки владельца (source_data/100_ai_symposium) держались на 15 таблицах
 * с префиксом lib100_ и обязательной сущностью «симпозиум» (раунд, участники,
 * рейтинги, избранное, метрики, память агента, лог инструментов…). Здесь схема
 * сведена к пяти таблицам, потому что сущностей, за которые владелец платил
 * вниманием, всего три: ДИАЛОГ, его РЕПЛИКИ и его МАТЕРИАЛЫ.
 *
 *   nj_662_dialogs   — диалог: тема, вводная, два участника (имя, характер,
 *                      модель), настройки хода, живое состояние (статус, этап,
 *                      текущий обмен, счётчики), итог, публикация;
 *   nj_662_messages  — реплики и заметки: кто сказал, что, каким токеном, к
 *                      какому поиску это относится, выжимка обмена;
 *   nj_662_searches  — поиски в интернете («утка го»): запрос, находки,
 *                      прочитанные страницы — доказательство работы агентов;
 *   nj_662_knowledge — материалы диалога (RAG): текст или ссылка, состояние
 *                      индексации в общей семантике платформы (nj115);
 *   nj_662_events    — лента диалога: запуск, пауза, публикация, ошибки.
 *
 * Почему всё своё, а не nj_115/jj_120: очередь живёт в своих таблицах, семантика
 * в своих — сюда кладётся только то, что показывает страница диалога. Ход
 * диалога ведёт СЕРВЕР: после каждого обмена сайт ставит следующую задачу, и
 * диалог идёт без браузера (в наработках очередь крутил jQuery на странице —
 * закрыл вкладку, разговор встал).
 */
class pgMigration_Nj662_001_Dialog_CreateTables extends pgMigration {

	public function up()
	{
		// 1. Диалог. Модели участников — строками «провайдер::модель» (список в
		//    config/nj662_dialog.php); пусто = модель воркера по умолчанию.
		//    Статус: draft → quick (подбираю агентов) → running ⇄ paused → done,
		//    а также failed и cancelled.
		$this->run_query("CREATE TABLE IF NOT EXISTS nj_662_dialogs
		(
		  id             serial PRIMARY KEY,
		  user_id        integer NOT NULL DEFAULT 0,
		  topic          varchar(300) NOT NULL,
		  brief          text NOT NULL DEFAULT '',
		  a_name         varchar(80) NOT NULL DEFAULT 'Структура',
		  a_system       text NOT NULL DEFAULT '',
		  a_model        varchar(160) NOT NULL DEFAULT '',
		  b_name         varchar(80) NOT NULL DEFAULT 'Импульс',
		  b_system       text NOT NULL DEFAULT '',
		  b_model        varchar(160) NOT NULL DEFAULT '',
		  pairs          integer NOT NULL DEFAULT 5,
		  context_pairs  integer NOT NULL DEFAULT 3,
		  summarize      boolean NOT NULL DEFAULT true,
		  ddg            integer NOT NULL DEFAULT 0,
		  status         varchar(16) NOT NULL DEFAULT 'draft',
		  stage          varchar(80) NOT NULL DEFAULT '',
		  stage_note     varchar(300) NOT NULL DEFAULT '',
		  stage_at       timestamptz NULL,
		  turn           integer NOT NULL DEFAULT 0,
		  searches_used  integer NOT NULL DEFAULT 0,
		  tokens_total   integer NOT NULL DEFAULT 0,
		  summary        text NOT NULL DEFAULT '',
		  outcome        text NOT NULL DEFAULT '',
		  error          varchar(500) NOT NULL DEFAULT '',
		  job_id         bigint NOT NULL DEFAULT 0,
		  is_public      boolean NOT NULL DEFAULT false,
		  published_at   timestamptz NULL,
		  started_at     timestamptz NULL,
		  finished_at    timestamptz NULL,
		  created_at     timestamptz NOT NULL DEFAULT now(),
		  updated_at     timestamptz NOT NULL DEFAULT now()
		)");

		$this->run_script("COMMENT ON TABLE nj_662_dialogs IS
			'Диалог двух ИИ: тема, участники, настройки хода и живое состояние'");

		// Индекс «мои диалоги» — самый частый запрос рабочего стола.
		$this->run_script("CREATE INDEX IF NOT EXISTS nj_662_dialogs_user_idx
			ON nj_662_dialogs (user_id, created_at DESC)");

		// Каталог и карта сайта ходят по опубликованным.
		$this->run_script("CREATE INDEX IF NOT EXISTS nj_662_dialogs_public_idx
			ON nj_662_dialogs (is_public, published_at DESC)");

		// 2. Реплики. turn — номер обмена (1..pairs): по нему страница собирает
		//    ленту и понимает, какой обмен уже доехал (это же условие reconcile
		//    в манифесте канала). Поиски, которые дали реплике факты, лежат
		//    отдельной таблицей и связаны тем же номером обмена: лишних связей
		//    не заводим (владелец просил лаконично).
		$this->run_query("CREATE TABLE IF NOT EXISTS nj_662_messages
		(
		  id           serial PRIMARY KEY,
		  dialog_id    integer NOT NULL,
		  turn         integer NOT NULL DEFAULT 1,
		  who          varchar(8) NOT NULL DEFAULT 'a',
		  author_name  varchar(80) NOT NULL DEFAULT '',
		  content      text NOT NULL,
		  summary      text NOT NULL DEFAULT '',
		  model        varchar(160) NOT NULL DEFAULT '',
		  tokens       integer NOT NULL DEFAULT 0,
		  created_at   timestamptz NOT NULL DEFAULT now()
		)");

		$this->run_script("CREATE INDEX IF NOT EXISTS nj_662_messages_dialog_idx
			ON nj_662_messages (dialog_id, turn, id)");

		$this->run_script("COMMENT ON TABLE nj_662_messages IS
			'Реплики диалога и выжимки обменов: кто, что, чем и за сколько сказал'");

		// 3. Поиски в интернете. results — находки выдачи, pages — прочитанные
		//    страницы (текст срезан по limits.page_chars): и то, и другое нужно
		//    и странице («откуда факты»), и следующей реплике агента.
		$this->run_query("CREATE TABLE IF NOT EXISTS nj_662_searches
		(
		  id          serial PRIMARY KEY,
		  dialog_id   integer NOT NULL,
		  turn        integer NOT NULL DEFAULT 1,
		  agent       varchar(8) NOT NULL DEFAULT 'a',
		  query       varchar(300) NOT NULL DEFAULT '',
		  found       integer NOT NULL DEFAULT 0,
		  results     jsonb NOT NULL DEFAULT '[]'::jsonb,
		  pages       jsonb NOT NULL DEFAULT '[]'::jsonb,
		  note        varchar(300) NOT NULL DEFAULT '',
		  error       varchar(300) NOT NULL DEFAULT '',
		  created_at  timestamptz NOT NULL DEFAULT now()
		)");

		$this->run_script("CREATE INDEX IF NOT EXISTS nj_662_searches_dialog_idx
			ON nj_662_searches (dialog_id, id)");

		$this->run_script("COMMENT ON TABLE nj_662_searches IS
			'Поиски агентов в интернете (DuckDuckGo): запрос, находки, прочитанные страницы'");

		// 4. Материалы диалога. index_state — честное состояние индексации в
		//    общей семантике (nj115): new → queued → indexed|error. Текст храним
		//    целиком: он же уходит в подсказку агентам, а не только в векторы.
		$this->run_query("CREATE TABLE IF NOT EXISTS nj_662_knowledge
		(
		  id           serial PRIMARY KEY,
		  dialog_id    integer NOT NULL,
		  user_id      integer NOT NULL DEFAULT 0,
		  title        varchar(300) NOT NULL DEFAULT '',
		  source       varchar(500) NOT NULL DEFAULT '',
		  text         text NOT NULL DEFAULT '',
		  chars        integer NOT NULL DEFAULT 0,
		  index_state  varchar(16) NOT NULL DEFAULT 'new',
		  index_note   varchar(300) NOT NULL DEFAULT '',
		  created_at   timestamptz NOT NULL DEFAULT now(),
		  updated_at   timestamptz NOT NULL DEFAULT now()
		)");

		$this->run_script("CREATE INDEX IF NOT EXISTS nj_662_knowledge_dialog_idx
			ON nj_662_knowledge (dialog_id, id)");

		$this->run_script("COMMENT ON TABLE nj_662_knowledge IS
			'Материалы диалога: текст или прочитанная ссылка + состояние индексации в nj115'");

		// 5. Лента диалога. Нужна там, где реплик нет: подбор агентов, пауза,
		//    публикация, отказ. Плюс это журнал для владельца.
		$this->run_query("CREATE TABLE IF NOT EXISTS nj_662_events
		(
		  id          serial PRIMARY KEY,
		  dialog_id   integer NOT NULL,
		  kind        varchar(16) NOT NULL DEFAULT 'note',
		  message     varchar(500) NOT NULL DEFAULT '',
		  created_at  timestamptz NOT NULL DEFAULT now()
		)");

		$this->run_script("CREATE INDEX IF NOT EXISTS nj_662_events_dialog_idx
			ON nj_662_events (dialog_id, id DESC)");

		$this->run_script("COMMENT ON TABLE nj_662_events IS
			'Лента диалога: запуск, пауза, поиск, публикация, ошибки'");
	}

	public function down()
	{
		foreach (['nj_662_events', 'nj_662_knowledge', 'nj_662_searches',
			'nj_662_messages', 'nj_662_dialogs'] as $table)
		{
			$this->run_query("DROP TABLE IF EXISTS ".$table);
		}
	}
}
