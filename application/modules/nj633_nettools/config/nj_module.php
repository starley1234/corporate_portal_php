<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id'        => 633,
    'slug'      => 'net',
    'title'     => 'Сетевые инструменты',
    'short'     => 'DNS, WHOIS, доступность порта и проверка хоста — с защитой от SSRF',
    'icon'      => 'fas fa-shield-halved',
    'href'      => '/net',
    'group'     => 'Разработчику',
    'sort'      => 633,
    'keywords'  => 'dns whois ping доступность порт host сетевая проверка ssrf безопасность',
    'api'       => ['/net/api/dns', '/net/api/whois', '/net/api/check', '/net/api/ipconv', '/net/api/base64'],
    'card_view' => 'nj633/card',

    // --- Пятый контракт: чистки данных (/nj100/cleanup) ----------------------------
    'cleanup' => [
        'nj633_checks' => [
            'label'      => 'nj633 · история сетевых проверок',
            'note'       => 'Сырьё диагностики: гостевые DNS/WHOIS/TCP-проверки старше 30 дней.',
            'days'       => 30,
            'count_sql'  => 'SELECT count(*) FROM nj_633_checks WHERE created_at < now() - :old',
            'delete_sql' => 'DELETE FROM nj_633_checks WHERE id IN (
                                SELECT id FROM nj_633_checks WHERE created_at < now() - :old LIMIT :limit)',
        ],
        'nj633_notes' => [
            'label'      => 'nj633 · LLM-объяснения сетевых проверок',
            'note'       => 'Черновики ИИ-пояснений старше 30 дней.',
            'days'       => 30,
            'count_sql'  => 'SELECT count(*) FROM nj_633_notes WHERE created_at < now() - :old',
            'delete_sql' => 'DELETE FROM nj_633_notes WHERE id IN (
                                SELECT id FROM nj_633_notes WHERE created_at < now() - :old LIMIT :limit)',
        ],
    ],
];
