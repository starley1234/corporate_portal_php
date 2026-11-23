<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инвентаризация модулей платформы:
 *
 *   bin/console module:list                      человекочитаемая таблица
 *   bin/console module:list --json=1             то же в JSON (для агентов)
 *   bin/console module:list --markdown=1         реестр для docs/ARCHITECTURE.md §2
 *   bin/console module:list --markdown=1 --write=1  вписать реестр в файл
 *
 * Реестр модулей в документации НЕ пишется руками: до 28.09.2026 таблица §2
 * описывала 23 модуля из 81 и содержала сломанную строку. Теперь она
 * генерируется из манифестов (`config/prx_module.php`) и миграций, а CI
 * проверяет, что записанное в репозитории совпадает с генератором.
 */
class Task_Module_List extends Minion_Task {

    /** Маркеры блока в docs/ARCHITECTURE.md. */
    const MARK_BEGIN = '<!-- module-table:begin -->';
    const MARK_END   = '<!-- module-table:end -->';

    protected $_options = [
        'json'     => NULL,
        'markdown' => NULL,
        'write'    => NULL,
    ];

    protected function _execute(array $params)
    {
        $dir = APPPATH.'modules';
        $registered = Kohana::modules();
        $rows = [];

        foreach (scandir($dir) as $name)
        {
            if ($name[0] === '.' OR ! is_dir($dir.'/'.$name))
                continue;

            $path = $dir.'/'.$name;
            $classes = 0;
            $migrations = 0;

            foreach (['classes', 'migrations_pg'] as $sub)
            {
                if ( ! is_dir($path.'/'.$sub))
                    continue;

                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path.'/'.$sub));
                foreach ($it as $file)
                {
                    if ($file->getExtension() === 'php')
                    {
                        $sub === 'migrations_pg' ? $migrations++ : $classes++;
                    }
                }
            }

            $manifest = Manifest::get($name);

