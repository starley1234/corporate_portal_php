<?php defined('SYSPATH') or die('No direct script access.');

/** Глобальный поиск по реестрам Manifest. Объектную policy задаёт владелец источника. */
class Service_Prx100_Search
{
    /**
     * Реестр источников для страницы /search: type = значение kind в выдаче.
     * label — русское название группы, icon — иконка для UI.
     *
     * Сам реестр с 28.09.2026 живёт в манифестах модулей-владельцев (секция
     * `search`): кто владеет таблицей, тот владеет и строкой поиска по ней.
     * Порядок групп задаёт `sort` в манифесте.
     */
    public static function sources()
    {
        // Сервисы каталога — встроенный источник (видны только доступные
        // пользователю); сущности объявляют модули-владельцы секцией `search`.
        $out = ['service' => ['label' => 'Сервисы', 'icon' => 'fas fa-grip']];

        foreach (Manifest::search() as $type => $spec)
        {
            $out[$type] = [
                'label' => (string) Arr::get($spec, 'label', $type),
                'icon'  => (string) Arr::get($spec, 'icon', 'fas fa-circle'),
            ];
        }

        return $out;
    }

    /** Хеш типа для фильтра: «всё» или явный набор. */
    public static function normalize_filter($only)
    {
        $map = self::sources();

        if ( ! is_array($only) OR ! $only)
        {
            return array_fill_keys(array_keys($map), TRUE);
        }

        $out = [];

        foreach ($only as $t)
        {
            if (isset($map[$t]))
            {
                $out[$t] = TRUE;
            }
        }

        return $out ?: array_fill_keys(array_keys($map), TRUE);
    }

    /** Топ-результаты по всем источникам (опц. by типами). */
    public static function search($q, $user_id = NULL, $per_source = NULL, array $only_types = NULL)
    {
        $q = trim((string) $q);

        if ($q === '' OR mb_strlen($q) < 2)
        {
            return [];
        }

        $filter = self::normalize_filter($only_types);

        $cfg = Kohana::$config->load('prx100_search');
        $per = $per_source ?: (int) ($cfg ? $cfg->get('per_source', 5) : 5);
        $cap = (int) ($cfg ? $cfg->get('total_cap', 40) : 40);

        $like = self::_escape_like($q);
        $out  = [];

        // Что искать — объявляет владелец таблицы (секция `search` его манифеста).
        // Как искать — сортировку, лимит на источник и общий потолок — решает
        // агрегатор. Пользовательский запрос попадает в SQL только через
        // плейсхолдеры :l/:qenc — их подставляет Prx_Db (quote на стороне ядра).
        //
        // Пробел перед :scope уже стоит в шаблоне — здесь его быть не должно.
        $scope = $user_id ? 'AND user_id = '.(int) $user_id : '';
        $qenc  = '/prx560?q='.rawurlencode($like);

        // Контекст пользователя — для фильтра аудитории источников и сервиса.
        $ctx = class_exists('Service_Prx110_Catalog')
            ? Service_Prx110_Catalog::user_context()
            : ['admin' => FALSE, 'logged' => TRUE, 'guest' => FALSE, 'agent' => FALSE];
        $manifests = Manifest::all();

        // Встроенный источник «Сервисы»: карточки каталога, доступные
        // текущему пользователю (search_json уже отфильтрован по аудитории).
        if (isset($filter['service']) AND class_exists('Service_Prx110_Catalog'))
        {
            $qcmp = mb_strtolower($q);

            foreach (Service_Prx110_Catalog::search_json($ctx)['items'] as $item)
            {
                $hay = mb_strtolower($item['title'].' '.$item['short'].' '.$item['keywords']);

                if ($qcmp !== '' AND mb_stripos($hay, $qcmp) === FALSE) { continue; }

                $out[] = [
                    'kind'  => 'Сервисы',
                    'type'  => 'service',
                    'title' => (string) $item['title'],
                    'sub'   => trim((string) $item['short']),
                    'url'   => (string) $item['href'],
                    'ts'    => PHP_INT_MAX, // сервисы — вверху выдачи
                ];
            }
        }

        foreach (Manifest::search() as $type => $spec)
        {
            if (Arr::get($spec, 'scope') === 'user' AND ! $user_id) { continue; }

            // Источник виден только той аудитории, которой виден его модуль
            // (например, «База знаний» и «Файлы» — только админам).
            $mod = (string) Arr::get($spec, 'module', '');
            if ($mod !== '' AND isset($manifests[$mod])
                AND ($manifests[$mod]['audience'] ?? 'auth') === 'admin' AND empty($ctx['admin']))
            {
                continue;
            }

            $sql = (string) Arr::get($spec, 'sql', '');

            // не вошёл в фильтр only_types или объявлен без запроса
            if ($sql === '' OR ! isset($filter[$type]))
            {
                continue;
            }

            $label = (string) Arr::get($spec, 'label', $type);
            $sql   = strtr($sql, [
                ':scope' => Arr::get($spec, 'scope') === 'user' ? $scope : '',
                ':per'   => (string) (int) $per,
            ]);

            try
            {
                $rows = Prx_Db::select($sql, [
                    ':qenc' => $qenc,
                    ':l'    => '%'.$like.'%',
                ]);
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'prx100: источник :s: :e', [':s' => $label, ':e' => $e->getMessage()]);
                continue;
            }

            foreach ($rows as $r)
            {
                $out[] = [
                    'kind'  => $label,
                    'type'  => (string) $r['kind'],
                    'title' => (string) ($r['t1'] ?: $r['t2'] ?: '(без названия)'),
                    'sub'   => trim((string) ($r['t2'] ?: '')).' '.trim((string) $r['sub']),
                    'url'   => (string) $r['url'],
                    'ts'    => strtotime((string) $r['created_at']),
                ];
            }
        }

        usort($out, function ($a, $b) {
            return ($b['ts'] ?: 0) <=> ($a['ts'] ?: 0);
        });

        return array_slice($out, 0, $cap);
    }

    protected static function _escape_like($s)
    {
        return str_replace(['%', '_', '\\'], ['\%', '\_', '\\\\'], trim((string) $s));
    }
}
