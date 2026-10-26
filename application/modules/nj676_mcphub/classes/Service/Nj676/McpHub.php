<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Каталог MCP-серверов (nj676_mcphub).
 *
 * Три роли сервиса:
 *   1. витрина — поиск с ранжированием, категории, «живые» статусы и похожие
 *      серверы; всё читается из одной таблицы nj_676_servers;
 *   2. автосборка — прогоны (nj_676_runs) и приёмка карточек от python-воркера
 *      (канал nj676.discover): воркер ищет по GitHub и поиску, сайт валидирует
 *      и сохраняет;
 *   3. выдача конфигурации — готовый фрагмент для Claude Desktop / Cursor /
 *      Windsurf: локальный stdio с закреплённой версией ИЛИ удалённый URL.
 *
 * Ничего не запускается на сервере: каталог описывает, а не исполняет.
 */
class Service_Nj676_McpHub
{
    const MAX_TOOLS_BYTES = 65536;
    const MAX_TOOLS = 100;
    const MAX_RESULTS = 100;
    const PER_PAGE = 24;
    const DEFAULT_DISCOVER = 12;
    const MAX_DISCOVER = 30;

    const TRANSPORTS = [
        'unknown' => 'Не указан',
        'stdio' => 'Локальный · stdio',
        'sse' => 'Удалённый · SSE',
        'streamable_http' => 'Удалённый · Streamable HTTP',
    ];

    const AUTH = [
        'unknown' => 'Не указано',
        'none' => 'Без авторизации',
        'optional' => 'Необязателен (демо без ключа)',
        'api_key' => 'API-ключ',
        'oauth' => 'OAuth',
    ];

    const HEALTH = [
        'unverified' => ['Не проверен', 'gray'],
        'operational' => ['Работает', 'emerald'],
        'degraded' => ['Сбоит', 'amber'],
        'offline' => ['Недоступен', 'rose'],
    ];

    const CATEGORIES = [
        'development' => 'Разработка',
        'web' => 'Веб и поиск',
        'data' => 'Данные и базы',
        'ai' => 'ИИ и модели',
        'files' => 'Файлы и документы',
        'productivity' => 'Продуктивность',
        'cloud' => 'Облако и DevOps',
        'communication' => 'Связь и почта',
        'finance' => 'Финансы',
        'security' => 'Безопасность',
        'media' => 'Медиа и контент',
        'research' => 'Исследования',
        'other' => 'Другое',
    ];

    const CATEGORY_ICONS = [
        'development' => 'fa-code',
        'web' => 'fa-globe',
        'data' => 'fa-database',
        'ai' => 'fa-microchip',
        'files' => 'fa-folder-open',
        'productivity' => 'fa-list-check',
        'cloud' => 'fa-cloud',
        'communication' => 'fa-envelope',
        'finance' => 'fa-coins',
        'security' => 'fa-shield-halved',
        'media' => 'fa-photo-film',
        'research' => 'fa-flask',
        'other' => 'fa-cube',
    ];

    /** Слова, которые есть в каждой карточке: в полнотекстовый запрос не идут. */
    const STOPWORDS = ['mcp', 'server', 'servers', 'tool', 'tools', 'api', 'ai', 'app', 'the', 'and'];

    const SORTS = [
        'relevance' => 'По совпадению',
        'utility' => 'По пользе (алмазы)',
        'agent_rating' => 'По рейтингу агентов',
        'trust' => 'По доверию',
        'reliability' => 'По надёжности',
        'popular' => 'По популярности',
        'stars' => 'По звёздам',
        'latency' => 'По скорости отклика',
        'new' => 'Сначала новые',
        'name' => 'По названию',
    ];

    const ORIGINS = [
        'auto' => 'Автосборка',
        'manual' => 'Модерация',
        'registry' => 'Официальный реестр',
        'github' => 'Каталог',
        'native' => 'N2J',
    ];

    /**
     * Русское слово → английские понятия каталога (стемы и базовые формы).
     *
     * Каталог на 90% англоязычный, а спрашивают по-русски: «курс валют»,
     * «время и часовые пояса», «криптовалюта» давали 0 при 1007 серверах
     * (тест 24.10.2026). EN_TO_RU переводит только англоязычный запрос,
     * поэтому нужен и обратный словарь. Ключи — стемы: «курс» накрывает
     * «курсы», «курсами», «курсовая».
     */
    const RU_TO_EN = [
        'поиск' => ['search', 'lookup', 'find', 'query'],
        'веб' => ['web', 'browser', 'internet', 'http'],
        'браузер' => ['browser', 'playwright', 'puppeteer', 'chrome'],
        'данные' => ['data', 'dataset'],
        'проект' => ['project', 'repo', 'repository'],
        'гит' => ['git', 'github', 'gitlab'],
        'аудио' => ['audio', 'speech', 'voice', 'transcribe'],
        'финансы' => ['finance', 'payment', 'bank', 'stripe'],
        'безопасность' => ['security', 'audit', 'vulnerability', 'cve'],
        'ноушен' => ['notion'],
        'слак' => ['slack'],
        'телеграм' => ['telegram'],
        'ютуб' => ['youtube'],
        'знания' => ['knowledge', 'rag', 'memory'],
        'воркер' => ['worker', 'queue', 'job'],
        'очередь' => ['queue', 'job', 'kafka'],
        'crm' => ['crm', 'salesforce', 'hubspot'],
        'слово' => ['text', 'nlp', 'word'],
        'текст' => ['text', 'nlp', 'content'],
        'оракул' => ['oracle'],
        'редис' => ['redis'],
        'тайм' => ['time', 'datetime'],
        'вики' => ['wiki', 'wikipedia', 'encyclopedia'],
        'документац' => ['documentation', 'docs', 'reference'],
        'расписан' => ['schedule', 'timetable', 'calendar'],
        'курс' => ['exchange', 'rate', 'currency', 'forex', 'price'],
        'валют' => ['currency', 'exchange', 'forex', 'rate', 'fx'],
        'крипт' => ['cryptocurrency', 'bitcoin', 'crypto', 'ethereum', 'blockchain', 'coin'],
        'биткоин' => ['bitcoin', 'btc', 'cryptocurrency', 'coingecko', 'coinmarketcap', 'crypto', 'blockchain'],
        'эфир' => ['ethereum', 'eth', 'cryptocurrency', 'crypto'],
        'википед' => ['wikipedia', 'wiki', 'encyclopedia'],
        'энциклопед' => ['encyclopedia', 'wikipedia', 'wiki'],
        'врем' => ['time', 'timezone', 'clock', 'date', 'worldtime'],
        'часов' => ['time', 'timezone', 'clock', 'hour'],
        'пояс' => ['timezone', 'zone'],
        'дат' => ['date', 'calendar', 'time'],
        'франшиз' => ['franchise', 'franchising'],
        'реклам' => ['ads', 'advertising', 'adwords', 'campaign', 'marketing'],
        'гугл' => ['google', 'adwords', 'analytics', 'search'],
        'маркетинг' => ['marketing', 'campaign', 'ads', 'seo'],
        'новост' => ['news', 'rss', 'headlines', 'feed'],
        'цен' => ['price', 'pricing', 'cost', 'quote'],
        'товар' => ['product', 'products', 'goods', 'catalog'],
        'заказ' => ['order', 'orders', 'checkout', 'purchase'],
        'доставк' => ['delivery', 'shipping', 'logistics'],
        'отзыв' => ['review', 'reviews', 'feedback', 'rating'],
        'алмаз' => ['gem', 'recommended', 'verified', 'useful'],
        'аналитик' => ['analytics', 'metrics', 'insights', 'stats', 'bi'],
        'статистик' => ['statistics', 'stats', 'analytics', 'data'],
        'таблиц' => ['spreadsheet', 'table', 'csv', 'excel', 'sheet'],
        'эксель' => ['excel', 'spreadsheet', 'xlsx'],
        'презентац' => ['presentation', 'slides', 'powerpoint'],
        'музык' => ['music', 'audio', 'spotify', 'song'],
        'фильм' => ['movie', 'film', 'video', 'cinema'],
        'книг' => ['book', 'books', 'library', 'literature'],
        'наук' => ['science', 'research', 'paper', 'academic'],
        'стать' => ['article', 'articles', 'paper', 'publication'],
        'закон' => ['law', 'legal', 'regulation', 'legislation'],
        'суд' => ['court', 'legal', 'lawsuit'],
        'налог' => ['tax', 'vat', 'fiscal'],
        'бухгалтер' => ['accounting', 'ledger', 'bookkeeping', 'finance'],
        'склад' => ['warehouse', 'inventory', 'stock'],
        'логист' => ['logistics', 'shipping', 'supply'],
        'медицин' => ['medical', 'health', 'healthcare', 'clinical'],
        'здоров' => ['health', 'medical', 'wellness', 'fitness'],
        'аптек' => ['pharmacy', 'medicine', 'drug'],
        'спорт' => ['sport', 'sports', 'fitness', 'workout'],
        'путешеств' => ['travel', 'trip', 'flight', 'booking'],
        'отел' => ['hotel', 'hotels', 'booking', 'travel'],
        'билет' => ['ticket', 'tickets', 'booking', 'flight'],
        'авиа' => ['flight', 'airline', 'aviation', 'airport'],
        'пробк' => ['traffic', 'route', 'congestion'],
        'маршрут' => ['route', 'directions', 'navigation', 'routing'],
        'координат' => ['coordinates', 'geocoding', 'geo', 'location'],
        'адрес' => ['address', 'geocoding', 'location'],
        'заметк' => ['note', 'notes', 'memo', 'markdown'],
        'задач' => ['task', 'tasks', 'todo', 'issue'],
        'напомин' => ['reminder', 'alert', 'notify', 'schedule'],
        'уведом' => ['notification', 'notifications', 'alert', 'notify'],
        'календар' => ['calendar', 'schedule', 'event', 'events'],
        'встреч' => ['meeting', 'meetings', 'calendar', 'event'],
        'почт' => ['email', 'mail', 'gmail', 'smtp', 'imap'],
        'письм' => ['email', 'mail', 'message', 'send'],
        'смс' => ['sms', 'message', 'twilio'],
        'звон' => ['call', 'voice', 'phone'],
        'телефон' => ['phone', 'telephony', 'sms', 'voice'],
        'сайт' => ['website', 'web', 'http', 'scrape', 'site'],
        'страниц' => ['page', 'web', 'scrape', 'html'],
        'скриншот' => ['screenshot', 'screen', 'capture'],
        'пдф' => ['pdf', 'document', 'documents'],
        'документ' => ['document', 'documents', 'docs', 'docx', 'pdf', 'office'],
        'контрагент' => ['company', 'counterparty', 'organization', 'registry', 'inn', 'business'],
        'компан' => ['company', 'companies', 'organization', 'registry', 'business'],
        'огрн' => ['company', 'registry', 'business'],
        'инн' => ['company', 'registry', 'tax', 'business'],
        'счет' => ['invoice', 'account', 'billing', 'score'],
        'платеж' => ['payment', 'payments', 'billing', 'stripe', 'invoice', 'checkout'],
        'банк' => ['bank', 'banking', 'payment', 'finance'],
        'кредит' => ['credit', 'loan', 'finance'],
        'акци' => ['stock', 'stocks', 'quote', 'ticker', 'market'],
        'бирж' => ['exchange', 'stock', 'market', 'trading'],
        'инвест' => ['investment', 'portfolio', 'stock', 'trading'],
        'недвижим' => ['realty', 'real', 'property', 'estate'],
        'ваканс' => ['vacancy', 'vacancies', 'job', 'hiring'],
        'резюме' => ['resume', 'cv', 'candidate'],
        'персонал' => ['hr', 'recruiting', 'employee', 'people'],
        'клиент' => ['customer', 'customers', 'crm', 'client'],
        'продаж' => ['sales', 'crm', 'deal', 'leads'],
        'поддержк' => ['support', 'helpdesk', 'ticket', 'tickets'],
        'ошибк' => ['error', 'errors', 'bug', 'exception', 'logging'],
        'тест' => ['test', 'testing', 'qa', 'spec'],
        'сборк' => ['build', 'ci', 'pipeline', 'compile'],
        'деплой' => ['deploy', 'deployment', 'release', 'hosting'],
        'сервер' => ['server', 'servers', 'hosting', 'infra'],
        'баз' => ['database', 'databases', 'db', 'sql', 'postgres', 'mysql', 'sqlite', 'mongodb'],
        'файл' => ['file', 'files', 'filesystem', 'fs'],
        'папк' => ['folder', 'directory', 'filesystem'],
        'архив' => ['archive', 'zip', 'compress'],
        'конверт' => ['convert', 'conversion', 'transform', 'format'],
        'шифров' => ['encrypt', 'encryption', 'cryptography', 'cipher'],
        'парол' => ['password', 'passwords', 'secret', 'credential'],
        'ключ' => ['key', 'keys', 'token', 'credential'],
        'аутентификац' => ['auth', 'authentication', 'login', 'oauth'],
        'домен' => ['domain', 'domains', 'dns', 'whois'],
        'ссылк' => ['link', 'links', 'url', 'shortener'],
        'карт' => ['map', 'maps', 'geo', 'location', 'geocoding'],
        'картинк' => ['image', 'images', 'photo', 'vision', 'picture'],
        'изображен' => ['image', 'images', 'photo', 'vision'],
        'видео' => ['video', 'videos', 'youtube', 'media'],
        'перевод' => ['translate', 'translation', 'translator'],
        'язык' => ['language', 'translate', 'nlp'],
        'код' => ['code', 'coding', 'repository', 'ast', 'snippet'],
        'репозитор' => ['repository', 'repo', 'git', 'github'],
        'погод' => ['weather', 'forecast', 'climate', 'temperature'],
        'проб' => ['test', 'probing', 'diagnostics'],
        'план' => ['plan', 'planner', 'schedule', 'roadmap'],
        'баг' => ['bug', 'issue', 'error', 'ticket'],
        'лог' => ['log', 'logs', 'logging'],
        'метрик' => ['metric', 'metrics', 'analytics', 'monitoring'],
        'мониторинг' => ['monitoring', 'observability', 'metrics', 'uptime', 'logs'],
        'скрап' => ['scrape', 'scraper', 'crawler', 'spider'],
        'парс' => ['parse', 'parser', 'scrape', 'crawler', 'extract'],
        'база знан' => ['knowledge', 'rag', 'memory', 'embedding'],
        'памят' => ['memory', 'knowledge', 'rag', 'embedding'],
        'модел' => ['model', 'models', 'llm', 'ai'],
        'нейросет' => ['ai', 'llm', 'model', 'openai', 'anthropic'],
        'облак' => ['cloud', 'aws', 'azure', 'gcp', 'kubernetes'],
        'контейнер' => ['container', 'docker', 'kubernetes'],
        'докер' => ['docker', 'container', 'kubernetes'],
        'иконк' => ['icon', 'icons', 'dashboard', 'svg', 'logo'],
        'икон' => ['icon', 'icons', 'svg', 'logo'],
        'логотип' => ['logo', 'logos', 'icon', 'brand'],
        'эмблем' => ['logo', 'logos', 'icon', 'badge'],
        'пиктограмм' => ['icon', 'icons', 'svg', 'glyph'],
        'дашборд' => ['dashboard', 'panel', 'widget', 'chart'],
        'график' => ['chart', 'graph', 'plot', 'diagram'],
        'диаграмм' => ['diagram', 'chart', 'graph', 'mermaid'],
        'технолог' => ['technology', 'technologies', 'technographics', 'stack'],
        'техстек' => ['technographics', 'stack', 'technology'],
        'стек' => ['stack', 'technographics', 'technology'],
        // Глаголы задач: агент формулирует задачу действием («отправить»,
        // «создать»), а в каталоге она записана существительным и по-английски.
        // Без них «отправить письмо» не находило почтовых серверов вовсе.
        'отправ' => ['send', 'sending', 'email', 'mail', 'message'],
        'отправить' => ['send', 'email', 'mail', 'message'],
        'создат' => ['create', 'add', 'new', 'make'],
        'создать' => ['create', 'add', 'new', 'make'],
        'добав' => ['add', 'create', 'insert', 'append'],
        'прочит' => ['read', 'get', 'fetch', 'parse'],
        'прочитать' => ['read', 'get', 'fetch'],
        'читат' => ['read', 'reader', 'parse'],
        'получ' => ['get', 'fetch', 'list', 'retrieve'],
        'найти' => ['search', 'find', 'lookup', 'query'],
        'найд' => ['search', 'find', 'lookup', 'query'],
        'удал' => ['delete', 'remove', 'drop'],
        'обнов' => ['update', 'refresh', 'patch'],
        'скачат' => ['download', 'export', 'save'],
        'загруз' => ['upload', 'import', 'load'],
        'показ' => ['list', 'show', 'view', 'get'],
        'показать' => ['list', 'show', 'view'],
        'конвертир' => ['convert', 'conversion', 'transform'],
        'перевест' => ['translate', 'translation'],
        'провер' => ['check', 'validate', 'verify', 'probe'],
        'посчит' => ['calculate', 'compute', 'calc', 'eval'],
        'считат' => ['calculate', 'compute', 'calc'],
        'напомн' => ['remind', 'reminder', 'notify'],
        'забронир' => ['book', 'booking', 'reserve'],
        'речь' => ['speech', 'audio', 'voice', 'asr', 'transcription'],
        'распозна' => ['recognize', 'recognition', 'transcribe', 'asr', 'detect', 'ocr'],
        'транскрип' => ['transcribe', 'transcription', 'asr'],
        'озвуч' => ['tts', 'speech', 'voice', 'audio'],
        'голос' => ['voice', 'speech', 'audio', 'tts'],
        'значок' => ['icon', 'icons', 'svg', 'badge'],
        'бренд' => ['brand', 'logo', 'identity'],
        'конспект' => ['summary', 'summarize', 'digest', 'abstract'],
        'пересказ' => ['summary', 'summarize', 'digest'],
        'цитат' => ['quote', 'quotes', 'citation'],
        'время' => ['time', 'worldtime', 'clock', 'datetime'],
    ];

    /** Алиас для обратной совместимости (стенд и внешние вызовы). */
    const SYNONYMS = self::RU_TO_EN;

    /**
     * Категория «по смыслу», когда точных слов не нашлось.
     *
     * Тема без совпадений не должна давать пустую выдачу: агент получает
     * ближайшую категорию и живые серверы в ней.
     */
    const CATEGORY_HINTS = [
        'валют' => 'finance', 'курс' => 'finance', 'крипт' => 'finance', 'биткоин' => 'finance',
        'финанс' => 'finance', 'банк' => 'finance', 'налог' => 'finance', 'бухгалтер' => 'finance',
        'акци' => 'finance', 'бирж' => 'finance', 'инвест' => 'finance',
        'википед' => 'research', 'энциклопед' => 'research', 'наук' => 'research', 'стать' => 'research',
        'закон' => 'research', 'суд' => 'research',
        'врем' => 'productivity', 'часов' => 'productivity', 'пояс' => 'productivity',
        'календар' => 'productivity', 'задач' => 'productivity', 'заметк' => 'productivity',
        'напомин' => 'productivity',
        'реклам' => 'web', 'гугл' => 'web', 'маркетинг' => 'web', 'новост' => 'web',
        'поиск' => 'web', 'сайт' => 'web', 'скрап' => 'web', 'парс' => 'web',
        'почт' => 'communication', 'письм' => 'communication', 'смс' => 'communication',
        'телефон' => 'communication', 'звон' => 'communication', 'уведом' => 'communication',
        'документ' => 'files', 'пдф' => 'files', 'файл' => 'files', 'эксель' => 'files',
        'таблиц' => 'files', 'презентац' => 'files',
        'картинк' => 'media', 'изображен' => 'media', 'видео' => 'media', 'музык' => 'media',
        'фильм' => 'media',
        'код' => 'development', 'репозитор' => 'development', 'тест' => 'development',
        'сборк' => 'development', 'деплой' => 'development', 'баг' => 'development',
        'баз' => 'data', 'данн' => 'data', 'метрик' => 'data', 'лог' => 'data',
        'облак' => 'cloud', 'докер' => 'cloud', 'контейнер' => 'cloud', 'сервер' => 'cloud',
        'безопасн' => 'security', 'парол' => 'security', 'шифров' => 'security',
        'ключ' => 'security', 'домен' => 'security',
        'модел' => 'ai', 'нейросет' => 'ai', 'памят' => 'ai', 'погод' => 'web',
        'карт' => 'web', 'маршрут' => 'web', 'координат' => 'web',
    ];

    /** Предлоги и слова-связки: без них «время и часовые пояса» ищет «и». */
    const RU_STOPWORDS = ['и', 'в', 'во', 'на', 'с', 'со', 'по', 'для', 'от', 'до', 'о', 'об',
        'а', 'но', 'или', 'как', 'что', 'где', 'мне', 'нужен', 'нужна', 'нужно', 'можно',
        'пожалуйста', 'плиз', 'это', 'the', 'for', 'with', 'and', 'how', 'what'];

    /**
     * Английские слова → русские корни.
     *
     * Каталог заполняется и по-русски, и по-английски: агент пишет «wikipedia»,
     * а карточка называется «Википедия по репозиторию». Без обратного
     * перевода такие запросы молча возвращали пусто.
     */
    const EN_TO_RU = [
        'wikipedia' => ['wiki', 'encyclopedia', 'вики', 'энциклопед'],
        'wiki' => ['wikipedia', 'вики'],
        'weather' => ['forecast', 'climate', 'погод'],
        'forecast' => ['weather', 'прогноз'],
        'database' => ['sql', 'db', 'база', 'данн'],
        'currency' => ['forex', 'exchange', 'rate', 'валют'],
        'exchange' => ['currency', 'rate', 'курс', 'обмен'],
        'repository' => ['repo', 'git', 'code', 'репозитор', 'проект'],
        'repo' => ['repository', 'git', 'репозитор'],
        'vacancy' => ['job', 'hiring', 'ваканси'],
        'company' => ['organization', 'registry', 'компани', 'контрагент'],
        'invoice' => ['billing', 'payment', 'счёт', 'платеж'],
        'search' => ['lookup', 'find', 'поиск'],
        'browser' => ['playwright', 'chrome', 'браузер'],
        'email' => ['mail', 'gmail', 'почта', 'письмо'],
        // Слова, на которых русский поиск промахивался в замере 24.10.2026.
        'icon' => ['икон', 'логотип', 'значок'],
        'icons' => ['икон', 'логотип', 'значок'],
        'logo' => ['логотип', 'икон', 'бренд'],
        'screenshot' => ['скриншот', 'снимок'],
        'image' => ['картинк', 'изображен', 'фото'],
        'speech' => ['речь', 'распозна', 'голос'],
        'transcribe' => ['транскрип', 'распозна', 'речь'],
        'pdf' => ['пдф', 'документ'],
        'summary' => ['конспект', 'пересказ', 'кратко'],
    ];

