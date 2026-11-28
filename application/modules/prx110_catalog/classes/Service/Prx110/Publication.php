<?php defined('SYSPATH') or die('No direct script access.');

/** Решение о публикации в каталоге не меняет статус разработки и ACL модуля. */
final class Service_Prx110_Publication
{
    public const STATES = ['approved', 'wip', 'unpublished', 'archived'];
    public const PROTECTED_MODULES = ['prx110_catalog', 'prx112_portal_plan'];

    private static ?array $decisions = NULL;

    public static function eligible(array $module): bool
    {
        $href = (string) ($module['href'] ?? '');
        return ($module['kind'] ?? 'module') === 'module'
            && preg_match('~\A/(?!/)[^\x00-\x20\\\\]+\z~', $href) === 1
            && Service_Prx110_Catalog::resolve_status($module) !== 'deprecated';
    }

    public static function decision(string $name): ?string
    {
        return self::decision_meta($name)['state'] ?? NULL;
    }

    public static function decision_meta(string $name): ?array
    {
        if (self::$decisions === NULL)
        {
            self::$decisions = [];
            try
            {
                $rows = Prx_Db::select('SELECT p.module_name, p.state, p.decided_at, p.note, u.username AS decided_by_name
                    FROM prx_110_publications p LEFT JOIN users u ON u.id = p.decided_by');
                self::$decisions = array_column($rows, NULL, 'module_name');
            }
            catch (Throwable $e)
            {
                Log_Plain::event(Log::ERROR, 'prx110: не удалось прочитать решения о публикации (:type)', [':type' => get_class($e)]);
            }
        }
        return self::$decisions[$name] ?? NULL;
    }

    /** Чистый план: входные манифесты с module_name и, при наличии решения, decision. */
    public static function backfill_plan(array $modules): array
    {
        $plan = ['insert' => [], 'keep' => [], 'skip' => []];
        foreach ($modules as $module)
        {
            $name = (string) ($module['module_name'] ?? '');
            if (($module['kind'] ?? 'module') !== 'module' || trim($name) === ''
                || trim((string) ($module['title'] ?? '')) === '')
            {
                $plan['skip'][] = $name;
                continue;
            }
            if (isset($module['decision']))
            {
                $plan['keep'][$name] = $module['decision'];
                continue;
            }
            $protected = in_array($name, self::PROTECTED_MODULES, TRUE);
            $status = $module['status'] ?? NULL;
            $state = match (TRUE) {
                $protected => 'approved',
                $status === 'deprecated' => 'archived',
                ($module['catalog'] ?? TRUE) === FALSE => 'unpublished',
                $status === 'stable' => 'approved',
                default => 'wip',
            };
            $plan['insert'][$name] = ['state' => $state, 'decided_by' => NULL,
                'note' => $protected ? 'backfill:protected' : 'backfill'];
        }
        return $plan;
    }

    public static function backfill(bool $apply = FALSE): array
    {
        // Не используем кеш витрины: CLI должен явно упасть, если БД недоступна.
        $existing = array_column(Prx_Db::select('SELECT module_name, state FROM prx_110_publications'), 'state', 'module_name');
        $modules = [];
        foreach (Manifest::all() as $name => $manifest)
        {
            $manifest['module_name'] = $name;
            $manifest['decision'] = $existing[$name] ?? NULL;
            $modules[] = $manifest;
        }
        $plan = self::backfill_plan($modules);
        $inserted = [];
        if ($apply)
        {
            foreach ($plan['insert'] as $name => $row)
            {
                [, $affected] = Prx_Db::insert('INSERT INTO prx_110_publications (module_name, state, decided_by, decided_at, note)
                    VALUES (:name, :state, NULL, now(), :note) ON CONFLICT (module_name) DO NOTHING', [
                    ':name' => $name, ':state' => $row['state'], ':note' => $row['note'],
                ]);
                if ($affected > 0)
                {
                    $inserted[] = $name;
                }
            }
            self::$decisions = NULL;
        }
        return $plan + ['inserted' => $inserted];
    }

    public static function approve(string $name, Prx_Actor $actor): void
    {
        self::set_state($name, 'approved', $actor);
    }

    public static function set_state(string $name, string $state, Prx_Actor $actor, string $note = ''): void
    {
        $actor->require('prx112.approve');
        if ( ! in_array($state, self::STATES, TRUE))
        {
            throw HTTP_Exception::factory(400, 'Некорректное состояние публикации');
        }
        if (in_array($name, self::PROTECTED_MODULES, TRUE))
        {
            throw HTTP_Exception::factory(403, 'Защищённый модуль');
        }
        $module = Service_Prx110_Catalog::get($name);
        if ($module === NULL || $module['module_name'] !== $name || ! self::eligible($module))
        {
            throw HTTP_Exception::factory(404, 'Нет подключённого сервиса для изменения публикации');
        }

        $affected = Prx_Db::execute('INSERT INTO prx_110_publications (module_name, state, decided_by, note)
            VALUES (:name, :state, :actor, :note) ON CONFLICT (module_name) DO UPDATE
            SET state = EXCLUDED.state, decided_by = EXCLUDED.decided_by, decided_at = now(), note = EXCLUDED.note
            WHERE prx_110_publications.state IS DISTINCT FROM EXCLUDED.state
               OR prx_110_publications.note IS DISTINCT FROM EXCLUDED.note', [
            ':name' => $name, ':state' => $state, ':actor' => $actor->id(), ':note' => $note,
        ]);
        if ($affected > 0)
        {
            self::$decisions = NULL;
            Event::publish('prx110.publication.changed', ['module' => $name, 'state' => $state], $actor->id());
            Audit::log('prx110', 'publication_changed', $name, ['state' => $state, 'note' => mb_substr($note, 0, 200)]);
        }
    }
}
