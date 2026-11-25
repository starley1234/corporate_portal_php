<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Детерминированная сборка актуальной редакции — витрина модуля.
 *
 * Принцип «LLM предлагает, код распоряжается»: дельты от модели — это ТОЛЬКО
 * инструкции (replace/add/annul + anchor), а итоговый текст здесь собирается
 * чистыми строковыми операциями по цепочке ДС. Никакого текста из модели в
 * документ без явной инструкции попасть не может; повторный build на тех же
 * входных даёт байт-в-байт тот же результат.
 *
 * Вход — «плоское» дерево base-файла: [{path, title, text, status, source, history}].
 * Узел — пункт (path вида '4.2.1'); заголовки Раздел/Статья — узлы с
 * текстовым префиксом ('раздел-4', 'ст-1'), ссылки на них валидируются так же.
 */
class Service_Prx399_Merge {

    const ERROR = 'Prx399_Error';

    /** Инструкции, которые может отдать модель (схема дельт — см. AGENTS). */
    const OPS = ['replace', 'add', 'annul'];

    /** Надёжный якорь: пункт '4.2.1' или словесный заголовок 'раздел-4'. */
    const ANCHOR_RE = '/\A(?:\d{1,3}(?:\.\d{1,3}){0,4}|раздел-\d{1,3}|ст-\d{1,3}|прил-\d{1,3})\z/u';

    // ------------------------------------------------------------ validate

    /**
     * Валидация одной дельты от воркера. Строго: неизвестное поле/якорь/тип
     * операции — ошибка задания, а не «пропустили и забыли».
     */
    public static function validate_op(array $op)
    {
        if ( ! isset($op['op'], $op['anchor']) OR ! in_array($op['op'], self::OPS, TRUE))
        {
            throw new InvalidArgumentException('delta: op/anchor обязательны, op ∈ replace|add|annul');
        }
        $anchor = strtolower(trim((string) $op['anchor']));
        if (preg_match(self::ANCHOR_RE, $anchor) !== 1)
        {
            throw new InvalidArgumentException('delta: некорректный anchor '.$anchor);
        }
        $body = isset($op['body']) ? (string) $op['body'] : '';
        if (in_array($op['op'], ['replace', 'add'], TRUE) AND trim($body) === '')
        {
            throw new InvalidArgumentException('delta: replace/add требуют body');
        }
        $confidence = isset($op['confidence']) ? (float) $op['confidence'] : NULL;
        if ($confidence !== NULL AND ($confidence < 0 OR $confidence > 1))
        {
            $confidence = NULL;
        }

        return [
            'op' => $op['op'],
            'anchor' => $anchor,
            'body' => $body === '' ? NULL : $body,
            'note' => isset($op['note']) ? mb_substr((string) $op['note'], 0, 1000) : NULL,
            'confidence' => $confidence,
        ];
    }

    // --------------------------------------------------------------- build

    /**
     * Применить дельты по цепочке ДС и вернуть [tree, broken_links].
     * Вызывается транзакционно из Pipeline, когда parsed все файлы пакета.
     */
    public static function build($contract, array $files, array $deltas)
    {
        $base = NULL;
        foreach ($files as $file)
        {
            if ($file['kind'] === 'base')
            {
                $base = $file;
            }
        }
        if ($base === NULL OR empty($base['ast']))
        {
            throw new self::ERROR('no_base_ast', 409);
        }

        $tree = json_decode($base['ast'], TRUE);
        if ( ! isset($tree['nodes']) OR ! is_array($tree['nodes']))
        {
            throw new self::ERROR('no_base_ast', 409);
        }
        $nodes = $tree['nodes'];

        // ДС по ordinal'ам; внутри файла — seq (порядок из ответа модели).
        $by_file = [];
        foreach ($deltas as $delta)
        {
            $by_file[(int) $delta['file_id']][] = $delta;
        }

        $applied = [];
        foreach ($files as $file)
        {
            if ($file['kind'] !== 'ds')
            {
                continue;
            }
            $rev = 'ДС №'.$file['ordinal'];
            foreach ($by_file[(int) $file['id']] ?? [] as $delta)
            {
                $nodes = self::_apply($nodes, $delta, $rev, (int) $file['id']);
                $applied[] = ['rev' => $rev, 'anchor' => $delta['anchor'], 'op' => $delta['op']];
            }
        }

        $broken = self::_validate_links($nodes);

        return [$nodes, $broken, $applied];
    }

