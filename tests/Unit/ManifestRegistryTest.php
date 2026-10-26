<?php

use PHPUnit\Framework\TestCase;

/**
 * Пятый контракт: реестры платформы собраны из манифестов модулей.
 *
 * Эталонные списки ниже сняты с чужих конфигов и агрегаторов ПЕРЕД переносом
 * (28.09.2026): каналы очереди (nj120), расписания (nj603), чистки (nj100),
 * источники «хвоста ошибок» и ленты «последнее». Их смысл — не «проверить,
 * что массив непустой», а доказать, что при переезде не потерялась ни одна
 * запись и ни одна не сменила владельца.
 *
 * Тест намеренно требует ТОЧНОГО совпадения списков. Добавили запись — впишите
 * её сюда: это единственное место, где видны реестры целиком, и заодно повод
 * убедиться, что запись объявлена в правильном модуле.
 */
class ManifestRegistryTest extends TestCase {

    /** Все каналы, которые были в nj120_jobs.channels_ui + reconcile_hooks. */
    private static $channels = [
        'llm.summarize',
        'nj540.reply',
        'nj550.jobs',
        'nj570.clarify',
        'nj570.generate',
        'nj570.render',
        'nj580.generate',
        'nj590.design',
        'nj591.company',
        'nj591.profile',
        'nj592.email',
        'nj593.trends',
        'nj598.dossier',
        'nj600.compact',
        'nj602.article',
        'nj605.radar',
        'nj606.leads',
        'nj607.industry',
        'nj609.watchdog',
        'nj610.inbox',
        'nj612.touch',
        'nj613.digest',
        'nj639.analyze',
        'nj640.analyze',
        'nj641.generate',
        'nj643.cad',
        'nj645.review',
        'nj646.label',
        'nj646.study',
        'nj650.receipt',
        'nj651.meal',
        'nj652.plant',
        'nj653.embed',
        'nj654.embed',
        'erp222.forge',
        // Диалог двух ИИ (nj662_dialog): ход диалога и подбор участников
        'nj662.dialog',
        'nj662.quick',
        // Многоголосая озвучка (nj666_multivoice): разбор книги и озвучка блоков
        'nj666.plan',
        'nj666.voice',
        // Графовый 3D-конструктор (nj667_graph3d): сборка, достройка, саммари,
        // вектор саммари и пре-модерация
        'nj667.generate',
        'nj667.inpaint',
        'nj667.summary',
        'nj667.embed',
        'nj667.moderate',
        // Название/описание и идеи графа (nj667_graph3d)
        'nj667.caption',
        'nj667.ideas',
        // Плоские иллюстрации (nj668_draw2d)
        'nj668.generate',
        'nj668.ideas',
        // 3D-детали (nj669_solid3d): сборка, помощники, экспорт STL, ракурсы
        'nj669.generate',
        'nj669.caption',
        'nj669.ideas',
        'nj669.export',
        'nj669.verify',
        // ProcessHub (nj670_processhub): схема, журнал, аудит, регламент
        'nj670.synthesize',
        'nj670.mine',
        'nj670.diff',
        'nj670.export',
        // Стенд эмбеддингов (nj671_embedbench): сравнение двух путей
        'nj671.bench',
        // Сканер безопасности ИИ-моделей (nj672_aiscan): аудит OWASP + стресс
        'nj672.scan',
        // MCP Hub (nj676_mcphub): автосборка каталога и импорт официального реестра
        'nj676.discover',
        'nj676.registry',
        'nj676.probe',
        // Русские названия и описания карточек каталога (модель в воркере)
        'nj676.translate',
        'semantic.index',
    ];

