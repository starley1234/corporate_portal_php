<?php
/**
 * Контроллеры на голом Controller обходят вход (Controller_Base) и глобальный
 * CSRF (Prx_PostGuard в Controller_Prx102_Base::before). Каждый такой класс —
 * осознанное исключение со своей защитой. Новый — сначала сюда, с причиной.
 * Повод: 28.11.2026 Controller_Prx212_Link без единой проверки отдавал анониму
 * токены публичных ссылок и выпускал ссылки на любой файл.
 */
$root = dirname(__DIR__, 2).'/application';

const ALLOWED = [
    'Controller_Prx102_Base'         => 'база всех UI-контроллеров (вход, PostGuard)',
    'Controller_ModuleHome'          => 'GET-редирект /prxNNN_index на href манифеста; права — у цели',
    'Controller_CookieConsent'       => 'публичный баннер cookie',
    'Controller_Prx100_Search'       => 'статический 410 Gone',
    'Controller_Prx103_Health'       => '/health открыт, /health/metrics — токен или админ',
    'Controller_Prx111_Migrate'      => 'MIGRATIONS_TOKEN / админ-сессия',
    'Controller_Prx120_Api'          => 'X-Worker-Token (Prx_Gate)',
    'Controller_Prx395_Worker'       => 'X-Worker-Token (Prx_Gate)',
    'Controller_Prx396_Worker'       => 'X-Worker-Token (Prx_Gate)',
    'Controller_Prx397_Worker'       => 'X-Worker-Token (Prx_Gate)',
    'Controller_Prx398_Worker'       => 'X-Worker-Token (Prx_Gate)',
    'Controller_Prx399_Api'          => 'X-Worker-Token (PRX399_SITE_TOKEN)',
    'Controller_Prx400_Worker'       => 'X-Worker-Token (Prx_Gate)',
    'Controller_Prx597_Api'          => 'outbox — X-Worker-Token; остальное — сессия + свой CSRF в каждом POST',
    'Controller_Prx597_Hook'         => 'секрет вебхука из prx_597_bots (адрес или заголовок)',
    'Controller_Prx597_Unsub'        => 'HMAC-ссылка отписки (Notify::unsub_verify)',
];

$found = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ($file->getExtension() !== 'php' OR strpos($file->getPathname(), '/classes/Controller/') === FALSE) continue;
    $src = (string) file_get_contents($file->getPathname());
    if (preg_match_all('/^\s*(?:abstract\s+|final\s+)?class\s+(Controller_\w+)\s+extends\s+(?:Kohana_)?Controller\b(?!_)/m', $src, $m)) {
        foreach ($m[1] as $class) $found[$class] = substr($file->getPathname(), strlen($root) + 1);
    }
}

$unexpected = array_diff_key($found, ALLOWED);
if ($unexpected) {
    foreach ($unexpected as $class => $path) echo "FAIL $class ($path) наследует голый Controller: нет входа и CSRF. Наследуйте Controller_Base или добавьте в ALLOWED с причиной.\n";
    exit(1);
}
$stale = array_diff_key(ALLOWED, $found);
if ($stale) {
    echo 'FAIL устаревшие исключения: '.implode(', ', array_keys($stale))."\n";
    exit(1);
}
echo 'ok   контроллеры без Controller_Base: '.count($found)." — все в списке исключений\n";
