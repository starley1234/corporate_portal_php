<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx120: автособираемая документация по воркерам.
 *
 * Источник истины — workers/prx_workers/handlers/*.py: docstring каждого
 * обработчика (человекопонятное описание + «Пайплайн:»), константа CHANNEL
 * и реестр HANDLERS в handlers/__init__.py. База данных не нужна: страницу
 * можно открыть в любой момент, документация всегда соответствует коду.
 *
 * Mermaid-диаграммы собираем из шагов пайплайна (стрелки «→» или нумерованный
 * конвейер в docstring); промпт модели — из константы SYSTEM*. Всё — из кода.
 */
class Service_Prx120_Workerdocs {

    /** Каталог с обработчиками воркера (относительно DOCROOT — public_html). */
    public static function handlers_dir(): string
    {
        return realpath(DOCROOT.'..'.DIRECTORY_SEPARATOR.'workers'
            .DIRECTORY_SEPARATOR.'prx_workers'.DIRECTORY_SEPARATOR.'handlers') ?: '';
    }

    /**
     * Краткие карточки из манифестов: видны всегда, даже когда каталог
     * workers/ не развёрнут рядом с сайтом. Полные страницы (пайплайн,
     * промпты, mermaid) собираются только из кода воркера.
     */
    protected static function _from_manifests(): array
    {
        $out = [];
        foreach (Manifest::all() as $module)
        {
            $channels = (array) ($module['worker']['channels'] ?? []);
            if ($channels === []) continue;

            $out[] = [
                'name'     => (string) ($module['slug'] ?? ''),
                'file'     => 'application/modules/'.(string) ($module['slug'] ?? '').'/config/prx_module.php',
                'channels' => array_keys($channels),
                'title'    => (string) ($module['title'] ?? ''),
                'about'    => (string) ($module['short'] ?? ''),
                'pipeline' => [],
                'env'      => [],
                'prompt'   => '',
                'mermaid'  => '',
                'source'   => 'manifest',
            ];
        }

        usort($out, static function (array $a, array $b): int {
            return strcmp((string) $a['name'], (string) $b['name']);
        });

        return $out;
    }

    /** Все воркеры: [][name, channels, title, about, pipeline, env, mermaid, file, source]. */
    public static function all(): array
    {
        $dir = self::handlers_dir();

        if ($dir === '')
        {
            // Код воркера развёрнут не рядом с каждым сайтом (Toolforge: только
            // public_html). Документация всё равно нужна — собираем КРАТКИЕ
            // карточки из манифестов (title/short/каналы), чтобы страница
            // не была пустой («Обработчики не найдены»).
            return self::_from_manifests();
        }

        $channels_of = self::_registry_channels($dir.DIRECTORY_SEPARATOR.'__init__.py');
        $out = [];

        foreach (glob($dir.DIRECTORY_SEPARATOR.'*.py') as $file)
        {
            $name = basename($file, '.py');

            if ($name === '__init__')
            {
                continue;
            }

            $src     = (string) file_get_contents($file);
            $doc     = self::_docstring($src);
            $title   = self::_title($doc, $name);
            $about   = self::_about($doc);
            $pipe    = self::_pipeline($doc);
            $env     = self::_env_keys($doc);

            $out[] = [
                'name'     => $name,
                'file'     => 'workers/prx_workers/handlers/'.basename($file),
                'channels' => $channels_of[$name] ?? [],
                'title'    => $title,
                'about'    => $about,
                'pipeline' => $pipe,
                'env'      => $env,
                'prompt'   => self::_prompt($src),
                'mermaid'  => self::_mermaid($pipe),
                'source'   => 'code',
            ];
        }

        // по имени файла: системные (echo/embeddings/...) внизу, prxXXX — по номеру
        usort($out, static function (array $a, array $b): int {
            $sa = str_starts_with($a['name'], 'prx') ? '0'.$a['name'] : '1'.$a['name'];
            $sb = str_starts_with($b['name'], 'prx') ? '0'.$b['name'] : '1'.$b['name'];

            return strcmp($sa, $sb);
        });

        return $out;
    }

    /** Реестр HANDLERS: модуль → список каналов (константа + явные строки). */
    protected static function _registry_channels(string $init_file): array
    {
        if ( ! is_file($init_file))
        {
            return [];
        }

        $src = (string) file_get_contents($init_file);
        $map = [];

        // "prx602.article": prx602_article.handle — явная регистрация канала
        if (preg_match_all('~"([a-z0-9_.]+)":\s*(\w+)\.handle~', $src, $m, PREG_SET_ORDER))
        {
            foreach ($m as $r)
            {
                $map[$r[2]][] = $r[1];
            }
        }

        // embeddings.CHANNEL: embeddings.handle — канал берётся из константы модуля
        if (preg_match_all('~(\w+)\.CHANNEL:\s*\1\.handle~', $src, $m, PREG_SET_ORDER))
        {
            foreach ($m as $r)
            {
                // константа CHANNEL = "..."
                $mod_src = @file_get_contents(dirname($init_file).DIRECTORY_SEPARATOR.$r[1].'.py');

                if ($mod_src AND preg_match('~CHANNEL\s*=\s*"([a-z0-9_.]+)"~', $mod_src, $c))
                {
                    $map[$r[1]][] = $c[1];
                }
            }
        }

        foreach ($map as $mod => $ch)
        {
            $map[$mod] = array_values(array_unique($ch));
        }

        return $map;
    }

