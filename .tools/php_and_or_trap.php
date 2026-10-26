<?php
/**
 * Живая демонстрация ловушки «AND/OR слабее = и ?:» — до и после правки.
 *
 * Зачем: 28.09.2026 такая запись нашлась в 34 местах (см. .tools/php_ternary_lint.py
 * и docs/AGENT-LESSONS.md). Здесь видно, что именно уезжало в переменную, в SQL и
 * в права доступа, — на чистом PHP, без фреймворка и базы.
 *
 * Запуск:  php .tools/php_and_or_trap.php
 *          (в песочнице без php-cli — через phpwasm-харнесс: node harness.mjs '{}' "$(cat .tools/php_and_or_trap.php)")
 */

echo "PHP ".PHP_VERSION."\n";

/* 1. Секундомер статус-ряда (prl120 JobQueue::_status_of): было — bool, стало — секунды */
$status = 'processing';
$started_ts = time() - 240;

$was = ($status === 'processing' OR $status === 'pending') AND $started_ts
    ? max(0, time() - $started_ts)
    : 0;
$now = (($status === 'processing' OR $status === 'pending') AND $started_ts)
    ? max(0, time() - $started_ts)
    : 0;

echo "1) секундомер: было ".(int) $was." (".gettype($was)."), стало ".(int) $now."\n";
echo "   ОК: было 1/0, стало 240 = ".(((int) $was <= 1 AND (int) $now >= 240) ? 'да' : 'НЕТ')."\n";

/* 2. SQL-склейка (prl580 Orgdocs: обновление существующего документа) */
$sql_status = 'done';
$was_sql = 'finished_at = '.($sql_status === 'done' OR $sql_status === 'error' ? 'now()' : 'finished_at').'';
$now_sql = 'finished_at = '.(($sql_status === 'done' OR $sql_status === 'error') ? 'now()' : 'finished_at').'';

echo "2) SQL: было «{$was_sql}», стало «{$now_sql}»\n";
echo "   ОК: «finished_at = 1» больше не собирается = ".((str_contains($was_sql, '= 1') AND $now_sql === 'finished_at = now()') ? 'да' : 'НЕТ')."\n";

/* 3. Права администратора (prl605/606/609/642): было — «вошёл», стало — право */
$user_id   = 12;      // вошедший пользователь БЕЗ права prl605.admin
$can_admin = FALSE;
$was_admin = (bool) $user_id AND $can_admin;
$now_admin = ((bool) $user_id AND $can_admin);

echo "3) админ: было ".var_export((bool) $was_admin, TRUE).", стало ".var_export((bool) $now_admin, TRUE)."\n";
echo "   ОК: без права — не админ = ".(((bool) $was_admin === TRUE AND (bool) $now_admin === FALSE) ? 'да' : 'НЕТ')."\n";

/* 4. Признак AJAX (prl606/612): страница шлёт только заголовок X-Requested-With */
$post_ajax = NULL;
$header    = 'XMLHttpRequest';
$was_ajax = $post_ajax === '1' OR strtolower($header) === 'xmlhttprequest';
$now_ajax = ($post_ajax === '1' OR strtolower($header) === 'xmlhttprequest');

echo "4) ajax: было ".var_export((bool) $was_ajax, TRUE).", стало ".var_export((bool) $now_ajax, TRUE)."\n";
echo "   ОК: заголовок распознан = ".(((bool) $was_ajax === FALSE AND (bool) $now_ajax === TRUE) ? 'да' : 'НЕТ')."\n";