    /** Канал => модуль-владелец (проверяем, что объявлен в своём модуле). */
    private static $owners = [
        'llm.summarize' => 'llm',
        'nj540.reply' => 'nj540_reply',
        'nj550.jobs' => 'nj550_jobs',
        'nj570.clarify' => 'nj570_models',
        'nj570.generate' => 'nj570_models',
        'nj570.render' => 'nj570_models',
        'nj580.generate' => 'nj580_orgdocs',
        'nj590.design' => 'nj590_design',
        'nj591.company' => 'nj591_company_profile',
        'nj591.profile' => 'nj591_company_profile',
        'nj592.email' => 'nj592_coldmail',
        'nj593.trends' => 'nj593_trends',
        'nj598.dossier' => 'nj598_dossier',
        'nj600.compact' => 'nj600_prefs',
        'nj602.article' => 'nj602_content',
        'nj605.radar' => 'nj605_radar',
        'nj606.leads' => 'nj606_leads',
        'nj607.industry' => 'nj607_industry',
        'nj609.watchdog' => 'nj609_watchdog',
        'nj610.inbox' => 'nj610_inbox',
        'nj612.touch' => 'nj612_touch',
        'nj613.digest' => 'nj613_digest',
        'nj639.analyze' => 'nj639_qc',
        'nj640.analyze' => 'nj640_cameras',
        'nj641.generate' => 'nj641_imagegen',
        'nj643.cad' => 'nj643_cad',
        'nj645.review' => 'nj645_llmlab',
        'nj646.label' => 'nj646_label',
        'nj646.study' => 'nj646_label',
        'nj650.receipt' => 'nj650_receipt',
        'nj651.meal' => 'nj651_meal',
        'nj652.plant' => 'nj652_plant',
        'nj653.embed' => 'nj653_veclab',
        'nj654.embed' => 'nj654_similar',
        'erp222.forge' => 'erp222_forge',
        'nj662.dialog' => 'nj662_dialog',
        'nj662.quick' => 'nj662_dialog',
        'nj666.plan' => 'nj666_multivoice',
        'nj666.voice' => 'nj666_multivoice',
        'nj667.generate' => 'nj667_graph3d',
        'nj667.inpaint' => 'nj667_graph3d',
        'nj667.summary' => 'nj667_graph3d',
        'nj667.embed' => 'nj667_graph3d',
        'nj667.moderate' => 'nj667_graph3d',
        'nj667.caption' => 'nj667_graph3d',
        'nj667.ideas' => 'nj667_graph3d',
        'nj668.generate' => 'nj668_draw2d',
        'nj668.ideas' => 'nj668_draw2d',
        'nj669.generate' => 'nj669_solid3d',
        'nj669.caption' => 'nj669_solid3d',
        'nj669.ideas' => 'nj669_solid3d',
        'nj669.export' => 'nj669_solid3d',
        'nj669.verify' => 'nj669_solid3d',
        'nj670.synthesize' => 'nj670_processhub',
        'nj670.mine' => 'nj670_processhub',
        'nj670.diff' => 'nj670_processhub',
        'nj670.export' => 'nj670_processhub',
        'nj671.bench' => 'nj671_embedbench',
        'nj672.scan' => 'nj672_aiscan',
        'nj676.discover' => 'nj676_mcphub',
        'nj676.registry' => 'nj676_mcphub',
        'nj676.probe' => 'nj676_mcphub',
        'nj676.translate' => 'nj676_mcphub',
        'semantic.index' => 'nj115_semantic',
    ];

