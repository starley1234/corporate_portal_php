<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест сервиса «Подписи и реквизиты PDF» (nj636_pdfsign) для nj110_catalog.
 */

return [
    'id'        => 636,
    'slug'      => 'pdf-sign',
    'title'     => 'Подписи и реквизиты PDF',
    'short'     => 'Должность, ФИО и дата поверх документа: подготовьте лист подписи за минуту',
    'icon'      => 'fas fa-pen-nib',
    'href'      => '/pdf-sign',
    'group'     => 'Разработчику',
    // Семейство домена: четыре номера — один домен (PDF). Каталог показывает
    // их одним разделом, новые возможности PDF идут ВНУТРЬ nj634, а не
    // пятым номером (решение 28.09.2026, план п. 4.5).
    'family'    => 'pdf',
    'sort'      => 636,
    'keywords'  => 'подпись пдф реквизиты документа должность фио дата лист подписи печать виза',
    'api'       => ['/pdf-sign/api/stamp', '/pdf-sign/api/count', '/pdf-sign/api/run'],
    'card_view' => 'nj636/card',
];
