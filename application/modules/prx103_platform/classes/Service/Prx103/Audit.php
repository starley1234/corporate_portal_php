<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Журнал действий администратора: кто, когда, что сделал с чем.
 *
 * Пишется через фасад `Audit::log()` (application/classes/Audit.php) — модули
 * таблицу prx_103_audit_log не трогают. Запись никогда не роняет действие,
 * которое журналируют: таблицы нет (миграция не применена) или сбой —
 * строка уходит в системный лог, действие продолжается.
 *
 * Секреты не пишутся: ключи data с password/token/secret/hash/authorization
 * заменяются на «***» на любой глубине. IP — только HMAC (как в prx150).
 */
class Service_Prx103_Audit
{
    public const TABLE = 'prx_103_audit_log';
    public const DATA_MAX_BYTES = 8192;
    private const SECRET_RX = '/pass|token|secret|hash|authorization|api_?key|cookie/i';

    /** @var bool|null кэш «таблица есть» на процесс */
    private static ?bool $ready = NULL;

    /**
     * @param array{id:int,name:string}|null $actor NULL — текущий пользователь сессии (CLI — «cli»)
     * @return int id записи или 0, если журнал недоступен
     */
    public static function write(string $category, string $action, $object_id = NULL, array $data = [], ?array $actor = NULL): int
    {
        $category = mb_substr(trim($category), 0, 64);
        $action   = mb_substr(trim($action), 0, 64);
        $actor    = $actor ?? self::current_actor();
        $object   = ($object_id === NULL OR $object_id === '')
            ? NULL
            : mb_substr(is_scalar($object_id) ? (string) $object_id : json_encode($object_id, JSON_UNESCAPED_UNICODE), 0, 128);
        $json     = self::encode(self::redact($data));

        if ( ! self::ready())
        {
            self::fallback($category, $action, $object, $json, $actor);
            return 0;
        }

        try
        {
            $row = Prx_Db::one(
                'INSERT INTO prx_103_audit_log (actor_id, actor, category, action, object_id, data, ip_hash)
                 VALUES (:actor_id, :actor, :category, :action, :object, CAST(:data AS jsonb), :ip)
                 RETURNING id',
                [
                    ':actor_id' => (int) $actor['id'],
                    ':actor'    => mb_substr((string) $actor['name'], 0, 64),
                    ':category' => $category,
                    ':action'   => $action,
                    ':object'   => $object,
                    ':data'     => $json,
                    ':ip'       => self::ip_hash(),
                ]);

            return (int) ($row['id'] ?? 0);
        }
        catch (Throwable $e)
        {
            self::fallback($category, $action, $object, $json, $actor, $e->getMessage());
            return 0;
        }
    }