    /** Канал => подпись (бывший channels_ui.label). */
    private static $labels = [
        'nj540.reply' => 'Ответ на отзыв',
        'nj550.jobs' => 'Поиск вакансий',
        'nj570.generate' => '3D-модель',
        'nj570.clarify' => '3D-модель (опрос)',
        'nj570.render' => '3D-модель (рендер)',
        'nj580.generate' => 'Документы организации',
        'nj590.design' => 'Дизайн страницы',
        'nj591.company' => 'Профиль компании',
        'nj592.email' => 'Холодное письмо',
        'nj593.trends' => 'Тренды GitHub',
        'nj598.dossier' => 'AI Досье на работодателя',
        'nj600.compact' => 'Сжатие профилей (nj600)',
        'nj602.article' => 'Статья в журнал (nj602)',
        'nj605.radar' => 'Радар заказов (nj605)',
        'nj606.leads' => 'Поток заказчиков (nj606)',
        'nj607.industry' => 'Карта отрасли (nj607)',
        'nj609.watchdog' => 'Сторож контрагентов (nj609)',
        'nj610.inbox' => 'Входящие под контролем (nj610)',
        'semantic.index' => 'Семантический индекс (поиск)',
        'llm.summarize' => 'ИИ-объяснение сетевой проверки (nj633)',
        'nj612.touch' => 'Серия касаний (nj612)',
        'nj613.digest' => 'Дайджест недели (nj613)',
        'nj645.review' => 'Лаборатория LLM: прогон промпта',
        'nj646.label' => 'Разбор состава: фото этикетки',
        'nj646.study' => 'Разбор состава: изучение ингредиента',
        'nj650.receipt' => 'Чек по фото: распознавание',
        'nj651.meal' => 'Калории по фото: разбор тарелки',
        'nj652.plant' => 'Растение по фото: определение и уход',
        'nj653.embed' => 'Лаборатория векторов: мультимодальный эмбеддинг',
        'nj654.embed' => 'Похожие картинки: вектор элемента или запроса',
        // Кузница систем (erp222_forge): цикл синтеза — роли выпускают артефакты
        'erp222.forge' => 'Кузница систем (erp222)',
        // Диалог двух ИИ (nj662_dialog): ход ведёт сайт, обмен считает воркер
        'nj662.dialog' => 'Диалог двух ИИ · обмен репликами (nj662)',
        'nj662.quick' => 'Диалог двух ИИ · подбор агентов (nj662)',
        // Многоголосая озвучка книги (nj666_multivoice)
        'nj666.plan' => 'Многоголосая озвучка · разбор книги (nj666)',
        'nj666.voice' => 'Многоголосая озвучка · озвучка блоков (nj666)',
        'nj667.generate' => 'Графовый 3D-конструктор · сборка графа по описанию (nj667)',
        'nj667.inpaint' => 'Графовый 3D-конструктор · достройка выделенного (nj667)',
        'nj667.summary' => 'Графовый 3D-конструктор · саммари графа (nj667)',
        'nj667.embed' => 'Графовый 3D-конструктор · вектор саммари (nj667)',
        'nj667.moderate' => 'Графовый 3D-конструктор · пре-модерация (nj667)',
        'nj667.caption' => 'Графовый 3D-конструктор · название и описание (nj667)',
        'nj667.ideas' => 'Графовый 3D-конструктор · идеи для сборки (nj667)',
        'nj668.generate' => 'Плоские иллюстрации · сборка сцены по описанию (nj668)',
        'nj668.ideas' => 'Плоские иллюстрации · идеи для сборки (nj668)',
        'nj669.generate' => '3D-детали · сборка модели',
        'nj669.caption' => '3D-детали · название, описание, теги',
        'nj669.ideas' => '3D-детали · идеи для создания',
        'nj669.export' => '3D-детали · STL под свои размеры',
        'nj669.verify' => '3D-детали · проверка ракурсов ИИ (по запросу администратора)',
        'nj670.synthesize' => 'ProcessHub · схема по тексту (план / AI-достройка)',
        'nj670.mine' => 'ProcessHub · разбор журнала событий (факт)',
        'nj670.diff' => 'ProcessHub · AI-аудит расхождений плана и факта',
        'nj670.export' => 'ProcessHub · выпуск регламента DOCX/PDF',
        // Стенд эмбеддингов: один материал через два пути получить вектор
        'nj671.bench' => 'Стенд эмбеддингов · сравнение двух путей (nj671)',
        // Сканер безопасности ИИ-моделей: батарея атак OWASP + стресс-профиль
        'nj672.scan' => 'Сканер безопасности ИИ-моделей · аудит OWASP + стресс (nj672)',
        'nj676.discover' => 'MCP Hub · автосборка каталога',
        'nj676.registry' => 'MCP Hub · импорт официального реестра',
        'nj676.probe' => 'MCP Hub · проверка живости серверов',
        'nj676.translate' => 'MCP Hub · русские названия и описания',
    ];

    /** Каналы, у которых было условие дожима (бывший reconcile_hooks). */
    private static $reconcile = [
        'nj591.profile',
        'nj593.trends',
        'nj598.dossier',
        'nj602.article',
        'nj605.radar',
        'nj606.leads',
        'nj607.industry',
        'nj609.watchdog',
        'nj610.inbox',
        'nj612.touch',
        'nj613.digest',
        'nj639.analyze',
        'nj640.analyze',
        'nj641.generate',
        'nj643.cad',
        'nj645.review',
        'nj646.label',
        'nj646.study',
        'nj650.receipt',
        'nj651.meal',
        'nj652.plant',
        'nj653.embed',
        'nj654.embed',
        // Кузница систем: цикл закрыт ИЛИ уже выпустил артефакт — задача не в ретрае
        'erp222.forge',
        // Диалог двух ИИ: реплики обмена лежат в базе / диалог уже не ждёт подбора
        'nj662.dialog',
        'nj662.quick',
        // Многоголосая озвучка: книга вышла из «разбирается» / «озвучивается»
        'nj666.plan',
        'nj666.voice',
        // Графовый 3D-конструктор: у проекта снят job_id (граф принят, саммари
        // записано, вектор посчитан) или в журнале появилась пометка guard
        'nj667.generate',
        'nj667.inpaint',
        'nj667.summary',
        'nj667.embed',
        'nj667.moderate',
        // Название/описание и идеи графа (nj667_graph3d)
        'nj667.caption',
        'nj667.ideas',
        // Плоские иллюстрации (nj668_draw2d)
        'nj668.generate',
        'nj668.ideas',
        // 3D-детали (nj669_solid3d): сборка, помощники, экспорт STL, ракурсы
        'nj669.generate',
        'nj669.caption',
        'nj669.ideas',
        'nj669.export',
        'nj669.verify',
        // ProcessHub (nj670_processhub): схема, журнал, аудит, регламент
        'nj670.synthesize',
        'nj670.mine',
        'nj670.diff',
        'nj670.export',
        // Стенд эмбеддингов (nj671_embedbench): сравнение двух путей
        'nj671.bench',
        // Сканер безопасности ИИ-моделей (nj672_aiscan): повтор = повторная атака
        'nj672.scan',
        'semantic.index',
    ];

