<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Конфиг nj601_billing: тарификация AI-операций в кредитах.
 *
 * cost — цена одной операции модуля в кредитах (0 = бесплатно/beta);
 * free_per_month — бесплатная норма на субъекта в месяц;
 * packs — пакеты кредитов: ключ => [цена ₽, кредитов, подпись счёта].
 */
return [

    'free_per_month' => 3,

    'cost' => [
        'nj591_company_profile' => 1,
        'nj592_coldmail'        => 1,
        'nj598_dossier'         => 2,
        'nj605_radar'           => 0, // подписка на закупки — beta
        'nj606_leads'           => 1, // один сбор потока
        'nj607_industry'        => 5, // карта отрасли (отчёт)
        'nj609_watchdog'        => 0, // сторож контрагентов — beta
    ],

    'packs' => [
        'pack_10'  => ['price' => 500,  'credits' => 10,  'label' => 'Старт: 10 кредитов'],
        'pack_40'  => ['price' => 1500, 'credits' => 40,  'label' => 'Работа: 40 кредитов'],
        'pack_200' => ['price' => 5000, 'credits' => 200, 'label' => 'Поток: 200 кредитов'],
    ],
];
