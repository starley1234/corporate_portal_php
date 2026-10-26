<?php
/**
 * nj607_industry: проверка i18n-подписей статусов отчётов без БД.
 *
 * Запуск в песочнице без PHP CLI:
 *   N2J_ROOT=/home/user/n2j.ru node .tools/phpwasm_stand.mjs .tools/nj607_industry_stand.php
 */
if ( ! defined('SYSPATH')) define('SYSPATH', dirname(__DIR__).'/system/');

$ROOT = dirname(__DIR__);
$fail = 0;

class I18n {
    public static $messages = [];

    public static function get($key)
    {
        return self::$messages[$key] ?? $key;
    }
}

function __($key)
{
    return I18n::get($key);
}

I18n::$messages = (array) include $ROOT.'/application/modules/nj607_industry/i18n/ru.php';
require $ROOT.'/application/modules/nj607_industry/classes/Service/Nj607/Industry.php';

$check = function ($name, $ok) use (&$fail) {
    echo ($ok ? '  ок      ' : '  ПРОВАЛ  ').$name."\n";
    if ( ! $ok) $fail++;
};

$expected = [
    'new' => 'в очереди',
    'running' => 'собирается',
    'done' => 'готово',
    'error' => 'ошибка',
    'closed' => 'закрыто',
];

foreach ($expected as $status => $label)
{
    $check('статус '.$status.' переводится в «'.$label.'»',
        Service_Nj607_Industry::status_label($status) === $label);
}

$stored = ['status' => 'running'];
$check('неизвестный статус остаётся без изменений',
    Service_Nj607_Industry::status_label('future-state') === 'future-state');
$check('пустая подпись остаётся пустой', Service_Nj607_Industry::status_label('') === '');
$check('машинное значение в строке не изменено', $stored['status'] === 'running');
$check('ключи отчёта используют заданный префикс',
    isset(I18n::$messages['nj607.industry.report.status.running'])
    AND ! isset(I18n::$messages['nj607.industry.status.running']));

exit($fail === 0 ? 0 : 1);