    /** Расписание => модуль-владелец (бывший nj603_kicker.schedules). */
    private static $schedule_owners = [
        'notifications' => 'nj597_notifications',
        'trends' => 'nj593_trends',
        'content' => 'nj602_content',
        'maintenance' => 'nj120_jobs',
        'radar' => 'nj605_radar',
        'leads' => 'nj606_leads',
        'watchdog' => 'nj609_watchdog',
        'inbox' => 'nj610_inbox',
        'touches' => 'nj612_touch',
        'digest' => 'nj613_digest',
        'vacancies' => 'nj642_vacancies',
        'qc' => 'nj639_qc',
        'cameras' => 'nj640_cameras',
        'example' => 'nj603_kicker',
    ];

    /** Расписание => период: частота пинка не должна меняться при переезде. */
    private static $schedule_every = [
        'notifications' => '5 minutes',
        'trends' => '12 hours',
        'content' => '2 hours',
        'maintenance' => '10 minutes',
        'radar' => '30 minutes',
        'leads' => '1 hour',
        'watchdog' => '6 hours',
        'inbox' => '30 minutes',
        'touches' => '30 minutes',
        'digest' => '1 hour',
        'vacancies' => '3 hours',
        'qc' => '30 minutes',
        'cameras' => '10 minutes',
        'example' => '1 hour',
    ];

    /** Чистка => модуль-владелец (бывший конфиг nj100_cleanup). */
    private static $cleanup_owners = [
        'nj600_events' => 'nj600_prefs',
        'nj600_reco_log' => 'nj600_prefs',
        'nj516_views' => 'nj516_metrics',
        'nj599_logins' => 'nj599_account',
        'nj120_jobs' => 'nj120_jobs',
        'nj120_usage' => 'nj120_jobs',
        'nj550_results' => 'nj550_jobs',
        'nj598_dossiers' => 'nj598_dossier',
        'erp208_events' => 'erp208_events',
        'nj633_checks' => 'nj633_nettools',
        'nj633_notes' => 'nj633_nettools',
        'nj634_runs' => 'nj634_pdf',
        'nj115_query_cache' => 'nj115_semantic',
        'nj115_searches' => 'nj115_semantic',
        'nj_645_runs' => 'nj645_llmlab',
        'nj_645_guesses' => 'nj645_llmlab',
        'nj_645_corpus' => 'nj645_llmlab',
        'nj_646_runs' => 'nj646_label',
        'nj_646_analyses' => 'nj646_label',
        'nj_646_profiles' => 'nj646_label',
        'nj_650_runs' => 'nj650_receipt',
        'nj_651_runs' => 'nj651_meal',
        'nj_652_runs' => 'nj652_plant',
        'nj_653_runs' => 'nj653_veclab',
        'nj_653_items' => 'nj653_veclab',
        'nj_654_queries' => 'nj654_similar',
        'nj_657_dialogs' => 'nj657_alice',
        'nj_660_runs' => 'nj660_arch',
        'nj_661_items' => 'nj661_airadar',
        'nj_661_runs' => 'nj661_airadar',
        // Кузница систем: артефакты и миры — навсегда, стареют только циклы и лента
        'erp_222_runs' => 'erp222_forge',
        'erp_222_events' => 'erp222_forge',
        // Диалог двух ИИ: живут долго сами диалоги и реплики, стареет журнал
        'nj_662_searches' => 'nj662_dialog',
        'nj_662_events' => 'nj662_dialog',
        // Озвучивание текста (nj665): файлы выметает sweep(), здесь строки журнала
        'nj_665_speeches' => 'nj665_voice',
        // Многоголосая озвучка (nj666): книги живут 30 дней, файлы чистит sweep()
        'nj_666_books' => 'nj666_multivoice',
        // Графовый 3D-конструктор (nj667): проекты, история правок и журнал
        // модерации; гостевые черновики убирает свой sweep()
        'nj_667_projects' => 'nj667_graph3d',
        'nj_667_revisions' => 'nj667_graph3d',
        'nj_667_events' => 'nj667_graph3d',
        // Уведомления (nj597): лента, очередь доставки и журнал попыток
        'nj597_inbox' => 'nj597_notifications',
        'nj597_outbox' => 'nj597_notifications',
        'nj597_log' => 'nj597_notifications',
        // Комплекты favicon (nj663) и плоские иллюстрации (nj668)
        'nj_663_packs' => 'nj663_favicon',
        'nj_668_projects' => 'nj668_draw2d',
        'nj_668_revisions' => 'nj668_draw2d',
        // 3D-детали (nj669): удалённые проекты, помощники, журнал
        'nj_669_solid3d_projects' => 'nj669_solid3d',
        'nj_669_solid3d_tasks' => 'nj669_solid3d',
        'nj_669_solid3d_events' => 'nj669_solid3d',
        // ProcessHub (nj670): импорты, журнал, старые регламенты
        'nj_670_imports' => 'nj670_processhub',
        'nj_670_ontology_jobs' => 'nj670_processhub',
        'nj_670_events' => 'nj670_processhub',
        'nj_670_reports' => 'nj670_processhub',
        // Стенд эмбеддингов (nj671): отчёты сравнения стареют
        'nj_671_runs' => 'nj671_embedbench',
        // Сканер безопасности ИИ (nj672): отчёты аудита с доказательствами
        'nj_672_runs' => 'nj672_aiscan',
        // MCP Hub: журнал автосборки каталога (карточки остаются)
        'nj_676_runs' => 'nj676_mcphub',
        // Разговор с ИИ голосом (nj677): реплики и сами разговоры живут 30 дней
        'nj_677_messages' => 'nj677_talk',
        'nj_677_talks' => 'nj677_talk',
    ];

