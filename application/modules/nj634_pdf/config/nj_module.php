<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id'        => 634,
    'slug'      => 'pdf',
    'title'     => 'PDF-инструменты',
    'short'     => 'Извлечь текст, посмотреть данные файла, сделать PDF из текста и вырезать страницы',
    'icon'      => 'fas fa-file-pdf',
    'href'      => '/pdf',
    'group'     => 'Разработчику',
    // Семейство домена: четыре номера — один домен (PDF). Каталог показывает
    // их одним разделом, новые возможности PDF идут ВНУТРЬ nj634, а не
    // пятым номером (решение 28.09.2026, план п. 4.5).
    'family'    => 'pdf',
    'sort'      => 634,
    'keywords'  => 'pdf извлечь текст из pdf текст в pdf вырезать страницы pdf метаданные pdf объединить страницы онлайн без скачивания',
    'api'       => ['/pdf/api/info', '/pdf/api/text', '/pdf/api/txt2pdf', '/pdf/api/split', '/pdf/api/merge', '/pdf/api/mergefiles'],
    'card_view' => 'nj634/card',

    // --- Пятый контракт: чистки данных (/nj100/cleanup) ----------------------------
    'cleanup' => [
        'nj634_runs' => [
            'label'      => 'nj634 · история PDF-операций',
            'note'       => 'Прогоны PDF-инструментов (сводки и тексты) старше 30 дней.',
            'days'       => 30,
            'count_sql'  => 'SELECT count(*) FROM nj_634_runs WHERE created_at < now() - :old',
            'delete_sql' => 'DELETE FROM nj_634_runs WHERE id IN (
                                SELECT id FROM nj_634_runs WHERE created_at < now() - :old LIMIT :limit)',
        ],
    ],
];