    // ---------------------------------------------------------------------
    // Витрина: поиск, фильтры, статистика
    // ---------------------------------------------------------------------

    /**
     * Поиск опубликованных карточек.
     *
     * Фильтры (все — из allowlist, значения только плейсхолдерами):
     *   q, category, transport, auth, health, origin, sort, page, per.
     *
     * @return array{sql: string, servers: array, total: int, page: int, per: int, pages: int}
     */
    public static function search($filters = [])
    {
        [$where, $params] = self::_where($filters);
        $where_sql = implode(' AND ', $where ?: ['TRUE']);

        $count_rows = self::_query(
            'SELECT count(*) AS c FROM nj_676_servers WHERE '.$where_sql, $params
        )->as_array();
        $total = (int) ($count_rows[0]['c'] ?? 0);

        $raw_per = $filters['per'] ?? ($filters['limit'] ?? ($filters['per_page'] ?? 0));
        $per = (int) $raw_per > 0 ? self::_int($raw_per, self::PER_PAGE, 1, 60) : self::PER_PAGE;
        $pages = max(1, (int) ceil($total / $per));
        $page = self::_int($filters['page'] ?? 0, 1, 1, max(1, $pages));

        $raw_sort = (string) ($filters['sort'] ?? ($filters['sort_by'] ?? ''));
        $raw_order = (string) ($filters['order'] ?? '');
        $order = self::_order($raw_sort, (string) ($filters['q'] ?? ''), $raw_order);

        if (isset($filters['offset']) AND is_numeric($filters['offset']) AND (int) $filters['offset'] >= 0)
        {
            $offset = min(max(0, $total), (int) $filters['offset']);
            $page = max(1, (int) floor($offset / $per) + 1);
        }
        else
        {
            $offset = ($page - 1) * $per;
        }

        $sql = 'SELECT '.self::_columns().', '.self::_rank_sql($params).' AS rank
                FROM nj_676_servers
                WHERE '.$where_sql.'
                ORDER BY '.$order.' LIMIT '.$per.' OFFSET '.$offset;
        $rows = self::_query($sql, $params)->as_array();

        // Ранжирование из SQL отдаём наружу: шлюзу нужен реальный признак
        // совпадения, а не «позиция в списке». Без него сервер, попавший в
        // пул за готовность, получал ненулевую релевантность и вытеснял
        // подходящие карточки (тест 24.10.2026: «отправить письмо» →
        // набор иконок).
        foreach ($rows as & $row)
        {
            $row['rank'] = isset($row['rank']) ? (float) $row['rank'] : 0.0;
        }
        unset($row);

        return [
            'servers' => array_map([__CLASS__, 'shape'], $rows),
            'total' => $total,
            'page' => $page,
            'per' => $per,
            'pages' => $pages,
            'sort' => $raw_sort !== '' && isset(self::SORTS[$raw_sort]) ? $raw_sort : (trim((string) ($filters['q'] ?? '')) !== '' ? 'relevance' : 'popular'),
        ];
    }

    /**
     * Карточки по списку slug — для семантических кандидатов.
     *
     * Векторный поиск возвращает только slug и дистанцию: остальное берём
     * одним SQL, а не N запросами.
     */
    public static function by_slugs(array $slugs)
    {
        $clean = [];

        foreach ($slugs as $slug)
        {
            $slug = trim((string) $slug);

            if ($slug !== '')
            {
                $clean[] = mb_substr($slug, 0, 120, 'UTF-8');
            }
        }

        $clean = array_values(array_unique($clean));

        if ($clean === [])
        {
            return [];
        }

        $params = [];
        $placeholders = [];

        foreach ($clean as $i => $slug)
        {
            $placeholders[] = ':s'.$i;
            $params[':s'.$i] = $slug;
        }

        $rows = self::_query(
            'SELECT '.self::_columns().' FROM nj_676_servers
              WHERE is_published = TRUE AND slug IN ('.implode(', ', $placeholders).')',
            $params
        )->as_array();

        return array_map([__CLASS__, 'shape'], $rows);
    }

    /**
     * Список серверов для админки и выгрузки с опциональным поиском и фильтром состояния.
     *
     * @param  int   $limit
     * @param  array $filters ['q' => string, 'filter' => string]
     * @return array
     */
    public static function all_for_admin($limit = 200, array $filters = [])
    {
        $max = max(1, min(500, (int) $limit));
        $q = trim((string) ($filters['q'] ?? ''));
        $filter = trim((string) ($filters['filter'] ?? ''));

        if ($q === '' AND $filter === '')
        {
            $rows = self::_query(
                'SELECT '.self::_columns().' FROM nj_676_servers ORDER BY updated_at DESC LIMIT '.$max
            )->as_array();

            return array_map([__CLASS__, 'shape'], $rows);
        }

        $where = ['1=1'];
        $params = [];

        if ($q !== '')
        {
            $where[] = '(slug ILIKE :q OR name ILIKE :q OR name_ru ILIKE :q OR description ILIKE :q OR description_ru ILIKE :q OR search_text ILIKE :q)';
            $params[':q'] = '%'.$q.'%';
        }

        if ($filter === 'ready')
        {
            $where[] = 'is_published = TRUE';
            $where[] = self::_ready_condition($params);
        }
        elseif ($filter === 'broken_schema')
        {
            $where[] = "(schema_valid = FALSE OR (jsonb_typeof(tools) = 'array' AND jsonb_array_length(tools) > 0 AND tools::text LIKE '%\"properties\":[]%'))";
        }
        elseif ($filter === 'untranslated')
        {
            $where[] = "is_published = TRUE AND (description_ru = '' OR name_ru = '')";
        }
        elseif ($filter === 'featured')
        {
            $where[] = 'featured = TRUE';
        }
        elseif ($filter === 'unpublished')
        {
            $where[] = 'is_published = FALSE';
        }
        elseif ($filter === 'manual')
        {
            $where[] = "origin = 'manual'";
        }
        elseif ($filter === 'gems')
        {
            $where[] = 'is_gem = TRUE';
        }
        elseif ($filter === 'audited')
        {
            $where[] = "audited_at IS NOT NULL AND audit_verdict <> 'pending'";
        }

        $fetch_limit = $filter === 'ready' ? min(500, $max * 2) : $max;
        $rows = self::_query(
            'SELECT '.self::_columns().' FROM nj_676_servers WHERE '.implode(' AND ', $where).' ORDER BY updated_at DESC LIMIT '.$fetch_limit,
            $params
        )->as_array();

        $shaped = array_map([__CLASS__, 'shape'], $rows);
        if ($filter === 'ready')
        {
            $shaped = array_values(array_filter($shaped, static function (array $row) {
                return ! empty($row['readiness']['ready']);
            }));
        }

        return array_slice($shaped, 0, $max);
    }

    /** Опубликованные счётчики витрины: карточки, инструменты, категории. */
    public static function stats()
    {
        $row = self::_query(
            "SELECT count(*) AS servers,
                    COALESCE(sum(coalesce(jsonb_array_length(
                        CASE WHEN jsonb_typeof(tools) = 'array' THEN tools ELSE '[]'::jsonb END), 0)), 0) AS tools,
                    count(*) FILTER (WHERE health_status = 'operational') AS operational,
                    count(*) FILTER (WHERE created_at > now() - interval '7 days') AS fresh
               FROM nj_676_servers WHERE is_published = TRUE"
        )->current();

        $row = $row ? (array) $row : [];

        $servers = (int) ($row['servers'] ?? 0);
        $healthy = (int) ($row['operational'] ?? 0);
        $recent = (int) ($row['fresh'] ?? 0);

        // «fresh: 1007» читалось как «всё проверено» (реальный тест 24.10.2026:
        // fresh 1007 при operational 8). Имена говорят, что именно посчитано:
        // записи обновлялись vs серверы реально ответили.
        return [
            'servers' => $servers,
            'tools' => (int) ($row['tools'] ?? 0),
            'records_updated' => $recent,
            'healthy_verified' => $healthy,
            'categories' => count(self::CATEGORIES),
            // Старые имена — для уже написанных вьюх и внешних потребителей.
            'operational' => $healthy,
            'fresh' => $recent,
        ];
    }

    /** Сколько карточек в каждой категории (для плиток и фильтров). */
    public static function facets()
    {
        $out = ['categories' => [], 'transport' => [], 'auth' => [], 'health' => []];

        foreach (self::_query(
            "SELECT c AS key, count(*) AS n
               FROM nj_676_servers,
                    jsonb_array_elements_text(CASE WHEN jsonb_typeof(categories) = 'array' THEN categories ELSE '[]'::jsonb END) AS c
              WHERE is_published = TRUE GROUP BY c ORDER BY n DESC"
        )->as_array() as $row)
        {
            $key = (string) ($row['key'] ?? '');
            if (isset(self::CATEGORIES[$key]))
            {
                $out['categories'][$key] = (int) ($row['n'] ?? 0);
            }
        }

        foreach (['transport' => 'transport', 'auth_mode' => 'auth', 'health_status' => 'health'] as $column => $bucket)
        {
            foreach (self::_query(
                'SELECT '.$column.' AS key, count(*) AS n FROM nj_676_servers
                  WHERE is_published = TRUE GROUP BY '.$column
            )->as_array() as $row)
            {
                $key = (string) ($row['key'] ?? '');
                if ($key !== '')
                {
                    $out[$bucket][$key] = (int) ($row['n'] ?? 0);
                }
            }
        }

        return $out;
    }

    /**
     * Витрина «выбор редакции»: карточки, которые владелец отметил вручную,
     * и, если таких мало, лучшие по доверию удалённые серверы.
     */
    public static function featured($limit = 3)
    {
        $rows = self::_query(
            'SELECT '.self::_columns().' FROM nj_676_servers
              WHERE is_published = TRUE AND featured = TRUE
              ORDER BY trust DESC, stars DESC, name ASC LIMIT '.max(1, min(12, (int) $limit))
        )->as_array();

        if (count($rows) < $limit)
        {
            $exclude = array_map(static function ($row) { return (string) $row['slug']; }, $rows);
            $params = [];
            $where = '';

            if ($exclude !== [])
            {
                $placeholders = [];
                foreach (array_values($exclude) as $i => $slug)
                {
                    $placeholders[] = ':x'.$i;
                    $params[':x'.$i] = $slug;
                }
                $where = ' AND slug NOT IN ('.implode(', ', $placeholders).')';
            }

            $more = self::_query(
                'SELECT '.self::_columns().' FROM nj_676_servers
                  WHERE is_published = TRUE AND remote_url <> \'\''.$where.'
                  ORDER BY trust DESC, stars DESC, name ASC LIMIT '.max(1, (int) $limit - count($rows)),
                $params
            )->as_array();

            $rows = array_merge($rows, $more);
        }

        return array_map([__CLASS__, 'shape'], $rows);
    }

    /**
     * Серверы, которые прямо сейчас ответили на tools/list.
     *
     * Один источник правды: тот же `readiness()`, что у `hub_ready` и витрины.
     * Раньше здесь был отдельный SQL с условиями «health operational + свежая
     * проверка», и он не знал ни про `auth_required_at` (temporal-mcp висел в
     * «Отвечают прямо сейчас», хотя вызов возвращал AUTH_REQUIRED), ни про
     * ключ, который сервер требует по факту. Три способа считать готовность —
     * три разных числа; теперь способ один.
     */
    public static function live_now($limit = 8)
    {
        $out = [];

        foreach (self::_ready_all() as $server)
        {
            // Виджет обещает «вызов без ключа», поэтому серверы с ключом сюда
            // не попадают: обещание должно совпадать с тем, что будет на вызове.
            if ( ! in_array((string) $server['auth'], ['none', 'unknown'], TRUE)
                AND (string) $server['auth'] !== 'optional')
            {
                continue;
            }

            if (trim((string) ($server['remote_url'] ?? '')) === '')
            {
                continue;
            }

            $out[] = $server;
        }

        return array_slice($out, 0, max(1, min(24, (int) $limit)));
    }

    /**
     * Какие удалённые серверы пора проверить.
     *
     * Сначала те, что ни разу не отвечали, затем самые «залежавшиеся».
     * Возвращаем только slug и адрес: больше воркеру ничего не нужно.
     *
     * @return array<int, array{slug:string,url:string}>
     */
    public static function probe_targets($limit = 24, array $exclude = [])
    {
        // Исключения — «уже проверены этим прогоном»: без них вторая порция
        // повторяла бы первую, потому что порядок выбирает самые давние.
        $params = [];
        $only = '';

        $clean = [];
        foreach (array_slice($exclude, 0, 300) as $slug)
        {
            $slug = trim((string) $slug);

            if ($slug !== '')
            {
                $clean[] = $slug;
            }
        }

        if ($clean !== [])
        {
            $placeholders = [];
            foreach (array_values($clean) as $i => $slug)
            {
                $placeholders[] = ':x'.$i;
                $params[':x'.$i] = $slug;
            }

            $only = ' AND slug NOT IN ('.implode(', ', $placeholders).')';
        }

        $rows = self::_query(
            'SELECT slug, remote_url FROM nj_676_servers
              WHERE is_published = TRUE AND remote_url <> \'\'
                AND url_valid IS NOT FALSE
                AND NOT (COALESCE(probe_failures, 0) >= 10
                         AND health_checked_at IS NOT NULL
                         AND health_checked_at > now() - interval \'30 days\')'.$only.'
              ORDER BY (health_checked_at IS NULL) DESC,
                       (COALESCE(probe_failures, 0) >= 10) ASC,
                       health_checked_at ASC NULLS FIRST,
                       trust DESC
              LIMIT '.max(1, min(60, (int) $limit)),
            $params
        )->as_array();

        $out = [];
        foreach ($rows as $row)
        {
            $url = trim((string) ($row['remote_url'] ?? ''));

            if ($url === '' OR stripos($url, 'https://') !== 0)
            {
                continue;
            }

            $out[] = ['slug' => (string) $row['slug'], 'url' => $url];
        }

        return $out;
    }

    /**
     * Сколько серверов ждут живой проверки.
     *
     * Кнопка «Проверить удалённые» берёт порцию (24–60) и на тысяче карточек
     * молчит о том, сколько ещё осталось: владелец не может понять, догнал он
     * каталог или нет. Счётчики отвечают на это одним запросом.
     *
     * @return array{remote:int,never:int,stale:int,fresh:int,days:int}
     */
    public static function probe_backlog()
    {
        $days = (int) getenv('NJ676_STALE_DAYS');
        $days = $days > 0 ? min(365, $days) : 7;

        $out = ['remote' => 0, 'never' => 0, 'stale' => 0, 'fresh' => 0, 'quarantined' => 0, 'days' => $days];

        try
        {
            $row = self::_query(
                "SELECT count(*) AS remote,
                        count(*) FILTER (WHERE health_checked_at IS NULL) AS never,
                        count(*) FILTER (WHERE health_checked_at IS NOT NULL
                                          AND health_checked_at < now() - (:days || ' days')::interval) AS stale,
                        count(*) FILTER (WHERE health_checked_at IS NOT NULL
                                          AND health_checked_at >= now() - (:days || ' days')::interval) AS fresh,
                        count(*) FILTER (WHERE COALESCE(probe_failures, 0) >= 10) AS quarantined
                   FROM nj_676_servers
                  WHERE is_published = TRUE
                    AND remote_url <> ''
                    AND remote_url ILIKE 'https://%'",
                [':days' => (string) $days]
            )->current();
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::WARNING, 'nj676: очередь проверок не посчитана: :e', [':e' => $e->getMessage()]);

            return $out;
        }

        if ($row)
        {
            $row = (array) $row;

            $out['remote'] = (int) ($row['remote'] ?? 0);
            $out['never'] = (int) ($row['never'] ?? 0);
            $out['stale'] = (int) ($row['stale'] ?? 0);
            $out['fresh'] = (int) ($row['fresh'] ?? 0);
            $out['quarantined'] = (int) ($row['quarantined'] ?? 0);
        }

        return $out;
    }

    /**
     * Поставить опрос MCP-эндпоинтов (initialize + tools/list) одной или несколькими пачками по 60.
     *
     * Одна задача воркера ограничена 60 серверами (NJ676_PROBE_MAX = 60), чтобы
     * уложиться в таймаут очереди. Когда нужно опросить больше 60 за раз (120,
     * 240, 300), режем выборку на непересекающиеся порции через $exclude в
     * probe_targets(): каждая задача получает свой срез сразу, не дожидаясь
     * завершения предыдущей.
     *
     * @param  int $total_limit сколько эндпоинтов опросить суммарно (1..300)
     * @param  int $user_id     кто запустил (0 — расписание/тик)
     * @return array{queued:int,batches:int,jobs:array<int,int>,runs:array<int,int>,run_id:int,error:string}
     */
    public static function queue_probe($total_limit = 60, $user_id = 0)
    {
        $total_limit = max(1, min(300, (int) $total_limit));
        $excluded = [];
        $jobs = [];
        $runs = [];
        $error = '';

        while (count($excluded) < $total_limit AND count($jobs) < 5)
        {
            $chunk_size = min(60, $total_limit - count($excluded));
            $targets = self::probe_targets($chunk_size, $excluded);

            if ($targets === [])
            {
                break;
            }

            foreach ($targets as $t)
            {
                $excluded[] = (string) $t['slug'];
            }

            $run = self::create_run('проверка удалённых серверов', 0, (int) $user_id, 'probe');
            $run_id = (int) ($run['id'] ?? 0);

            try
            {
                $job_id = (int) Service_Nj120_JobQueue::push('nj676.probe', [
                    'run_id' => $run_id,
                    'targets' => $targets,
                ], [
                    'module' => 'nj676',
                    'timeout_sec' => 900,
                    'created_by' => (int) $user_id,
                    'priority' => 100,
                ]);

                if ($run_id > 0)
                {
                    self::set_run_job($run_id, $job_id);
                    $runs[] = $run_id;
                }
                $jobs[] = $job_id;
            }
            catch (Throwable $e)
            {
                $error = $e->getMessage();
                if ($run_id > 0)
                {
                    self::finish_run($run_id, ['status' => 'fail', 'error' => $error]);
                }
                break;
            }
        }

        return [
            'queued' => count($excluded),
            'batches' => count($jobs),
            'jobs' => $jobs,
            'runs' => $runs,
            'run_id' => (int) (end($runs) ?: 0),
            'error' => $error,
        ];
    }

    /**
     * Авто-продолжение опроса эндпоинтов после завершения прогона nj676.probe.
     *
     * Если в каталоге ещё остались неопрошенные (never > 0) или просроченные
     * (stale > 0) серверы, а активных задач nj676.probe в очереди нет — ставим
     * следующую пачку из 60 адресов (с потолком NJ676_PROBE_CHAIN_CAP пачек в час).
     *
     * @return int сколько эндпоинтов поставлено в продолжение
     */
    public static function queue_probe_continuation()
    {
        if ((string) getenv('NJ676_AUTO_PROBE') === '0')
        {
            return 0;
        }

        $backlog = self::probe_backlog();
        if ((int) ($backlog['never'] ?? 0) + (int) ($backlog['stale'] ?? 0) <= 0)
        {
            return 0;
        }

        if (class_exists('Service_Nj120_JobQueue'))
        {
            try
            {
                $jobs = Service_Nj120_JobQueue::instance()->channel_jobs('nj676.probe', 8);
                foreach ((array) $jobs as $j)
                {
                    if (in_array((string) ($j['status'] ?? ''), ['pending', 'queued', 'processing', 'running', 'claimed'], TRUE))
                    {
                        return 0;
                    }
                }
            }
            catch (Throwable $e)
            {
                return 0;
            }
        }

        $cap = (int) getenv('NJ676_PROBE_CHAIN_CAP');
        $cap = $cap > 0 ? min(30, $cap) : 10;

        try
        {
            $cache = Cache::instance('file');
            $key = 'nj676_probe_chain_'.date('YmdH');
            $used = (int) $cache->get($key, 0);

            if ($used >= $cap)
            {
                return 0;
            }

            $res = self::queue_probe(60, 0);
            if ((int) ($res['queued'] ?? 0) > 0)
            {
                $cache->set($key, $used + 1, 3600);
            }

            return (int) ($res['queued'] ?? 0);
        }
        catch (Throwable $e)
        {
            return 0;
        }
    }

    /**
     * Комплексное обслуживание каталога одной функцией (для кнопки в админке и для cron).
     *
     * Выполняет за один вызов все операции, чтобы каталог стал работоспособным,
     * без ручного обхода пяти вкладок и десятка кнопок:
     *   1. Чистка старых записей телеметрии вызовов и журнала спроса (prune);
     *   2. Перепроверка JSON Schema и URL всех карточек без обращения к сети;
     *   3. Актуализация из официального реестра MCP (nj676.registry, при with_registry=TRUE);
     *   4. Опрос MCP-эндпоинтов (initialize + tools/list) пачками по 60 с авто-продолжением;
     *   5. Снятие мёртвых клеймов и постановка цепочки перевода на русский (queue_all);
     *   6. Индексирование векторов смысла (nj115) для новых и переведённых карточек;
     *   7. Опционально — добор новых серверов по спросу агентов (при with_discover=TRUE).
     *
     * @param  array $options ['probe_limit' => int, 'user_id' => int, 'with_registry' => bool, 'translate_all' => bool, 'with_semantic' => bool, 'with_discover' => bool]
     * @return array сводка выполненных и поставленных в очередь операций
     */
    public static function maintain(array $options = [])
    {
        $probe_limit = isset($options['probe_limit']) ? max(0, min(300, (int) $options['probe_limit'])) : 180;
        $user_id = (int) ($options['user_id'] ?? 0);
        $with_registry = array_key_exists('with_registry', $options) ? (bool) $options['with_registry'] : TRUE;
        $translate_all = array_key_exists('translate_all', $options) ? (bool) $options['translate_all'] : TRUE;
        $with_semantic = array_key_exists('with_semantic', $options) ? (bool) $options['with_semantic'] : TRUE;
        $with_audit = array_key_exists('with_audit', $options) ? (bool) $options['with_audit'] : TRUE;
        $with_discover = ! empty($options['with_discover']);

        $report = [
            'ok' => TRUE,
            'pruned' => FALSE,
            'registry' => ['run_id' => 0, 'job_id' => 0, 'queued' => FALSE],
            'schemas' => ['checked' => 0, 'broken' => 0, 'urls' => 0],
            'probe' => ['queued' => 0, 'batches' => 0, 'run_id' => 0],
            'audit' => ['queued' => 0, 'run_id' => 0, 'job_id' => 0],
            'translate' => ['queued' => 0, 'jobs' => 0, 'released' => 0, 'job_id' => 0, 'pending' => 0],
            'semantic' => ['queued' => 0, 'skipped' => 0, 'enabled' => FALSE],
            'discover' => ['run_id' => 0, 'theme' => ''],
        ];

        // 1. Чистка журналов телеметрии и спроса.
        try
        {
            Service_Nj676_Telemetry::prune();
            Service_Nj676_Demand::prune();
            $report['pruned'] = TRUE;
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::DEBUG, 'nj676.maintain: чистка журналов не прошла: :e', [':e' => $e->getMessage()]);
        }

