<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Общий rate limiter платформы (prx100_platform).
 *
 * Сетевым и файловым сервисам нужен одинаковый контур «мягкого» ограничения по
 * IP: считаем операции в скользящих окнах (минута / час) по таблице истории
 * МОДУЛЯ-ВЛАДЕЛЬЦА (модуль владеет своими записями, как требует конвенция),
 * решаем «можно / нельзя», печатаем человекочитаемый лимит. До этого каждый
 * модуль держал свою копию одной и той же логики — третий сервис
 * получил бы третью. Теперь это одно место.
 *
 * Пороги остаются У владельца (конфиг модуля + env, напр. PRX633_RATE_PER_MINUTE):
 * у разных сервисов разумные лимиты разные, и их значения — не политика
 * платформы, а настройка сервиса.
 *
 * Безопасность: имена таблиц/колонок проходят строгую валидацию (^[a-z0-9_]+$),
 * значения видов — ^[a-z0-9_-]{1,32}$, всё остальное идёт через Database::quote.
 * Никакого пользовательского ввода в SQL — параметры задаёт ТОЛЬКО код модуля.
 *
 * Пример использования:
 *   $counts = Service_Prx100_Ratelimit::window_counts(
 *       'prx_120_jobs', Service_Prx100_Ratelimit::client_ip(),
 *       'client_ip', ['dns', 'whois', 'tcp']);
 *   if ( ! Service_Prx100_Ratelimit::allowed($counts, 5, 60)) { // 429 }
 */
class Service_Prx100_Ratelimit
{
    /**
     * IP клиента с учётом X-Forwarded-For (за прокси). Единая реализация —
     * копировать по модулям больше нельзя, берите отсюда.
     */
    public static function client_ip()
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        $xff    = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';

        if ($xff !== '' AND ($remote === '' OR filter_var($remote, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === FALSE))
        {
            return trim((string) strtok($xff, ','));
        }

        return $remote;
    }

    /**
     * Счётчик операций по IP в окнах «минута» / «час» по таблице истории модуля.
     *
     * @param string $table       имя таблицы-владельца (напр. 'prx_120_jobs')
     * @param string $ip          IP (из self::client_ip())
     * @param string $ip_column   колонка с IP (по умолчанию 'client_ip')
     * @param array  $kinds       необязательный фильтр видов (колонка 'kind');
     *                            пусто = считаем все строки владельца
     * @param string $kind_column имя колонки видов (по умолчанию 'kind')
     * @param string $time_column колонка времени (по умолчанию 'created_at')
     *
     * @return array ['minute' => int, 'hour' => int]
     */
    public static function window_counts($table, $ip, $ip_column = 'client_ip',
        array $kinds = [], $kind_column = 'kind', $time_column = 'created_at')
    {
        if ( ! preg_match('/^[a-z0-9_]+$/', (string) $table))
        {
            throw new InvalidArgumentException('Недопустимое имя таблицы для rate limit.');
        }

        foreach ([$ip_column, $kind_column, $time_column] as $col)
        {
            if ( ! preg_match('/^[a-z0-9_]+$/', (string) $col))
            {
                throw new InvalidArgumentException('Недопустимое имя колонки для rate limit.');
            }
        }

        $db    = Database::instance();
        $where = $ip_column.' = '.$db->quote((string) $ip);

        if ($kinds)
        {
            $parts = [];

            foreach ($kinds as $k)
            {
                if ( ! preg_match('/^[a-z0-9_-]{1,32}$/i', (string) $k))
                {
                    throw new InvalidArgumentException('Недопустимый вид операции для rate limit.');
                }

                $parts[] = $db->quote((string) $k);
            }

            $where .= ' AND '.$kind_column.' IN ('.implode(', ', $parts).')';
        }

        $row = $db->query(Database::SELECT,
            'SELECT
                COUNT(*) FILTER (WHERE '.$time_column.' > now() - interval \'1 minute\') AS minute,
                COUNT(*) FILTER (WHERE '.$time_column.' > now() - interval \'1 hour\')   AS hour
             FROM '.$table.' WHERE '.$where, FALSE)->current();

        // Пустой current() драйвера PostgreSQL — это FALSE (не NULL);
        // агрегат без GROUP BY всегда даёт одну строку, но нормализуем на всякий.
        $row = $row ? (array) $row : ['minute' => 0, 'hour' => 0];

        return ['minute' => (int) $row['minute'], 'hour' => (int) $row['hour']];
    }

    /** Решение «можно / нельзя» по счётчику окна и порогам модуля. */
    public static function allowed(array $counts, $per_minute, $per_hour)
    {
        return (int) ($counts['minute'] ?? 0) < (int) $per_minute
            AND (int) ($counts['hour'] ?? 0) < (int) $per_hour;
    }

    /** Человекочитаемый лимит для UI: «5 проверок в минуту, 60 в час». */
    public static function limit_text($per_minute, $per_hour, $unit = 'операций')
    {
        return (int) $per_minute.' '.$unit.' в минуту, '.(int) $per_hour.' в час';
    }
}