    /** Чистка => порог хранения в днях: пороги не должны молча меняться. */
    private static $cleanup_days = [
        'nj600_events' => 21,
        'nj600_reco_log' => 90,
        'nj516_views' => 400,
        'nj599_logins' => 90,
        'nj120_jobs' => 60,
        'nj120_usage' => 400,
        'nj550_results' => 30,
        'nj598_dossiers' => 180,
        'erp208_events' => 90,
        'nj633_checks' => 30,
        'nj633_notes' => 30,
        'nj634_runs' => 30,
        'nj115_query_cache' => 30,
        'nj115_searches' => 30,
        'nj_645_runs' => 90,
        'nj_645_guesses' => 180,
        'nj_645_corpus' => 30,
        'nj_646_runs' => 90,
        'nj_646_analyses' => 180,
        'nj_646_profiles' => 365,
        'nj_650_runs' => 90,
        'nj_651_runs' => 90,
        'nj_652_runs' => 90,
        'nj_653_runs' => 30,
        'nj_653_items' => 30,
        'nj_654_queries' => 90,
        'nj_657_dialogs' => 180,
        'nj_660_runs' => 180,
        'nj_661_items' => 365,
        'nj_661_runs' => 180,
        'erp_222_runs' => 180,
        'erp_222_events' => 180,
        'nj_662_searches' => 180,
        'nj_662_events' => 180,
        'nj_665_speeches' => 30,
        'nj_667_projects' => 180,
        'nj_667_revisions' => 180,
        'nj_667_events' => 180,
        'nj_666_books' => 30,
        'nj597_inbox' => 180,
        'nj597_outbox' => 60,
        'nj597_log' => 60,
        'nj_663_packs' => 30,
        'nj_668_projects' => 180,
        'nj_668_revisions' => 180,
        'nj_669_solid3d_projects' => 180,
        'nj_669_solid3d_tasks' => 180,
        'nj_669_solid3d_events' => 180,
        'nj_670_imports' => 90,
        'nj_670_ontology_jobs' => 90,
        'nj_670_events' => 180,
        'nj_670_reports' => 60,
        'nj_671_runs' => 180,
        'nj_672_runs' => 90,
        'nj_676_runs' => 180,
        'nj_677_messages' => 30,
        'nj_677_talks' => 30,
    ];

    /** Таблицы «хвоста ошибок» (бывший $sources в Nj100/Errors). */
    private static $health_tables = [
        'nj_120_jobs' => 'nj120_jobs',
        'nj_130_agent_runs' => 'nj130_agents',
        'nj_540_replies' => 'nj540_reply',
        'nj_550_searches' => 'nj550_jobs',
        'nj_580_packages' => 'nj580_orgdocs',
        'nj_590_runs' => 'nj590_design',
        'nj_591_profiles' => 'nj591_company_profile',
        'nj_592_letters' => 'nj592_coldmail',
        'nj_597_outbox' => 'nj597_notifications',
        'nj_598_dossiers' => 'nj598_dossier',
        'nj_602_articles' => 'nj602_content',
    ];

    /** Таблицы ленты «последнее» (бывший $src в Nj100/Dashboard). */
    private static $recent_tables = [
        'nj_530_links' => 'nj530_jump',
        'nj_550_searches' => 'nj550_jobs',
        'nj_570_models' => 'nj570_models',
        'nj_580_packages' => 'nj580_orgdocs',
        'nj_591_profiles' => 'nj591_company_profile',
        'nj_593_reviews' => 'nj593_trends',
        'nj_667_projects' => 'nj667_graph3d',
        'nj_668_projects' => 'nj668_draw2d',
    ];