        // 2. Быстрая проверка JSON Schema и URL сохранённых карточек (без сети).
        try
        {
            $report['schemas'] = self::revalidate_schemas(2000);
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::DEBUG, 'nj676.maintain: проверка схем не прошла: :e', [':e' => $e->getMessage()]);
        }

        // 3. Актуализация из официального реестра MCP (server.json без LLM).
        if ($with_registry)
        {
            try
            {
                $reg_running = self::latest_run_of_kind('registry');
                $reg_busy = ($reg_running AND $reg_running['status'] === 'running'
                    AND strtotime((string) $reg_running['started_at']) > time() - 3600);

                if ( ! $reg_busy)
                {
                    $reg_run = self::create_run('реестр MCP', 0, $user_id, 'registry');
                    if ( ! empty($reg_run['id']))
                    {
                        $reg_job = (int) Service_Nj120_JobQueue::push('nj676.registry', [
                            'run_id' => (int) $reg_run['id'],
                            'limit' => 2500,
                        ], [
                            'module' => 'nj676',
                            'timeout_sec' => 1800,
                            'created_by' => $user_id,
                            'priority' => 85,
                        ]);
                        self::set_run_job((int) $reg_run['id'], $reg_job);
                        $report['registry'] = [
                            'run_id' => (int) $reg_run['id'],
                            'job_id' => $reg_job,
                            'queued' => TRUE,
                        ];
                    }
                }
            }
            catch (Throwable $e)
            {
                Log_Plain::event(Log::DEBUG, 'nj676.maintain: реестр не поставлен: :e', [':e' => $e->getMessage()]);
            }
        }

        // 4. Опрос MCP-эндпоинтов (initialize + tools/list) пачками по 60.
        if ($probe_limit > 0)
        {
            try
            {
                $report['probe'] = self::queue_probe($probe_limit, $user_id);
            }
            catch (Throwable $e)
            {
                Log_Plain::event(Log::DEBUG, 'nj676.maintain: опрос не поставлен: :e', [':e' => $e->getMessage()]);
            }
        }

        // 4b. Полевое исследование открытых MCP-серверов воркером-агентом (поиск «алмазов»).
        if ($with_audit AND class_exists('Service_Nj676_Audit'))
        {
            try
            {
                $audit_q = Service_Nj676_Audit::queue(Service_Nj676_Audit::DEFAULT_BATCH, '', $user_id);
                $report['audit'] = [
                    'queued' => (int) ($audit_q['queued'] ?? 0),
                    'run_id' => (int) ($audit_q['run_id'] ?? 0),
                    'job_id' => (int) ($audit_q['job_id'] ?? 0),
                ];
            }
            catch (Throwable $e)
            {
                Log_Plain::event(Log::DEBUG, 'nj676.maintain: исследование не поставлено: :e', [':e' => $e->getMessage()]);
            }
        }

        // 5. Перевод карточек на русский (с авто-освобождением мёртвых клеймов и цепочкой задач).
        if ((string) getenv('NJ676_AUTO_TRANSLATE') !== '0')
        {
            try
            {
                $tr_status = Service_Nj676_Translate::status();
                $report['translate']['pending'] = (int) ($tr_status['pending'] ?? 0);

                if ($translate_all)
                {
                    $batch = Service_Nj676_Translate::queue_all();
                    $jobs_cnt = (int) ($batch['count'] ?? 0);
                    $per_job = (int) ($batch['job']['limit'] ?? 40);
                    $report['translate']['jobs'] = $jobs_cnt;
                    $report['translate']['queued'] = $jobs_cnt * $per_job;
                    $report['translate']['released'] = (int) ($batch['released'] ?? 0);
                    $report['translate']['job_id'] = (int) ($batch['jobs'][0] ?? 0);
                }
                else
                {
                    $free = (int) ($tr_status['free'] ?? 0);
                    $active = (int) ($tr_status['queue']['active'] ?? 0);
                    if ($free > 0 AND $active === 0)
                    {
                        $tr_queued = Service_Nj676_Translate::queue(min(40, $free));
                        $report['translate']['jobs'] = (int) ($tr_queued['job_id'] ?? 0) > 0 ? 1 : 0;
                        $report['translate']['queued'] = (int) ($tr_queued['limit'] ?? 0);
                        $report['translate']['job_id'] = (int) ($tr_queued['job_id'] ?? 0);
                    }
                }
            }
            catch (Throwable $e)
            {
                Log_Plain::event(Log::DEBUG, 'nj676.maintain: перевод не поставлен: :e', [':e' => $e->getMessage()]);
            }
        }

        // 6. Догон векторного индекса (nj115).
        if ($with_semantic)
        {
            try
            {
                $report['semantic']['enabled'] = Service_Nj676_Semantic::enabled();
                if ($report['semantic']['enabled'])
                {
                    $idx = Service_Nj676_Semantic::index(500);
                    $report['semantic']['queued'] = (int) ($idx['queued'] ?? 0);
                    $report['semantic']['skipped'] = (int) ($idx['skipped'] ?? 0);
                }
            }
            catch (Throwable $e)
            {
                Log_Plain::event(Log::DEBUG, 'nj676.maintain: индекс не поставлен: :e', [':e' => $e->getMessage()]);
            }
        }

        // 7. Добор карточек по спросу (если включён).
        if ($with_discover)
        {
            try
            {
                $running = self::latest_run_of_kind('discover');
                $busy = ($running AND $running['status'] === 'running'
                    AND strtotime((string) $running['started_at']) > time() - 3600);

                if ( ! $busy)
                {
                    $theme = (string) getenv('NJ676_AUTO_DEMAND') === '0' ? '' : Service_Nj676_Demand::next_theme();
                    $n = (int) getenv('NJ676_AUTO_COUNT');
                    $n = $n > 0 ? min(self::MAX_DISCOVER, $n) : 6;
                    $run = self::create_run($theme, $n, $user_id);
                    if ( ! empty($run['id']))
                    {
                        $job_id = Service_Nj120_JobQueue::push('nj676.discover', [
                            'run_id' => (int) $run['id'],
                            'theme' => $theme,
                            'limit' => $n,
                        ], ['module' => 'nj676', 'timeout_sec' => 900, 'priority' => 120]);
                        self::set_run_job((int) $run['id'], (int) $job_id);
                        $report['discover'] = ['run_id' => (int) $run['id'], 'theme' => $theme];
                    }
                }
            }
            catch (Throwable $e)
            {
                Log_Plain::event(Log::DEBUG, 'nj676.maintain: автосборка не поставлена: :e', [':e' => $e->getMessage()]);
            }
        }

        self::reset_ready_cache();

        return $report;
    }

    /**
     * Найти удалённый сервер по имени инструмента.
     *
     * Нужно для слабых моделей: агент помнит «get_weather», а не slug сервера.
     * Совпадение точное (без учёта регистра); при нескольких — сначала тот, что
     * реально ответил на проверку.
     */
    public static function find_by_tool($tool)
    {
        $tool = mb_strtolower(trim((string) $tool));

        if ($tool === '' OR ! preg_match('/^[a-z0-9_.:-]{1,128}$/', $tool))
        {
            return NULL;
        }

        $row = self::_query(
            'SELECT '.self::_columns().' FROM nj_676_servers
              WHERE is_published = TRUE AND remote_url <> \'\'
                AND EXISTS (
                    SELECT 1 FROM jsonb_array_elements(
                        CASE WHEN jsonb_typeof(tools) = \'array\' THEN tools ELSE \'[]\'::jsonb END
                    ) AS t
                     WHERE lower(t->>\'name\') = :tool
                )
              ORDER BY (health_status = \'operational\') DESC, trust DESC, stars DESC
              LIMIT 1',
            [':tool' => $tool]
        )->current();

        return $row ? self::shape((array) $row) : NULL;
    }

    /** Похожие серверы: общие категории, затем популярность. */
    public static function related($server, $limit = 4)
    {
        $server = self::shape((array) $server);
        $cats = array_values(array_intersect((array) $server['categories'], array_keys(self::CATEGORIES)));

        if ($cats === [])
        {
            return [];
        }

        $params = [':slug' => (string) $server['slug']];
        $match = [];
        foreach ($cats as $i => $category)
        {
            $params[':cat'.$i] = json_encode([$category]);
            $match[] = 'categories @> CAST(:cat'.$i.' AS jsonb)';
        }
        $rows = self::_query(
            'SELECT '.self::_columns().'
               FROM nj_676_servers
              WHERE is_published = TRUE AND slug <> :slug
                AND ('.implode(' OR ', $match).')
              ORDER BY stars DESC, name ASC LIMIT '.max(1, min(12, (int) $limit)),
            $params
        )->as_array();

        return array_map([__CLASS__, 'shape'], $rows);
    }

    /** Счётчик просмотров карточки. Сбой счётчика не должен ломать страницу. */
    public static function record_view($slug)
    {
        try
        {
            self::_query(
                'UPDATE nj_676_servers SET views = views + 1 WHERE slug = :slug',
                [':slug' => (string) $slug]
            );
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::DEBUG, 'nj676: просмотр не учтён: :e', [':e' => $e->getMessage()]);
        }
    }

    /** Карточка по slug; NULL — нет или не опубликована. */
    public static function get($slug, $include_unpublished = FALSE)
    {
        $sql = 'SELECT '.self::_columns().' FROM nj_676_servers WHERE slug = :slug';
        $params = [':slug' => (string) $slug];

        if ( ! $include_unpublished)
        {
            $sql .= ' AND is_published = TRUE';
        }

        $row = self::_query($sql.' LIMIT 1', $params)->current();

        return $row ? self::shape((array) $row) : NULL;
    }

    // ---------------------------------------------------------------------
    // Автосборка: прогоны и приёмка карточек
    // ---------------------------------------------------------------------

    /** Создать прогон автосборки. Возвращает массив записи. */
    /**
     * Создать прогон.
     *
     * @param string   $theme     тематика ('' — общая заметность)
     * @param int      $requested сколько карточек
     * @param int|NULL $user_id   кто запустил (NULL/0 — автоматика)
     * @param string   $kind      discover|probe|registry — тип прогона
     */
    public static function create_run($theme, $requested, $user_id = NULL, $kind = 'discover')
    {
        $theme = mb_substr(trim((string) $theme), 0, 200);
        $requested = self::_int($requested, self::DEFAULT_DISCOVER, 1, self::MAX_DISCOVER);
        $kind = in_array($kind, ['discover', 'probe', 'registry'], TRUE) ? $kind : 'discover';

        $row = self::_query(
            'INSERT INTO nj_676_runs (status, theme, requested, user_id, kind)
             VALUES (\'running\', :theme, '.$requested.', '.($user_id ? (int) $user_id : 'NULL').', :kind)
             RETURNING id, status, theme, requested, started_at, kind',
            [':theme' => $theme, ':kind' => $kind]
        )->current();

        return $row ? (array) $row : [];
    }

    /**
     * Последний прогон указанного типа.
     *
     * Нужен автосборке: проверка живости и импорт реестра тоже пишут строки в
     * журнал, но «идёт сборка» — это только discover.
     */
    public static function latest_run_of_kind($kind = 'discover')
    {
        $row = self::_query(
            'SELECT * FROM nj_676_runs WHERE kind = :kind ORDER BY id DESC LIMIT 1',
            [':kind' => (string) $kind]
        )->current();

        return $row ? (array) $row : NULL;
    }

    public static function set_run_job($run_id, $job_id)
    {
        self::_query(
            'UPDATE nj_676_runs SET job_id = :job WHERE id = :id',
            [':job' => (int) $job_id, ':id' => (int) $run_id]
        );
    }

    public static function set_progress($run_id, $text)
    {
        self::_query(
            'UPDATE nj_676_runs SET progress = :p WHERE id = :id AND status = \'running\'',
            [':p' => mb_substr(trim((string) $text), 0, 500), ':id' => (int) $run_id]
        );
    }

    public static function run($run_id)
    {
        $row = self::_query(
            'SELECT * FROM nj_676_runs WHERE id = :id LIMIT 1',
            [':id' => (int) $run_id]
        )->current();

        return $row ? (array) $row : NULL;
    }

    /** Последний прогон с указанной темой (нужен расписанию проверок). */
    public static function latest_run_by_theme($theme)
    {
        $row = self::_query(
            'SELECT * FROM nj_676_runs WHERE theme = :theme ORDER BY id DESC LIMIT 1',
            [':theme' => mb_substr(trim((string) $theme), 0, 200)]
        )->current();

        return $row ? (array) $row : NULL;
    }

    public static function latest_run()
    {
        $row = self::_query('SELECT * FROM nj_676_runs ORDER BY id DESC LIMIT 1')->current();

        return $row ? (array) $row : NULL;
    }

    public static function runs($limit = 8)
    {
        return self::_query(
            'SELECT * FROM nj_676_runs ORDER BY id DESC LIMIT '.max(1, min(50, (int) $limit))
        )->as_array();
    }

    /** Закрыть прогон. Идемпотентно: закрытый прогон не перезаписывается. */
    public static function finish_run($run_id, array $data)
    {
        $status = (string) ($data['status'] ?? 'done');
        if ( ! in_array($status, ['done', 'fail', 'cancelled'], TRUE))
        {
            $status = 'done';
        }

        self::_query(
            'UPDATE nj_676_runs
                SET status = :status,
                    found = GREATEST(found, :found),
                    added = GREATEST(added, :added),
                    updated = GREATEST(updated, :updated),
                    skipped = GREATEST(skipped, :skipped),
                    probed = GREATEST(probed, :probed),
                    error = :error,
                    progress = :progress,
                    finished_at = now()
              WHERE id = :id AND status = \'running\'',
            [
                ':status' => $status,
                ':found' => self::_int($data['found'] ?? 0, 0, 0, 10000),
                ':added' => self::_int($data['added'] ?? 0, 0, 0, 10000),
                ':updated' => self::_int($data['updated'] ?? 0, 0, 0, 10000),
                ':skipped' => self::_int($data['skipped'] ?? 0, 0, 0, 10000),
                ':probed' => self::_int($data['probed'] ?? 0, 0, 0, 10000),
                ':error' => mb_substr(trim((string) ($data['error'] ?? '')), 0, 2000),
                ':progress' => mb_substr(trim((string) ($data['progress'] ?? '')), 0, 500),
                ':id' => (int) $run_id,
            ]
        );

        return self::run($run_id);
    }

    /**
     * Какие из предложенных репозиториев уже есть в каталоге.
     *
     * Воркер присылает owner/repo, получает разбивку new/known, чтобы не
     * тратить LLM на то, что уже собрано, и обновить только метаданные.
     */
    public static function known_slugs(array $repos)
    {
        $slugs = [];
        foreach (array_slice($repos, 0, 200) as $repo)
        {
            $slug = self::slug_from_repo((string) $repo);
            if ($slug !== '')
            {
                $slugs[$slug] = (string) $repo;
            }
        }

        if ($slugs === [])
        {
            return ['known' => [], 'new' => []];
        }

        $params = [];
        $i = 0;
        foreach (array_keys($slugs) as $slug)
        {
            $params[':s'.$i] = $slug;
            $i++;
        }

        $found = [];
        foreach (self::_query(
            'SELECT slug FROM nj_676_servers WHERE slug IN ('.implode(', ', array_keys($params)).')',
            $params
        )->as_array() as $row)
        {
            $found[(string) $row['slug']] = TRUE;
        }

        $known = [];
        $new = [];
        foreach ($slugs as $slug => $repo)
        {
            if (isset($found[$slug]))
            {
                $known[] = $repo;
            }
            else
            {
                $new[] = $repo;
            }
        }

        return ['known' => $known, 'new' => $new];
    }

    /**
     * Строка журнала прогона: что именно произошло с одной карточкой.
     *
     * Журнал вспомогательный по отношению к каталогу: его недоступность (нет
     * таблицы до миграции, ошибка прав) не имеет права ломать сборку.
     */
    public static function record_run_item($run_id, array $item, $status, $reason = '')
    {
        $status = in_array($status, ['added', 'updated', 'skipped'], TRUE) ? $status : 'skipped';

        try
        {
            self::_query(
                'INSERT INTO nj_676_run_items (run_id, slug, name, status, reason, repo_url, stars)
                 VALUES (:run_id, :slug, :name, :status, :reason, :repo, :stars)',
                [
                    ':run_id' => max(0, (int) $run_id),
                    ':slug' => mb_substr(trim((string) ($item['slug'] ?? '')), 0, 100),
                    ':name' => mb_substr(trim((string) ($item['name'] ?? '')), 0, 200),
                    ':status' => $status,
                    ':reason' => mb_substr(trim((string) $reason), 0, 4000),
                    ':repo' => mb_substr(trim((string) ($item['repo_url'] ?? '')), 0, 500),
                    ':stars' => max(0, (int) ($item['stars'] ?? 0)),
                ]
            );
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::DEBUG, 'nj676: строка журнала прогона не записана: :e', [':e' => $e->getMessage()]);
        }
    }

    /**
     * Журнал прогона для страницы отчёта.
     *
     * Если строк нет (прогон был до миграции 012), восстанавливаем список по
     * карточкам: `nj_676_servers.run_id` хранит, какой прогон последним трогал
     * запись. Точный статус «добавлено/обновлено» берём по `created_at`
     * относительно старта прогона — приблизительно, и об этом честно сказано
     * в интерфейсе (`reconstructed`).
     *
     * @return array{items:array<int,array<string,mixed>>,reconstructed:bool}
     */
    public static function run_items($run)
    {
        $run = is_array($run) ? $run : self::run((int) $run);
        $run_id = (int) ($run['id'] ?? 0);

        if ($run_id <= 0)
        {
            return ['items' => [], 'reconstructed' => FALSE];
        }

        try
        {
            $rows = self::_query(
                'SELECT slug, name, status, reason, repo_url, stars, created_at
                   FROM nj_676_run_items
                  WHERE run_id = :id
                  ORDER BY id ASC
                  LIMIT 500',
                [':id' => $run_id]
            )->as_array();

            if ($rows !== [])
            {
                return ['items' => array_map(static function ($row) {
                    return (array) $row;
                }, $rows), 'reconstructed' => FALSE];
            }
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::DEBUG, 'nj676: журнал прогона не прочитан: :e', [':e' => $e->getMessage()]);
        }

        // Восстановление для старых прогонов: карточки, которые писал этот прогон.
        try
        {
            $started = trim((string) ($run['started_at'] ?? ''));
            $rows = self::_query(
                "SELECT slug, name, repo_url, stars, created_at,
                        CASE WHEN :started = ''
                                  OR created_at >= (CAST(:started AS timestamptz) - interval '5 minutes')
                             THEN 'added' ELSE 'updated' END AS status
                   FROM nj_676_servers
                  WHERE run_id = :id
                  ORDER BY id ASC
                  LIMIT 500",
                [':id' => $run_id, ':started' => $started]
            )->as_array();
        }
        catch (Throwable $e)
        {
            return ['items' => [], 'reconstructed' => TRUE];
        }

        return ['items' => array_map(static function ($row) {
            $row = (array) $row;

            return $row + ['reason' => 'восстановлено по карточке: прогон был до журнала строк'];
        }, $rows), 'reconstructed' => TRUE];
    }

    /**
     * Принять одну карточку от воркера. Пишет только разрешённые поля,
     * не трогает ручную модерацию и не публикует приватные адреса.
     *
     * @return array{status: string, slug: string, message: string}
     */
    public static function apply_discovery_item(array $item, $run_id = 0)
    {
        [$error, $record] = self::normalize_discovery($item);

        if ($error !== '')
        {
            self::record_run_item($run_id, [
                'slug' => self::slug_from_repo($item['repo_url'] ?? ''),
                'name' => $item['name'] ?? '',
                'repo_url' => $item['repo_url'] ?? '',
            ], 'skipped', $error);

            return ['status' => 'skipped', 'slug' => '', 'message' => $error];
        }

        $record['run_id'] = (int) $run_id;
        $record['slug'] = self::_unique_slug($record['slug'], $record['repo_url'], $record['owner_login']);

        // Адрес проверяем ДО публикации: «вызов сразу» с нерабочей ссылкой —
        // худший ответ агента (реальный тест: ai-bittlebits-bittlebits).
        [$url_valid, $url_note] = self::endpoint_check($record['remote_url']);

        if ($url_valid === FALSE)
        {
            $record['health_status'] = 'offline';
            $record['health_note'] = $url_note;
            $record['trust'] = max(0, min(100, (int) $record['trust'] - 20));
        }

        $exists = self::_query(
            'SELECT id, origin FROM nj_676_servers WHERE slug = :slug LIMIT 1',
            [':slug' => $record['slug']]
        )->current();

        $auto_publish = (string) getenv('NJ676_AUTO_PUBLISH') !== '0';

        $bindings = [
            ':slug' => $record['slug'],
            ':name' => $record['name'],
            ':description' => $record['description'],
            ':name_ru' => $record['name_ru'],
            ':description_ru' => $record['description_ru'],
            ':ru_source' => $record['ru_source'],
            // Второй биндинг с тем же значением: драйверы PostgreSQL не дают
            // подставить один именованный параметр дважды в одном запросе.
            ':ru_source2' => $record['ru_source'],
            ':repo' => $record['repo_url'],
            ':homepage' => $record['homepage_url'],
            ':source' => $record['source'],
            ':transport' => $record['transport'],
            ':auth' => $record['auth_mode'],
            ':categories' => json_encode($record['categories'], JSON_UNESCAPED_UNICODE),
            ':health' => $record['health_status'],
            ':health_note' => $record['health_note'],
            ':topics' => json_encode($record['topics'], JSON_UNESCAPED_UNICODE),
            ':highlights' => json_encode($record['highlights'], JSON_UNESCAPED_UNICODE),
            ':use_cases' => json_encode($record['use_cases'], JSON_UNESCAPED_UNICODE),
            ':packages' => json_encode($record['packages'], JSON_UNESCAPED_UNICODE),
            ':env_keys' => json_encode($record['env_keys'], JSON_UNESCAPED_UNICODE),
            ':tools' => json_encode($record['tools'], JSON_UNESCAPED_UNICODE),
            ':remote_url' => $record['remote_url'],
            ':url_valid' => $url_valid,
            ':url_note' => mb_substr((string) $url_note, 0, 200),
            ':remote_kind' => $record['remote_kind'],
            ':stars' => $record['stars'],
            ':forks' => $record['forks'],
            ':language' => $record['language'],
            ':license' => $record['license'],
            ':owner' => $record['owner_login'],
            ':github_id' => $record['github_id'],
            ':trust' => $record['trust'],
            ':pushed_at' => $record['repo_pushed_at'] !== '' ? $record['repo_pushed_at'] : NULL,
            ':archived' => $record['repo_archived'],
            ':latency' => $record['latency_ms'],
            ':run_id' => $record['run_id'],
            ':search_text' => self::search_text($record),
            ':published' => $auto_publish,
        ];

        $sql = 'INSERT INTO nj_676_servers
                (slug, name, description, name_ru, description_ru, ru_source, ru_at,
                 repo_url, homepage_url, source, transport, auth_mode,
                 categories, health_status, health_checked_at, health_note, topics, highlights,
                 use_cases, packages, env_keys, tools, remote_url, remote_kind, stars, forks,
                 language, license, owner_login, github_id, trust, repo_pushed_at, repo_archived,
                 latency_ms, run_id, search_text, origin, is_published, security_report,
                 url_valid, url_note, updated_at)
                VALUES
                (:slug, :name, :description, :name_ru, :description_ru, :ru_source,
                 CASE WHEN :ru_source2 = \'\' THEN NULL ELSE now() END,
                 :repo, :homepage, :source, :transport, :auth,
                 CAST(:categories AS jsonb), :health, now(), :health_note,
                 CAST(:topics AS jsonb), CAST(:highlights AS jsonb), CAST(:use_cases AS jsonb),
                 CAST(:packages AS jsonb), CAST(:env_keys AS jsonb), CAST(:tools AS jsonb),
                 :remote_url, :remote_kind, :stars, :forks, :language, :license, :owner, :github_id,
                 :trust, :pushed_at, :archived, :latency, :run_id, :search_text, \'auto\', :published,
                 CAST(:security AS jsonb), :url_valid, :url_note, now())
                ON CONFLICT (slug) DO UPDATE SET
                    name = CASE WHEN nj_676_servers.origin = \'manual\'
                                THEN nj_676_servers.name ELSE EXCLUDED.name END,
                    -- Пустое описание в обновлении (воркер присылает только
                    -- метаданные) не стирает уже собранный текст: иначе ночное
                    -- обновление звёзд затирало бы русское описание модели.
                    description = CASE
                        WHEN nj_676_servers.origin = \'manual\' THEN nj_676_servers.description
                        WHEN EXCLUDED.description = \'\' THEN nj_676_servers.description
                        ELSE EXCLUDED.description END,
                    -- Перевод редакции важнее машинного: ручной текст не
                    -- перезаписываем, машинный обновляем вместе с оригиналом.
                    name_ru = CASE
                        WHEN nj_676_servers.ru_source = \'manual\' THEN nj_676_servers.name_ru
                        WHEN EXCLUDED.name_ru <> \'\' THEN EXCLUDED.name_ru
                        ELSE nj_676_servers.name_ru END,
                    description_ru = CASE
                        WHEN nj_676_servers.ru_source = \'manual\' THEN nj_676_servers.description_ru
                        WHEN EXCLUDED.description_ru <> \'\' THEN EXCLUDED.description_ru
                        ELSE nj_676_servers.description_ru END,
                    ru_source = CASE
                        WHEN nj_676_servers.ru_source = \'manual\' THEN \'manual\'
                        WHEN EXCLUDED.name_ru <> \'\' OR EXCLUDED.description_ru <> \'\' THEN EXCLUDED.ru_source
                        ELSE nj_676_servers.ru_source END,
                    ru_at = CASE
                        WHEN nj_676_servers.ru_source = \'manual\' THEN nj_676_servers.ru_at
                        WHEN EXCLUDED.name_ru <> \'\' OR EXCLUDED.description_ru <> \'\' THEN now()
                        ELSE nj_676_servers.ru_at END,
                    homepage_url = COALESCE(NULLIF(EXCLUDED.homepage_url, \'\'), nj_676_servers.homepage_url),
                    source = EXCLUDED.source,
                    transport = CASE WHEN EXCLUDED.transport = \'unknown\'
                                     THEN nj_676_servers.transport ELSE EXCLUDED.transport END,
                    auth_mode = CASE WHEN EXCLUDED.auth_mode = \'unknown\'
                                     THEN nj_676_servers.auth_mode ELSE EXCLUDED.auth_mode END,
                    categories = CASE WHEN jsonb_array_length(EXCLUDED.categories) = 0
                                      THEN nj_676_servers.categories ELSE EXCLUDED.categories END,
                    health_status = CASE
                        WHEN EXCLUDED.url_valid IS FALSE THEN \'offline\'
                        WHEN nj_676_servers.health_status = \'unverified\' THEN EXCLUDED.health_status
                        ELSE nj_676_servers.health_status END,
                    health_checked_at = CASE WHEN nj_676_servers.health_status = \'unverified\'
                                             THEN EXCLUDED.health_checked_at ELSE nj_676_servers.health_checked_at END,
                    health_note = CASE
                        WHEN EXCLUDED.url_valid IS FALSE THEN EXCLUDED.url_note
                        WHEN nj_676_servers.health_status = \'unverified\' THEN EXCLUDED.health_note
                        ELSE nj_676_servers.health_note END,
                    url_valid = EXCLUDED.url_valid,
                    url_note = EXCLUDED.url_note,
                    latency_ms = COALESCE(EXCLUDED.latency_ms, nj_676_servers.latency_ms),
                    topics = EXCLUDED.topics,
                    highlights = CASE WHEN jsonb_array_length(EXCLUDED.highlights) = 0
                                      THEN nj_676_servers.highlights ELSE EXCLUDED.highlights END,
                    use_cases = CASE WHEN jsonb_array_length(EXCLUDED.use_cases) = 0
                                     THEN nj_676_servers.use_cases ELSE EXCLUDED.use_cases END,
                    packages = CASE WHEN jsonb_array_length(EXCLUDED.packages) = 0
                                    THEN nj_676_servers.packages ELSE EXCLUDED.packages END,
                    env_keys = CASE WHEN jsonb_array_length(EXCLUDED.env_keys) = 0
                                    THEN nj_676_servers.env_keys ELSE EXCLUDED.env_keys END,
                    tools = CASE WHEN jsonb_array_length(EXCLUDED.tools) = 0
                                 THEN nj_676_servers.tools ELSE EXCLUDED.tools END,
                    remote_url = COALESCE(NULLIF(EXCLUDED.remote_url, \'\'), nj_676_servers.remote_url),
                    remote_kind = COALESCE(NULLIF(EXCLUDED.remote_kind, \'\'), nj_676_servers.remote_kind),
                    stars = EXCLUDED.stars, forks = EXCLUDED.forks,
                    language = EXCLUDED.language, license = EXCLUDED.license,
                    owner_login = EXCLUDED.owner_login, github_id = EXCLUDED.github_id,
                    trust = EXCLUDED.trust, repo_pushed_at = EXCLUDED.repo_pushed_at,
                    repo_archived = EXCLUDED.repo_archived,
                    run_id = EXCLUDED.run_id, search_text = EXCLUDED.search_text,
                    security_report = COALESCE(EXCLUDED.security_report, nj_676_servers.security_report),
                    updated_at = now()
                RETURNING slug, origin';

        $bindings[':security'] = $record['tools'] === []
            ? NULL
            : json_encode(self::_guard($record['tools']), JSON_UNESCAPED_UNICODE);

        $row = self::_query($sql, $bindings)->current();
        $origin = $row ? (string) $row['origin'] : 'auto';

        $status = $exists ? 'updated' : 'added';
        $reason = '';

        if ($url_valid === FALSE)
        {
            $reason = 'адрес не прошёл проверку: '.trim((string) $url_note);
        }
        elseif ($origin === 'manual')
        {
            $reason = 'карточка под ручной модерацией — тексты сохранены';
        }

        self::record_run_item($run_id, [
            'slug' => (string) $record['slug'],
            'name' => (string) $record['name'],
            'repo_url' => (string) $record['repo_url'],
            'stars' => (int) $record['stars'],
        ], $status, $reason);

        self::reset_ready_cache();
        if (class_exists('Service_Nj676_Semantic'))
        {
            try
            {
                Service_Nj676_Semantic::index_slugs([(string) $record['slug']]);
            }
            catch (Throwable $e) {}
        }

        return [
            'status' => $status,
            'slug' => (string) $record['slug'],
            'message' => $origin === 'manual' ? 'Карточка была под ручной модерацией — тексты сохранены.' : '',
        ];
    }

    /**
     * Слаг не должен «украсть» чужую карточку.
     *
     * Slug строится из имени репозитория, а имена повторяются (mcp-server,
     * weather-mcp). Если по этому слагу уже лежит ДРУГОЙ репозиторий —
     * добавляем владельца, затем номер. Свой же репозиторий обновляем как есть.
     */
    protected static function _unique_slug($slug, $repo_url, $owner = '')
    {
        $slug = (string) $slug;
        $candidates = [$slug];

        $owner_slug = preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $owner));
        $owner_slug = trim((string) $owner_slug, '-');

        if ($owner_slug !== '')
        {
            $candidates[] = mb_substr($slug.'-'.$owner_slug, 0, 100);
        }

        for ($i = 2; $i <= 5; $i++)
        {
            $candidates[] = mb_substr($slug.'-'.$i, 0, 100);
        }

        foreach ($candidates as $candidate)
        {
            if (mb_strlen($candidate) < 2)
            {
                continue;
            }

            $row = self::_query(
                'SELECT repo_url FROM nj_676_servers WHERE slug = :slug LIMIT 1',
                [':slug' => $candidate]
            )->current();

            if ( ! $row)
            {
                return $candidate;
            }

            if (rtrim(strtolower((string) $row['repo_url']), '/') === rtrim(strtolower((string) $repo_url), '/'))
            {
                return $candidate;
            }
        }

        return $candidates[count($candidates) - 1];
    }

    /** Записать результат живой проверки удалённого сервера. */
    public static function apply_probe($slug, $ok, $latency_ms = 0, $error = '', $tools = [])
    {
        $server = self::get($slug, TRUE);

        if ( ! $server)
        {
            return ['status' => 'skipped', 'message' => 'Карточки нет'];
        }

        // Адрес перепроверяем при каждой живой проверке: он мог быть добавлен
        // до валидатора или измениться в карточке реестра.
        [$url_valid, $url_note] = self::endpoint_check($server['remote_url'] ?? '');

        // Одна неудача — «сбоит», две подряд — «недоступен». Иначе случайный
        // DNS-таймаут выключал бы живой сервер из выдачи до следующего прогона.
        $failures = (int) ($server['probe_failures'] ?? 0);
        $failures = $ok ? 0 : min(32000, $failures + 1);

        $status = 'operational';
        if ( ! $ok)
        {
            $status = $failures >= 2 ? 'offline' : 'degraded';
        }

        $note = $ok
            ? 'Ответил на tools/list за '.((int) $latency_ms).' мс'
            : mb_substr(trim((string) $error), 0, 300);

        if ($url_valid === FALSE)
        {
            // Нерабочий адрес не «сбоит иногда» — он неверный. Две неудачи тут
            // не нужны: вызов невозможен в принципе.
            $status = 'offline';
            $note = $url_note !== '' ? $url_note : 'адрес не проходит проверку безопасности';
            $failures = max(2, $failures);
        }
        elseif ($ok AND $tools === [])
        {
            // Пустой tools/list при живом сервере — почти всегда сбой пробы
            // (таймаут на вложенном запросе, CDN отдал 204), а не «инструментов
            // нет». Прошлый снимок не выбрасываем: иначе одна неудачная
            // проверка скрывает рабочий сервер от всех агентов.
            $known_tools = is_array($server['tools'] ?? NULL) ? count($server['tools']) : 0;

            if ($known_tools > 0)
            {
                $status = 'operational';
                $note = 'ответил, но список инструментов не пришёл — оставлен прошлый снимок ('
                    .$known_tools.' шт.)';
            }
            else
            {
                $status = 'degraded';
                $note = 'сервер ответил, но tools/list пуст: инструментов нет';
            }
        }

        $params = [
            ':health' => $status,
            ':note' => $note,
            ':latency' => $latency_ms > 0 ? (int) $latency_ms : NULL,
            ':failures' => $failures,
            ':url_valid' => $url_valid,
            ':url_note' => mb_substr($url_note, 0, 200),
            ':slug' => (string) $slug,
        ];

        $sets = 'health_status = :health, health_checked_at = now(), health_note = :note,
                 latency_ms = :latency, probe_failures = :failures,
                 url_valid = :url_valid, url_note = :url_note';

        $new_tools_indexed = FALSE;
        if ($tools !== [])
        {
            [$tools_error, $normalized] = self::normalize_tools_json(json_encode([
                'tools' => array_slice($tools, 0, self::MAX_TOOLS),
            ], JSON_UNESCAPED_UNICODE));

            if ($tools_error === '' AND $normalized !== [])
            {
                $prev_count = is_array($server['tools'] ?? NULL) ? count($server['tools']) : 0;
                $sets .= ', tools = CAST(:tools AS jsonb), search_text = :search_text';
                $params[':tools'] = json_encode($normalized, JSON_UNESCAPED_UNICODE);
                $updated_record = $server;
                $updated_record['tools'] = $normalized;
                $params[':search_text'] = self::search_text($updated_record);
                $new_tools_indexed = ($prev_count !== count($normalized));

                // Схемы проверяем на приёмке: снимок tools/list попадает в
                // каталог только вместе с вердиктом. Один `properties: []`
                // отключает у строгих клиентов ВСЕ инструменты шлюза.
                $schema = self::validate_tool_schemas($normalized);
                $sets .= ', schema_valid = :schema_valid, schema_note = :schema_note';
                $params[':schema_valid'] = $schema['ok'];
                $params[':schema_note'] = mb_substr((string) $schema['note'], 0, 200);
            }
        }

        self::_query(
            'UPDATE nj_676_servers SET '.$sets.', updated_at = now() WHERE slug = :slug',
            $params
        );

        if ($new_tools_indexed AND class_exists('Service_Nj676_Semantic'))
        {
            try
            {
                Service_Nj676_Semantic::index_slugs([(string) $slug]);
            }
            catch (Throwable $e) {}
        }

        self::reset_ready_cache();

        return ['status' => 'probed', 'health' => $status];
    }

    // ---------------------------------------------------------------------
    // Ручная модерация (админка)
    // ---------------------------------------------------------------------

    /** Валидирует и сохраняет ручную карточку. Вызов только из admin controller. */
    public static function save($input)
    {
        $raw_input = (array) $input;
        [$error, $record] = self::validate_record($raw_input);

        if ($error !== '')
        {
            return [$error, NULL];
        }

        $report = NULL;
        if ($record['tools'] !== [] AND class_exists('Service_Nj674_McpGuard'))
        {
            $report = Service_Nj674_McpGuard::analyze(json_encode(['tools' => $record['tools']]));
            if ( ! empty($report['error']))
            {
                return ['Не удалось провести статическую проверку описаний tools.', NULL];
            }
        }

        $status = $record['health_status'];
        $checked_at = $status === 'unverified' ? NULL : date('Y-m-d H:i:s');
        $has_ru = (array_key_exists('name_ru', $raw_input) OR array_key_exists('description_ru', $raw_input));
        $has_remote = array_key_exists('remote_url', $raw_input);
        $has_featured = (array_key_exists('featured', $raw_input) OR array_key_exists('id', $raw_input));
        $ru_source = ($record['name_ru'] !== '' OR $record['description_ru'] !== '') ? 'manual' : '';
        $ru_at = $ru_source !== '' ? date('Y-m-d H:i:s') : NULL;

        $assignments = 'slug = :slug, name = :name, description = :description,
                        name_ru = CASE WHEN :has_ru = TRUE THEN :name_ru ELSE name_ru END,
                        description_ru = CASE WHEN :has_ru = TRUE THEN :description_ru ELSE description_ru END,
                        ru_source = CASE WHEN :has_ru = TRUE THEN :ru_source ELSE ru_source END,
                        ru_at = CASE WHEN :has_ru = TRUE THEN :ru_at ELSE ru_at END,
                        repo_url = :repo, homepage_url = :homepage, source = :source,
                        transport = :transport, auth_mode = :auth,
                        remote_url = CASE WHEN :has_remote = TRUE THEN :remote_url ELSE remote_url END,
                        remote_kind = CASE WHEN :has_remote = TRUE THEN :remote_kind ELSE remote_kind END,
                        categories = CAST(:categories AS jsonb), health_status = :health,
                        health_checked_at = :checked_at, package_manager = :package_manager,
                        package_name = :package_name, package_entrypoint = :package_entrypoint,
                        env_keys = CAST(:env_keys AS jsonb),
                        tools = CAST(:tools AS jsonb), security_report = CAST(:security_report AS jsonb),
                        search_text = :search_text, origin = \'manual\',
                        featured = CASE WHEN :has_featured = TRUE THEN :featured ELSE featured END,
                        is_published = :published, updated_at = now()';

        if ($record['id'] > 0)
        {
            $sql = 'UPDATE nj_676_servers SET '.$assignments.' WHERE id = :id';
            $type = Database::UPDATE;
        }
        else
        {
            $sql = 'INSERT INTO nj_676_servers
                (slug, name, description, name_ru, description_ru, ru_source, ru_at,
                 repo_url, homepage_url, source, transport, auth_mode, remote_url, remote_kind,
                 categories, health_status, health_checked_at, package_manager, package_name,
                 package_entrypoint, env_keys, tools, security_report, search_text, origin, featured, is_published, updated_at)
                VALUES (:slug, :name, :description, :name_ru, :description_ru, :ru_source, :ru_at,
                        :repo, :homepage, :source, :transport, :auth, :remote_url, :remote_kind,
                        CAST(:categories AS jsonb), :health, :checked_at, :package_manager, :package_name,
                        :package_entrypoint, CAST(:env_keys AS jsonb), CAST(:tools AS jsonb),
                        CAST(:security_report AS jsonb), :search_text, \'manual\', :featured, :published, now())
                ON CONFLICT (slug) DO UPDATE SET
                    name = EXCLUDED.name, description = EXCLUDED.description,
                    name_ru = CASE WHEN :has_ru = TRUE THEN EXCLUDED.name_ru ELSE nj_676_servers.name_ru END,
                    description_ru = CASE WHEN :has_ru = TRUE THEN EXCLUDED.description_ru ELSE nj_676_servers.description_ru END,
                    ru_source = CASE WHEN :has_ru = TRUE THEN EXCLUDED.ru_source ELSE nj_676_servers.ru_source END,
                    ru_at = CASE WHEN :has_ru = TRUE THEN EXCLUDED.ru_at ELSE nj_676_servers.ru_at END,
                    repo_url = EXCLUDED.repo_url, homepage_url = EXCLUDED.homepage_url,
                    source = EXCLUDED.source, transport = EXCLUDED.transport,
                    auth_mode = EXCLUDED.auth_mode,
                    remote_url = CASE WHEN :has_remote = TRUE THEN EXCLUDED.remote_url ELSE nj_676_servers.remote_url END,
                    remote_kind = CASE WHEN :has_remote = TRUE THEN EXCLUDED.remote_kind ELSE nj_676_servers.remote_kind END,
                    categories = EXCLUDED.categories,
                    health_status = EXCLUDED.health_status, health_checked_at = EXCLUDED.health_checked_at,
                    package_manager = EXCLUDED.package_manager, package_name = EXCLUDED.package_name,
                    package_entrypoint = EXCLUDED.package_entrypoint,
                    env_keys = EXCLUDED.env_keys, tools = EXCLUDED.tools,
                    security_report = EXCLUDED.security_report, search_text = EXCLUDED.search_text,
                    origin = \'manual\',
                    featured = CASE WHEN :has_featured = TRUE THEN EXCLUDED.featured ELSE nj_676_servers.featured END,
                    is_published = EXCLUDED.is_published,
                    updated_at = now()';
            $type = Database::INSERT;
        }

        $query = DB::query($type, $sql);
        $bindings = [
            ':slug' => $record['slug'], ':name' => $record['name'], ':description' => $record['description'],
            ':name_ru' => $record['name_ru'], ':description_ru' => $record['description_ru'],
            ':ru_source' => $ru_source, ':ru_at' => $ru_at, ':has_ru' => $has_ru,
            ':repo' => $record['repo_url'], ':homepage' => $record['homepage_url'], ':source' => $record['source'],
            ':transport' => $record['transport'], ':auth' => $record['auth_mode'],
            ':remote_url' => $record['remote_url'], ':remote_kind' => $record['remote_kind'], ':has_remote' => $has_remote,
            ':categories' => json_encode($record['categories']), ':health' => $status,
            ':checked_at' => $checked_at, ':package_manager' => $record['package_manager'],
            ':package_name' => $record['package_name'], ':package_entrypoint' => $record['package_entrypoint'],
            ':env_keys' => json_encode($record['env_keys']),
            ':tools' => json_encode($record['tools']),
            ':security_report' => $report === NULL ? NULL : json_encode($report),
            ':search_text' => self::search_text($record),
            ':featured' => $record['featured'], ':has_featured' => $has_featured,
            ':published' => $record['is_published'],
        ];
        if ($record['id'] > 0)
        {
            $bindings[':id'] = $record['id'];
        }
        foreach ($bindings as $key => $value)
        {
            $query->param($key, $value);
        }
        $affected = $query->execute();
        if ($record['id'] > 0 AND (int) $affected < 1)
        {
            return ['Карточка для обновления не найдена; список мог устареть.', NULL];
        }

        // Держим флаг адреса и семантический индекс в согласии с обновлённой карточкой.
        self::refresh_url_validation($record['slug']);
        self::reset_ready_cache();
        if (class_exists('Service_Nj676_Semantic'))
        {
            try
            {
                Service_Nj676_Semantic::index_slugs([$record['slug']]);
            }
            catch (Throwable $e) {}
        }

        return ['', $record['slug']];
    }

    /** Чистая валидация ручной карточки (используется стендом). */
    public static function validate_record($input)
    {
        $input = (array) $input;
        $slug = strtolower(trim(self::_scalar_string($input['slug'] ?? '')));
        $name = trim(self::_scalar_string($input['name'] ?? ''));
        $description = trim(self::_scalar_string($input['description'] ?? ''));
        $name_ru = trim(self::_scalar_string($input['name_ru'] ?? ''));
        $description_ru = trim(self::_scalar_string($input['description_ru'] ?? ''));
        $repo = trim(self::_scalar_string($input['repo_url'] ?? ''));
        $homepage = trim(self::_scalar_string($input['homepage_url'] ?? ''));
        $remote_url = trim(self::_scalar_string($input['remote_url'] ?? ''));
        $source = self::_scalar_string($input['source'] ?? 'github');
        $transport = self::_scalar_string($input['transport'] ?? 'unknown');
        $auth = self::_scalar_string($input['auth_mode'] ?? 'unknown');
        $health = self::_scalar_string($input['health_status'] ?? 'unverified');
        $manager = self::_scalar_string($input['package_manager'] ?? '');
        $package = trim(self::_scalar_string($input['package_name'] ?? ''));
        $entrypoint = trim(self::_scalar_string($input['package_entrypoint'] ?? ''));
        $id_text = self::_scalar_string($input['id'] ?? '');
        if ($id_text !== '' AND ! ctype_digit($id_text))
        {
            return ['Идентификатор карточки некорректен.', NULL];
        }
        $id = $id_text === '' ? 0 : (int) $id_text;
        if ($id < 0)
        {
            return ['Идентификатор карточки некорректен.', NULL];
        }

        if ( ! preg_match('/^[a-z0-9][a-z0-9-]{1,99}$/', $slug))
        {
            return ['Slug: латиница, цифры и дефисы; длина 2–100 символов.', NULL];
        }
        if ($name === '' OR mb_strlen($name) > 160 OR strlen($description) > 12000)
        {
            return ['Название обязательно (до 160 символов); описание — до 12 КБ.', NULL];
        }
        if (mb_strlen($name_ru) > 160 OR strlen($description_ru) > 12000)
        {
            return ['Русское название — до 160 символов; русское описание — до 12 КБ.', NULL];
        }
        if ( ! self::_https_url($repo) OR ($homepage !== '' AND ! self::_https_url($homepage)))
        {
            return ['Ссылки на исходный код и сайт должны быть корректными HTTPS URL.', NULL];
        }
        if ($remote_url !== '' AND ! self::_https_url($remote_url))
        {
            return ['Удалённый MCP-адрес должен быть корректным публичным HTTPS URL.', NULL];
        }
        if ( ! in_array($source, ['github', 'npm', 'pypi', 'manual', 'native'], TRUE)
            OR ! isset(self::TRANSPORTS[$transport]) OR ! isset(self::AUTH[$auth]) OR ! isset(self::HEALTH[$health]))
        {
            return ['Источник, транспорт, тип авторизации или статус не распознаны.', NULL];
        }
        if ($manager !== '' AND ! in_array($manager, ['npm', 'pypi'], TRUE))
        {
            return ['Экспорт конфигурации разрешён только для пакетов npm или PyPI.', NULL];
        }
        if ($manager !== '' AND ! self::_valid_package($manager, $package))
        {
            return ['Укажите пакет с точной версией (например, package@1.2.3 или package==1.2.3).', NULL];
        }
        if ($manager === 'pypi' AND ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,100}$/', $entrypoint))
        {
            return ['Для PyPI укажите проверенное имя исполняемой команды (entrypoint).', NULL];
        }
        if ($manager !== 'pypi' AND $entrypoint !== '')
        {
            return ['Entrypoint задаётся только для PyPI-пакетов.', NULL];
        }

        $categories = self::_categories($input['categories'] ?? []);
        $env_keys = self::_env_keys($input['env_keys'] ?? []);

        $tools_json = $input['tools_json'] ?? '[]';
        [$tools_error, $tools] = self::normalize_tools_json($tools_json);
        if ($tools_error !== '')
        {
            return [$tools_error, NULL];
        }
        if (self::_looks_sensitive($description."\n".$description_ru."\n".json_encode($tools)))
        {
            return ['В описаниях обнаружен шаблон, похожий на секрет. Удалите ключи и токены до публикации.', NULL];
        }

        $remote_kind = $remote_url !== '' ? (in_array($transport, ['sse', 'http', 'remote'], TRUE) ? $transport : 'http') : '';

        return ['', [
            'id' => $id,
            'slug' => $slug, 'name' => mb_substr($name, 0, 160), 'description' => $description,
            'name_ru' => mb_substr($name_ru, 0, 160), 'description_ru' => $description_ru,
            'repo_url' => $repo, 'homepage_url' => $homepage, 'source' => $source,
            'transport' => $transport, 'auth_mode' => $auth, 'health_status' => $health,
            'categories' => $categories, 'package_manager' => $manager,
            'package_name' => $manager === '' ? '' : $package,
            'package_entrypoint' => $manager === 'pypi' ? $entrypoint : '',
            'env_keys' => $env_keys, 'tools' => $tools,
            'packages' => $manager === '' ? [] : [['manager' => $manager, 'name' => $package, 'entrypoint' => $manager === 'pypi' ? $entrypoint : '']],
            'topics' => [], 'highlights' => [], 'use_cases' => [],
            'remote_url' => $remote_url, 'remote_kind' => $remote_kind,
            'stars' => 0, 'forks' => 0, 'language' => '', 'license' => '', 'owner_login' => '',
            'github_id' => 0, 'trust' => 0, 'repo_pushed_at' => '', 'repo_archived' => FALSE,
            'latency_ms' => NULL, 'health_note' => '',
            'featured' => in_array(strtolower(self::_scalar_string($input['featured'] ?? '')), ['1', 'true', 'on'], TRUE),
            'is_published' => in_array(strtolower(self::_scalar_string($input['is_published'] ?? '')), ['1', 'true', 'on'], TRUE),
        ]];
    }

    /** Принимает MCP tools/list, tools или массив инструментов; лимит 64 КБ/100. */
    public static function normalize_tools_json($json)
    {
        if ( ! is_string($json) AND ! is_numeric($json))
        {
            return ['JSON инструментов должно быть строкой.', NULL];
        }
        $json = trim((string) $json);
        if ($json === '')
        {
            $json = '[]';
        }
        if (strlen($json) > self::MAX_TOOLS_BYTES)
        {
            return ['Описание tools превышает лимит 64 КБ.', NULL];
        }
        $data = json_decode($json, TRUE);
        if (json_last_error() !== JSON_ERROR_NONE)
        {
            return ['JSON инструментов некорректен: '.json_last_error_msg(), NULL];
        }
        if (isset($data['result']['tools']))
        {
            $tools = $data['result']['tools'];
        }
        elseif (isset($data['tools']))
        {
            $tools = $data['tools'];
        }
        else
        {
            $tools = $data;
        }
        if ( ! is_array($tools) OR ! array_is_list($tools) OR count($tools) > self::MAX_TOOLS)
        {
            return ['Ожидается массив tools, не более 100 элементов.', NULL];
        }
        foreach ($tools as $tool)
        {
            $description = is_array($tool) ? ($tool['description'] ?? '') : '';
            if ( ! is_array($tool) OR ! isset($tool['name']) OR ! is_string($tool['name']) OR strlen($tool['name']) > 128
                OR ( ! is_string($description) AND ! is_numeric($description))
                OR strlen((string) $description) > 4000)
            {
                return ['Каждый инструмент должен иметь имя (до 128 байт) и описание не длиннее 4 КБ.', NULL];
            }
        }

        return ['', $tools];
    }

    // ---------------------------------------------------------------------
    // Конфигурация для клиентов
    // ---------------------------------------------------------------------

    /**
     * Готовый фрагмент конфигурации. Секретов не содержит: значения
     * переменных окружения — плейсхолдеры.
     *
     * stdio — только пакет с закреплённой версией (npx -y pkg@1.2.3,
     * uvx --from pkg==1.2.3 entrypoint). Удалённый сервер — URL без запуска
     * кода на машине пользователя.
     */
    public static function export_config($server)
    {
        $server = (array) $server;

        // Поля приходят и массивом из БД (packages может быть JSON-строкой),
        // и уже разобранными из shape(). Полный shape() здесь вызывать нельзя:
        // он сам спрашивает export_config() про has_config — была бы рекурсия.
        $packages = $server['packages'] ?? [];
        if (is_string($packages))
        {
            $packages = json_decode($packages, TRUE) ?: [];
        }
        $env_keys = $server['env_keys'] ?? [];
        if (is_string($env_keys))
        {
            $env_keys = json_decode($env_keys, TRUE) ?: [];
        }

        $transport = (string) ($server['transport'] ?? 'unknown');
        $remote_url = trim((string) ($server['remote_url'] ?? ''));

        if (in_array($transport, ['sse', 'streamable_http'], TRUE) AND $remote_url !== ''
            AND self::_https_url($remote_url))
        {
            $entry = ['url' => $remote_url];
            $server_name = (string) ($server['slug'] ?? 'mcp');

            if ( ! in_array((string) ($server['auth_mode'] ?? 'unknown'), ['none', 'unknown'], TRUE))
            {
                $entry['headers'] = ['Authorization' => 'Bearer SET_LOCALLY'];
            }

            return [
                'mcpServers' => [$server_name => $entry],
                '_note' => 'Удалённый MCP-сервер: код на вашем компьютере не запускается. '
                    .'Ключ подставьте локально вместо SET_LOCALLY.',
            ];
        }

        $package = self::_pinned_package([
            'packages' => $packages,
            'package_manager' => $server['package_manager'] ?? '',
            'package_name' => $server['package_name'] ?? '',
            'package_entrypoint' => $server['package_entrypoint'] ?? '',
        ]);

        if ($package === NULL OR $transport !== 'stdio')
        {
            return NULL;
        }

        $command = $package['manager'] === 'npm' ? 'npx' : 'uvx';
        $args = $package['manager'] === 'npm'
            ? ['-y', $package['name']]
            : ['--from', $package['name'], (string) $package['entrypoint']];

        $env = [];
        foreach ((array) $env_keys as $key)
        {
            $env[(string) $key] = 'SET_LOCALLY';
        }

        return [
            'mcpServers' => [(string) ($server['slug'] ?? 'mcp') => [
                'command' => $command,
                'args' => $args,
                'env' => (object) $env,
            ]],
            '_note' => 'Локальный MCP-сервер: команду запускает ваш клиент. Проверьте владельца и код пакета.',
        ];
    }

    /** Блок «Куда вставить»: подписи клиентов и их файлы конфигурации. */
    public static function client_targets()
    {
        return [
            'claude' => ['title' => 'Claude Desktop', 'file' => 'claude_desktop_config.json'],
            'cursor' => ['title' => 'Cursor', 'file' => '~/.cursor/mcp.json'],
            'windsurf' => ['title' => 'Windsurf', 'file' => '~/.codeium/windsurf/mcp_config.json'],
        ];
    }

    /** Текстовая версия конфигурации для копирования в буфер. */
    public static function config_snippet($server)
    {
        $config = self::export_config($server);

        if ($config === NULL)
        {
            return '';
        }

        unset($config['_note']);

        return json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ---------------------------------------------------------------------
    // Форма записи
    // ---------------------------------------------------------------------

    public static function shape($row)
    {
        $row = (array) $row;
        foreach (['categories', 'env_keys', 'tools', 'security_report', 'topics', 'highlights', 'use_cases', 'packages', 'audit_report'] as $field)
        {
            if (isset($row[$field]) AND is_string($row[$field]))
            {
                $row[$field] = json_decode($row[$field], TRUE);
            }
        }
        foreach (['categories', 'env_keys', 'tools', 'topics', 'highlights', 'use_cases', 'packages', 'audit_report'] as $field)
        {
            if ( ! is_array($row[$field] ?? NULL))
            {
                $row[$field] = [];
            }
        }
        if ( ! is_array($row['security_report'] ?? NULL))
        {
            $row['security_report'] = NULL;
        }

        // Поля, которые появились миграцией 002: у старых карточек и у записей,
        // собранных до неё, их может не быть вовсе — вьюха не должна падать.
        $defaults = [
            'owner_login' => '', 'language' => '', 'license' => '', 'remote_url' => '',
            'remote_kind' => '', 'health_note' => '', 'topics' => [], 'highlights' => [],
            'use_cases' => [], 'packages' => [], 'latency_ms' => NULL, 'views' => 0,
            'stars' => 0, 'forks' => 0, 'trust' => 0, 'origin' => 'manual',
            'repo_pushed_at' => NULL, 'repo_archived' => FALSE, 'health_checked_at' => NULL,
            'package_hint' => '', 'security_report' => NULL,
            'registry_name' => '', 'registry_version' => '', 'synced_at' => NULL,
            'featured' => FALSE, 'probe_failures' => 0, 'probe_tools_at' => NULL,
            'schema_valid' => NULL, 'schema_note' => '',
            'url_valid' => NULL, 'url_note' => '', 'last_ok_at' => NULL,
            // Миграция 014: «сервер просит авторизацию» (живой вызов вернул 401).
            'auth_required_at' => NULL,
            // Перевод (миграция 011). Пустая строка — перевода нет, и
            // интерфейс честно показывает оригинал автора.
            'name_ru' => '', 'description_ru' => '', 'ru_source' => '', 'ru_at' => NULL,
            // Полевое исследование воркером-агентом и рейтинг отзывов (миграция 017).
            'audit_score' => 0, 'safety_score' => 0, 'audit_verdict' => '',
            'is_gem' => FALSE, 'audit_summary' => '', 'audit_report' => [],
            'audited_at' => NULL, 'agent_rating' => 0.0, 'agent_reviews_count' => 0,
        ];
        foreach ($defaults as $key => $default)
        {
            if ( ! array_key_exists($key, $row) OR $row[$key] === NULL)
            {
                $row[$key] = $default;
            }
        }

        // PostgreSQL отдаёт boolean то как bool, то как 't'/'f' (зависит от
        // драйвера и настроек). В JSON `"f"` — истина для слабой модели,
        // поэтому нормализуем: ровно true / false / null.
        foreach (['schema_valid', 'url_valid', 'featured', 'repo_archived', 'is_gem'] as $flag)
        {
            $row[$flag] = self::as_bool($row[$flag] ?? NULL);
        }
        $row['is_gem'] = ($row['is_gem'] === TRUE);
        $row['audit_score'] = max(0, min(100, (int) ($row['audit_score'] ?? 0)));
        $row['safety_score'] = max(0, min(100, (int) ($row['safety_score'] ?? 0)));
        $row['audit_verdict'] = trim((string) ($row['audit_verdict'] ?? ''));
        $row['audit_summary'] = trim((string) ($row['audit_summary'] ?? ''));
        $row['audit_verdict_label'] = class_exists('Service_Nj676_Audit')
            ? (Service_Nj676_Audit::VERDICTS[$row['audit_verdict']][0] ?? ($row['is_gem'] ? 'Алмаз каталога' : ''))
            : '';
        $row['audit_verdict_tone'] = class_exists('Service_Nj676_Audit')
            ? (Service_Nj676_Audit::VERDICTS[$row['audit_verdict']][1] ?? ($row['is_gem'] ? 'emerald' : 'gray'))
            : 'gray';
        $row['agent_reviews_count'] = max(0, (int) ($row['agent_reviews_count'] ?? 0));
        $row['agent_rating'] = $row['agent_reviews_count'] > 0 || (float) ($row['agent_rating'] ?? 0) > 0
            ? round((float) $row['agent_rating'], 2)
            : NULL;

        // Что показывать человеку и агенту: русский текст редакции, если он
        // есть, иначе оригинал автора. Оригинал всегда остаётся рядом
        // (`name`/`description`) — переводу нельзя доверять слепо.
        // `?? ''` — для строк не из _columns() (фикстуры, частичные выборки):
        // старые карточки приходят без русских колонок до миграции 011.
        $row['name_ru'] = trim((string) ($row['name_ru'] ?? ''));
        $row['description_ru'] = trim((string) ($row['description_ru'] ?? ''));
        $row['display_name'] = $row['name_ru'] !== '' ? $row['name_ru'] : (string) ($row['name'] ?? '');
        $row['display_description'] = $row['description_ru'] !== ''
            ? $row['description_ru'] : (string) ($row['description'] ?? '');
        $row['has_ru'] = ($row['name_ru'] !== '' OR $row['description_ru'] !== '');

        $row['tool_count'] = count($row['tools']);
        $row['approx_schema_tokens'] = (int) ceil(strlen((string) json_encode($row['tools'])) / 4);
        $published = $row['is_published'] ?? FALSE;
        $row['is_published'] = ($published === TRUE OR $published === 1
            OR in_array(strtolower((string) $published), ['1', 't', 'true', 'yes'], TRUE));

        $health = (string) ($row['health_status'] ?? 'unverified');
        $row['health_label'] = self::HEALTH[$health][0] ?? self::HEALTH['unverified'][0];
        $row['health_tone'] = self::HEALTH[$health][1] ?? 'gray';
        $row['transport_label'] = self::TRANSPORTS[(string) ($row['transport'] ?? 'unknown')] ?? self::TRANSPORTS['unknown'];
        $row['auth_mode_raw'] = (string) ($row['auth_mode'] ?? 'unknown');
        if ($row['auth_mode_raw'] === 'api_key' AND self::is_optional_auth($row))
        {
            $row['auth_mode'] = 'optional';
        }
        $row['auth_label'] = self::AUTH[(string) ($row['auth_mode'] ?? 'unknown')] ?? self::AUTH['unknown'];
        $row['is_remote'] = in_array((string) ($row['transport'] ?? ''), ['sse', 'streamable_http'], TRUE)
            || trim((string) ($row['remote_url'] ?? '')) !== '';
        // Готовность к вызову — единый ответ на вопрос агента «можно звать
        // прямо сейчас или нужен ключ/установка?». Считается здесь, чтобы
        // каталог, шлюз и JSON-поиск говорили одно и то же.
        $row['readiness'] = self::readiness($row);
        $row['auth'] = (string) ($row['auth_mode'] ?? 'unknown');
        $row['install'] = $row['readiness']['install'];
        $row['is_ready'] = $row['readiness']['ready'];

        $labels = [];
        foreach ($row['categories'] as $category)
        {
            if (isset(self::CATEGORIES[$category]))
            {
                $labels[] = self::CATEGORIES[$category];
            }
        }
        $row['category_labels'] = $labels;

        $row['stars'] = (int) ($row['stars'] ?? 0);
        $row['forks'] = (int) ($row['forks'] ?? 0);
        $row['views'] = (int) ($row['views'] ?? 0);
        $row['trust'] = (int) ($row['trust'] ?? 0);
        if ($row['trust'] <= 0)
        {
            $high_risk = (int) ($row['security_report']['counts']['high'] ?? 0);
            $calc_trust = self::_trust_score([
                'stars' => $row['stars'],
                'license' => $row['license'] ?? '',
                'pushed_at' => $row['repo_pushed_at'] ?? '',
                'archived' => ! empty($row['repo_archived']),
                'health' => (string) ($row['health_status'] ?? 'unverified'),
                'high_risk' => $high_risk,
            ]);
            if ( ! empty($row['is_ready']))
            {
                $calc_trust = min(100, $calc_trust + 10);
            }
            elseif (in_array((string) ($row['health_status'] ?? ''), ['degraded', 'offline'], TRUE))
            {
                $calc_trust = max(5, $calc_trust - 15);
            }
            if (($row['schema_valid'] ?? NULL) === TRUE AND (int) ($row['tool_count'] ?? 0) > 0)
            {
                $calc_trust = min(100, $calc_trust + 10);
            }
            $row['trust'] = max(0, min(100, $calc_trust));
        }
        $row['origin'] = (string) ($row['origin'] ?? 'manual');
        $row['origin_label'] = self::ORIGINS[$row['origin']] ?? 'Каталог';
        $featured = $row['featured'] ?? FALSE;
        $row['featured'] = ($featured === TRUE OR $featured === 1
            OR in_array(strtolower((string) $featured), ['1', 't', 'true', 'yes'], TRUE));

        if ( ! empty($row['repo_pushed_at']))
        {
            $row['fresh_days'] = max(0, (int) floor((time() - strtotime((string) $row['repo_pushed_at'])) / 86400));
        }
        else
        {
            $row['fresh_days'] = NULL;
        }

        $row['package_hint'] = '';
        $pinned = self::_pinned_package($row);
        if ($pinned !== NULL)
        {
            $row['package_hint'] = $pinned['manager'] === 'npm'
                ? 'npx -y '.$pinned['name']
                : 'uvx --from '.$pinned['name'].' '.$pinned['entrypoint'];
        }

        $row['has_config'] = (self::export_config($row) !== NULL);

        return $row;
    }

    /** Текст, по которому ищет PostgreSQL и по которому считает ранжирование. */
    public static function search_text(array $record)
    {
        // repo_url намеренно НЕ входит: «github.com» есть у каждой карточки и
        // делал любой запрос со словом «git» совпадением со всем каталогом.
        // Точный поиск по ссылке идёт отдельным ILIKE в _where().
        // Слаг и имя в реестре входят и целиком, и по частям: иначе запрос по
        // точному слагу (ai-echoloc-company-technographics, huntboard, justidea)
        // терял карточку после пересчёта search_text.
        $slug = (string) ($record['slug'] ?? '');
        $registry_name = (string) ($record['registry_name'] ?? '');
        $audit_report = is_array($record['audit_report'] ?? NULL) ? $record['audit_report'] : [];
        $is_gem = ! empty($record['is_gem']) || (($record['audit_verdict'] ?? '') === 'gem');
        $parts = [
            $slug,
            str_replace(['-', '_'], ' ', $slug),
            $registry_name,
            str_replace(['/', '.', '-', '_'], ' ', $registry_name),
            (string) ($record['name_ru'] ?? ''),
            (string) ($record['description_ru'] ?? ''),
            (string) ($record['name'] ?? ''),
            (string) ($record['description'] ?? ''),
            (string) ($record['audit_summary'] ?? ''),
            implode(' ', (array) ($record['use_cases'] ?? [])),
            implode(' ', (array) ($audit_report['use_cases'] ?? [])),
            (string) ($audit_report['observations'] ?? ''),
            (string) ($audit_report['safety_notes'] ?? ''),
            $is_gem ? 'алмаз gem проверено агентом recommended' : '',
            (string) ($record['package_name'] ?? ''),
            implode(' ', (array) ($record['topics'] ?? [])),
            implode(' ', array_map([__CLASS__, '_category_title'], (array) ($record['categories'] ?? []))),
            (string) json_encode((array) ($record['tools'] ?? []), JSON_UNESCAPED_UNICODE),
        ];

        return mb_substr(trim(preg_replace('/\s+/u', ' ', implode(' ', $parts))), 0, 20000);
    }

    /** Слаг из owner/repo или из ссылки на репозиторий. */
    public static function slug_from_repo($repo)
    {
        $repo = trim((string) $repo);
        if ($repo === '')
        {
            return '';
        }

        if (preg_match('~github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)~i', $repo, $m))
        {
            $repo = $m[1].'/'.$m[2];
        }

        $parts = explode('/', trim($repo, '/'));
        $name = strtolower(end($parts));
        $name = preg_replace('/\.git$/', '', $name);
        $name = preg_replace('/[^a-z0-9]+/', '-', $name);
        $name = trim($name, '-');

        if (mb_strlen($name) > 100)
        {
            $name = rtrim(mb_substr($name, 0, 100), '-');
        }

        return mb_strlen($name) >= 2 ? $name : '';
    }

    // ---------------------------------------------------------------------
    // Внутреннее
    // ---------------------------------------------------------------------

    /**
     * Пересчитать `url_valid`/`url_note` по сохранённому адресу.
     *
     * Вызывается после ручного сохранения карточки: адрес мог быть изменён
     * импортом, и каталог не должен показывать «готово» по старому флагу.
     */
    public static function refresh_url_validation($slug)
    {
        $row = self::_query(
            'SELECT remote_url, url_valid FROM nj_676_servers WHERE slug = :slug LIMIT 1',
            [':slug' => (string) $slug]
        )->current();

        if ( ! $row)
        {
            return FALSE;
        }

        [$valid, $note] = self::endpoint_check((string) ($row['remote_url'] ?? ''));
        $note = mb_substr($note, 0, 200);

        if (self::as_bool($row['url_valid'] ?? NULL) === $valid
            AND ($valid !== FALSE OR $note !== ''))
        {
            return TRUE;
        }

        self::_query(
            'UPDATE nj_676_servers
                SET url_valid = :valid, url_note = :note,
                    health_status = CASE WHEN CAST(:bad AS integer) = 1 THEN \'offline\' ELSE health_status END,
                    health_note = CASE WHEN CAST(:bad AS integer) = 1 THEN :note ELSE health_note END,
                    updated_at = now()
              WHERE slug = :slug',
            [
                ':valid' => $valid,
                ':note' => $note,
                ':bad' => $valid === FALSE ? 1 : 0,
                ':slug' => (string) $slug,
            ]
        );

        return TRUE;
    }

    /**
     * Строгий boolean | null из любого представления PostgreSQL.
     *
     * `'f'` в JSON — это строка, и клиент читает её как истину. В контракте
     * `schema_valid`/`url_valid` только `true`, `false` или `null`.
     */
    public static function as_bool($value)
    {
        if ($value === NULL OR $value === '')
        {
            return NULL;
        }

        if (is_bool($value))
        {
            return $value;
        }

        return in_array(strtolower((string) $value), ['t', 'true', '1', 'y', 'yes', 'on'], TRUE);
    }

    /**
     * Проверка удалённого адреса до публикации карточки.
     *
     * Синтаксис, схема и хост проверяются здесь же; DNS-разрешение делает
     * `Gateway::public_https_url()` в момент вызова/проверки — иначе временный
     * сбой DNS навсегда выключал бы живой сервер из каталога.
     *
     * @return array{0:bool|null,1:string} [валиден|null, причина отказа]
     */
    public static function endpoint_check($url)
    {
        $url = trim((string) $url);

        if ($url === '')
        {
            // Локальный (stdio) сервер: удалённого адреса нет — это не ошибка.
            return [NULL, ''];
        }

        if ( ! self::_https_url($url))
        {
            return [FALSE, 'адрес не проходит проверку: нужен https и публичный хост'];
        }

        return [TRUE, ''];
    }

    protected static function _columns()
    {
        return 'id, slug, name, description, name_ru, description_ru, ru_source, ru_at,
                repo_url, homepage_url, source, transport,
                auth_mode, categories, health_status, health_checked_at, health_note,
                package_manager, package_name, package_entrypoint, packages, env_keys, tools,
                security_report, is_published, created_at, updated_at,
                stars, forks, language, license, owner_login, topics, highlights, use_cases,
                remote_url, remote_kind, origin, trust, views, latency_ms,
                repo_pushed_at, repo_archived, registry_name, registry_version,
                synced_at, featured, probe_failures, probe_tools_at, schema_valid, schema_note,
                url_valid, url_note, last_ok_at, auth_required_at,
                audit_score, safety_score, audit_verdict, is_gem, audit_summary, audit_report,
                audited_at, agent_rating, agent_reviews_count';
    }

    /** WHERE + параметры для витрины. */
    protected static function _where(array $filters)
    {
        $where = ['is_published = TRUE'];
        $params = [];

        $q = mb_substr(trim(self::_scalar_string($filters['q'] ?? '')), 0, 120);
        if ($q !== '')
        {
            $params[':exact_q'] = mb_strtolower($q);
            $params[':like'] = '%'.self::_like_escape($q).'%';
            $or = [
                'lower(slug) = :exact_q',
                'slug ILIKE :like',
                'name ILIKE :like',
                "name_ru IS NOT NULL AND name_ru <> '' AND name_ru ILIKE :like",
                'registry_name ILIKE :like',
                'search_text ILIKE :like',
            ];

            $slug_q = trim((string) preg_replace('/[^a-z0-9]+/i', '-', mb_strtolower($q)), '-');
            if ($slug_q !== '' AND mb_strlen($slug_q) >= 3 AND $slug_q !== mb_strtolower($q))
            {
                $params[':slug_like'] = '%'.self::_like_escape($slug_q).'%';
                $or[] = 'slug ILIKE :slug_like';
            }

            // Ссылку ищем только когда запрос похож на неё: «github.com» есть у
            // всех карточек, и общий ILIKE делал «git» совпадением со всем каталогом.
            if (mb_strpos($q, '/') !== FALSE OR mb_stripos($q, 'github') !== FALSE)
            {
                $or[] = 'repo_url ILIKE :like';
            }

            // Слова запроса и их английские синонимы — полнотекстово. Подстрочный
            // поиск по всему тексту давал шум («research» находился запросом
            // «search»), поэтому слова ищутся по началу лексемы.
            $ts = self::_tsquery_string(self::_terms($q));
            if ($ts !== '')
            {
                $params[':tsq'] = $ts;
                $or[] = "to_tsvector('simple'::regconfig, search_text) @@ to_tsquery('simple'::regconfig, :tsq)";
            }

            $where[] = '('.implode(' OR ', $or).')';
        }

        $category = self::_scalar_string($filters['category'] ?? '');
        if (isset(self::CATEGORIES[$category]))
        {
            $params[':category'] = json_encode([$category]);
            $where[] = 'categories @> CAST(:category AS jsonb)';
        }

        $allowed = [
            'transport' => array_keys(self::TRANSPORTS),
            'auth' => array_keys(self::AUTH),
            'health' => array_keys(self::HEALTH),
        ];

        foreach ($allowed as $input => $values)
        {
            $value = self::_scalar_string($filters[$input] ?? '');
            if ($value !== '' AND in_array($value, $values, TRUE))
            {
                $column = $input === 'auth' ? 'auth_mode' : ($input === 'health' ? 'health_status' : 'transport');
                $params[':f_'.$column] = $value;
                $where[] = $column.' = :f_'.$column;
            }
        }

        $origin = self::_scalar_string($filters['origin'] ?? '');
        if ($origin !== '' AND isset(self::ORIGINS[$origin]))
        {
            $params[':origin'] = $origin;
            $where[] = 'origin = :origin';
        }

        // «Можно вызвать прямо сейчас» — условия readiness()=ready в SQL, иначе
        // счётчик и пагинация витрины врут (фильтр после выборки оставляет
        // «1 из 60» при тысячах карточек).
        if ( ! empty($filters['ready']))
        {
            // Тот же предикат, что у ready_overview(): иначе «готовы к вызову»
            // на витрине и в hub_ready считаются по разным правилам — ровно так
            // и получалось «3 на сайте, 0 в шлюзе» (тест 24.10.2026).
            $where[] = self::_ready_condition($params);
        }

        if ( ! empty($filters['gems']))
        {
            $where[] = '(is_gem = TRUE OR audit_score >= 80)';
        }

        if ( ! empty($filters['audited']))
        {
            $where[] = 'audited_at IS NOT NULL';
        }

        return [$where, $params];
    }

    /** Ранжирование: точное совпадение слага/имени на первом месте, затем заголовок и полнотекст. */
    protected static function _rank_sql(array &$params)
    {
        if ( ! isset($params[':like']))
        {
            return '0';
        }

        // Точный слаг и подстрока в слаге обязаны перевешивать общие слова («ai»,
        // «company», «mcp») из полнотекстового запроса: иначе запрос по точному
        // слагу (ai-echoloc-company-technographics, temporal-mcp, huntboard,
        // justidea) тонул за карточками с большим числом звёзд.
        $parts = [
            'CASE WHEN lower(slug) = :exact_q THEN 200 ELSE 0 END',
            'CASE WHEN lower(name) = :exact_q THEN 150 ELSE 0 END',
            "CASE WHEN name_ru IS NOT NULL AND name_ru <> '' AND lower(name_ru) = :exact_q THEN 150 ELSE 0 END",
            'CASE WHEN slug ILIKE :like THEN 40 ELSE 0 END',
            'CASE WHEN registry_name ILIKE :like THEN 25 ELSE 0 END',
            'CASE WHEN name ILIKE :like THEN 12 ELSE 0 END',
            "CASE WHEN name_ru IS NOT NULL AND name_ru <> '' AND name_ru ILIKE :like THEN 12 ELSE 0 END",
            'CASE WHEN search_text ILIKE :like THEN 3 ELSE 0 END',
        ];

        if (isset($params[':slug_like']))
        {
            $parts[] = 'CASE WHEN slug ILIKE :slug_like THEN 35 ELSE 0 END';
        }

        $ts = (string) ($params[':tsq'] ?? '');
        if ($ts !== '')
        {
            $parts[] = "COALESCE(ts_rank(to_tsvector('simple'::regconfig, search_text),
                                        to_tsquery('simple'::regconfig, :tsq)), 0) * 20";
        }

        return '('.implode(' + ', $parts).')';
    }

    protected static function _order($sort, $q, $dir = '')
    {
        $sort = self::_scalar_string($sort);
        $dir_norm = strtolower(trim(self::_scalar_string($dir)));
        $asc = ($dir_norm === 'asc');

        if ($sort === '' OR ! isset(self::SORTS[$sort]))
        {
            $sort = trim((string) $q) !== '' ? 'relevance' : 'popular';
        }

        switch ($sort)
        {
            case 'utility':
                return 'is_gem DESC, audit_score '.($asc ? 'ASC' : 'DESC').", safety_score DESC, agent_rating DESC, (health_status = 'operational') DESC, trust DESC, stars DESC, name ASC";
            case 'agent_rating':
                return 'agent_rating '.($asc ? 'ASC' : 'DESC').', agent_reviews_count DESC, is_gem DESC, audit_score DESC, trust DESC, stars DESC, name ASC';
            case 'trust':
                return 'trust '.($asc ? 'ASC' : 'DESC').", (health_status = 'operational') DESC, stars DESC, name ASC";
            case 'reliability':
                return "(health_status = 'operational') DESC, (health_status = 'unverified') DESC, (health_status = 'degraded') ASC, (health_status = 'offline') ASC, trust ".($asc ? 'ASC' : 'DESC').', latency_ms ASC NULLS LAST, stars DESC, name ASC';
            case 'latency':
                return 'latency_ms '.($dir_norm === 'desc' ? 'DESC' : 'ASC').', trust DESC, stars DESC, name ASC';
            case 'stars':
                return 'stars '.($asc ? 'ASC' : 'DESC').', trust DESC, views DESC, name ASC';
            // «По алфавиту» сортируем по тому имени, которое видит человек:
            // русский перевод, если он есть, иначе оригинал автора.
            case 'new':
                return 'created_at '.($asc ? 'ASC' : 'DESC').', stars DESC';
            case 'name':
                return "coalesce(nullif(name_ru, ''), name) ".($dir_norm === 'desc' ? 'DESC' : 'ASC');
            case 'relevance':
                return trim((string) $q) === '' ? 'stars DESC, name ASC' : 'rank '.($asc ? 'ASC' : 'DESC').', is_gem DESC, audit_score DESC, agent_rating DESC, trust DESC, stars DESC, name ASC';
            case 'popular':
            default:
                return 'stars '.($asc ? 'ASC' : 'DESC').', trust DESC, views DESC, name ASC';
        }
    }

    /**
     * Стемминг для поиска. Русские окончания режем, английское — только
     * множественное число: `to_tsquery` уже ищет по началу слова (`:*`),
     * поэтому «weather» находит «weathering» без отдельной лемматизации.
     */
    public static function stem($word)
    {
        $word = mb_strtolower(trim((string) $word));

        if ($word === '')
        {
            return '';
        }

        if (preg_match('/^[а-яё-]+$/u', $word))
        {
            // Длинные окончания — первыми, иначе «курсами» потеряет только «и».
            foreach (['ами', 'ями', 'ого', 'его', 'ому', 'ему', 'ыми', 'ими', 'ов', 'ев',
                         'ей', 'ой', 'ый', 'ий', 'ая', 'яя', 'ое', 'ее', 'ые', 'ие', 'ых', 'их',
                         'ом', 'ем', 'ах', 'ях', 'ую', 'юю', 'ии', 'ий', 'ья', 'ье', 'ьи',
                         'а', 'я', 'о', 'е', 'у', 'ю', 'ы', 'и', 'ь'] as $suffix)
            {
                if (mb_strlen($word) - mb_strlen($suffix) >= 3 AND mb_substr($word, -mb_strlen($suffix)) === $suffix)
                {
                    $word = mb_substr($word, 0, -mb_strlen($suffix));
                    break;
                }
            }

            return $word;
        }

        // Английский: аккуратно снимаем множественное число и -ing/-ed.
        if (mb_strlen($word) > 4 AND preg_match('/[a-z]$/', $word))
        {
            if (mb_substr($word, -3) === 'ies' AND mb_strlen($word) > 5)
            {
                return mb_substr($word, 0, -3).'y';
            }
            if (mb_substr($word, -2) === 'es' AND mb_strlen($word) > 5)
            {
                return mb_substr($word, 0, -2);
            }
            if (mb_substr($word, -1) === 's' AND mb_substr($word, -2) !== 'ss')
            {
                return mb_substr($word, 0, -1);
            }
        }

        return $word;
    }

    /** Публичная обёртка над словарём: по каким словам реально идёт поиск. */
    public static function terms($query)
    {
        return self::_terms((string) $query);
    }

    /** Слова запроса + переводы по словарю синонимов (без дублей). */
    protected static function _terms($q)
    {
        $q = mb_strtolower((string) $q);
        $words = preg_split('/[^a-zа-яё0-9]+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $terms = [];

        foreach ($words as $word)
        {
            if (mb_strlen($word) < 2 OR in_array($word, self::RU_STOPWORDS, TRUE))
            {
                continue;
            }

            $terms[$word] = TRUE;

            // Русское слово в любой форме → английские понятия каталога.
            $stem = self::stem($word);
            foreach ([$word, $stem] as $probe)
            {
                foreach (self::RU_TO_EN as $ru => $en)
                {
                    if ($probe === $ru OR mb_strpos($probe, $ru) === 0 OR mb_strpos($ru, $probe) === 0 OR mb_strpos($probe, $ru) !== FALSE)
                    {
                        foreach ($en as $translated)
                        {
                            $terms[$translated] = TRUE;
                        }
                    }
                }
            }

            $word = $stem;

            foreach (self::EN_TO_RU as $en => $ru_words)
            {
                if ($word === $en OR mb_strpos($word, $en) === 0)
                {
                    foreach ($ru_words as $ru_word)
                    {
                        $terms[$ru_word] = TRUE;
                    }
                }
            }
        }

        if (count($terms) === 0)
        {
            $terms[$q] = TRUE;
        }

        return array_slice(array_keys($terms), 0, 12);
    }

    /** to_tsquery («слово:* | слово:*») из очищенных слов; пусто — собирать нечего. */
    protected static function _tsquery_string(array $terms)
    {
        // Короткие английские корни с ложными префиксными соседями:
        // read:* ловит readiness/readme, crypto:* ловит cryptography,
        // rate:* ловит ratelimit. Для них даём точные словоформы без :*.
        $exact_forms = [
            'read' => ['read', 'reads', 'reading', 'reader'],
            'crypto' => ['crypto', 'cryptos', 'cryptocurrency', 'cryptocurrencies'],
            'rate' => ['rate', 'rates'],
            'auth' => ['auth', 'authentication', 'oauth'],
        ];

        $words = [];
        foreach ($terms as $term)
        {
            $term = mb_strtolower(trim((string) $term));
            if (preg_match('/^[a-zа-яё0-9]{2,}$/u', $term) AND ! in_array($term, self::STOPWORDS, TRUE))
            {
                if (isset($exact_forms[$term]))
                {
                    foreach ($exact_forms[$term] as $form)
                    {
                        $words[] = $form;
                    }
                }
                else
                {
                    $words[] = $term.':*';
                }
            }
        }

        return implode(' | ', array_slice(array_unique($words), 0, 14));
    }

    /**
     * Схемы инструментов в виде, который переживает json_encode.
     *
     * Ловушка PHP: пустой массив кодируется как `[]`, а JSON Schema требует
     * ОБЪЕКТ для `properties` («карта имя → схема»). Клиенты со строгой
     * валидацией (OpenCode v2.1.4) из-за одного такого поля отклоняют весь
     * ответ `tools/list` — и все инструменты разом становятся недоступны.
     * Ровно это случилось 23.10.2026: `hub_status` отдавал
     * `"inputSchema": {"type":"object","properties":[]}`.
     *
     * Второй путь той же ошибки: схемы удалённых серверов лежат в jsonb,
     * читаются в PHP-массив и перекодируются в JSON при отдаче — `{}` из базы
     * возвращался вызывающему как `[]`. Поэтому чистим не только свою схему,
     * но и всё, что уходит наружу через API.
     *
     * @param  mixed $tools список инструментов
     * @return mixed
     */
    public static function schemas_as_objects($tools)
    {
        if ( ! is_array($tools))
        {
            return $tools;
        }

        foreach ($tools as $index => $tool)
        {
            if ( ! is_array($tool))
            {
                continue;
            }

            foreach (['inputSchema', 'outputSchema'] as $key)
            {
                if (isset($tool[$key]) AND is_array($tool[$key]))
                {
                    $tools[$index][$key] = self::_schema_maps($tool[$key]);
                }
            }
        }

        return $tools;
    }

    /**
     * Рекурсивно заменить пустые «карты» схемы на объект.
     *
     * @param  array $schema
     * @return array
     */
    protected static function _schema_maps(array $schema)
    {
        // Ключи, значением которых служит объект «имя → подсхема».
        $maps = ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'];

        // Ключи со списком подсхем.
        $lists = ['allOf', 'anyOf', 'oneOf', 'prefixItems'];

        // Ключи с одной подсхемой (или булевым значением).
        $singles = ['items', 'additionalItems', 'additionalProperties', 'unevaluatedProperties',
            'propertyNames', 'not', 'if', 'then', 'else', 'contains'];

        foreach ($maps as $key)
        {
            if ( ! array_key_exists($key, $schema))
            {
                continue;
            }

            if ($schema[$key] === [])
            {
                $schema[$key] = new stdClass();
                continue;
            }

            if ( ! is_array($schema[$key]))
            {
                continue;
            }

            foreach ($schema[$key] as $name => $sub)
            {
                if (is_array($sub))
                {
                    $schema[$key][$name] = self::_schema_maps($sub);
                }
            }
        }

        foreach ($lists as $key)
        {
            if ( ! isset($schema[$key]) OR ! is_array($schema[$key]))
            {
                continue;
            }

            foreach ($schema[$key] as $index => $sub)
            {
                if (is_array($sub))
                {
                    $schema[$key][$index] = self::_schema_maps($sub);
                }
            }
        }

        foreach ($singles as $key)
        {
            if (isset($schema[$key]) AND is_array($schema[$key]))
            {
                $schema[$key] = self::_schema_maps($schema[$key]);
            }
        }

        return $schema;
    }

    /**
     * Готовность карточки к вызову «прямо сейчас».
     *
     * Реальный тест агентами (23.10.2026): `only_ready` возвращал сервисы,
     * которым нужен Bearer-ключ, а `hub_status` обещал «30 инструментов» без
     * единого доступного. Агент делал вызов, получал отказ и терял доверие к
     * каталогу. Поэтому готовность считается явно и одинаково везде:
     *
     *   ready         — удалённый, без ключа (или ключ настроен на сайте),
     *                   проверен меньше STALE_DAYS назад и отвечает;
     *   needs_key     — удалённый, но нужен ключ, которого у сайта нет;
     *   needs_install — локальный (stdio): на сервере не запускается;
     *   degraded      — отвечал с ошибками, но ещё в каталоге;
     *   stale         — удалённый, но проверялся давно или никогда;
     *   broken        — две неудачи подряд, сервер считается недоступным.
     *
     * @return array{readiness:string,ready:bool,auth:string,install:string,
     *               verified_at:string,latency_ms:int|null,schema_valid:bool|null,
     *               note:string}
     */
    /**
     * Серверы, которые можно вызвать прямо сейчас — по всей базе.
     *
     * «Сначала 60 самых популярных» не работает: при 1007 серверах готовые в
     * эту страницу не попадают, и `hub_ready` отвечает пусто.
     *
     * До 24.10.2026 готовность считалась одним SQL-условием `operational` +
     * свежая проверка, а поиск с `only_ready` — через `readiness()`. Числа
     * расходились (витрина «8 проверены», шлюз «10 готовы»), потому что
     * `readiness()` уважает живой вызов `last_ok_at` и ключ сервера из
     * окружения. Теперь один источник правды: SQL отбирает кандидатов, а
     * решение принимает `readiness()`.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function ready_servers($limit = 60)
    {
        $all = self::_ready_all();
        if ((int) $limit <= 0)
        {
            return $all;
        }

        return array_slice($all, 0, max(1, min(1000, (int) $limit)));
    }

    /**
     * Сколько серверов и инструментов можно вызвать прямо сейчас — по всей базе.
     *
     * Один источник правды для витрины, `hub_status` и `hub_ready`: все три
     * читают `ready_overview()`. До этого числа расходились (витрина «3
     * готовы», `hub_status` «0 / 0», `hub_search_tools only_ready` находит
     * сервер) — потому что считались тремя разными способами.
     *
     * @return array{servers:int,tools:int}
     */
    public static function ready_stats()
    {
        $overview = self::ready_overview();

        return [
            'servers' => (int) $overview['servers'],
            'tools' => (int) $overview['tools'],
            'ready_probe_ok' => (int) ($overview['ready_probe_ok'] ?? $overview['servers']),
            'ready_anonymous' => (int) ($overview['ready_anonymous'] ?? $overview['servers']),
            'ready_keyed' => (int) ($overview['ready_keyed'] ?? 0),
            'ready_optional' => (int) ($overview['ready_optional'] ?? 0),
        ];
    }

    /**
     * Готовое к вызову: список, счётчики и причина, если посчитать не вышло.
     *
     * `error` — не украшение. Раньше сбой запроса молча давал нули, и владелец
     * сутки видел «0 готовы», хотя серверы отвечали. Теперь причина уходит в
     * журнал (WARNING) и в `hub_status`, а не в тишину.
     *
     * @return array{servers:int,tools:int,ready_probe_ok:int,ready_anonymous:int,ready_keyed:int,ready_optional:int,list:array<int,array<string,mixed>>,error:string}
     */
    public static function ready_overview()
    {
        $list = self::_ready_all();

        $tools = 0;
        $anonymous = 0;
        $keyed = 0;
        $optional = 0;
        foreach ($list as $server)
        {
            $tools += is_array($server['tools'] ?? NULL) ? count($server['tools']) : 0;
            $has_cred = ! empty($server['readiness']['has_credentials']);
            $auth = (string) ($server['auth'] ?? 'none');
            if ($has_cred)
            {
                $keyed++;
            }
            else
            {
                $anonymous++;
                if ($auth === 'optional')
                {
                    $optional++;
                }
            }
        }

        return [
            'servers' => count($list),
            'tools' => $tools,
            'ready_probe_ok' => count($list),
            'ready_anonymous' => $anonymous,
            'ready_keyed' => $keyed,
            'ready_optional' => $optional,
            'list' => $list,
            'error' => (string) self::_ready_error(),
        ];
    }

    /**
     * Причина, по которой счётчик готовности не посчитался ('' — всё хорошо).
     *
     * Публичная, потому что витрина показывает её рядом с цифрой: «0 готовы»
     * при живых серверах владелец сутки принимал за правду.
     */
    public static function ready_error()
    {
        return self::_ready_error();
    }

    /** Результат последнего расчёта готовности (в пределах запроса + опциональный кэш). */
    protected static function _ready_all()
    {
        $cached = self::_stat('ready_all_cache');

        if (is_array($cached))
        {
            return $cached;
        }

        $ttl = (int) getenv('NJ676_READY_CACHE_SEC');
        if ($ttl > 0 AND class_exists('Cache'))
        {
            try
            {
                $file_cached = Cache::instance('file')->get('nj676_ready_all_cache', NULL);
                if (is_array($file_cached))
                {
                    self::_stat('ready_all_cache', $file_cached);

                    return $file_cached;
                }
            }
            catch (Throwable $e) {}
        }

        $rows = self::_ready_rows();
        self::_stat('ready_all_cache', $rows);

        if ($ttl > 0 AND class_exists('Cache') AND self::_ready_error() === '')
        {
            try
            {
                Cache::instance('file')->set('nj676_ready_all_cache', $rows, min(300, $ttl));
            }
            catch (Throwable $e) {}
        }

        return $rows;
    }

    /** Сбросить кэш готовности после изменения карточки или статуса пробы. */
    public static function reset_ready_cache()
    {
        self::_stat('ready_all_cache', FALSE);
        self::_stat('ready_error', '');

        if (class_exists('Cache'))
        {
            try
            {
                Cache::instance('file')->delete('nj676_ready_all_cache');
            }
            catch (Throwable $e) {}
        }
    }

    /** Последняя причина, по которой готовность не посчиталась ('' — не было). */
    protected static function _ready_error()
    {
        return (string) self::_stat('ready_error', '');
    }

    /** Простое хранилище в пределах запроса: без статики на класс-наследник. */
    protected static function _stat($key, $value = NULL)
    {
        static $box = [];

        if ($value !== NULL)
        {
            $box[$key] = $value;

            return $value;
        }

        return $box[$key] ?? NULL;
    }

    /**
     * SQL-предикат «сервер может быть готов» — один на витрину и на счётчики.
     *
     * Раньше условие было скопировано в двух местах и уже разошлось: витрина
     * требовала auth_mode IN ('none','unknown'), а ready_overview() пускал
     * серверы с ключом из окружения сайта. Отсюда «3 на сайте, 0 в шлюзе».
     *
     * Готовность окончательно решает readiness() в PHP; здесь — только отбор
     * кандидатов, чтобы не тянуть из базы весь каталог.
     *
     * @param  array $params сюда дописываются параметры запроса
     * @return string SQL без ведущего AND
     */
    protected static function _ready_condition(array & $params)
    {
        $stale_days = (int) getenv('NJ676_STALE_DAYS');
        $stale_days = $stale_days > 0 ? min(365, $stale_days) : 7;

        $params[':ready_days'] = (string) $stale_days;
        $params[':ready_ok'] = (string) self::_ok_window();
        $params[':ready_auth_days'] = (string) self::_auth_window_days();

        return "url_valid IS NOT FALSE
                AND remote_url <> ''
                AND coalesce(jsonb_array_length(
                        CASE WHEN jsonb_typeof(tools) = 'array' THEN tools ELSE '[]'::jsonb END), 0) > 0
                AND (auth_mode IN ('none', 'unknown', 'api_key', 'oauth') OR auth_mode = 'optional')
                AND (auth_required_at IS NULL
                     OR auth_required_at < now() - (:ready_auth_days || ' days')::interval)
                AND (
                      (health_status = 'operational'
                       AND health_checked_at IS NOT NULL
                       AND health_checked_at > now() - (:ready_days || ' days')::interval)
                   OR (last_ok_at IS NOT NULL
                       AND last_ok_at > now() - (:ready_ok || ' minutes')::interval)
                )";
    }

    /**
     * Кандидаты в «готовые»: предварительный отбор в SQL + проверка
     * `readiness()` в PHP.
     *
     * SQL берёт всё, что в принципе может быть готово: без невалидного адреса, с
     * инструментами, с удалённым адресом и с любым режимом авторизации
     * (ключ из окружения сайта — не преграда). Второе условие — либо свежая
     * успешная проба, либо успешный вызов в окне `NJ676_OK_WINDOW_MIN`.
     *
     * @param  int|NULL $limit NULL — все строки (для подсчёта)
     * @return array<int,array<string,mixed>>
     */
    protected static function _ready_rows($limit = NULL)
    {
        $params = [];
        $ready_where = self::_ready_condition($params);

        try
        {
            $rows = self::_query(
                // jsonb_typeof(...) = 'array' перед jsonb_array_length — не
                // украшение: в базе есть карточки, где tools сохранён объектом
                // (старые записи, ручные правки). jsonb_array_length на таком
                // значении БРОСАЕТ ошибку, и весь запрос готовности падал —
                // а catch молча отдавал пустой список. Ровно поэтому
                // «готовы к вызову» сутками показывало 0 при живых серверах.
                'SELECT '.self::_columns().'
                   FROM nj_676_servers
                  WHERE is_published = TRUE
                    AND '.$ready_where.'
                  ORDER BY latency_ms NULLS LAST, trust DESC, stars DESC',
                $params
            )->as_array();
        }
        catch (Throwable $e)
        {
            // WARNING, а не DEBUG: обнулённая готовность — это «агент видит,
            // что в каталоге ничего нет». Такое нельзя терять в журнале.
            Log_Plain::event(Log::WARNING, 'nj676: готовые серверы не посчитаны: :e', [':e' => $e->getMessage()]);
            self::_stat('ready_error', mb_substr($e->getMessage(), 0, 200));

            return [];
        }

        $ready = [];

        foreach ($rows as $row)
        {
            // shape() обязателен ДО readiness(): из базы jsonb приходит
            // строкой, и readiness() видел «инструментов 0» → объявлял сервер
            // сбоящим → готовых не оставалось вовсе. Витрина и поиск звали
            // shape() сами, а счётчик — нет, отсюда три разных числа.
            $server = self::shape($row);
            $state = $server['readiness'];

            if (empty($state['ready']))
            {
                continue;
            }

            $ready[] = $server;

            if ($limit !== NULL AND count($ready) >= $limit)
            {
                break;
            }
        }

        return $ready;
    }

    /**
     * Сервер реально отвечал недавно?
     *
     * `health_checked_at` — «когда проверяли», `last_ok_at` — «когда он
     * последний раз сработал». Это разные сигналы, и до 24.10.2026 карточка
     * считалась сломанной из-за одной сорвавшейся пробы, хотя вызовы шли.
     * Окно настраивается: NJ676_OK_WINDOW_MIN (по умолчанию 30 минут).
     */
    public static function recently_ok(array $server)
    {
        $last = trim((string) ($server['last_ok_at'] ?? ''));

        if ($last === '')
        {
            return FALSE;
        }

        return (time() - (int) strtotime($last)) <= self::_ok_window() * 60;
    }

    /** Окно «недавно сработал», минуты: NJ676_OK_WINDOW_MIN (30 по умолчанию). */
    protected static function _ok_window()
    {
        $window = (int) getenv('NJ676_OK_WINDOW_MIN');

        return $window > 0 ? min(1440, $window) : 30;
    }

    /** Сколько дней верим отметке «сервер требует авторизацию». */
    protected static function _auth_window_days()
    {
        $days = (int) getenv('NJ676_AUTH_WINDOW_DAYS');

        return $days > 0 ? min(365, $days) : 30;
    }

    /**
     * Живой вызов вернул отказ по авторизации — запомнить это в карточке.
     *
     * Без отметки витрина и `hub_ready` продолжали обещать вызов, а агент
     * получал AUTH_REQUIRED. Это не «сломанный сервер» (health не трогаем):
     * сервер работает, просто не для нас без ключа.
     */
    public static function mark_auth_required($slug)
    {
        $slug = trim((string) $slug);

        if ($slug === '')
        {
            return;
        }

        try
        {
            self::_query(
                'UPDATE nj_676_servers SET auth_required_at = now()
                  WHERE slug = :slug
                    AND (auth_required_at IS NULL OR auth_required_at < now() - interval \'1 hour\')',
                [':slug' => $slug]
            );
            self::reset_ready_cache();
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::DEBUG, 'nj676: отметка авторизации не записана: :e', [':e' => $e->getMessage()]);
        }
    }

    /** Успешный вызов снимает отметку «нужен ключ». */
    public static function clear_auth_required($slug)
    {
        $slug = trim((string) $slug);

        if ($slug === '')
        {
            return;
        }

        try
        {
            self::_query(
                'UPDATE nj_676_servers SET auth_required_at = NULL
                  WHERE slug = :slug AND auth_required_at IS NOT NULL',
                [':slug' => $slug]
            );
            self::reset_ready_cache();
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::DEBUG, 'nj676: отметка авторизации не снята: :e', [':e' => $e->getMessage()]);
        }
    }

    /** Отметить успешный вызов: «этот сервер живой, несмотря на пробу». */
    public static function mark_call_ok($slug)
    {
        $slug = trim((string) $slug);

        if ($slug === '')
        {
            return;
        }

        try
        {
            // Отметка нужна с точностью до минуты, а не до вызова: под
            // нагрузкой шлюз не должен писать в ту же строку на каждый запрос.
            self::_query(
                'UPDATE nj_676_servers SET last_ok_at = now()
                  WHERE slug = :slug
                    AND (last_ok_at IS NULL OR last_ok_at < now() - interval \'60 seconds\')',
                [':slug' => $slug]
            );
        }
        catch (Throwable $e)
        {
            // Отметка — вспомогательный сигнал: без неё вызов всё равно прошёл.
            Log_Plain::event(Log::DEBUG, 'nj676: last_ok_at не записан: :e', [':e' => $e->getMessage()]);
        }
    }

    /** «12 мин назад» для подписи готовности. */
    protected static function _ago_short($time)
    {
        $age = max(0, time() - (int) strtotime((string) $time));

        if ($age < 60)
        {
            return 'только что';
        }

        if ($age < 3600)
        {
            return (int) floor($age / 60).' мин назад';
        }

        return (int) floor($age / 3600).' ч назад';
    }

    /**
     * Есть ли у шлюза рабочий серверный токен для этой карточки.
     */
    public static function has_server_credentials(array $server)
    {
        if ( ! class_exists('Service_Nj676_Gateway'))
        {
            return FALSE;
        }

        return Service_Nj676_Gateway::remote_token($server) !== '';
    }

    /**
     * Работает ли сервер в демо-режиме без ключа (auth: optional / freemium).
     *
     * Пример: ai-echoloc-company-technographics объявляет ECHOLOC_API_KEY, но
     * апстрим явно разрешает анонимные preview-вызовы («anonymous calls return
     * a preview...»). Если живой вызов не возвращал 401 (auth_required_at),
     * блокировать такой сервер на шлюзе до обращения к апстриму нельзя.
     */
    public static function is_optional_auth(array $server)
    {
        $mode = (string) ($server['auth_mode_raw'] ?? $server['auth_mode'] ?? '');
        if ($mode === 'optional')
        {
            return TRUE;
        }

        $auth_required_at = trim((string) ($server['auth_required_at'] ?? ''));
        if ($auth_required_at !== ''
            AND (time() - (int) strtotime($auth_required_at)) <= self::_auth_window_days() * 86400)
        {
            return FALSE;
        }

        $haystack = (string) ($server['description'] ?? '').' '
            .(string) ($server['description_ru'] ?? '').' '
            .json_encode((array) ($server['tools'] ?? []), JSON_UNESCAPED_UNICODE);

        return (bool) preg_match(
            '/\b(anonymous calls\b|always works\b[^.]{0,50}\banonymous|works without (an? )?api[_ -]?key|optional api[_ -]?key|preview without (an? )?key|free preview without)\b/iu',
            $haystack
        );
    }

    /**
     * Готовность сервера к вызову одной строкой.
     */
    public static function readiness(array $server)
    {
        $auth = (string) ($server['auth_mode'] ?? 'unknown');
        if ($auth === 'api_key' AND self::is_optional_auth($server))
        {
            $auth = 'optional';
        }
        $transport = (string) ($server['transport'] ?? 'unknown');
        $is_remote = in_array($transport, ['sse', 'streamable_http'], TRUE)
            || trim((string) ($server['remote_url'] ?? '')) !== '';

        // Способ появления у клиента: none (готовый адрес), npx/uvx (пакет),
        // manual (собрать руками из README).
        $install = 'manual';
        if ($is_remote)
        {
            $install = 'none';
        }
        elseif ((string) ($server['package_manager'] ?? '') === 'npm')
        {
            $install = 'npx';
        }
        elseif ((string) ($server['package_manager'] ?? '') === 'pypi')
        {
            $install = 'uvx';
        }

        $checked = trim((string) ($server['health_checked_at'] ?? ''));
        $age = $checked !== '' ? time() - (int) strtotime($checked) : NULL;
        $stale_days = (int) getenv('NJ676_STALE_DAYS');
        $stale_days = $stale_days > 0 ? min(365, $stale_days) : 7;

        // Ключ сервера живёт в окружении сайта (NJ676_REMOTE_TOKEN_<SLUG>):
        // если он есть, «нужен ключ» уже не преграда для вызова.
        $has_token = self::has_server_credentials($server);

        // Живой вызов сказал «нужен ключ», хотя карточка считала иначе
        // (temporal-mcp, 24.10.2026). Верим вызову: обещать кнопку, которая
        // гарантированно вернёт AUTH_REQUIRED, нельзя.
        $auth_required_at = trim((string) ($server['auth_required_at'] ?? ''));
        // Скобки обязательны: `AND` в PHP слабее `=`, и без них выражение
        // читается как ($auth_required = $auth_required_at !== '') AND …
        // Любая старая отметка объявляла сервер «нужен ключ» навсегда.
        $auth_required = (
            $auth_required_at !== ''
            AND (time() - (int) strtotime($auth_required_at)) <= self::_auth_window_days() * 86400
            AND ! $has_token
        );

        $env_keys = is_array($server['env_keys'] ?? NULL) ? array_values(array_filter($server['env_keys'], 'strlen')) : [];
        $health = (string) ($server['health_status'] ?? 'unverified');
        $readiness = 'ready';
        $note = '';

        $url_valid = isset($server['url_valid']) ? self::as_bool($server['url_valid']) : NULL;
        $url_note = trim((string) ($server['url_note'] ?? ''));
        $tools_count = is_array($server['tools'] ?? NULL) ? count($server['tools']) : 0;

        if ( ! $is_remote)
        {
            $readiness = 'needs_install';
            $note = $install === 'manual'
                ? 'локальный: нужна установка, готовой команды нет'
                : 'локальный: ставится командой '.$install;
        }
        elseif ($url_valid === FALSE)
        {
            // Адрес не прошёл проверку при приёмке: обещать вызов нельзя,
            // иначе агент тратит его на заведомую ошибку UPSTREAM_ERROR.
            $readiness = 'broken';
            $note = $url_note !== '' ? $url_note : 'адрес не проходит проверку безопасности';
        }
        elseif (in_array($auth, ['api_key', 'oauth'], TRUE) AND ! $has_token)
        {
            $readiness = 'needs_key';
            $note = 'нужен ключ ('.$auth.'), у сайта его нет';
        }
        elseif ($auth === 'unknown' AND $env_keys !== [] AND ! $has_token AND ! self::is_optional_auth($server))
        {
            $readiness = 'needs_key';
            $note = 'нужен ключ ('.implode(', ', array_slice($env_keys, 0, 2)).'), у сайта его нет';
        }
        elseif ($auth_required)
        {
            $readiness = 'needs_key';
            $note = 'сервер требует авторизации (живой вызов вернул отказ '
                .self::_ago_short($auth_required_at).')';
        }
        elseif ($health === 'offline')
        {
            $readiness = 'broken';
            $note = trim((string) ($server['health_note'] ?? '')) ?: 'две неудачные проверки подряд';
        }
        elseif ($health === 'degraded' AND mb_strpos((string) ($server['health_note'] ?? ''), 'агенты') === 0)
        {
            // Демот по отзывам важнее свежести проверки: мы специально
            // сбросили health_checked_at, чтобы сервер проверили вне очереди,
            // и до проверки он обязан выглядеть «сбоит», а не «не проверялся».
            $readiness = 'degraded';
            $note = (string) $server['health_note'];
        }
        elseif ($age === NULL OR $age > $stale_days * 86400)
        {
            $readiness = 'stale';
            $note = $age === NULL ? 'ещё не проверялся вживую' : 'проверялся '.(int) floor($age / 86400).' дн. назад';
        }
        elseif ($health === 'degraded')
        {
            $readiness = 'degraded';
            $note = trim((string) ($server['health_note'] ?? '')) ?: 'последняя проверка неудачна';
        }
        elseif ($tools_count === 0)
        {
            // Сервер отвечает, но список инструментов не читается: вызвать
            // нечего, и агент должен видеть это до попытки (тест: ai-1325-mcp).
            $readiness = 'degraded';
            $note = 'список инструментов не читается (tools/list пуст или с ошибкой)';
        }

        // Живой вызов важнее неудачной пробы. Реальный случай 24.10.2026:
        // open-meteo отдавал погоду, но одна сорвавшаяся проверка tools/list
        // объявила его «сбоит», и он выпал из only_ready. При этом демот по
        // отзывам агентов («агенты сообщают о сбоях») не отменяем: это
        // осознанный сигнал, а не случайность пробы.
        if (in_array($readiness, ['degraded', 'stale'], TRUE)
            AND self::recently_ok($server)
            AND mb_strpos((string) ($server['health_note'] ?? ''), 'агенты') !== 0)
        {
            $readiness = 'ready';
            $note = 'вызов прошёл '.self::_ago_short($server['last_ok_at']);
        }

        $schema_valid = $server['schema_valid'] ?? NULL;

        // Подпись для людей: слово «degraded» в каталоге ничего не объясняет.
        $labels = [
            'ready' => 'готов к вызову',
            'needs_key' => 'нужен ключ',
            'needs_install' => 'нужна установка',
            'degraded' => 'сбоит',
            'stale' => 'давно не проверялся',
            'broken' => 'недоступен',
        ];

        return [
            'readiness' => $readiness,
            'label' => $labels[$readiness] ?? $readiness,
            'ready' => $readiness === 'ready',
            'auth' => $auth,
            'has_credentials' => $has_token,
            'anonymous_ready' => ($readiness === 'ready' AND ! $has_token),
            'install' => $install,
            'verified_at' => $checked !== '' ? date('c', (int) strtotime($checked)) : '',
            'latency_ms' => isset($server['latency_ms']) && $server['latency_ms'] !== NULL
                ? (int) $server['latency_ms'] : NULL,
            'schema_valid' => $schema_valid === NULL ? NULL : (bool) $schema_valid,
            'note' => $note,
        ];
    }

    /**
     * Перепроверить сохранённые схемы всех карточек (без сети).
     *
     * Зачем: схема могла быть сохранена до появления валидатора, а один
     * `properties: []` валит весь `tools/list` у строгих клиентов. Кнопка в
     * админке и self-check прогоняют это же правило по уже собранным данным.
     *
     * @return array{checked:int,broken:int,empty:int}
     */
    public static function revalidate_schemas($limit = 500)
    {
        $rows = self::_query(
            'SELECT id, slug, tools, remote_url, url_valid FROM nj_676_servers ORDER BY id LIMIT '
                .max(1, min(2000, (int) $limit))
        )->as_array();

        $checked = 0;
        $broken = 0;
        $empty = 0;
        $urls = 0;

        foreach ($rows as $row)
        {
            // Заодно приёмка адреса: она локальная (без DNS и сети), а
            // «готов к вызову» с нерабочей ссылкой — худший ответ каталога.
            [$url_valid, $url_note] = self::endpoint_check((string) ($row['remote_url'] ?? ''));
            $url_bad = $url_valid === FALSE;
            $urls += $url_bad ? 1 : 0;

            $tools = json_decode((string) ($row['tools'] ?? '[]'), TRUE);
            $tools = is_array($tools) ? $tools : [];

            if ($tools === [])
            {
                // Схему ещё не собирали — это «неизвестно», а не «сломано».
                $empty++;
                $valid = NULL;
                $note = '';
            }
            else
            {
                $check = self::validate_tool_schemas($tools);
                $valid = (bool) $check['ok'];
                $note = $valid ? '' : mb_substr(implode('; ', (array) $check['problems']), 0, 200);
                $checked++;
                $broken += $valid ? 0 : 1;
            }

            try
            {
                DB::query(Database::UPDATE,
                    'UPDATE nj_676_servers
                        SET schema_valid = :valid, schema_note = :note,
                            url_valid = :url_valid, url_note = :url_note,
                            health_status = CASE WHEN CAST(:url_bad AS integer) = 1
                                                 THEN \'offline\' ELSE health_status END,
                            health_note = CASE WHEN CAST(:url_bad AS integer) = 1
                                               THEN :url_note ELSE health_note END,
                            search_text = CASE WHEN position(lower(slug) in lower(coalesce(search_text, \'\'))) > 0
                                               THEN search_text
                                               ELSE trim(slug || \' \' || replace(slug, \'-\', \' \') || \' \' || coalesce(registry_name, \'\') || \' \' || coalesce(search_text, \'\'))
                                          END
                      WHERE id = :id')
                    ->param(':valid', $valid)
                    ->param(':note', $note)
                    ->param(':url_valid', $url_valid)
                    ->param(':url_note', mb_substr((string) $url_note, 0, 200))
                    ->param(':url_bad', $url_bad ? 1 : 0)
                    ->param(':id', (int) $row['id'])
                    ->execute();
            }
            catch (Throwable $e)
            {
                Log_Plain::event(Log::WARNING, 'nj676: схема :s не записана: :e',
                    [':s' => (string) $row['slug'], ':e' => $e->getMessage()]);
            }
        }

        return ['checked' => $checked, 'broken' => $broken, 'empty' => $empty, 'urls' => $urls];
    }

    /**
     * Авто-демот по отзывам агентов.
     *
     * Три разных ключа сказали «не работает» — сервер уходит в degraded и
     * попадает в начало очереди проверки (`health_checked_at = NULL`).
     * Один и тот же ключ сколько угодно раз сервер не роняет: считаются
     * уникальные агенты (`Telemetry::negative_agents`).
     */
    public static function demote_by_feedback($slug, $reports = 3)
    {
        try
        {
            DB::query(Database::UPDATE,
                "UPDATE nj_676_servers
                    SET health_status = CASE WHEN health_status = 'offline' THEN 'offline' ELSE 'degraded' END,
                        health_note = :note,
                        health_checked_at = NULL,
                        updated_at = now()
                  WHERE slug = :slug AND is_published = TRUE")
                ->param(':note', 'агенты сообщают о проблемах: '.(int) $reports.' за 7 дней')
                ->param(':slug', (string) $slug)
                ->execute();
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::WARNING, 'nj676: демот :s не записан: :e',
                [':s' => (string) $slug, ':e' => $e->getMessage()]);

            return FALSE;
        }

        return TRUE;
    }

    /**
     * Проверка снимка tools/list по правилам JSON Schema/MCP.
     *
     * Зачем это в приёмке, а не только в тесте: 23.10.2026 один инструмент с
     * `properties: []` выключил у OpenCode ВСЕ инструменты шлюза. Сервер,
     * который отдаёт такую схему, нельзя пускать в каталог «молча» — он ломает
     * подключение целиком.
     *
     * @param  array $tools снимок tools/list
     * @return array{ok:bool,note:string,tools:int,problems:array}
     */
    public static function validate_tool_schemas(array $tools)
    {
        $problems = [];

        if ($tools === [])
        {
            return ['ok' => FALSE, 'note' => 'пустой список инструментов', 'tools' => 0,
                'problems' => ['tools/list пуст']];
        }

        // Проверяем ровно то, что увидит клиент: PHP-массив не отличает `[]`
        // от `{}`, поэтому смотрим на результат json_encode.
        $json = json_encode(['tools' => array_values($tools)], JSON_UNESCAPED_UNICODE);
        $decoded = is_string($json) ? json_decode($json, FALSE) : NULL;

        if ( ! is_object($decoded) OR ! isset($decoded->tools) OR ! is_array($decoded->tools))
        {
            return ['ok' => FALSE, 'note' => 'снимок не кодируется в JSON', 'tools' => 0,
                'problems' => ['tools/list не сериализуется']];
        }

        foreach ($decoded->tools as $index => $tool)
        {
            if ( ! is_object($tool))
            {
                $problems[] = 'инструмент #'.($index + 1).' — не объект';
                continue;
            }

            $name = isset($tool->name) ? (string) $tool->name : '';
            if ($name === '')
            {
                $problems[] = 'инструмент #'.($index + 1).' без имени';
                continue;
            }

            if ( ! isset($tool->inputSchema))
            {
                // Схема не обязательна по спецификации, но без неё агент
                // угадывает аргументы — это отдельное замечание, не блокер.
                continue;
            }

            if ( ! is_object($tool->inputSchema))
            {
                $problems[] = $name.': inputSchema не объект';
                continue;
            }

            $schema = $tool->inputSchema;

            if (isset($schema->type) AND (string) $schema->type !== 'object')
            {
                $problems[] = $name.': inputSchema.type = '.(string) $schema->type.' (нужен object)';
            }

            if (isset($schema->properties) AND ! is_object($schema->properties))
            {
                $problems[] = $name.': properties — массив вместо объекта';
            }

            if (isset($schema->required) AND ! is_array($schema->required))
            {
                $problems[] = $name.': required не массив';
            }

            if (isset($schema->required) AND is_array($schema->required) AND isset($schema->properties) AND is_object($schema->properties))
            {
                $known = array_keys(get_object_vars($schema->properties));
                foreach ($schema->required as $field)
                {
                    if ( ! in_array((string) $field, $known, TRUE))
                    {
                        $problems[] = $name.': required «'.$field.'» нет в properties';
                    }
                }
            }
        }

        $ok = $problems === [];

        return [
            'ok' => $ok,
            'note' => $ok ? 'схемы валидны' : mb_substr(implode('; ', array_slice($problems, 0, 3)), 0, 200),
            'tools' => count($decoded->tools),
            'problems' => $problems,
        ];
    }

    /** Тот же SELECT, но доступный сервисам модуля (реестр, шлюз). */
    public static function query_raw($sql, array $params = [])
    {
        return self::_query($sql, $params);
    }

    protected static function _query($sql, array $params = [])
    {
        $query = DB::query(Database::SELECT, $sql);
        foreach ($params as $key => $value)
        {
            $query->param($key, $value);
        }

        return $query->execute();
    }

    /**
     * Приёмка карточки от воркера: строгий allowlist полей и значений.
     * Ничего, что пришло извне, не попадает в SQL без проверки.
     */
    protected static function normalize_discovery(array $item)
    {
        $repo = trim(self::_scalar_string($item['repo_url'] ?? ''));
        if ( ! self::_https_url($repo) OR strpos($repo, 'https://github.com/') !== 0)
        {
            return ['Нужна HTTPS-ссылка на GitHub-репозиторий.', NULL];
        }

        $slug = self::slug_from_repo($repo);
        if ($slug === '')
        {
            return ['Не удалось получить slug из ссылки на репозиторий.', NULL];
        }

        $name = mb_substr(trim(self::_scalar_string($item['name'] ?? '')), 0, 160);
        if ($name === '')
        {
            $name = $slug;
        }

        $description = mb_substr(trim(self::_scalar_string($item['description'] ?? '')), 0, 4000);
        $transport = self::_scalar_string($item['transport'] ?? 'unknown');
        if ( ! isset(self::TRANSPORTS[$transport]))
        {
            $transport = 'unknown';
        }
        $auth = self::_scalar_string($item['auth_mode'] ?? 'unknown');
        if ( ! isset(self::AUTH[$auth]))
        {
            $auth = 'unknown';
        }

        $remote_url = trim(self::_scalar_string($item['remote_url'] ?? ''));
        if ($remote_url !== '' AND filter_var($remote_url, FILTER_VALIDATE_URL) === FALSE)
        {
            // Мусор в поле адреса отбрасываем, а вот небезопасный https-адрес
            // (внутренний хост, localhost) сохраняем: карточка должна получить
            // url_valid = false и причину, а не притвориться локальной.
            $remote_url = '';
        }

        if ($remote_url !== '' AND ! in_array(strtolower((string) parse_url($remote_url, PHP_URL_SCHEME)), ['http', 'https'], TRUE))
        {
            $remote_url = '';
        }
        $remote_kind = $remote_url === '' ? '' : (strpos($remote_url, '/sse') !== FALSE ? 'sse' : 'streamable_http');
        if ($remote_kind === 'sse')
        {
            $transport = 'sse';
        }
        elseif ($remote_kind !== '')
        {
            $transport = 'streamable_http';
        }

        $owner = mb_substr(trim(self::_scalar_string($item['owner_login'] ?? '')), 0, 120);
        if ($owner === '' AND preg_match('~github\.com/([A-Za-z0-9_.-]+)/~i', $repo, $owner_match))
        {
            $owner = mb_substr($owner_match[1], 0, 120);
        }

        $packages = [];
        foreach (array_slice((array) ($item['packages'] ?? []), 0, 4) as $package)
        {
            if ( ! is_array($package))
            {
                continue;
            }
            $manager = self::_scalar_string($package['manager'] ?? '');
            $package_name = trim(self::_scalar_string($package['name'] ?? ''));
            if (in_array($manager, ['npm', 'pypi'], TRUE) AND self::_valid_package($manager, $package_name))
            {
                $packages[] = [
                    'manager' => $manager,
                    'name' => $package_name,
                    'entrypoint' => $manager === 'pypi' ? mb_substr(trim(self::_scalar_string($package['entrypoint'] ?? '')), 0, 100) : '',
                ];
            }
        }

        $tools = [];
        if (isset($item['tools']) AND is_array($item['tools']))
        {
            [$tools_error, $tools] = self::normalize_tools_json(json_encode(['tools' => $item['tools']], JSON_UNESCAPED_UNICODE));
            if ($tools_error !== '')
            {
                $tools = [];
            }
        }

        $highlights = [];
        foreach (array_slice((array) ($item['highlights'] ?? []), 0, 5) as $line)
        {
            $line = mb_substr(trim(self::_scalar_string($line)), 0, 300);
            if ($line !== '')
            {
                $highlights[] = $line;
            }
        }

        $use_cases = [];
        foreach (array_slice((array) ($item['use_cases'] ?? []), 0, 5) as $line)
        {
            $line = mb_substr(trim(self::_scalar_string($line)), 0, 300);
            if ($line !== '')
            {
                $use_cases[] = $line;
            }
        }

        $topics = [];
        foreach (array_slice((array) ($item['topics'] ?? []), 0, 12) as $topic)
        {
            $topic = mb_strtolower(mb_substr(trim(self::_scalar_string($topic)), 0, 40));
            if ($topic !== '' AND preg_match('/^[a-z0-9._-]+$/', $topic))
            {
                $topics[] = $topic;
            }
        }

        $guard = $tools === [] ? NULL : self::_guard($tools);
        $counts = (array) ($guard['counts'] ?? []);
        $trust = self::_trust_score([
            'stars' => (int) ($item['stars'] ?? 0),
            'high_risk' => (int) ($counts['high'] ?? 0),
            'archived' => ! empty($item['repo_archived']),
            'pushed_at' => (string) ($item['repo_pushed_at'] ?? ''),
            'license' => (string) ($item['license'] ?? ''),
            'health' => (string) ($item['health_status'] ?? 'unverified'),
        ]);

        $health = self::_scalar_string($item['health_status'] ?? 'unverified');
        if ( ! isset(self::HEALTH[$health]))
        {
            $health = 'unverified';
        }

        $pushed_at = trim(self::_scalar_string($item['repo_pushed_at'] ?? ''));
        if ($pushed_at !== '' AND strtotime($pushed_at) === FALSE)
        {
            $pushed_at = '';
        }

        if (self::_looks_sensitive($description))
        {
            $description = '';
        }

        // Русский перевод (необязателен). Приходит из модели автоперевода или
        // от редактора; пустая строка — перевода нет.
        $name_ru = mb_substr(trim(self::_scalar_string($item['name_ru'] ?? '')), 0, 160);
        $description_ru = mb_substr(trim(self::_scalar_string($item['description_ru'] ?? '')), 0, 4000);

        if (self::_looks_sensitive($description_ru))
        {
            $description_ru = '';
        }

        if ($name_ru === '')
        {
            $description_ru = '';
        }

        $ru_source = '';
        if ($name_ru !== '' OR $description_ru !== '')
        {
            $ru_source = self::_scalar_string($item['ru_source'] ?? 'llm');
            $ru_source = in_array($ru_source, ['llm', 'manual'], TRUE) ? $ru_source : 'llm';
        }

        return ['', [
            'slug' => $slug,
            'name' => $name,
            'description' => $description,
            'name_ru' => $name_ru,
            'description_ru' => $description_ru,
            'ru_source' => $ru_source,
            'repo_url' => $repo,
            'homepage_url' => self::_https_url((string) ($item['homepage_url'] ?? '')) ? (string) $item['homepage_url'] : '',
            'source' => 'github',
            'transport' => $transport,
            'auth_mode' => $auth,
            'health_status' => $health,
            'health_note' => mb_substr(trim(self::_scalar_string($item['health_note'] ?? '')), 0, 300),
            'categories' => self::_categories($item['categories'] ?? []),
            'topics' => $topics,
            'highlights' => $highlights,
            'use_cases' => $use_cases,
            'packages' => $packages,
            'env_keys' => self::_env_keys($item['env_keys'] ?? []),
            'tools' => $tools,
            'remote_url' => $remote_url,
            'remote_kind' => $remote_kind,
            'stars' => max(0, (int) ($item['stars'] ?? 0)),
            'forks' => max(0, (int) ($item['forks'] ?? 0)),
            'language' => mb_substr(trim(self::_scalar_string($item['language'] ?? '')), 0, 60),
            'license' => mb_substr(trim(self::_scalar_string($item['license'] ?? '')), 0, 80),
            'owner_login' => $owner,
            'github_id' => max(0, (int) ($item['github_id'] ?? 0)),
            'trust' => $trust,
            'repo_pushed_at' => $pushed_at,
            'repo_archived' => ! empty($item['repo_archived']),
            'latency_ms' => isset($item['latency_ms']) ? max(0, (int) $item['latency_ms']) : NULL,
            'is_published' => TRUE,
        ]];
    }

    /** Статический разбор инструментов, если модуль MCP Guard подключён. */
    protected static function _guard(array $tools)
    {
        if ( ! class_exists('Service_Nj674_McpGuard'))
        {
            return NULL;
        }

        try
        {
            $report = Service_Nj674_McpGuard::analyze(json_encode(['tools' => $tools]));

            return empty($report['error']) ? $report : NULL;
        }
        catch (Throwable $e)
        {
            return NULL;
        }
    }

    /** Оценка «насколько карточке можно доверять»: 0–100, объяснимая. */
    protected static function _trust_score(array $data)
    {
        $score = 30;
        $stars = (int) $data['stars'];

        if ($stars >= 1000) { $score += 30; }
        elseif ($stars >= 300) { $score += 22; }
        elseif ($stars >= 100) { $score += 15; }
        elseif ($stars >= 20) { $score += 8; }

        if ( ! empty($data['license'])) { $score += 5; }
        if ( ! empty($data['pushed_at']) AND strtotime((string) $data['pushed_at']) > time() - 180 * 86400) { $score += 15; }
        if ( ! empty($data['archived'])) { $score -= 25; }
        if (($data['health'] ?? '') === 'operational') { $score += 15; }
        $score -= 20 * (int) $data['high_risk'];

        return max(0, min(100, $score));
    }

    protected static function _categories($input)
    {
        if ( ! is_array($input))
        {
            $input = preg_split('/[,;\s]+/', self::_scalar_string($input), -1, PREG_SPLIT_NO_EMPTY);
        }

        $out = [];
        foreach ($input as $category)
        {
            if (is_string($category) AND isset(self::CATEGORIES[$category]))
            {
                $out[] = $category;
            }
        }

        return array_values(array_unique($out));
    }

    protected static function _env_keys($input)
    {
        if ( ! is_array($input))
        {
            $input = preg_split('/[,;\s]+/', self::_scalar_string($input), -1, PREG_SPLIT_NO_EMPTY);
        }

        $out = [];
        foreach (array_slice($input, 0, 40) as $key)
        {
            if ( ! is_string($key) AND ! is_numeric($key)) { continue; }
            $key = strtoupper(trim((string) $key));
            if (preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', $key))
            {
                $out[] = $key;
            }
        }

        return array_values(array_unique(array_slice($out, 0, 20)));
    }

    /** Пакет с закреплённой версией: packages[] или legacy-поля. */
    protected static function _pinned_package(array $server)
    {
        foreach ((array) ($server['packages'] ?? []) as $package)
        {
            if ( ! is_array($package))
            {
                continue;
            }
            $manager = (string) ($package['manager'] ?? '');
            // Реестр MCP зовёт имя пакета identifier, автосборка — name.
            $name = (string) ($package['name'] ?? $package['identifier'] ?? '');
            $entrypoint = (string) ($package['entrypoint'] ?? '');

            // Автосборка хранит «package@1.2.3» одной строкой, реестр — имя и
            // версию отдельными полями. Приводим к одному виду: без закреплённой
            // версии команда npx подтянет что угодно, а это чужой код.
            $version = trim((string) ($package['version'] ?? ''));
            if ($version !== '' AND strpos($name, '@', 1) === FALSE AND strpos($name, '==') === FALSE)
            {
                $name = $manager === 'pypi' ? $name.'=='.$version : $name.'@'.$version;
            }

            // PyPI без имени исполняемой команды не годится: uvx без entrypoint
            // либо угадывает, либо падает. Лучше честно «конфигурации нет».
            if ($manager === 'pypi' AND ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,100}$/', $entrypoint))
            {
                continue;
            }

            if (self::_valid_package($manager, $name))
            {
                return [
                    'manager' => $manager,
                    'name' => $name,
                    'entrypoint' => $entrypoint,
                ];
            }
        }

        $manager = (string) ($server['package_manager'] ?? '');
        $name = (string) ($server['package_name'] ?? '');

        if (self::_valid_package($manager, $name))
        {
            return [
                'manager' => $manager,
                'name' => $name,
                'entrypoint' => (string) ($server['package_entrypoint'] ?? ''),
            ];
        }

        return NULL;
    }

    protected static function _category_title($category)
    {
        $category = (string) $category;
        $title = self::CATEGORIES[$category] ?? '';
        $icon = self::CATEGORY_ICONS[$category] ?? '';

        return trim($title.' '.str_replace('fa-', '', $icon));
    }

    protected static function _scalar_string($value)
    {
        return is_scalar($value) ? (string) $value : '';
    }

    protected static function _scalar($value)
    {
        return is_scalar($value) ? $value : 0;
    }

    protected static function _int($value, $default, $min, $max)
    {
        if (is_string($value) AND $value !== '' AND ! is_numeric($value))
        {
            return $default;
        }
        $value = (int) $value;

        return $value <= 0 ? $default : max($min, min($max, $value));
    }

    protected static function _like_escape($value)
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $value);
    }

    protected static function _https_url($url)
    {
        $url = trim((string) $url);
        if ($url === '' OR strlen($url) > 1200 OR filter_var($url, FILTER_VALIDATE_URL) === FALSE)
        {
            return FALSE;
        }
        $parts = parse_url($url);
        if ( ! is_array($parts) OR strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            OR isset($parts['user']) OR isset($parts['pass']) OR ! isset($parts['host']))
        {
            return FALSE;
        }
        $host = strtolower(rtrim(trim((string) $parts['host'], '[]'), '.'));
        if ($host === 'localhost')
        {
            return FALSE;
        }
        foreach (['.localhost', '.local', '.internal', '.test'] as $suffix)
        {
            if (str_ends_with($host, $suffix))
            {
                return FALSE;
            }
        }
        if (filter_var($host, FILTER_VALIDATE_IP)
            AND filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === FALSE)
        {
            return FALSE;
        }

        return TRUE;
    }

    protected static function _looks_sensitive($text)
    {
        return (bool) preg_match('/(?:sk-[A-Za-z0-9_-]{12,}|AKIA[0-9A-Z]{16}|-----BEGIN (?:RSA |EC )?PRIVATE KEY-----|Bearer\s+[A-Za-z0-9._-]{16,})/u', (string) $text);
    }

    protected static function _valid_package($manager, $package)
    {
        $version = '(?:0|[1-9][0-9]*)[.](?:0|[1-9][0-9]*)[.](?:0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?(?:[+][0-9A-Za-z.-]+)?';
        if ($manager === 'npm')
        {
            return (bool) preg_match('/^(?:@[a-z0-9][a-z0-9._-]*\/)?[a-z0-9][a-z0-9._-]*@'.$version.'$/i', (string) $package);
        }
        if ($manager === 'pypi')
        {
            return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,100}=='.$version.'$/i', (string) $package);
        }

        return FALSE;
    }
}
