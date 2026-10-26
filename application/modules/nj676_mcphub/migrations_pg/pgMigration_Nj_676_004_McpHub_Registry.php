<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Глобальный каталог: слияние с официальным реестром MCP и «витрина дня».
 *
 * Что добавляет:
 *   * registry_name / registry_version — обратная ссылка на запись в
 *     registry.modelcontextprotocol.io (по ней идёт обновление, поэтому
 *     повторный импорт не плодит дубликаты);
 *   * synced_at — когда карточку последний раз видели в реестре;
 *   * featured — ручная витрина «выбор редакции» (её ведёт владелец, импорт
 *     реестра её не перебивает);
 *   * стартовый набор публичных удалённых серверов, которые работают без
 *     установки. Все они помечены `unverified`: утверждать «работает», пока
 *     серверная проверка tools/list не прошла, нельзя.
 *
 * Миграция идемпотентна: ADD COLUMN IF NOT EXISTS / ON CONFLICT DO NOTHING.
 */
class pgMigration_Nj_676_004_McpHub_Registry extends pgMigration
{
    public function up()
    {
        foreach ([
            "ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS registry_name VARCHAR(200) NOT NULL DEFAULT ''",
            "ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS registry_version VARCHAR(60) NOT NULL DEFAULT ''",
            "ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS synced_at TIMESTAMPTZ",
            "ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS featured BOOLEAN NOT NULL DEFAULT FALSE",
        ] as $sql)
        {
            $this->run_query($sql.';');
        }

        // Одна запись реестра = одна карточка, сколько бы версий ни вышло.
        $this->run_query("CREATE UNIQUE INDEX IF NOT EXISTS nj_676_servers_registry_uniq
            ON nj_676_servers (registry_name) WHERE registry_name <> '';");
        $this->run_query("CREATE INDEX IF NOT EXISTS nj_676_servers_repo_idx
            ON nj_676_servers (lower(repo_url));");
        $this->run_query("CREATE INDEX IF NOT EXISTS nj_676_servers_featured_idx
            ON nj_676_servers (featured DESC, is_published, stars DESC);");
        $this->run_query("CREATE INDEX IF NOT EXISTS nj_676_servers_remote_idx
            ON nj_676_servers (lower(remote_url)) WHERE remote_url <> '';");

        // Витрина: серверы, которые можно подключить ссылкой, без установки.
        // Описания — наши; технические поля — из документации самих серверов.
        $this->run_query(<<<'SQL'
INSERT INTO nj_676_servers
    (slug, name, description, repo_url, homepage_url, source, transport, auth_mode,
     categories, health_status, health_note, remote_url, remote_kind, env_keys,
     highlights, use_cases, origin, trust, featured, is_published, search_text)
VALUES
    ('openweathermap-mcp', 'OpenWeather для агентов',
     'Погода и климат по всему миру прямо в диалоге: текущие условия, прогноз, история. Три инструмента работают без ключа, остальные — с бесплатным ключом OpenWeather.',
     '', 'https://mcp.openweathermap.org/', 'native', 'streamable_http', 'none',
     '["research","other"]', 'unverified', 'Ждёт первой проверки с сервера', 'https://mcp.openweathermap.org/mcp', 'streamable_http', '[]',
     '["Погода и прогноз без установки — достаточно URL", "Три инструмента доступны без ключа"]',
     '["Узнать погоду в городе", "Прогноз на неделю", "Климатическая справка для поездки"]',
     'manual', 80, TRUE, TRUE,
     'openweathermap mcp погода weather прогноз климат temperature forecast погода в городе'),

    ('open-meteo-mcp', 'Open-Meteo',
     'Геокодирование, прогноз, исторический климат, морские условия, качество воздуха и высота над уровнем моря — на открытых данных Open-Meteo, без ключей.',
     'https://github.com/cyanheads/open-meteo-mcp-server', 'https://open-meteo.caseyjhand.com/mcp', 'github', 'streamable_http', 'none',
     '["research","data"]', 'unverified', 'Ждёт первой проверки с сервера', 'https://open-meteo.caseyjhand.com/mcp', 'streamable_http', '[]',
     '["Публичный хостинг, ключи не нужны", "Координаты, климат и качество воздуха в одном наборе"]',
     '["Найти координаты города", "Погода на 7 дней", "Исторические данные о климате"]',
     'manual', 78, TRUE, TRUE,
     'open-meteo погода weather geocode прогноз климат air quality море высота'),

    ('calculator-mcp', 'Calculator (math.js)',
     'Считает то, в чём языковые модели ошибаются: точные выражения, упрощение формул и производные. Один инструмент, три операции, публичный хостинг.',
     'https://github.com/cyanheads/calculator-mcp-server', 'https://calculator.caseyjhand.com/mcp', 'github', 'streamable_http', 'none',
     '["data","research"]', 'unverified', 'Ждёт первой проверки с сервера', 'https://calculator.caseyjhand.com/mcp', 'streamable_http', '[]',
     '["Проверка вычислений модели без установки", "Символьные операции: simplify и derivative"]',
     '["Посчитать сложное выражение", "Проверить производную", "Упростить формулу"]',
     'manual', 80, TRUE, TRUE,
     'calculator math математика калькулятор вычисления производная simplify derivative выражение'),

    ('everycalc', 'EveryCalc',
     'Бытовые, школьные, финансовые и военные калькуляторы как MCP: ставка по кредиту, проценты, конвертации. Без ключа и регистрации.',
     'https://github.com/xOyabunx/everycalc', 'https://everycalc.xyz', 'github', 'streamable_http', 'none',
     '["finance","data"]', 'unverified', 'Ждёт первой проверки с сервера', 'https://everycalc.xyz/mcp', 'streamable_http', '[]',
     '["Публичный Streamable HTTP без авторизации", "Ссылка на интерактивный калькулятор в ответе"]',
     '["Рассчитать кредит", "Перевести единицы", "Школьная задача"]',
     'manual', 70, FALSE, TRUE,
     'everycalc калькулятор calculator кредит проценты finance math конвертер'),

    ('precisioncalc', 'PrecisionCalc',
     'Финансовые расчёты в точной десятичной арифметике: проценты, аннуитет, накопления. Именно там, где float из модели даёт «почти правильный» ответ.',
     '', 'https://precisioncalc-mcp.pages.dev/', 'native', 'streamable_http', 'none',
     '["finance","data"]', 'unverified', 'Ждёт первой проверки с сервера', 'https://precisioncalc-mcp.pages.dev/mcp', 'streamable_http', '[]',
     '["Точная десятичная арифметика вместо float", "Один URL, без ключа"]',
     '["Посчитать платёж по кредиту", "Сложный процент", "Проверить финансовую модель"]',
     'manual', 68, FALSE, TRUE,
     'precisioncalc finance математика проценты аннуитет кредит decimal'),

    ('matrixcalculator-mcp', 'Matrix Calculator',
     'Матрицы по шагам: определитель, обратная, умножение, транспонирование — с записью решения, а не только ответом.',
     'https://github.com/gamestarry/matrixcalculator-mcp', 'https://matrixcalculator.net', 'github', 'streamable_http', 'none',
     '["data","research"]', 'unverified', 'Ждёт первой проверки с сервера', 'https://mcp.matrixcalculator.net/mcp', 'streamable_http', '[]',
     '["Пошаговое решение, а не только результат", "Публичный endpoint без ключа"]',
     '["Умножить матрицы", "Найти определитель", "Обратная матрица"]',
     'manual', 62, FALSE, TRUE,
     'matrix матрица определитель determinant линейная алгебра математика'),

    ('deepwiki-mcp', 'DeepWiki',
     '«Википедия» по любому публичному GitHub-репозиторию: как устроен проект, где что лежит, как собрать. Отвечает по коду, а не по README.',
     '', 'https://deepwiki.com', 'native', 'streamable_http', 'none',
     '["development","research"]', 'unverified', 'Ждёт первой проверки с сервера', 'https://mcp.deepwiki.com/mcp', 'streamable_http', '[]',
     '["Разбор любого публичного репозитория", "Публичный хостинг без ключа"]',
     '["Разберись в незнакомой библиотеке", "Найди, где реализована функция", "Как собрать проект"]',
     'manual', 82, TRUE, TRUE,
     'deepwiki github репозиторий документация код разбор архитектура wiki'),

    ('context7-mcp', 'Context7',
     'Актуальная документация библиотек и фреймворков по версии — вместо устаревших примеров из памяти модели.',
     'https://github.com/upstash/context7', 'https://context7.com', 'github', 'streamable_http', 'none',
     '["development","research"]', 'unverified', 'Ждёт первой проверки с сервера', 'https://mcp.context7.com/mcp', 'streamable_http', '[]',
     '["Свежие примеры под конкретную версию", "Работает без ключа с ограничением частоты"]',
     '["Покажи актуальный API библиотеки", "Пример кода для последней версии", "Миграция между версиями"]',
     'manual', 80, TRUE, TRUE,
     'context7 документация docs библиотека фреймворк версия примеры кода'),

    ('gitmcp', 'GitMCP',
     'Документация любого GitHub-проекта в один URL: gitmcp.io/{owner}/{repo}. Не нужно ничего публиковать — достаточно ссылки на репозиторий.',
     'https://github.com/idosal/git-mcp', 'https://gitmcp.io', 'github', 'streamable_http', 'none',
     '["development","research"]', 'unverified', 'Ждёт первой проверки с сервера', 'https://gitmcp.io/docs', 'streamable_http', '[]',
     '["Свой адрес для каждого репозитория", "Без ключей и установки"]',
     '["Документация небольшой библиотеки", "Поиск по коду проекта", "Свежие README и примеры"]',
     'manual', 72, FALSE, TRUE,
     'gitmcp github репозиторий документация docs код поиск'),

    ('huggingface-mcp', 'Hugging Face',
     'Модели, датасеты, пространства и статьи Hugging Face: поиск по экосистеме и получение карточек моделей прямо из диалога.',
     '', 'https://huggingface.co/mcp', 'native', 'streamable_http', 'oauth',
     '["ai","research"]', 'unverified', 'Ждёт первой проверки с сервера', 'https://huggingface.co/mcp', 'streamable_http', '[]',
     '["Каталог моделей и датасетов", "Вход через аккаунт Hugging Face для приватных ресурсов"]',
     '["Подбери модель под задачу", "Найди датасет", "Что нового в экосистеме"]',
     'manual', 76, FALSE, TRUE,
     'huggingface модели models датасеты datasets ai ml'),

    ('notion-mcp-remote', 'Notion',
     'Официальный удалённый MCP Notion: поиск по рабочим пространствам, чтение и создание страниц и баз.',
     'https://github.com/makenotion/notion-mcp-server', 'https://developers.notion.com/guides/mcp/get-started-with-mcp', 'github', 'streamable_http', 'oauth',
     '["productivity"]', 'unverified', 'Ждёт первой проверки с сервера', 'https://mcp.notion.com/mcp', 'streamable_http', '[]',
     '["Официальный сервер Notion", "Доступ по OAuth, права выдаёт владелец"]',
     '["Найди страницу в Notion", "Создай запись в базе", "Собери конспект встречи"]',
     'manual', 78, FALSE, TRUE,
     'notion заметки страницы база знаний productivity oauth'),

    ('microsoft-learn-mcp', 'Microsoft Learn',
     'Документация Microsoft — Azure, .NET, Windows, Microsoft 365 — из первоисточника, без выдуманных методов.',
     '', 'https://learn.microsoft.com', 'native', 'streamable_http', 'none',
     '["development","research"]', 'unverified', 'Ждёт первой проверки с сервера', 'https://learn.microsoft.com/api/mcp', 'streamable_http', '[]',
     '["Официальная документация Microsoft", "Без ключа"]',
     '["Как настроить Azure-сервис", "Актуальный API .NET", "Синтаксис PowerShell"]',
     'manual', 74, FALSE, TRUE,
     'microsoft learn azure dotnet документация docs windows m365'),

    ('cloudflare-docs-mcp', 'Cloudflare Docs',
     'Документация Cloudflare: Workers, Pages, R2, D1, Zero Trust. Полезно, когда агент пишет код для edge-платформы.',
     '', 'https://developers.cloudflare.com', 'native', 'streamable_http', 'none',
     '["development","cloud"]', 'unverified', 'Ждёт первой проверки с сервера', 'https://docs.mcp.cloudflare.com/mcp', 'streamable_http', '[]',
     '["Официальный сервер документации", "Без ключа"]',
     '["Настроить Workers", "Ограничения D1", "Правила кеширования"]',
     'manual', 72, FALSE, TRUE,
     'cloudflare workers pages r2 d1 документация docs edge'),

    ('linear-mcp', 'Linear',
     'Задачи и проекты Linear: создать issue, найти назначенное, обновить статус — из диалога, без переключения вкладок.',
     '', 'https://linear.app', 'native', 'streamable_http', 'oauth',
     '["productivity","development"]', 'unverified', 'Ждёт первой проверки с сервера', 'https://mcp.linear.app/mcp', 'streamable_http', '[]',
     '["Официальный OAuth-сервер Linear", "Работа с issues и проектами"]',
     '["Создай задачу", "Что назначено на меня", "Перенеси в другой статус"]',
     'manual', 70, FALSE, TRUE,
     'linear задачи issues проект трекер productivity'),

    ('sentry-mcp', 'Sentry',
     'Ошибки и падения из Sentry: найти свежие инциденты, разобрать стек, связать с релизом.',
     'https://github.com/getsentry/sentry-mcp', 'https://mcp.sentry.dev', 'github', 'streamable_http', 'oauth',
     '["development","security"]', 'unverified', 'Ждёт первой проверки с сервера', 'https://mcp.sentry.dev/mcp', 'streamable_http', '[]',
     '["Официальный сервер Sentry", "Инциденты, стеки, релизы"]',
     '["Что сломалось после релиза", "Разбери стек ошибки", "Сколько раз повторилось"]',
     'manual', 74, FALSE, TRUE,
     'sentry ошибки errors мониторинг инциденты стектрейс'),

    ('stripe-mcp', 'Stripe',
     'Платежи Stripe: найти платёж, посмотреть подписку, собрать отчёт — только чтение, деньги не двигаются.',
     '', 'https://docs.stripe.com/mcp', 'native', 'streamable_http', 'oauth',
     '["finance","development"]', 'unverified', 'Ждёт первой проверки с сервера', 'https://mcp.stripe.com', 'streamable_http', '[]',
     '["Официальный сервер Stripe", "Доступ по OAuth и правам аккаунта"]',
     '["Найди платёж клиента", "Статус подписки", "Сверка за месяц"]',
     'manual', 76, FALSE, TRUE,
     'stripe платежи payments подписки subscriptions финансы'),

    ('github-mcp-remote', 'GitHub',
     'Официальный удалённый MCP GitHub: репозитории, issues, pull request-ы, код. Нужен personal access token — зато без локального процесса.',
     'https://github.com/github/github-mcp-server', 'https://github.com/github/github-mcp-server', 'github', 'streamable_http', 'api_key',
     '["development"]', 'unverified', 'Ждёт первой проверки с сервера', 'https://api.githubcopilot.com/mcp/', 'streamable_http', '["GITHUB_PERSONAL_ACCESS_TOKEN"]',
     '["Официальный сервер GitHub", "Работает по токену, без установки"]',
     '["Покажи открытые PR", "Что в issue", "Найди код в репозитории"]',
     'manual', 78, FALSE, TRUE,
     'github репозиторий issues pull request код git'),

    ('exa-mcp-remote', 'Exa',
     'Поиск в интернете по смыслу и извлечение содержимого страниц — то, что нужно агенту вместо слепого HTML-парсинга.',
     'https://github.com/exa-labs/exa-mcp-server', 'https://exa.ai', 'github', 'streamable_http', 'api_key',
     '["web","research"]', 'unverified', 'Ждёт первой проверки с сервера', 'https://mcp.exa.ai/mcp', 'streamable_http', '["EXA_API_KEY"]',
     '["Семантический веб-поиск", "Извлечение текста страниц"]',
     '["Найди свежие материалы по теме", "Собери факты со страниц", "Сравни источники"]',
     'manual', 70, FALSE, TRUE,
     'exa поиск search веб web извлечение crawl research')
ON CONFLICT (slug) DO NOTHING;
SQL);
    }

    public function down()
    {
        $this->run_query("DELETE FROM nj_676_servers WHERE slug IN (
            'openweathermap-mcp', 'open-meteo-mcp', 'calculator-mcp', 'everycalc', 'precisioncalc',
            'matrixcalculator-mcp', 'deepwiki-mcp', 'context7-mcp', 'gitmcp', 'huggingface-mcp',
            'notion-mcp-remote', 'microsoft-learn-mcp', 'cloudflare-docs-mcp', 'linear-mcp',
            'sentry-mcp', 'stripe-mcp', 'github-mcp-remote', 'exa-mcp-remote');");
        $this->run_query('DROP INDEX IF EXISTS nj_676_servers_registry_uniq;');
        $this->run_query('DROP INDEX IF EXISTS nj_676_servers_repo_idx;');
        $this->run_query('DROP INDEX IF EXISTS nj_676_servers_featured_idx;');
        $this->run_query('DROP INDEX IF EXISTS nj_676_servers_remote_idx;');
    }
}
