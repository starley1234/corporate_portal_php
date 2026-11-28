<?php defined('SYSPATH') or die('No direct script access.');
// План, а не список подключённых модулей. Не создаёт маршрутов/таблиц/прав.
// Для подключённого модуля /portal-plan берёт состояние и заголовок из его
// манифеста (stable = «одобрен», beta/draft = «в работе»). Поле status здесь
// осмысленно только для записей без модуля: planned или archived.
// prxNNN резервирует ID для module:scaffold: ID уникальны, снятый из плана
// (слит/удалён) не переиспользовать. Сверка — tests/Contracts/portal_plan_config.php.
$docflow_status = isset(Kohana::modules()['prx390_intake']) ? 'active' : 'archived';
return ['domains' => [
    'hr' => ['title' => 'HR и развитие персонала', 'services' => [
        ['module' => 'prx300_employee', 'title' => 'Мой профиль сотрудника', 'status' => 'active'],
        ['module' => 'prx301_onboarding', 'title' => 'Онбординг-трек', 'status' => 'planned'],
        ['module' => 'prx302_career', 'title' => 'Карьерный трек', 'status' => 'planned'],
        ['module' => 'prx303_performance', 'title' => 'Управление эффективностью', 'status' => 'planned'],
        ['module' => 'prx304_learning', 'title' => 'Модуль обучения (LMS)', 'status' => 'planned'],
        ['module' => 'prx305_benefits', 'title' => 'Каталог льгот', 'status' => 'planned'],
        ['module' => 'prx306_recognition', 'title' => 'Система признания', 'status' => 'planned'],
        ['module' => 'prx307_leave', 'title' => 'Отпуска и командировки', 'status' => 'planned'],
        ['module' => 'prx308_competencies', 'title' => 'Оценка компетенций', 'status' => 'planned'],
        ['module' => 'prx309_mentoring', 'title' => 'Программа менторства', 'status' => 'planned'],
    ]],
    'communications' => ['title' => 'Корпоративные коммуникации и медиа', 'services' => [
        ['module' => 'prx320_news', 'title' => 'Лента новостей', 'status' => 'planned'],
        // 28.11.2026: «Центр событий» (prx326) слит сюда — календарь и события одной лентой.
        ['module' => 'prx321_calendar', 'title' => 'Календарь и события', 'status' => 'planned'],
        ['module' => 'prx322_video', 'title' => 'Видеотека', 'status' => 'planned'],
        ['module' => 'prx323_achievements', 'title' => 'Стена достижений', 'status' => 'planned'],
        ['module' => 'prx324_feedback', 'title' => 'Анонимная обратная связь', 'status' => 'planned'],
        ['module' => 'prx325_screens', 'title' => 'Корпоративное ТВ', 'status' => 'planned'],
        ['module' => 'prx327_partners', 'title' => 'Наши партнёры', 'status' => 'planned'],
    ]],
    'knowledge' => ['title' => 'База знаний и экспертиза', 'services' => [
        ['module' => 'prx340_wiki', 'title' => 'Вики-энциклопедия', 'status' => 'planned'],
        ['module' => 'prx341_regulations', 'title' => 'База технических регламентов', 'status' => 'planned'],
        ['module' => 'prx342_practices', 'title' => 'Best Practices', 'status' => 'planned'],
        ['module' => 'prx343_experts', 'title' => 'Экспертная карта', 'status' => 'planned'],
        ['module' => 'prx344_faq', 'title' => 'Часто задаваемые вопросы', 'status' => 'planned'],
        ['module' => 'prx345_standards', 'title' => 'Библиотека нормативной документации', 'status' => 'planned'],
        ['module' => 'prx346_materials', 'title' => 'Архив учебных материалов', 'status' => 'planned'],
        // Ответы по регламентам и базе знаний со ссылкой на источник (prx560 + prx115).
        ['module' => 'prx347_assistant', 'title' => 'ИИ-ассистент по регламентам и знаниям', 'status' => 'planned'],
    ]],
    'service' => ['title' => 'Сервисный центр', 'services' => [
        ['module' => 'prx360_helpdesk', 'title' => 'IT-поддержка', 'status' => 'planned'],
        ['module' => 'prx361_office', 'title' => 'Административный сервис', 'status' => 'planned'],
        ['module' => 'prx362_rooms', 'title' => 'Бронирование помещений', 'status' => 'planned'],
        ['module' => 'prx363_transport', 'title' => 'Заказ транспорта', 'status' => 'planned'],
        ['module' => 'prx364_maintenance', 'title' => 'Ремонт оборудования (АХО)', 'status' => 'planned'],
        ['module' => 'prx365_visits', 'title' => 'Пропуска и визиты', 'status' => 'planned'],
        ['module' => 'prx366_catering', 'title' => 'Организация питания', 'status' => 'planned'],
        ['module' => 'prx367_equipment', 'title' => 'Выдача СИЗ и инструментов', 'status' => 'planned'],
    ]],
    'security' => ['title' => 'Безопасность, комплаенс и охрана труда', 'services' => [
        // 28.11.2026: «Инструктажи по охране труда» (prx385) слиты сюда — один журнал допуска.
        ['module' => 'prx380_safety', 'title' => 'Охрана труда: обучение и инструктажи', 'status' => 'planned'],
        ['module' => 'prx381_incidents', 'title' => 'Регистрация инцидентов', 'status' => 'planned'],
        ['module' => 'prx382_ethics', 'title' => 'Линия этики', 'status' => 'planned'],
        ['module' => 'prx383_policies', 'title' => 'Политики безопасности', 'status' => 'planned'],
        ['module' => 'prx384_certificates', 'title' => 'Трекер сертификации', 'status' => 'planned'],
        ['module' => 'prx386_zones', 'title' => 'Карта зон доступа', 'status' => 'planned'],
    ]],
    'docflow' => ['title' => 'Документооборот', 'status' => $docflow_status, 'services' => [
        ['module' => 'prx390_intake', 'title' => 'Входящие документы', 'status' => $docflow_status],
        ['module' => 'prx391_documents', 'title' => 'Реестр входящих', 'status' => $docflow_status],
        ['module' => 'prx392_analysis', 'title' => 'ИИ-анализ входящих', 'status' => $docflow_status],
        ['module' => 'prx393_workflow', 'title' => 'Сроки и маршруты', 'status' => $docflow_status],
        ['module' => 'prx394_drafts', 'title' => 'Ответы на письма', 'status' => 'archived'],
        // prx399 подключается всегда (modules.php), независимо от старого документооборота 390–393.
        ['module' => 'prx399_docmerge', 'title' => 'Слияние договоров и ДС (ИИ)', 'status' => 'active'],
    ]],
    // Инженерный контур: от НСИ и состава изделия до рекламаций. Этапы — enterprise_plan.php.
    'engineering' => ['title' => 'Инженерия и производство', 'services' => [
        ['module' => 'prx400_specflow', 'title' => 'Оцифровка спецификаций', 'status' => 'active'],
        ['module' => 'prx440_nsi', 'title' => 'НСИ: номенклатура и справочники', 'status' => 'planned'],
        ['module' => 'prx441_plm', 'title' => 'Состав изделия и ревизии', 'status' => 'planned'],
        ['module' => 'prx442_eco', 'title' => 'Извещения об изменении (ECO)', 'status' => 'planned'],
        ['module' => 'prx443_claims', 'title' => 'Рекламации и 8D', 'status' => 'planned'],
        ['module' => 'prx444_traceability', 'title' => 'Прослеживаемость по серийным номерам', 'status' => 'planned'],
        ['module' => 'prx445_tooling', 'title' => 'Оснастка и поверка средств измерений', 'status' => 'planned'],
        ['module' => 'prx446_procurement', 'title' => 'Потребность и закупки', 'status' => 'planned'],
    ]],
    'projects' => ['title' => 'Управление проектами', 'services' => [
        // prx400 занят prx400_specflow (единый префикс классов/таблиц/прав) — доска проектов на prx405.
        ['module' => 'prx405_projects', 'title' => 'Доска общих проектов', 'status' => 'planned'],
        ['module' => 'prx401_tasks', 'title' => 'Управление задачами команд', 'status' => 'planned'],
        ['module' => 'prx402_minutes', 'title' => 'Протоколы совещаний', 'status' => 'planned'],
        ['module' => 'prx403_milestones', 'title' => 'Трекер вех', 'status' => 'planned'],
        ['module' => 'prx404_innovation', 'title' => 'Мероприятия по инновациям', 'status' => 'planned'],
    ]],
    'culture' => ['title' => 'Социальная ответственность и культура', 'services' => [
        ['module' => 'prx420_volunteering', 'title' => 'Волонтёрские проекты', 'status' => 'planned'],
        ['module' => 'prx421_store', 'title' => 'Корпоративный магазин', 'status' => 'planned'],
        ['module' => 'prx422_sports', 'title' => 'Спорт и здоровье', 'status' => 'planned'],
        ['module' => 'prx423_surveys', 'title' => 'Pulse Surveys', 'status' => 'planned'],
        ['module' => 'prx424_locations', 'title' => 'Интерактивная карта холдинга', 'status' => 'planned'],
    ]],
]];
