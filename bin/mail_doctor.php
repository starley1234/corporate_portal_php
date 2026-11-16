#!/usr/bin/env php
<?php defined('STDIN') or define('STDIN', fopen('php://stdin', 'r'));

/**
 * Диагностика почты PRL.ru (prl597_notifications + symfony/mailer).
 *
 * Запуск на сервере из корня сайта:
 *     php bin/mail_doctor.php кому@пример.ру
 *     php bin/mail_doctor.php кому@пример.ру --verbose
 *
 * Скрипт самодостаточен (без Kohana): проверяет PHP, .env, composer-пакеты,
 * DNS/соединение с SMTP-хостом и отправляет тестовое письмо. Все секреты
 * маскируются. Итог и лог — на stdout: скопируйте вывод целиком, если
 * что-то пошло не так.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);
$TO   = isset($argv[1]) ? trim($argv[1]) : '';
$VERBOSE = in_array('--verbose', $argv, TRUE);

out("=== Диагностика почты PRL.ru (prl597) — ".date('d.m.Y H:i:s')." ===");
out("Корень сайта: $ROOT");

if ($TO === '' OR ! filter_var($to = $TO, FILTER_VALIDATE_EMAIL))
{
    out("[FAIL] Не указан адрес получателя.");
    out("Использование: php bin/mail_doctor.php кому@пример.ру [--verbose]");
    exit(1);
}

$fail = 0;

// ---------------------------------------------------------------- 1. PHP
out('');
out("[1/7] PHP");
out("  версия: ".PHP_VERSION." (".PHP_OS.")");

foreach (['openssl', 'mbstring', 'curl'] as $ext)
{
    $ok = extension_loaded($ext);
    out("  ext $ext: ".($ok ? 'есть' : 'НЕТ').($ok ? '' : '  <-- 可能 причина проблем TLS/кодирования'));
    $ok OR $fail = 1;
}

$dis = ini_get('disable_functions');
out("  disable_functions: ".($dis !== '' && $dis !== false ? $dis : 'пусто'));

// ---------------------------------------------------------------- 2. .env
out('');
out("[2/7] Файлы .env");

$env = [];
foreach ([$ROOT.'/.env', $ROOT.'/workers/.env'] as $envfile)
{
    if (is_file($envfile))
    {
        $loaded = load_env($envfile);
        $env += $loaded;
        out("  ".basename(dirname($envfile))."/".basename($envfile).": найден, ключей ".count($loaded));
    }
    else
    {
        out("  ".substr($envfile, strlen($ROOT) + 1).": нет");
    }
}

// env-переменные процесса имеют приоритет (как на проде через Environment)
foreach (['EMAIL_DSN', 'EMAIL_FROM', 'EMAIL_FROM_NAME', 'PRL597_EMAIL_ENABLED'] as $k)
{
    $v = getenv($k);

    if ($v !== FALSE AND $v !== '')
    {
        $env[$k] = $v;
    }
}

$dsn  = trim((string) ($env['EMAIL_DSN'] ?? ''));
$from = trim((string) ($env['EMAIL_FROM'] ?? ''));
$fname = trim((string) ($env['EMAIL_FROM_NAME'] ?? '')) ?: 'PRL.ru';
$enabled = trim((string) ($env['PRL597_EMAIL_ENABLED'] ?? '1'));

out("  EMAIL_DSN: ".($dsn !== '' ? mask_dsn($dsn) : 'НЕ ЗАДАН'));
out("  EMAIL_FROM: ".($from !== '' ? $from : 'НЕ ЗАДАН'));
out("  EMAIL_FROM_NAME: ".($fname !== '' ? $fname : '(по умолчанию PRL.ru)'));
out("  PRL597_EMAIL_ENABLED: ".($enabled !== '' ? $enabled : '1 (по умолчанию)'));

if ($enabled === '0')
{
    out("  [ВНИМАНИЕ] PRL597_EMAIL_ENABLED=0 — центр уведомлений письма не шлёт вовсе.");
}

if ($dsn === '')
{
    out("[FAIL] EMAIL_DSN пуст — письма отправляться не будут.");
    out("       Пример: EMAIL_DSN=smtp://логин:пароль@smtp.yandex.ru:465");
    $fail = 1;
}

if ($from === '')
{
    out("[FAIL] EMAIL_FROM пуст — некому быть отправителем.");
    $fail = 1;
}

// ------------------------------------------------------------ 3. composer
out('');
out("[3/7] Библиотеки (composer)");

$autoload = $ROOT.'/vendor/autoload.php';

if ( ! is_file($autoload))
{
    out("[FAIL] vendor/autoload.php не найден — на сервере не выполнен composer install.");
    out("       Выполните в корне: composer install --no-dev --prefer-dist");
    finish($fail + 1);
}

require $autoload;
out("  autoload: ок");

foreach ([
    'Symfony\Component\Mailer\Transport' => 'symfony/mailer',
    'Symfony\Component\Mailer\Mailer'    => 'symfony/mailer',
    'Symfony\Component\Mime\Email'       => 'symfony/mime',
] as $class => $pkg)
{
    $ok = class_exists($class);
    out("  $pkg → $class: ".($ok ? 'ок' : 'ОТСУТСТВУЕТ'));
    $ok OR $fail = 1;
}

out("  версия symfony/mailer: ".pkg_version($ROOT, 'symfony/mailer'));

if ($dsn !== '' AND strncmp($dsn, 'smtp', 4) !== 0)
{
    out("  транспорт не smtp (".strtok($dsn, ':').") — проверка DNS/сокета ниже пропускается.");
}

// ------------------------------------------------------------ 4. DNS
$host = '';

if ($dsn !== '' AND preg_match('~@([^/:?]+)~', $dsn, $m))
{
    $host = $m[1];
}

if ($host !== '' AND strncmp($dsn, 'smtp', 4) === 0)
{
    out('');
    out("[4/7] DNS хоста $host");

    $ips = @dns_get_record($host, DNS_A);

    if ($ips)
    {
        out("  A: ".implode(', ', array_column($ips, 'ip')));
    }
    else
    {
        out("  [FAIL] A-запись не разрешилась (DNS недоступен или хост опечатан).");
        $fail = 1;
    }

    $domain = substr(strrchr($from, '@'), 1) ?: '';
    $mx = $domain !== '' ? @dns_get_mx($domain, $mxhosts) : FALSE;

    if ($mx)
    {
        out("  MX для $domain (SPF-домен отправителя): ".implode(', ', array_slice($mxhosts, 0, 3))." …");
    }
}

// ------------------------------------------------------------ 5. Сокет
if ($host !== '' AND strncmp($dsn, 'smtp', 4) === 0)
{
    out('');
    out("[5/7] TCP-соединение с $host");

    $port = 587;

    if (preg_match('~:(\d+)~', $dsn, $pm))
    {
        $port = (int) $pm[1];
    }

    $transport_scheme = ($port === 465) ? 'ssl' : 'tcp';
    $sock = @fsockopen($transport_scheme.'://'.$host, $port, $errno, $errstr, 8);

    if ($sock)
    {
        out("  $transport_scheme://$host:$port — соединение установлено");
        fclose($sock);
    }
    else
    {
        out("  [FAIL] $transport_scheme://$host:$port — $errno $errstr");
        out("         Проверьте: порт открыт на исходящие? (465=SSL, 587=STARTTLS, 25 часто закрыт у хостеров)");
        $fail = 1;
    }
}

// ------------------------------------------------------------ 6. Отправка
out('');
out("[6/7] Отправка тестового письма на $to");

if ($dsn === '' OR $from === '')
{
    out("[SKIP] Нет EMAIL_DSN/EMAIL_FROM — отправить нечем.");
    finish(max($fail, 1));
}

try
{
    $t0 = microtime(TRUE);
    $mailer = new Symfony\Component\Mailer\Mailer(
        Symfony\Component\Mailer\Transport::fromDsn($dsn));

    $email = (new Symfony\Component\Mime\Email())
        ->from(new Symfony\Component\Mime\Address($from, $fname))
        ->to($to)
        ->subject('Тест почты PRL.ru — '.date('H:i:s'))
        ->text("Это тестовое письмо диагностики bin/mail_doctor.php.\n\n"
            ."Время: ".date('d.m.Y H:i:s')."\n"
            ."Транспорт: ".mask_dsn($dsn)."\n"
            ."Сервер: ".php_uname('n')."\n\n"
            ."Если письмо дошло — почта настроена правильно. Проверьте и папку «Спам».");

    $mailer->send($email);
    out("  [OK] Письмо принято транспортом за ".round((microtime(TRUE) - $t0) * 1000)." мс.");
    out("       Проверьте ящик $to (и папку «Спам») в течение пары минут.");
}
catch (Throwable $e)
{
    out("  [FAIL] ".get_class($e));
    out("  Сообщение: ".$e->getMessage());

    if ($VERBOSE)
    {
        out("  Файл: ".$e->getFile().":".$e->getLine());
        out("  Трасса: ".substr($e->getTraceAsString(), 0, 1200));
    }

    $fail = 1;
}

// ------------------------------------------------------------ 7. Итог
out('');
out('[7/7] Подсказки');

out('  • Не дошло, но «принято транспортом»? Проверьте папку «Спам» и квоту ящика.');
out('  • Яндекс: пароль для SMTP — «пароль приложения» (не основной), SSL-порт 465.');
out('  • Письма центра уведомлений видны в /prl597/admin (статусы sent/failed и причина).');

finish($fail);

// ================================================================== utils

function out($s)
{
    echo $s."\n";
}

function finish($fail)
{
    out('');
    out($fail ? "=== ИТОГ: есть проблемы (код $fail) — пришлите этот вывод целиком ==="
              : "=== ИТОГ: всё в порядке — письмо должно дойти ===");
    exit($fail ? 1 : 0);
}

function load_env($file)
{
    $out = [];

    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line)
    {
        $line = trim($line);

        if ($line === '' OR $line[0] === '#')
        {
            continue;
        }

        $pos = strpos($line, '=');

        if ($pos === FALSE)
        {
            continue;
        }

        $k = trim(substr($line, 0, $pos));
        $v = trim(substr($line, $pos + 1));

        if ($v !== '' AND ($v[0] === '"' OR $v[0] === "'"))
        {
            $v = trim($v, "\"'");
        }

        $out[$k] = $v;
    }

    return $out;
}

function mask_dsn($dsn)
{
    return preg_replace('~//([^:/@]+):([^@]+)@~', '//$1:***@', $dsn);
}

function pkg_version($root, $pkg)
{
    $f = $root.'/vendor/composer/installed.json';

    if ( ! is_file($f))
    {
        return '?';
    }

    $j = json_decode((string) file_get_contents($f), TRUE);
    $all = isset($j['packages']) ? $j['packages'] : $j;

    foreach ((array) $all as $p)
    {
        if (($p['name'] ?? '') === $pkg)
        {
            return (string) ($p['version'] ?? '?');
        }
    }

    return '?';
}