    /** Источники глобального поиска (бывший $queries в Service_Nj100_Search). */
    private static $search_types = [
        'orgdocs' => 'nj580_orgdocs',
        'profile' => 'nj591_company_profile',
        'model'   => 'nj570_models',
        'kb'      => 'nj560_knowledge',
        'org'     => 'nj594_orgregistry',
        'job'     => 'nj550_jobs',
        'letter'  => 'nj592_coldmail',
        'dossier' => 'nj598_dossier',
        'subject' => 'nj609_watchdog',
    ];

    /** Порядок групп на /search — он виден пользователю, значит закреплён. */
    private static $search_order = [
        'orgdocs', 'profile', 'model', 'kb', 'org', 'job', 'letter', 'dossier', 'subject',
    ];

    public function test_manifest_class_loaded(): void
    {
        $this->assertTrue(class_exists('Manifest'), 'Класс Manifest не загрузился');
    }

    public function test_all_channels_present(): void
    {
        $actual = array_keys(Manifest::channels());
        sort($actual);
        $expected = self::$channels;
        sort($expected);

        $this->assertSame($expected, $actual,
            'Список каналов разошёлся с эталоном: '
            .'пропали ['.implode(', ', array_diff($expected, $actual)).'], '
            .'появились ['.implode(', ', array_diff($actual, $expected)).']');
    }

    public function test_each_channel_owned_by_its_module(): void
    {
        $channels = Manifest::channels();

        foreach (self::$owners as $channel => $module)
        {
            $this->assertArrayHasKey($channel, $channels, "Канал $channel не найден");
            $this->assertSame($module, $channels[$channel]['module'],
                "Канал $channel объявлен не тем модулем");
        }
    }

    public function test_channel_labels_preserved(): void
    {
        $ui = Manifest::channels_ui();

        foreach (self::$labels as $channel => $label)
        {
            $this->assertArrayHasKey($channel, $ui, "Нет подписи канала $channel");
            $this->assertSame($label, $ui[$channel]['label'], "Подпись $channel изменилась");
        }

        $this->assertCount(count(self::$labels), $ui, 'Число подписей не совпало с эталоном');
    }

    public function test_reconcile_conditions_preserved(): void
    {
        $hooks = Manifest::reconcile_hooks();
        $expected = self::$reconcile;
        sort($expected);
        $actual = array_keys($hooks);
        sort($actual);

        $this->assertSame($expected, $actual, 'Набор reconcile-условий разошёлся с эталоном');

        foreach ($hooks as $channel => $sql)
        {
            $this->assertStringStartsWith('SELECT 1 FROM', trim($sql),
                "Условие $channel должно быть проверкой существования (SELECT 1 FROM …)");
            $this->assertStringContainsString('j.', $sql,
                "Условие $channel должно ссылаться на строку задачи (алиас j)");
        }
    }

    public function test_all_schedules_present(): void
    {
        $actual = array_keys(Manifest::schedules());
        sort($actual);
        $expected = array_keys(self::$schedule_owners);
        sort($expected);

        $this->assertSame($expected, $actual,
            'Список расписаний разошёлся с эталоном: '
            .'пропали ['.implode(', ', array_diff($expected, $actual)).'], '
            .'появились ['.implode(', ', array_diff($actual, $expected)).']');
    }

    public function test_each_schedule_owned_by_its_module(): void
    {
        $schedules = Manifest::schedules();

        foreach (self::$schedule_owners as $key => $module)
        {
            $this->assertArrayHasKey($key, $schedules, "Расписание $key не найдено");
            $this->assertSame($module, $schedules[$key]['module'],
                "Расписание $key объявлено не тем модулем");
        }
    }

    public function test_schedule_periods_preserved(): void
    {
        $schedules = Manifest::schedules();

        foreach (self::$schedule_every as $key => $every)
        {
            $this->assertSame($every, (string) $schedules[$key]['every'],
                "Частота расписания $key изменилась при переезде");
        }
    }

    public function test_kicker_reads_schedules_from_manifests(): void
    {
        $this->assertSame(
            array_keys(Manifest::schedules()),
            array_keys(Service_Nj603_Kicker::schedules()),
            'Планировщик читает не тот источник, что Manifest');
    }

    public function test_all_cleanups_present(): void
    {
        $actual = array_keys(Manifest::cleanups());
        sort($actual);
        $expected = array_keys(self::$cleanup_owners);
        sort($expected);

        $this->assertSame($expected, $actual,
            'Список чисток разошёлся с эталоном: '
            .'пропали ['.implode(', ', array_diff($expected, $actual)).'], '
            .'появились ['.implode(', ', array_diff($actual, $expected)).']');
    }

    public function test_each_cleanup_owned_by_its_module(): void
    {
        $cleanups = Manifest::cleanups();

        foreach (self::$cleanup_owners as $key => $module)
        {
            $this->assertArrayHasKey($key, $cleanups, "Чистка $key не найдена");
            $this->assertSame($module, $cleanups[$key]['module'], "Чистка $key объявлена не тем модулем");
        }
    }

