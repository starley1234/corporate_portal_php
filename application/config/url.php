<?php defined('SYSPATH') or die('No direct access allowed.');
/**
 * Trusted hosts: какие значения Host-заголовка сайт считает своими.
 *
 * Домены сайта — можно несколько (например work-ai.servers.local и
 * dev.work-ai.servers.local одновременно):
 *
 *   APP_URL            — канонический адрес; его хост входит всегда,
 *                        canonical, ссылки писем и og:url строятся от него;
 *   APP_PUBLIC_HOSTS   — прочие публичные домены через запятую; сайт
 *                        отвечает на них так же, как на канонический;
 *   APP_INTERNAL_HOSTS — служебные DNS-имена сети (nginx в Docker и т.п.).
 *
 * Только явно заданные имена; не regex и не wildcard.
 */
$public = [parse_url((string) (getenv('APP_URL') ?: 'http://localhost:8080'), PHP_URL_HOST)];

foreach (explode(',', (string) getenv('APP_PUBLIC_HOSTS')) as $extra)
{
    $extra = trim($extra);
    if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.-]*$/D', $extra))
    {
        $public[] = $extra;
    }
}

$hosts = [];
foreach ($public as $host)
{
    if ($host)
    {
        $hosts[] = preg_quote($host, '~').'(:\\d+)?';
    }
}

// Внутренние имена сети — тот же строгий шаблон имени хоста.
foreach (explode(',', (string) getenv('APP_INTERNAL_HOSTS')) as $internal)
{
    $internal = trim($internal);
    if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.-]*$/D', $internal))
    {
        $hosts[] = preg_quote($internal, '~').'(:\\d+)?';
    }
}

if (Kohana::$environment === Kohana::DEVELOPMENT)
{
    $hosts = array_merge($hosts, ['localhost(:\\d+)?', '127\\.0\\.0\\.1(:\\d+)?', '.*\\.e2b\\.app(:\\d+)?']);
}

return ['trusted_hosts' => array_values(array_unique($hosts))];