    /**
     * Применение одной инструкции. Алгоритм:
     *  replace — переписать text узла (якорь обязан существовать);
     *  annul   — узел и его поддерево помечаются annulled (текст сохраняется
     *            в history, живой текст очищается — ссылка на него станет
     *            «битой», это фича, а не баг);
     *  add     — новый узел-ребёнок ПОСЛЕ поддерева якоря с номером
     *            max(существующие дети)+1 — детерминированно без LLM.
     */
    private static function _apply(array $nodes, array $delta, $rev, $file_id)
    {
        $anchor = (string) $delta['anchor'];
        $index = self::_index_of($nodes, $anchor);
        $op = (string) $delta['op'];

        if ($op === 'add')
        {
            $child = self::_next_child_path($nodes, $anchor);
            if ($child === NULL)
            {
                throw new self::ERROR('bad_anchor:'.$anchor, 409);
            }
            $nodes[] = [
                'path' => $child,
                'title' => 'Пункт '.$child,
                'text' => (string) $delta['body'],
                'status' => 'added',
                'source' => $rev,
                'history' => [['rev' => $rev, 'op' => 'add', 'file_id' => $file_id]],
            ];

            return $nodes;
        }

        if ($index === NULL)
        {
            throw new self::ERROR('bad_anchor:'.$anchor, 409);
        }

        if ($op === 'replace')
        {
            $nodes[$index]['history'][] = ['rev' => $rev, 'op' => 'replace', 'file_id' => $file_id, 'text' => $nodes[$index]['text']];
            $nodes[$index]['text'] = (string) $delta['body'];
            $nodes[$index]['status'] = 'changed';
            $nodes[$index]['source'] = $rev;

            return $nodes;
        }

        // annul: якорь + все узлы, путь которых начинается с якоря.
        $prefix = $anchor.'.';
        foreach ($nodes as $i => $node)
        {
            $path = (string) $node['path'];
            if ($path === $anchor OR strpos($path.'.', $prefix) === 0)
            {
                $nodes[$i]['history'][] = ['rev' => $rev, 'op' => 'annul', 'file_id' => $file_id, 'text' => $node['text']];
                $nodes[$i]['text'] = '';
                $nodes[$i]['status'] = 'annulled';
                $nodes[$i]['source'] = $rev;
            }
        }

        return $nodes;
    }

    private static function _index_of(array $nodes, $path)
    {
        foreach ($nodes as $i => $node)
        {
            if (strtolower((string) $node['path']) === $path)
            {
                return $i;
            }
        }

        return NULL;
    }

    /** Первый свободный номер ребёнка якоря: 4 + дети 4.1,4.2 → '4.3'. */
    private static function _next_child_path(array $nodes, $anchor)
    {
        $max = 0;
        $prefix = strtolower($anchor).'.';
        foreach ($nodes as $node)
        {
            $path = strtolower((string) $node['path']);
            if (strpos($path, $prefix) === 0 AND strpos($path, '.', strlen($prefix)) === FALSE)
            {
                $tail = (int) substr($path, strlen($prefix));
                $max = max($max, $tail);
            }
        }

        // У якоря ещё нет детей и якорь сам не существует — «добавить» нельзя:
        // осмысленно только в существующий пункт/раздел (см. tests воркера).
        if ($max === 0 AND self::_index_of($nodes, $anchor) === NULL)
        {
            return NULL;
        }

        return $anchor.'.'.($max + 1);
    }

    // -------------------------------------------------------- broken links

    /**
     * Гибридная валидация коллизий — БЕЗ LLM. Ищем ссылки «п. 4.2», «пп. 3.1»,
     * «статья 5», «раздел 2» в живых текстах и проверяем якоря: узел должен
     * существовать и не быть annulled. Каждая битая ссылка = статический риск
     * (severity high) + строка в broken_links для diff-вида.
     */
    public static function _validate_links(array $nodes)
    {
        $alive = [];
        foreach ($nodes as $node)
        {
            if ((string) $node['status'] !== 'annulled')
            {
                $alive[strtolower((string) $node['path'])] = TRUE;
            }
        }

        $ref_re = '/(?:пункт|п\.|пп\.)\s*(\d{1,3}(?:\.\d{1,3}){0,4})|(?:раздел)\s*(\d{1,3})|(?:стать(?:я|и|ей|ью))\s*(\d{1,3})/ui';
        $broken = [];
        foreach ($nodes as $node)
        {
            if ((string) $node['status'] === 'annulled' OR (string) $node['text'] === '')
            {
                continue;
            }
            if (preg_match_all($ref_re, (string) $node['text'], $matches, PREG_SET_ORDER))
            {
                foreach ($matches as $m)
                {
                    $ref = ($m[1] ?? '') !== '' ? $m[1] : (($m[2] ?? '') !== '' ? 'раздел-'.$m[2] : 'ст-'.($m[3] ?? ''));
                    $ref = strtolower(trim($ref));
                    if ( ! isset($alive[$ref]))
                    {
                        $broken[] = [
                            'ref' => $ref,
                            'where' => (string) $node['path'],
                            'excerpt' => mb_substr($m[0], 0, 80),
                        ];
                    }
                }
            }
        }

        return $broken;
    }

    // -------------------------------------------------------------- export

    /** Плоский текст итоговой редакции (download .txt, вход риск-аудита). */
    public static function render_text(array $nodes, $only_alive = TRUE)
    {
        $lines = [];
        foreach ($nodes as $node)
        {
            if ($only_alive AND (string) $node['status'] === 'annulled')
            {
                continue;
            }
            $title = trim((string) $node['title']);
            $text = trim((string) $node['text']);
            if ($title !== '')
            {
                $lines[] = $title;
            }
            if ($text !== '')
            {
                $lines[] = $text;
            }
            $lines[] = '';
        }

        return trim(implode("\n", $lines))."\n";
    }
}