    public function test_cleanup_thresholds_preserved(): void
    {
        $cleanups = Manifest::cleanups();

        foreach (self::$cleanup_days as $key => $days)
        {
            $this->assertSame($days, (int) $cleanups[$key]['days'],
                "Порог хранения для $key изменился при переезде");
        }
    }

    public function test_cleanup_deletes_in_batches(): void
    {
        // Удаление идёт порциями по клику: запрос без LIMIT способен положить
        // базу на большой таблице. Требование было в шапке старого конфига —
        // при переезде в манифесты его нужно было сохранить проверкой.
        foreach (Manifest::cleanups() as $key => $spec)
        {
            $this->assertStringContainsString('LIMIT', (string) $spec['delete_sql'],
                "Чистка $key удаляет без LIMIT — так нельзя");
        }
    }

    public function test_health_sources_present(): void
    {
        $this->_assert_registry(Manifest::health(), self::$health_tables, 'хвост ошибок');
    }

    public function test_recent_sources_present(): void
    {
        $this->_assert_registry(Manifest::recent(), self::$recent_tables, 'лента «последнее»');
    }

    public function test_recent_sql_fragments_are_not_user_data(): void
    {
        // title/base/icon подставляются в SQL как выражения. Манифест — код в
        // репозитории, а не данные, но проверка дешёвая и ловит опечатку вида
        // «кавычка внутри значения», которая превратилась бы в сломанный SQL.
        foreach (Manifest::recent() as $table => $spec)
        {
            $this->assertFalse(strpos((string) $spec['base'], "'") !== FALSE,
                "В base таблицы $table есть кавычка — сломает запрос");
            $this->assertFalse(strpos((string) $spec['icon'], "'") !== FALSE,
                "В icon таблицы $table есть кавычка — сломает запрос");
        }
    }

    public function test_search_sources_present(): void
    {
        $this->_assert_registry(Manifest::search(), self::$search_types, 'глобальный поиск');
    }

    public function test_search_group_order_preserved(): void
    {
        $this->assertSame(self::$search_order, array_keys(Manifest::search()),
            'Порядок групп на /search изменился — его задаёт ключ sort в манифестах');
    }

    public function test_search_sql_declares_its_own_kind(): void
    {
        foreach (Manifest::search() as $type => $spec)
        {
            $this->assertArrayHasKey('sql', $spec, "Источник поиска {$type} объявлен без запроса");
            $this->assertMatchesRegularExpression("/SELECT\\s+'".$type."'\\s+AS kind/", $spec['sql'],
                "Запрос источника {$type} возвращает чужой kind");

            foreach (['t1', 't2', 'sub', 'url', 'created_at'] as $col)
            {
                $this->assertStringContainsString($col, $spec['sql'],
                    "Запрос источника {$type} не отдаёт колонку {$col}, которую ждёт агрегатор");
            }

            $this->assertStringContainsString(':l', $spec['sql'],
                "Запрос источника {$type} не использует образец поиска :l");
            $this->assertStringContainsString('LIMIT :per', $spec['sql'],
                "Запрос источника {$type} не ограничен лимитом агрегатора");
        }
    }

    public function test_search_personal_sources_are_scoped_by_user(): void
    {
        foreach (Manifest::search() as $type => $spec)
        {
            // скобки обязательны: у AND приоритет ниже, чем у присваивания
            $personal = (isset($spec['scope']) AND $spec['scope'] === 'user');

            $this->assertSame($personal, strpos($spec['sql'], ':scope') !== FALSE,
                "У источника {$type} scope и :scope в запросе не согласованы: "
                .'личные данные обязаны фильтроваться по user_id');
        }
    }

    public function test_search_service_reads_sources_from_manifests(): void
    {
        $file = APPPATH.'modules/nj100_search/classes/Service/Nj100/Search.php';

        $this->assertFileExists($file);
        $code = file_get_contents($file);

        $this->assertStringNotContainsString('$queries = [', $code,
            'Реестр запросов вернулся в nj100_search — он живёт в манифестах владельцев');
        $this->assertStringContainsString('Manifest::search()', $code,
            'Service_Nj100_Search больше не читает манифесты');
    }

    /**
     * Манифесты читает ОДИН сборщик. До 28.09.2026 их сканировали трое —
     * Manifest, каталог nj110 и справочник nj596, — и только у первого был кэш.
     */
    public function test_manifests_have_a_single_reader(): void
    {
        $offenders = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(APPPATH, FilesystemIterator::SKIP_DOTS));

        foreach ($it as $file)
        {
            if ($file->getExtension() !== 'php')
            {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());

            // сами манифесты и единственный законный сборщик
            if (strpos($path, '/config/nj_module.php') !== FALSE
                OR strpos($path, '/classes/Manifest.php') !== FALSE)
            {
                continue;
            }

            $code = file_get_contents($path);

            if (preg_match('/include[^;]*nj_module\\.php/', $code))
            {
                $offenders[] = basename($path);
            }
        }