    /** Docstring файла (первый тройной кавычный блок). */
    protected static function _docstring(string $src): string
    {
        foreach (['"""', "'''"] as $q)
        {
            $pos = strpos($src, $q);

            if ($pos !== FALSE)
            {
                $end = strpos($src, $q, $pos + 3);

                if ($end !== FALSE)
                {
                    return trim((string) substr($src, $pos + 3, $end - $pos - 3));
                }
            }
        }

        return '';
    }

    /** Заголовок: первая строка docstring без префикса «канал — ». */
    protected static function _title(string $doc, string $fallback): string
    {
        $line = trim((string) strtok($doc, "\n"));

        // «prx606.leads — Поток заказчиков: найти компании...» → человекочитаемое
        $line = preg_replace('~^[a-z0-9_.]+\s+[—–-]\s*~u', '', $line);

        if ($line === '' OR $line === NULL)
        {
            return $fallback;
        }

        // отрезаем пояснение после «:» в первой фразе, если заголовок длинный
        if (mb_strlen($line) > 80 AND ($p = mb_strpos($line, ':')) !== FALSE)
        {
            $line = mb_substr($line, 0, $p);
        }

        return $line;
    }

    /** Описание: абзацы docstring до служебных секций (Пайплайн/Env/Новый канал). */
    protected static function _about(string $doc): string
    {
        $lines    = preg_split('~\R~', $doc) ?: [];
        $parts    = [];
        $started  = FALSE;

        foreach ($lines as $i => $line)
        {
            $t = trim($line);

            if ($i === 0)
            {
                continue; // заголовок уже показываем отдельно
            }

            if (preg_match('~^(Пайплайн|Конвейер|Env|Новый канал|Имя канала)\s*:?~u', $t))
            {
                break;
            }

            if ($t !== '')
            {
                $parts[] = $t;
                $started = TRUE;
            }
            elseif ($started)
            {
                break; // конец вводного абзаца
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Шаги пайплайна из docstring:
     *   1) «Пайплайн: A → B → C» / «Конвейер: …» — по стрелкам; цепочка может
     *      переноситься на несколько строк (строки до точки с «→» в буфере);
     *   2) иначе нумерованный список 1. / 1) внутри секции «Конвейер:» /
     *      «Архитектура:» (prx580, prx602, prx639...).
     */
    protected static function _pipeline(string $doc): array
    {
        $lines = preg_split('~\R~', $doc) ?: [];
        $buf   = '';
        $on    = FALSE;

        // 1) сокращённая запись «Пайплайн|Конвейер: …» (в любом месте docstring)
        foreach ($lines as $line)
        {
            $t = trim($line);

            if (preg_match('~^(Пайплайн|Конвейер)\s*:\s*(.*)$~u', $t, $m))
            {
                $buf = trim($m[2]);
                $on  = TRUE;

                if ($buf !== '' AND preg_match('~\.\s*$~', $buf) AND str_contains($buf, '→'))
                {
                    break; // законченная однострочная цепочка
                }

                continue;
            }

            if ($on)
            {
                if ($t === '')
                {
                    break; // пустая строка закрывает секцию
                }

                $buf .= ' '.$t;

                // цепочка закончилась: строка с точкой при уже собранных «→»
                if (preg_match('~\.\s*$~', $t) AND str_contains($buf, '→'))
                {
                    break;
                }
            }
        }

        if ($buf !== '' AND str_contains($buf, '→'))
        {
            return self::_steps_from_arrows($buf);
        }

        // 2) многострочный конвейер: шаги нумерованы внутри секции «Конвейер:»/
        //    «Архитектура:». Заголовок может быть с пояснением («Конвейер (…):»).
        $steps       = [];
        $in_section  = FALSE;

        foreach ($lines as $line)
        {
            $t = trim($line);

            if (preg_match('~^(Конвейер|Архитектура|Пайплайн)\b~u', $t))
            {
                $in_section = TRUE;
                continue;
            }

            if ( ! $in_section)
            {
                continue;
            }

            if ($t === '')
            {
                if (count($steps) > 0)
                {
                    break;
                }

                continue;
            }

            if (preg_match('~^\d+[.)]\s+~u', $t))
            {
                $steps[] = (string) preg_replace('~^\d+[.)]\s+~u', '', $t);
                continue;
            }

            if (count($steps) > 0)
            {
                // продолжение шага (длинные пункты переносятся), кроме новой служебной секции
                if (preg_match('~^(Env|Режим|LLM|Воркер|Безопасность)\b~u', $t))
                {
                    break;
                }

                $steps[count($steps) - 1] .= ' '.$t;
            }
            // строки-пояснения заголовка секции (до первого шага) — пропускаем
        }

        return count($steps) >= 2 ? $steps : [];
    }


