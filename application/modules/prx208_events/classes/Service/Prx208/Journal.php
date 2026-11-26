<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Журнал событий шины prx208 — читающая модель для /prx208_events.
 *
 * Журнал пишет EventBus::publish() (config/prx208_events.php → log_published_events);
 * из интерфейса он не редактируется: журнал — доказательство, а не форма. Старые
 * строки убирает контракт cleanup в манифесте (90 дней, /prx100/cleanup).
 *
 *   Service_Prx208_Journal::switches();   // что реально включено в шине
 *   Service_Prx208_Journal::summary();    // счётчики сводки
 *   Service_Prx208_Journal::rows($filters);
 */
class Service_Prx208_Journal
{
    public const PER_PAGE = 50;
    public const PER_OPTIONS = [20, 50, 100, 200];

    /** Префикс события → название модуля-издателя по манифестам (prx120 → «Очередь задач»). */
    public static function module_titles(): array
    {
        $titles = [];

        if (class_exists('Manifest'))
        {
            foreach (Manifest::all() as $m)
            {
                $name = (string) ($m['module_name'] ?? '');

                if (preg_match('/^(prx\d+)/', $name, $mm))
                {
                    $titles[$mm[1]] = (string) ($m['title'] ?? $name);
                }
            }
        }

        return $titles;
    }

    /** Флаги шины из config/prx208_events.php: что включено прямо сейчас. */
    public static function switches(): array
    {
        $cfg = Kohana::$config->load('prx208_events');
        $cfg = ($cfg instanceof Kohana_Config_Group) ? $cfg->as_array() : (array) $cfg;
        $bus = (isset($cfg['event_bus']) AND is_array($cfg['event_bus'])) ? $cfg['event_bus'] : [];

        return [
            'journal' => ! empty($bus['log_published_events']),
            'bridge'  => ! empty($bus['proxy_to_notification_center']),
            'db_subs' => ! empty($bus['load_db_subscribers']),
            'prx597'  => class_exists('Service_Prx597_Notify'),
        ];
    }