        $this->assertSame([], $offenders,
            'Эти файлы включают манифесты сами, мимо Manifest::all(): '.implode(', ', $offenders));
    }

    /**
     * Каждый свой подключённый модуль объявляет себя. Реестр в
     * docs/ARCHITECTURE.md §2 генерируется из манифестов: модуль без `title`
     * выпадет из документации молча.
     */
    public function test_every_connected_module_declares_itself(): void
    {
        $missing = [];

        foreach (Kohana::modules() as $name => $path)
        {
            // модули ядра Kohana лежат вне application/ — они не наши
            if (strpos($path, APPPATH) !== 0)
            {
                continue;
            }

            $manifest = Manifest::get($name);

            if (empty($manifest['title']))
            {
                $missing[] = $name;
            }
        }

        $this->assertSame([], $missing,
            'Нет config/nj_module.php с ключом title у модулей: '.implode(', ', $missing)
            .'. Без него модуль не попадёт в реестр docs/ARCHITECTURE.md §2.');
    }

    /**
     * Семейство домена: несколько номеров — один раздел. У семейства ровно
     * одна глава — модуль, чей `slug` совпадает с именем семейства; её
     * заголовок и становится названием раздела. Двух глав быть не может:
     * тогда название раздела зависело бы от порядка чтения манифестов.
     */
    public function test_domain_families_have_exactly_one_head(): void
    {
        $by_family = [];

        foreach (Manifest::all() as $name => $m)
        {
            if (empty($m['family']))
            {
                continue;
            }

            $by_family[(string) $m['family']][$name] = isset($m['slug']) ? (string) $m['slug'] : '';
        }

        $this->assertNotSame([], $by_family,
            'Ни одного семейства не объявлено — проверка потеряла смысл.');

        foreach ($by_family as $family => $members)
        {
            $heads = [];

            foreach ($members as $name => $slug)
            {
                if ($slug === $family)
                {
                    $heads[] = $name;
                }
            }

            $this->assertCount(1, $heads,
                'У семейства «'.$family.'» должна быть ровно одна глава (модуль со slug '
                .$family.'), а нашлось: '.($heads ? implode(', ', $heads) : 'ни одной'));
        }
    }

    /**
     * Каталог показывает семейство одним разделом: иначе четыре номера одного
     * домена снова растащит по четырём строкам меню.
     */
    public function test_family_is_shown_as_one_section(): void
    {
        $groups = [];

        foreach (Service_Nj110_Catalog::modules() as $m)
        {
            if (empty($m['family']))
            {
                continue;
            }

            $groups[(string) $m['family']][(string) $m['group']] = TRUE;
        }

        $this->assertNotSame([], $groups, 'Семейств в каталоге не видно.');

        foreach ($groups as $family => $names)
        {
            $this->assertCount(1, $names,
                'Семейство «'.$family.'» разъехалось по разделам: '.implode(', ', array_keys($names)));
        }
    }

    /**
     * Меню сервисов ставит базовый контроллер. До 28.09.2026 один и тот же
     * вызов с одной и той же защитой `class_exists` был скопирован в 102
     * контроллера — 103 вхождения, байт в байт одинаковых.
     */
    public function test_menu_is_not_copy_pasted_into_controllers(): void
    {
        $offenders = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(APPPATH, FilesystemIterator::SKIP_DOTS));

        foreach ($it as $file)
        {
            if ($file->getExtension() !== 'php')
            {
                continue;
            }

            $code = file_get_contents($file->getPathname());

            if (strpos($code, "set_menu(class_exists('Service_Nj110_Catalog')") !== FALSE)
            {
                $offenders[] = basename($file->getPathname());
            }
        }

        $this->assertSame([], $offenders,
            'Меню ставит Controller_Erp100_Base::before(). Свой набор пунктов — '
            .'обычный set_menu() после parent::before(). Копии в: '.implode(', ', $offenders));
    }

    /** Общая проверка реестра: состав и владелец каждой записи. */
    private function _assert_registry(array $actual, array $expected, string $what): void
    {
        $have = array_keys($actual);
        $want = array_keys($expected);
        sort($have);
        sort($want);

        $this->assertSame($want, $have,
            "Реестр «{$what}» разошёлся с эталоном: "
            .'пропали ['.implode(', ', array_diff($want, $have)).'], '
            .'появились ['.implode(', ', array_diff($have, $want)).']');

        foreach ($expected as $key => $module)
        {
            $this->assertSame($module, $actual[$key]['module'],
                "В реестре «{$what}» запись $key объявлена не тем модулем");
        }
    }
}