    /** Шаги из «A → B → C» (одна строка). */
    protected static function _steps_from_arrows(string $buf): array
    {
        $buf   = rtrim($buf, " .");
        $steps = array_map('trim', explode('→', $buf));

        return array_values(array_filter($steps, static fn(string $s): bool => $s !== ''));
    }

    /**
     * Промпт обработчика: текст первой промпт-константы вида
     *     SYSTEM = ("часть" "часть" ...)
     *     SYSTEM_CURATOR = (...)
     * Имя с «SYSTEM» (SYSTEM_*, RANK_SYSTEM, ANALYZE_SYSTEM...) — фильтр отсекает
     * списки вроде REVIEW_HOSTS / _UA. Склеим смежные строковые литералы.
     * Текст дословный: управление видит, ЧЕМУ учится модель, а не пересказ.
     */
    protected static function _prompt(string $src): string
    {
        if (preg_match_all('~^([A-Z][A-Z0-9_]*)\s*=\s*\(~m', $src, $mm, PREG_OFFSET_CAPTURE))
        {
            foreach ($mm[1] as $hit)
            {
                $name = $hit[0];

                if (stripos($name, 'SYSTEM') === FALSE)
                {
                    continue; // не промпт: хосты, UA, созвездия…
                }

                preg_match('~^'.preg_quote($name, '~').'\s*=\s*\(~m', $src, $m, PREG_OFFSET_CAPTURE);
                $open  = $m[0][1] + strlen($m[0][0]);
                $close = self::_match_parens($src, $open);

                if ($close !== FALSE)
                {
                    $text = self::_concat_string_literals(substr($src, $open, $close - $open + 1));

                    if ($text !== '')
                    {
                        return $text;
                    }
                }
            }
        }

        return '';
    }


    /** Индекс закрывающей скобки, считая вложенность и пропуская строки. */
    protected static function _match_parens(string $src, int $open): int|false
    {
        $depth = 0;
        $len   = strlen($src);
        $inq   = NULL;

        for ($i = $open; $i < $len; $i++)
        {
            $ch = $src[$i];

            if ($inq !== NULL)
            {
                if ($ch === chr(92)) // обратный слеш — экранированный символ
                {
                    $i++;
                    continue;
                }

                if ($ch === $inq)
                {
                    $inq = NULL;
                }

                continue;
            }

            if ($ch === '"' OR $ch === "'")
            {
                $inq = $ch;
                continue;
            }

            if ($ch === '(')
            {
                $depth++;
            }
            elseif ($ch === ')')
            {
                $depth--;

                if ($depth === 0)
                {
                    return $i;
                }
            }
        }

        return FALSE;
    }

    /** Склеить смежные строковые литералы "…"/'…' внутри скобок (ручной сканнер). */
    protected static function _concat_string_literals(string $inner): string
    {
        $parts = [];
        $i     = 0;
        $len   = strlen($inner);

        while ($i < $len)
        {
            $q = $inner[$i];

            if ($q === '"' OR $q === "'")
            {
                $i++;
                $buf = '';

                while ($i < $len AND $inner[$i] !== $q)
                {
                    if ($inner[$i] === chr(92) AND $i + 1 < $len)
                    {
                        $i++; // экранированный символ: берём его как есть
                    }

                    $buf .= $inner[$i];
                    $i++;
                }

                if (trim($buf) !== '')
                {
                    $parts[] = $buf;
                }

                $i++; // закрывающая кавычка
                continue;
            }

            $i++;
        }

        return implode('', $parts);
    }


    /** Env-ключи из docstring (PRXxxx_*, WORKER_*, LLM_*). */
    protected static function _env_keys(string $doc): array
    {
        if (preg_match_all('~\b((?:PRX\d{3}|WORKER|LLM|OPENAI|ANTHROPIC|GIGACHAT|YANDEX)_[A-Z0-9_]+)\b~', $doc, $m))
        {
            return array_values(array_unique($m[1]));
        }

        return [];
    }

    /** Mermaid flowchart LR из шагов пайплайна; без шагов — пустая строка. */
    protected static function _mermaid(array $steps): string
    {
        if (count($steps) < 2)
        {
            return '';
        }

        $lines = ['flowchart LR'];

        foreach ($steps as $i => $step)
        {
            $label = self::_mmd_label($i === count($steps) - 1 ? $step : $step);
            $lines[] = '    N'.$i.'["'.$label.'"]';
        }

        for ($i = 0; $i < count($steps) - 1; $i++)
        {
            $lines[] = '    N'.$i.' --> N'.($i + 1);
        }

        return implode("\n", $lines);
    }

    /** Подпись узла mermaid: кавычки — на #quot;, длина до 72 знаков.
     *  Скобки внутри «кавыченной» подписи mermaid допустимы как есть. */
    protected static function _mmd_label(string $s): string
    {
        $s = trim(preg_replace('~\s+~u', ' ', $s) ?? '', " .");
        $s = str_replace('"', '#quot;', $s);

        if (mb_strlen($s) > 72)
        {
            $s = mb_substr($s, 0, 71).'…';
        }

        return $s;
    }

}
