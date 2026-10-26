<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Русские подписи статусов модуля nj607_industry.
 *
 * В БД и API статусы остаются машинными (`new`, `running`, `done`, `error`,
 * `closed`); перевод применяется только в интерфейсе через
 * Service_Nj607_Industry::status_label(). Префикс модуля обязателен: общая
 * I18n::load() объединяет переводы всех модулей.
 */
return [
    // Отчёты nj_607_reports.status.
    'nj607.industry.report.status.new'     => 'в очереди',
    'nj607.industry.report.status.running' => 'собирается',
    'nj607.industry.report.status.done'    => 'готово',
    'nj607.industry.report.status.error'   => 'ошибка',
    'nj607.industry.report.status.closed'  => 'закрыто',

];