            $rows[] = [
                'module'      => $name,
                'numbered'    => strpos($name, 'prx') === 0 ? 'prx' : 'без номера',
                'registered'  => isset($registered[$name]) ? 'да' : 'НЕТ',
                'agents_md'   => is_file($path.'/AGENTS.md') ? 'да' : '—',
                'readme'      => is_file($path.'/README.md') ? 'да' : '—',
                'migrations'  => $migrations,
                'classes'     => $classes,
                'title'       => isset($manifest['title']) ? (string) $manifest['title'] : '',
                'short'       => isset($manifest['short']) ? (string) $manifest['short'] : '',
                'href'        => isset($manifest['href'])  ? (string) $manifest['href']  : '',
                'tables'      => self::_tables($path.'/migrations_pg'),
            ];
        }

        usort($rows, function ($a, $b) { return strcmp($a['module'], $b['module']); });

        if ( ! empty($params['json']))
        {
            Minion_CLI::write(json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return;
        }

        if ( ! empty($params['markdown']))
        {
            $md = self::_markdown($rows);

            if ( ! empty($params['write']))
            {
                Minion_CLI::write(self::_write($md));
                return;
            }

            Minion_CLI::write($md);
            return;
        }

        $out = [sprintf('%-32s %-12s %-11s %-10s %-7s %6s %7s',
            'МОДУЛЬ', 'ТИП', 'В BOOTSTRAP', 'AGENTS.md', 'README', 'МИГР.', 'КЛАССЫ')];

        foreach ($rows as $r)
        {
            $out[] = sprintf('%-32s %-12s %-11s %-10s %-7s %6d %7d',
                $r['module'], $r['numbered'], $r['registered'], $r['agents_md'],
                $r['readme'], $r['migrations'], $r['classes']);
        }

        $out[] = '';
        $out[] = 'Всего модулей: '.count($rows).
            ' | prx: '.count(array_filter($rows, function ($r) { return $r['numbered'] === 'prx'; })).
            ' | без AGENTS.md: '.count(array_filter($rows, function ($r) { return $r['agents_md'] === '—'; }));
        $out[] = 'Подробности: docs/MODULE_GUIDE.md, карточка модуля — <модуль>/AGENTS.md';

        Minion_CLI::write(implode("\n", $out));
    }

    /**
     * Таблицы, которые модуль создаёт своими миграциями.
     *
     * Читаем `CREATE TABLE`, а не спрашиваем БД: реестр должен собираться
     * на любой машине, в том числе без доступа к базе.
     *
     * @param  string $dir каталог migrations_pg модуля
     * @return array       имена таблиц в порядке появления, без повторов
     */
    protected static function _tables($dir)
    {
        if ( ! is_dir($dir))
        {
            return [];
        }

        $files = glob($dir.'/*.php');
        sort($files);
        $found = [];

        foreach ($files as $file)
        {
            // Комментарии вырезаем ДО разбора: строка «-- CREATE TABLE IF NOT
            // EXISTS …» в шапке миграции иначе даёт мнимую таблицу `if`
            // (регулярка видит «CREATE TABLE» и берёт именем слово IF).
            $sql = preg_replace(['~/\*.*?\*/~s', '~--[^\n]*~'], ' ', (string) file_get_contents($file));

            if ( ! preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?"?([a-z][a-z0-9_]*)"?/i', $sql, $m))
            {
                continue;
            }

            foreach ($m[1] as $t)
            {
                $t = strtolower($t);

                if ( ! in_array($t, $found, TRUE))
                {
                    $found[] = $t;
                }
            }
        }

        return $found;
    }

    /**
     * Реестр в markdown: сначала модули prx, затем prx, затем без номера;
     * внутри группы — по номеру.
     *
     * @param  array $rows строки инвентаризации
     * @return string
     */
    protected static function _markdown(array $rows)
    {
        $connected = array_values(array_filter($rows, function ($r) { return $r['registered'] === 'да'; }));
        $orphans   = array_values(array_filter($rows, function ($r) { return $r['registered'] !== 'да'; }));

        usort($connected, function ($a, $b) {
            return [self::_rank($a['module']), self::_number($a['module']), $a['module']]
               <=> [self::_rank($b['module']), self::_number($b['module']), $b['module']];
        });

        $out = [
            self::MARK_BEGIN,
            '',
            '<!-- Таблица генерируется: bin/console module:list --markdown=1 --write=1',
            '     Руками не править — CI сверяет её с манифестами модулей. -->',
            '',
            '| Модуль | Назначение | Адрес | Таблицы |',
            '|---|---|---|---|',
        ];

        foreach ($connected as $r)
        {
            $purpose = trim($r['title'].($r['short'] !== '' ? ' — '.$r['short'] : ''));
            $tables  = $r['tables']
                ? implode(', ', array_map(function ($t) { return '`'.$t.'`'; }, $r['tables']))
                : '—';

            $out[] = '| `'.$r['module'].'` | '.self::_cell($purpose !== '' ? $purpose : '—')
                   .' | '.($r['href'] !== '' ? '`'.$r['href'].'`' : '—')
                   .' | '.self::_cell($tables).' |';
        }

        $out[] = '';
        $out[] = 'Подключено модулей: '.count($connected).'. Карточка модуля — `<модуль>/AGENTS.md`.';
        $out[] = 'Зрелость сервиса объявляется в манифесте (`status`: draft / beta / stable /';
        $out[] = 'deprecated) и показывается значком в каталоге — в этой таблице её нет намеренно:';
        $out[] = 'колонка «состояние» здесь не обновлялась и врала.';

        if ($orphans)
        {
            $names = array_map(function ($r) { return '`'.$r['module'].'`'; }, $orphans);
            $out[] = '';
            $out[] = 'Каталоги в `application/modules`, **не подключённые** в `application/config/modules.php`: '
                   .implode(', ', $names).'. Kohana их не загружает — код лежит, но не работает.';
        }

        $out[] = '';
        $out[] = self::MARK_END;

        return implode("\n", $out);
    }

    /** Порядок групп: свои модули, потом legacy, потом безномерные. */
    protected static function _rank($name)
    {
        if (strpos($name, 'prx') === 0)  return 0;
        if (strpos($name, 'prx') === 0) return 1;

        return 2;
    }

    /** Номер модуля из имени (prx520_poll → 520), иначе 0. */
    protected static function _number($name)
    {
        return preg_match('/^[a-z]+([0-9]+)/', $name, $m) ? (int) $m[1] : 0;
    }

    /** Экранирование вертикальной черты: она разделяет колонки markdown. */
    protected static function _cell($text)
    {
        return str_replace('|', '\\|', trim($text));
    }

    /**
     * Вписать блок между маркерами в docs/ARCHITECTURE.md.
     *
     * @param  string $md готовый блок
     * @return string     что сказать в консоль
     */
    protected static function _write($md)
    {
        $root = realpath(DOCROOT.'..');
        $file = $root.'/docs/ARCHITECTURE.md';

        if ( ! is_file($file))
        {
            return 'НЕ НАЙДЕН: '.$file;
        }

        $doc = (string) file_get_contents($file);
        $a = strpos($doc, self::MARK_BEGIN);
        $b = strpos($doc, self::MARK_END);

        if ($a === FALSE OR $b === FALSE OR $b < $a)
        {
            return 'В '.$file.' нет маркеров '.self::MARK_BEGIN.' … '.self::MARK_END;
        }

        $new = substr($doc, 0, $a).$md.substr($doc, $b + strlen(self::MARK_END));

        if ($new === $doc)
        {
            return 'Реестр модулей уже актуален.';
        }

        file_put_contents($file, $new);

        return 'Реестр модулей обновлён: docs/ARCHITECTURE.md';
    }
}
