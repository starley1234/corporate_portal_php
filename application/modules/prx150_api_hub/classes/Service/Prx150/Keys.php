<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Ключи внешнего API: генерация, проверка, scope, мягкий лимит.
 *
 * В базе хранится только SHA-256 ключа. Полный ключ показывается один раз
 * при создании и больше не восстанавливается — как у GitHub/Stripe.
 *
 * Формат: `prx_` + 64 hex-символа. Префикс (первые 12) — для отображения
 * в списке и поиска, он не секрет.
 */
class Service_Prx150_Keys
{
    public const TOKEN_PREFIX = 'prx_';
    public const TOKEN_LENGTH = 68;

    public static function generate(): string
    {
        return self::TOKEN_PREFIX.bin2hex(random_bytes(32));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function prefix(string $token): string
    {
        return substr($token, 0, 12);
    }

    /**
     * Машинный principal из строки ключа: id, имя, организация, scope.
     *
     * Единственный способ получить контекст внешнего потребителя в сервисе.
     * Роли и ACL сотрудника сюда не попадают.
     */
    public static function principal(array $row): Prx150_Principal
    {
        $scopes = json_decode((string) ($row['scopes'] ?? '[]'), TRUE);
        $organization = (int) ($row['organization_id'] ?? 0);

        return new Prx150_Principal(
            (int) ($row['id'] ?? 0),
            (string) ($row['name'] ?? ''),
            $organization > 0 ? $organization : NULL,
            is_array($scopes) ? array_map('strval', $scopes) : [],
            self::file_limits($row)
        );
    }

    /**
     * Файловые лимиты строки ключа → нормализованный массив.
     * max_file_bytes: ?int (NULL = безлимит), max_files_per_request: ?int,
     * allowed_formats: список нижнерегистровых расширений или [].
     */
    public static function file_limits(array $row): array
    {
        $raw = json_decode((string) ($row['file_limits'] ?? '{}'), TRUE);
        $raw = is_array($raw) ? $raw : [];

        $bytes = (int) ($raw['max_file_bytes'] ?? 0);
        $files = (int) ($raw['max_files_per_request'] ?? 0);
        $formats = array_values(array_unique(array_filter(array_map(
            static function ($f) {
                $f = strtolower(trim((string) $f));
                return preg_match('/\A[a-z0-9]{1,8}\z/', $f) ? $f : '';
            }, (array) ($raw['allowed_formats'] ?? [])
        ))));

        return [
            'max_file_bytes' => $bytes > 0 ? $bytes : NULL,
            'max_files_per_request' => $files > 0 ? $files : NULL,
            'allowed_formats' => $formats,
        ];
    }

    /** Нормализованные лимиты формы → JSONB-строка ({} = безлимит). */
    public static function file_limits_json(?int $max_file_bytes, ?int $max_files, array $formats): string
    {
        $formats = array_values(array_filter(array_map('strtolower', array_map('trim', $formats))));
        $out = [];
        if ($max_file_bytes !== NULL AND $max_file_bytes > 0)
        {
            $out['max_file_bytes'] = $max_file_bytes;
        }
        if ($max_files !== NULL AND $max_files > 0)
        {
            $out['max_files_per_request'] = $max_files;
        }
        if ($formats !== [])
        {
            $out['allowed_formats'] = array_values(array_unique($formats));
        }

        return json_encode($out, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Правка ключа БЕЗ отзыва: имя, скоупы, rate-limit, срок, заметка,
     * организация, файловые лимиты. key_hash/key_prefix не меняются —
     * потребителю ничего передавать заново не нужно.
     */
    public static function update(
        int $id,
        string $name,
        array $scopes,
        int $rate_limit,
        ?string $expires_at,
        string $note = '',
        ?int $organization_id = NULL,
        string $file_limits_json = '{}'
    ): void {
        $row = Prx_Db::one('SELECT id, status FROM prx_150_api_clients WHERE id = :id', [':id' => $id]);

        if ( ! $row)
        {
            throw new Prx150_Error('not_found', 404);
        }

        if ($row['status'] !== 'active')
        {
            throw new Prx150_Error('key_revoked', 409);
        }

        $name = trim($name);

        if ($name === '' OR mb_strlen($name) > 120)
        {
            throw new Prx150_Error('invalid_name', 422);
        }

        if ($organization_id !== NULL AND ( ! class_exists('Service_Prx300_Directory')
            OR ! Service_Prx300_Directory::organization_exists($organization_id)))
        {
            throw new Prx150_Error('invalid_organization', 422);
        }

        $known = Service_Prx150_Services::scopes();
        $scopes = array_values(array_intersect(array_unique($scopes), array_keys($known)));

        if ($scopes === [])
        {
            throw new Prx150_Error('invalid_scopes', 422);
        }

        sort($scopes);
        $rate_limit = max(1, min(600, $rate_limit));

        $expires = NULL;

        if ($expires_at !== NULL AND trim($expires_at) !== '')
        {
            $ts = strtotime($expires_at);

            if ($ts === FALSE)
            {
                throw new Prx150_Error('invalid_expires', 422);
            }

            $expires = date('Y-m-d H:i:s', $ts);
        }

        Prx_Db::execute(
            'UPDATE prx_150_api_clients SET name = :name, scopes = CAST(:scopes AS jsonb), '
            .'rate_limit_per_min = :limit, expires_at = :expires, note = :note, '
            .'organization_id = :org, file_limits = CAST(:flimits AS jsonb), updated_at = now() '
            .'WHERE id = :id',
            [
                ':name' => $name,
                ':scopes' => json_encode($scopes, JSON_UNESCAPED_UNICODE),
                ':limit' => $rate_limit,
                ':expires' => $expires,
                ':note' => mb_substr(trim($note), 0, 500),
                ':org' => $organization_id,
                ':flimits' => $file_limits_json === '' ? '{}' : $file_limits_json,
                ':id' => $id,
            ]);
    }

    /**
     * Организации для формы выдачи ключа. Пусто, если prx300 не подключён:
     * тогда ключ можно создать только без организации.
     *
     * @return array<int, array{id:int,code:string,name:string}>
     */
    public static function organizations(): array
    {
        return class_exists('Service_Prx300_Directory')
            ? Service_Prx300_Directory::organization_options()
            : [];
    }

    /**
     * Ключ из `Authorization: Bearer …`.
     *
     * 401 — ключа нет или он неизвестен; 403 — отозван/истёк. Разница важна
     * потребителю: в первом случае проверяют заголовок, во втором — админа.
     */
    public static function authenticate(Request $request): array
    {
        $header = trim((string) $request->headers('Authorization'));

        if ( ! preg_match('/\ABearer (prx_[a-f0-9]{64})\z/i', $header, $m))
        {
            throw new Prx150_Error('unauthorized', 401);
        }

        try
        {
            $row = Prx_Db::one(
                'SELECT id, name, key_prefix, scopes, rate_limit_per_min, status, expires_at, last_used_at, '
                .'organization_id '
                .'FROM prx_150_api_clients WHERE key_hash = :hash',
                [':hash' => self::hash($m[1])]
            );
        }
        catch (Throwable $e)
        {
            if (Kohana::$log)
            {
                Kohana::$log->add(Log::ERROR, 'prx150: authenticate failed: :e', [':e' => $e->getMessage()]);
            }
            throw new Prx150_Error('service_unavailable', 503);
        }

        if ($row === NULL)
        {
            throw new Prx150_Error('unauthorized', 401);
        }

        if ($row['status'] !== 'active')
        {
            throw new Prx150_Error('key_revoked', 403);
        }

        if ($row['expires_at'] !== NULL AND strtotime((string) $row['expires_at']) < time())
        {
            throw new Prx150_Error('key_expired', 403);
        }

        return $row;
    }

    /** Право на конкретный scope; `*` — все scope (для внутренних задач). */
    public static function require_scope(array $client, string $scope): void
    {
        if ( ! self::principal($client)->hasScope($scope))
        {
            throw new Prx150_Error('forbidden', 403, ['required_scope' => $scope]);
        }
    }

    /**
     * Мягкий лимит: сколько запросов ключ начал за последнюю минуту.
     * Считаем по журналу использования; один лишний параллельный запрос
     * возможен — это защита от пиков, а не биллинг.
     */
    public static function rate_limit(array $client, string $scope): void
    {
        $limit = (int) ($client['rate_limit_per_min'] ?? 0);

        if ($limit <= 0)
        {
            return;
        }

        try
        {
            $row = Prx_Db::one(
                "SELECT count(*) AS n FROM prx_150_api_usage "
                ."WHERE client_id = :id AND created_at > now() - interval '1 minute'",
                [':id' => (int) $client['id']]
            );
        }
        catch (Throwable $e)
        {
            if (Kohana::$log)
            {
                Kohana::$log->add(Log::WARNING, 'prx150: rate limit check failed: :e', [':e' => $e->getMessage()]);
            }
            return;
        }

        if ((int) ($row['n'] ?? 0) >= $limit)
        {
            throw new Prx150_Error('rate_limited', 429, [
                'retry_after' => 60, 'limit_per_min' => $limit, 'scope' => $scope,
            ]);
        }
    }

    /**
     * Создать ключ. Возвращает [id, полный ключ].
     *
     * Организация необязательна: внешний ключ может быть общим для портала.
     * FK на prx300 нет — проверяем через публичный сервис владельца, если
     * модуль подключён; иначе допустим только NULL.
     *
     * @param array<int, string> $scopes
     */
    public static function create(
        string $name,
        array $scopes,
        int $rate_limit,
        ?string $expires_at,
        int $user_id,
        string $note = '',
        ?int $organization_id = NULL
    ): array {
        $name = trim($name);

        if ($name === '' OR mb_strlen($name) > 120)
        {
            throw new Prx150_Error('invalid_name', 422);
        }

        if ($organization_id !== NULL AND ( ! class_exists('Service_Prx300_Directory')
            OR ! Service_Prx300_Directory::organization_exists($organization_id)))
        {
            throw new Prx150_Error('invalid_organization', 422);
        }

        $known = Service_Prx150_Services::scopes();
        $scopes = array_values(array_intersect(array_unique($scopes), array_keys($known)));

        if ($scopes === [])
        {
            throw new Prx150_Error('invalid_scopes', 422);
        }

        sort($scopes);

        $rate_limit = max(1, min(600, $rate_limit));
        $expires = NULL;

        if ($expires_at !== NULL AND trim($expires_at) !== '')
        {
            $ts = strtotime($expires_at);

            if ($ts === FALSE)
            {
                throw new Prx150_Error('invalid_expires', 422);
            }

            $expires = date('Y-m-d H:i:s', $ts);
        }

        $token = self::generate();
        $note = mb_substr(trim($note), 0, 500);

        [$id] = Prx_Db::insert(
            'INSERT INTO prx_150_api_clients '
            .'(name, key_hash, key_prefix, scopes, rate_limit_per_min, status, expires_at, '
            .'created_by, note, organization_id) '
            ."VALUES (:name, :hash, :prefix, CAST(:scopes AS jsonb), :limit, 'active', :expires, :user, :note, :org) "
            .'RETURNING id',
            [
                ':name'   => $name,
                ':hash'   => self::hash($token),
                ':prefix' => self::prefix($token),
                ':scopes' => json_encode($scopes, JSON_UNESCAPED_UNICODE),
                ':limit'  => $rate_limit,
                ':expires'=> $expires,
                ':user'   => $user_id,
                ':note'   => $note,
                ':org'    => $organization_id,
            ]
        );

        return [(int) $id, $token];
    }

    /** Отозвать ключ. Отозванный ключ не восстанавливается — создайте новый. */
    public static function revoke(int $id): bool
    {
        return Prx_Db::execute(
            "UPDATE prx_150_api_clients SET status = 'revoked', revoked_at = now() "
            ."WHERE id = :id AND status = 'active'",
            [':id' => $id]
        ) > 0;
    }
}