    /**
     * Записи для страницы журнала. Фильтры: category, actor (id), object_id,
     * q (подстрока действия), days; страницы — по before_id.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function search(array $filter = [], int $limit = 100): array
    {
        if ( ! self::ready())
        {
            return [];
        }

        $where  = ["created_at > now() - make_interval(days => :days)"];
        $params = [':days' => max(1, min(3650, (int) ($filter['days'] ?? 30))), ':limit' => max(1, min(500, $limit))];

        if ( ! empty($filter['category']))
        {
            $where[] = 'category = :category';
            $params[':category'] = (string) $filter['category'];
        }
        if (isset($filter['actor']) AND $filter['actor'] !== '')
        {
            $where[] = 'actor_id = :actor_id';
            $params[':actor_id'] = (int) $filter['actor'];
        }
        if ( ! empty($filter['object_id']))
        {
            $where[] = 'object_id = :object_id';
            $params[':object_id'] = (string) $filter['object_id'];
        }
        if ( ! empty($filter['q']))
        {
            $where[] = "action ILIKE :q";
            $params[':q'] = '%'.addcslashes((string) $filter['q'], '%_\\').'%';
        }
        if ( ! empty($filter['before_id']))
        {
            $where[] = 'id < :before_id';
            $params[':before_id'] = (int) $filter['before_id'];
        }

        return Prx_Db::select(
            'SELECT id, created_at, actor_id, actor, category, action, object_id, data
             FROM prx_103_audit_log WHERE '.implode(' AND ', $where).'
             ORDER BY id DESC LIMIT :limit', $params);
    }

    /** Категории и авторы за период — для фильтров страницы. */
    public static function facets(int $days = 365): array
    {
        if ( ! self::ready())
        {
            return ['categories' => [], 'actors' => []];
        }

        $p = [':days' => max(1, min(3650, $days))];

        return [
            'categories' => Prx_Db::select(
                'SELECT category, count(*) AS n FROM prx_103_audit_log
                 WHERE created_at > now() - make_interval(days => :days)
                 GROUP BY category ORDER BY category', $p),
            'actors' => Prx_Db::select(
                'SELECT actor_id, max(actor) AS actor, count(*) AS n FROM prx_103_audit_log
                 WHERE created_at > now() - make_interval(days => :days)
                 GROUP BY actor_id ORDER BY max(actor)', $p),
        ];
    }

    /** Ключи с секретами → «***», рекурсивно. */
    public static function redact(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v)
        {
            if (is_string($k) AND preg_match(self::SECRET_RX, $k))
            {
                $out[$k] = is_bool($v) ? $v : '***';
                continue;
            }
            $out[$k] = is_array($v) ? self::redact($v) : (is_object($v) ? '['.get_class($v).']' : $v);
        }
        return $out;
    }

    /** Только тесты: сбросить кэш «таблица есть». */
    public static function reset_cache(): void
    {
        self::$ready = NULL;
    }

    // ------------------------------------------------------------------

    private static function encode(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';
        if (strlen($json) > self::DATA_MAX_BYTES)
        {
            $json = json_encode(['truncated' => TRUE, 'preview' => mb_strcut($json, 0, self::DATA_MAX_BYTES - 64)], JSON_UNESCAPED_UNICODE) ?: '{}';
        }
        return $json === '[]' ? '{}' : $json;
    }

    private static function ready(): bool
    {
        if (self::$ready === NULL)
        {
            try
            {
                $row = Prx_Db::one("SELECT to_regclass('prx_103_audit_log') IS NOT NULL AS ok");
                self::$ready = in_array($row['ok'] ?? FALSE, [TRUE, 't', 'true', '1', 1], TRUE);
            }
            catch (Throwable $e)
            {
                return FALSE;
            }
        }
        return self::$ready;
    }

    /** @return array{id:int,name:string} */
    private static function current_actor(): array
    {
        if (PHP_SAPI === 'cli')
        {
            return ['id' => 0, 'name' => 'cli'];
        }

        try
        {
            $user = Auth::instance()->get_user();
            if ($user)
            {
                return ['id' => (int) $user->id, 'name' => (string) $user->username];
            }
        }
        catch (Throwable $e)
        {
            // сессия недоступна — пишем как систему
        }

        return ['id' => 0, 'name' => 'system'];
    }

    private static function ip_hash(): ?string
    {
        if (PHP_SAPI === 'cli')
        {
            return NULL;
        }
        $ip  = (string) Request::$client_ip;
        $key = (string) Kohana::$config->load('auth')->get('hash_key');

        return ($ip !== '' AND $ip !== '0.0.0.0' AND $key !== '') ? hash_hmac('sha256', $ip, $key) : NULL;
    }

    private static function fallback(string $category, string $action, ?string $object, string $json, array $actor, string $error = ''): void
    {
        if ( ! Kohana::$log)
        {
            return;
        }
        Kohana::$log->add($error === '' ? Log::INFO : Log::WARNING,
            'AUDIT: :category - :action (object :object, by :actor#:actor_id) :data:err', [
                ':category' => $category, ':action' => $action, ':object' => (string) $object,
                ':actor' => (string) $actor['name'], ':actor_id' => (int) $actor['id'], ':data' => $json,
                ':err' => $error === '' ? '' : ' — журнал недоступен: '.$error,
            ]);
    }
}