    /** Счётчики сводки: активность журнала и подписок. */
    public static function summary(): array
    {
        $events = Prx_Db::one("SELECT count(*) AS total,
                                      count(*) FILTER (WHERE created_at > now() - interval '24 hours') AS day,
                                      count(*) FILTER (WHERE created_at > now() - interval '7 days') AS week,
                                      count(DISTINCT split_part(event_name, '.', 1)) AS publishers
                               FROM prx_208_events") ?? ['total' => 0, 'day' => 0, 'week' => 0, 'publishers' => 0];

        $subs = Prx_Db::one("SELECT count(*) FILTER (WHERE is_active) AS active,
                                    count(*) FILTER (WHERE is_active AND is_notification) AS notifying
                             FROM prx_208_event_subscribers") ?? ['active' => 0, 'notifying' => 0];

        return [
            'total'      => (int) $events['total'],
            'day'        => (int) $events['day'],
            'week'       => (int) $events['week'],
            'publishers' => (int) $events['publishers'],
            'active'     => (int) $subs['active'],
            'notifying'  => (int) $subs['notifying'],
        ];
    }

    /** Издатели для фильтра: префикс → сколько событий. */
    public static function prefixes(): array
    {
        return Prx_Db::select("SELECT split_part(event_name, '.', 1) AS prefix, count(*) AS n
                               FROM prx_208_events GROUP BY 1 ORDER BY 2 DESC, 1");
    }

    /**
     * Страница журнала.
     *
     * @param  array $filters prefix, q, initiator, page, per
     * @return array rows, total, page, pages, per
     */
    public static function rows(array $filters): array
    {
        $where = [];
        $params = [];

        if ($filters['prefix'] !== '')
        {
            $where[] = "split_part(event_name, '.', 1) = :prefix";
            $params[':prefix'] = $filters['prefix'];
        }

        if ($filters['q'] !== '')
        {
            $where[] = 'event_name ILIKE :q';
            $params[':q'] = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']).'%';
        }

        if ((int) $filters['initiator'] > 0)
        {
            $where[] = 'initiator_id = :initiator';
            $params[':initiator'] = (int) $filters['initiator'];
        }

        $sql_where = $where ? 'WHERE '.implode(' AND ', $where) : '';
        $total = (int) (Prx_Db::one("SELECT count(*) AS c FROM prx_208_events {$sql_where}", $params)['c'] ?? 0);

        $per = in_array((int) $filters['per'], self::PER_OPTIONS, TRUE) ? (int) $filters['per'] : self::PER_PAGE;
        $pages = max(1, (int) ceil($total / $per));
        $page = min(max(1, (int) $filters['page']), $pages);

        $rows = Prx_Db::select("SELECT id, event_name, initiator_id, payload, created_at
                                FROM prx_208_events {$sql_where}
                                ORDER BY id DESC LIMIT :limit OFFSET :offset",
            $params + [':limit' => $per, ':offset' => ($page - 1) * $per]);

        $titles = self::module_titles();

        foreach ($rows as &$row)
        {
            $row = self::decorate($row, $titles);
        }
        unset($row);

        return [
            'rows'  => $rows,
            'total' => $total,
            'page'  => $page,
            'pages' => $pages,
            'per'   => $per,
        ];
    }

    /**
     * Лента событий для «Использование модулей» (/prx100/usage): чужие модули
     * читают журнал только через этот публичный метод — SQL у владельца.
     */
    public static function recent($interval, $prefix, $limit = 400)
    {
        if (preg_match('/^\\d+ (hour|hours|minute|minutes|day|days)$/', (string) $interval) === 1)
        {
            $interval = '24 hours';
        }

        $sql = "SELECT id, event_name, initiator_id, payload, created_at
                FROM prx_208_events
                WHERE created_at >= now() - CAST(:window AS interval)"
            .($prefix !== '' ? " AND split_part(event_name, '.', 1) = :prefix" : '')
            .' ORDER BY id DESC LIMIT '.(int) $limit;

        $params = [':window' => (string) $interval];
        if ($prefix !== '') { $params[':prefix'] = (string) $prefix; }

        return Prx_Db::select($sql, $params);
    }

    /** Одно событие и подписки на его имя. NULL, если нет. */
    public static function detail(int $id): ?array
    {
        $row = Prx_Db::one("SELECT id, event_name, initiator_id, payload, created_at
                            FROM prx_208_events WHERE id = :id", [':id' => $id]);

        if ($row === NULL)
        {
            return NULL;
        }

        $row = self::decorate($row, self::module_titles());
        $row['subscribers'] = Prx_Db::select("SELECT id, module_id, callback_method, is_active, is_notification, description
                                              FROM prx_208_event_subscribers
                                              WHERE event_name = :name ORDER BY is_active DESC, id", [':name' => $row['event_name']]);

        return $row;
    }

    /** Строка журнала → части имени, модуль, красиво напечатанный payload. */
    protected static function decorate(array $row, array $titles): array
    {
        $name = (string) $row['event_name'];
        $dot = strpos($name, '.');
        $prefix = $dot === FALSE ? $name : substr($name, 0, $dot);
        $row['prefix'] = $prefix;
        $row['action'] = $dot === FALSE ? '' : substr($name, $dot + 1);
        $row['module_title'] = $titles[$prefix] ?? '';
        $row['initiator_id'] = ($row['initiator_id'] === NULL OR $row['initiator_id'] === '') ? NULL : (int) $row['initiator_id'];
        $row['when'] = $row['created_at'] ? date('d.m.Y H:i:s', (int) strtotime((string) $row['created_at'])) : '—';

        $payload = json_decode((string) $row['payload'], TRUE);
        $json = is_array($payload)
            ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : (string) $row['payload'];
        $row['payload_text'] = ($json === FALSE OR $json === '') ? '—' : $json;
        $row['payload_keys'] = is_array($payload) ? array_slice(array_keys($payload), 0, 6) : [];

        return $row;
    }
}
