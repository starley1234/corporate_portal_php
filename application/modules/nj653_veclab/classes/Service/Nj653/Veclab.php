<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-653 «Лаборатория векторов» — чистая логика: без базы и без сети.
 *
 * Здесь живёт всё, что превращает голые числа в понятный человеку ответ:
 * словесная оценка косинусной близости, подписи модальностей и форматов
 * запроса, приведение ответа воркера к виду, который рисует страница.
 *
 * Почему это отдельный класс: такую логику надо проверять тестом
 * (`tests/Unit/VeclabServiceTest.php`), а тест не поднимает ни PostgreSQL,
 * ни эмбеддинг-сервер. Работа с таблицами — в `Service_Nj653_Store`.
 */
class Service_Nj653_Veclab
{
    /** Режимы прогона: ключ => подпись для человека. */
    const MODES = [
        'probe'   => 'Проверка модели',
        'add'     => 'Добавление в коллекцию',
        'search'  => 'Поиск по смыслу',
        'compare' => 'Сравнение пары',
    ];

    /** Модальности входа. */
    const KINDS = [
        'text'  => 'текст',
        'image' => 'картинка',
        'both'  => 'текст и картинка',
    ];

    /**
     * Подписи форматов запроса (диалектов эмбеддинг-сервера).
     *
     * Коды задаются воркером (`workers/n2j_workers/mmembed.py`, DIALECTS);
     * здесь только человеческие названия для страницы. Неизвестный код
     * показывается как есть — так новый диалект воркера не ломает вывод.
     */
    const DIALECTS = [
        'omni_messages' => 'Чат-сообщения пачкой: messages = [[{role, content}]] (свой сервис, vLLM)',
        'cohere_inputs' => 'Cohere Embed v2: inputs = [{content: […]}] → /v2/embed',
        'qwen_objects'  => 'Qwen-объекты: input = [{text, image}]',
        'oai_content'   => 'OpenAI-обёртка: input = [{content: […]}] (llama.cpp)',
        'oai_parts'     => 'OpenAI-части: input = [{type: image_url}, {type: text}]',
        'chat_messages' => 'Чат-сообщения: messages = [{role, content}] (vLLM)',
        'media_prompt'  => 'llama.cpp: prompt_string + multimodal_data',
        'plain_text'    => 'Обычный текстовый OpenAI: input = [«строка»]',
    ];

    /**
     * Словесная оценка косинусной близости.
     *
     * Голое «0.62» человеку ничего не говорит, а шкалу-полоску рисовать
     * нельзя (правило платформы: полос прогресса и метров нет). Поэтому
     * возвращаем число И слова; пороги — в конфиге модуля, чтобы их можно
     * было подогнать под конкретную модель, не трогая код.
     *
     * @param  float $cos косинус в диапазоне [-1; 1]
     * @return array ['value' => float, 'title' => string, 'tone' => string]
     */
    public static function similarity($cos)
    {
        $cos = max(-1.0, min(1.0, (float) $cos));

        foreach (self::scale() as $step)
        {
            if ($cos >= (float) $step['from'])
            {
                return [
                    'value' => round($cos, 4),
                    'title' => (string) $step['title'],
                    'tone'  => (string) $step['tone'],
                ];
            }
        }

        return ['value' => round($cos, 4), 'title' => 'ничего общего', 'tone' => 'gray'];
    }

    /** Шкала оценок из конфига (с запасным вариантом, если конфига нет). */
    public static function scale()
    {
        $scale = (array) Kohana::$config->load('nj653_veclab.scale');

        if ( ! $scale)
        {
            return [
                ['from' => 0.70, 'title' => 'про одно и то же', 'tone' => 'emerald'],
                ['from' => 0.30, 'title' => 'есть общая тема',  'tone' => 'amber'],
                ['from' => -1.0, 'title' => 'ничего общего',    'tone' => 'gray'],
            ];
        }

        return $scale;
    }

    /** Подпись модальности: text | image | both. */
    public static function kind_label($kind)
    {
        return self::KINDS[(string) $kind] ?? self::KINDS['text'];
    }

    /** Иконка модальности для списка коллекции. */
    public static function kind_icon($kind)
    {
        return [
            'text'  => 'fa-align-left',
            'image' => 'fa-image',
            'both'  => 'fa-layer-group',
        ][(string) $kind] ?? 'fa-align-left';
    }

    /** Подпись формата запроса (диалекта) эмбеддинг-сервера. */
    public static function dialect_title($code)
    {
        $code = trim((string) $code);

        if ($code === '')
        {
            return 'не определён';
        }

        return self::DIALECTS[$code] ?? $code;
    }

    /** Подпись режима прогона. */
    public static function mode_label($mode)
    {
        return self::MODES[(string) $mode] ?? 'Прогон';
    }

    /**
     * Косинусная близость двух векторов.
     *
     * Сайту она нужна редко (обычно считает PostgreSQL), но без неё нельзя
     * проверить тестом ни оценку, ни разбор ответа воркера.
     */
    public static function cosine(array $a, array $b)
    {
        $n = min(count($a), count($b));

        if ($n === 0 OR count($a) !== count($b))
        {
            return 0.0;
        }

        $dot = $na = $nb = 0.0;

        for ($i = 0; $i < $n; $i++)
        {
            $x = (float) $a[$i];
            $y = (float) $b[$i];

            $dot += $x * $y;
            $na  += $x * $x;
            $nb  += $y * $y;
        }

        if ($na <= 0 OR $nb <= 0)
        {
            return 0.0;
        }

        return max(-1.0, min(1.0, $dot / (sqrt($na) * sqrt($nb))));
    }

    /**
     * Ответ воркера (jsonb прогона) → данные для страницы.
     *
     * Правило: страница не должна гадать. Любой режим отдаёт одинаковый
     * каркас (mode, title, model, dims, dialect, elapsed_ms), а своё — в
     * собственном разделе: checks/passport у проверки, hits у поиска,
     * pair у сравнения.
     *
     * @param  array $raw содержимое nj_653_runs.result
     * @return array
     */
    public static function shape(array $raw)
    {
        $mode = (string) ($raw['mode'] ?? 'probe');

        if ( ! isset(self::MODES[$mode]))
        {
            $mode = 'probe';
        }

        $out = [
            'mode'       => $mode,
            'mode_label' => self::mode_label($mode),
            'title'      => mb_substr(trim((string) ($raw['title'] ?? '')), 0, 200),
            'model'      => mb_substr(trim((string) ($raw['model'] ?? '')), 0, 160),
            'dims'       => (int) ($raw['dims'] ?? 0),
            // Проверка модели не кладёт диалект в корень результата (там
            // только паспорт), поэтому пустую строку добираем из паспорта —
            // иначе страница писала «формат не определён» на рабочей модели.
            'dialect'    => (string) (($raw['dialect'] ?? '') !== ''
                ? $raw['dialect']
                : (($raw['passport']['dialect'] ?? '') ?: '')),
            'elapsed_ms' => (int) ($raw['elapsed_ms'] ?? 0),
            'error'      => mb_substr(trim((string) ($raw['error'] ?? '')), 0, 1000),
        ];

        $out['dialect_title'] = self::dialect_title($out['dialect']);

        if ($mode === 'probe')
        {
            $out['passport'] = self::_passport((array) ($raw['passport'] ?? []));
            $out['checks']   = self::_checks($raw['checks'] ?? []);
            $out['cross']    = (array) ($raw['cross'] ?? []);
            $out['ok']       = (bool) ($raw['ok'] ?? FALSE);
            $out['verdict']  = self::probe_verdict($out);
        }
        elseif ($mode === 'compare')
        {
            $out['pair'] = self::similarity($raw['cosine'] ?? 0);
            $out['kinds'] = self::_kinds($raw['kinds'] ?? []);
        }
        elseif ($mode === 'search')
        {
            $out['hits'] = self::hits($raw['hits'] ?? []);
        }
        else
        {
            $out['item_id']   = (int) ($raw['item_id'] ?? 0);
            $out['neighbors'] = self::hits($raw['neighbors'] ?? []);
            // Сколько элементов реально легло в коллекцию и сколько отсеялось
            // как уже имеющееся: без этих двух чисел страница после пачки
            // картинок говорила бы «Добавлено: 0».
            $out['added']      = (int) ($raw['added'] ?? 0);
            $out['duplicates'] = (int) ($raw['duplicates'] ?? 0);
        }

        return $out;
    }

    /**
     * Находки поиска (и соседи нового элемента) → вид для страницы.
     *
     * Сюда приходят строки из базы, где `distance` — косинусная ДИСТАНЦИЯ
     * (0 — совпадение). Человеку показываем близость: 1 − дистанция.
     */
    public static function hits($rows)
    {
        $out = [];

        foreach ((array) $rows as $row)
        {
            if ( ! is_array($row))
            {
                continue;
            }

            $score = array_key_exists('score', $row)
                ? (float) $row['score']
                : 1.0 - (float) ($row['distance'] ?? 1.0);

            $out[] = [
                'id'      => (int) ($row['id'] ?? 0),
                'label'   => mb_substr(trim((string) ($row['label'] ?? '')), 0, 200),
                'kind'    => isset(self::KINDS[(string) ($row['kind'] ?? '')])
                    ? (string) $row['kind'] : 'text',
                'content' => mb_substr(trim((string) ($row['content'] ?? '')), 0, 400),
                'preview' => self::safe_preview((string) ($row['preview'] ?? '')),
                'about'   => self::about($row['meta'] ?? NULL),
                'score'   => self::similarity($score),
            ];
        }

        return $out;
    }

    /**
     * Паспорт исходной картинки одной строкой: «IMG_0421.jpg · 4032×3024 · 3,1 МБ».
     *
     * В коллекции от кадра остаётся превью 96 px, и по нему не вспомнить, что
     * это был за файл. Метаданные (миграция 002) отвечают на этот вопрос, но
     * показывать сырой jsonb человеку нельзя — собираем строку.
     *
     * @param  mixed $meta jsonb из базы (строка или массив)
     * @return string пустая строка, если сведений нет
     */
    public static function about($meta)
    {
        if (is_string($meta))
        {
            $meta = json_decode($meta, TRUE);
        }

        if ( ! is_array($meta) OR ! $meta)
        {
            return '';
        }

        $parts = [];
        $name  = trim((string) ($meta['name'] ?? ''));

        if ($name !== '')
        {
            $parts[] = mb_substr($name, 0, 60);
        }

        $w = (int) ($meta['source_width'] ?? $meta['width'] ?? 0);
        $h = (int) ($meta['source_height'] ?? $meta['height'] ?? 0);

        if ($w > 0 AND $h > 0)
        {
            $parts[] = $w.'×'.$h;
        }

        $bytes = (int) ($meta['source_bytes'] ?? $meta['bytes'] ?? 0);

        if ($bytes > 0)
        {
            $parts[] = $bytes >= 1048576
                ? number_format($bytes / 1048576, 1, ',', ' ').' МБ'
                : max(1, (int) round($bytes / 1024)).' КБ';
        }

        return implode(' · ', $parts);
    }

    /**
     * Превью картинки для вывода в теге img.
     *
     * В базе лежит data URL, который прислал браузер. В разметку он попадает
     * как адрес, поэтому пропускаем ТОЛЬКО картинки в base64: иначе
     * `javascript:` или `data:text/html` из чужого запроса приехал бы прямо
     * в атрибут src.
     */
    public static function safe_preview($value)
    {
        $value = trim((string) $value);

        if ($value === '')
        {
            return '';
        }

        if ( ! preg_match('~^data:image/(png|jpeg|jpg|webp);base64,[A-Za-z0-9+/=\s]+$~', $value))
        {
            return '';
        }

        return preg_replace('/\s+/', '', $value);
    }

    /**
     * Итог самопроверки модели одной фразой.
     *
     * Отдельно вынесена кросс-модальность: модель может прекрасно работать с
     * текстом и при этом не связывать его с картинками — для лаборатории это
     * главный вопрос, и ответ «вроде всё хорошо» тут недопустим.
     */
    public static function probe_verdict(array $shape)
    {
        $checks = (array) ($shape['checks'] ?? []);
        $failed = [];
        $warned = FALSE;
        $cross  = NULL;

        foreach ($checks as $check)
        {
            if (($check['ok'] ?? NULL) === FALSE)
            {
                $failed[] = (string) ($check['title'] ?? 'проверка');
            }

            if ((string) ($check['level'] ?? '') === 'warn')
            {
                $warned = TRUE;
            }

            if ((string) ($check['key'] ?? '') === 'cross')
            {
                $cross = $check['ok'] ?? NULL;
            }
        }

        if ($cross === FALSE)
        {
            return [
                'ok'    => FALSE,
                'tone'  => 'rose',
                'title' => 'Модель не связывает текст и картинку',
                'note'  => 'Векторы считаются, но подпись не находит свою картинку: '
                    .'похоже, подключена обычная текстовая модель эмбеддингов '
                    .'или сервер отдаёт картинку не той модели.',
            ];
        }

        if ($failed)
        {
            return [
                'ok'    => FALSE,
                'tone'  => 'amber',
                'title' => 'Модель отвечает, но ведёт себя странно',
                'note'  => 'Не прошли проверки: '.implode('; ', $failed).'.',
            ];
        }

        if ($cross === NULL)
        {
            return [
                'ok'    => TRUE,
                'tone'  => 'amber',
                'title' => 'Текст работает, картинки не проверены',
                'note'  => 'Кросс-модальную пробу пропустили — смотрите пояснение ниже.',
            ];
        }

        if ((string) (($shape['passport'] ?? [])['mode'] ?? '') === 'caption')
        {
            // Работает, но ДРУГИМ способом: вектор по картинке даёт не
            // эмбеддинг-модель, а связка «vision-чат → слова → текстовый
            // вектор». Выдавать это за мультимодальную модель нечестно.
            return [
                'ok'    => TRUE,
                'tone'  => 'amber',
                'title' => 'Поиск работает через описание картинок',
                'note'  => 'Сервер не считает векторы по картинке, поэтому её описывает '
                    .'vision-модель (как в /meal), а вектор даёт текстовая модель. '
                    .'Поиск словами работает; качество зависит от описания.',
            ];
        }

        $note = 'Текст и картинки попадают в одно пространство: подпись находит '
            .'свою картинку, близкие по смыслу фразы — рядом.';

        // Оговорка о повторяемости: человек всё равно увидит 0,9976 вместо
        // единицы и решит, что модель сломана. Объясняем это прямо в итоге.
        if ($warned)
        {
            $note .= ' Один и тот же вход даёт не ровно единицу, а около 0,999: '
                .'так считает любая квантованная сборка, на поиск это не влияет.';
        }

        return [
            'ok'    => TRUE,
            'tone'  => 'emerald',
            'title' => 'Модель пригодна для мультимодального поиска',
            'note'  => $note,
        ];
    }

    // ------------------------------------------------------------ помощники

    protected static function _passport(array $raw)
    {
        $attempts = [];

        foreach ((array) ($raw['attempts'] ?? []) as $attempt)
        {
            if ( ! is_array($attempt))
            {
                continue;
            }

            $code = (string) ($attempt['dialect'] ?? '');

            $attempts[] = [
                'dialect' => $code,
                'title'   => self::dialect_title($code),
                'ok'      => ! empty($attempt['ok']),
                'ms'      => (int) ($attempt['ms'] ?? 0),
                'dims'    => (int) ($attempt['dims'] ?? 0),
                'error'   => mb_substr(trim((string) ($attempt['error'] ?? '')), 0, 300),
            ];
        }

        // Что сервер считает загруженным: без этого «ни один формат не
        // подошёл» выглядит как вина формата, хотя чаще дело в модели.
        $models = [];

        foreach ((array) ($raw['models'] ?? []) as $name)
        {
            if (is_string($name) AND trim($name) !== '')
            {
                $models[] = mb_substr(trim($name), 0, 160);
            }
        }

        return [
            'base_url'      => mb_substr(trim((string) ($raw['base_url'] ?? '')), 0, 200),
            'model'         => mb_substr(trim((string) ($raw['model'] ?? '')), 0, 160),
            'dims'          => (int) ($raw['dimensions'] ?? 0),
            'dims_real'     => (int) ($raw['dims_real'] ?? 0),
            'dialect'       => (string) ($raw['dialect'] ?? ''),
            'forced'        => ! empty($raw['forced']),
            'token_src'     => mb_substr(trim((string) ($raw['token_src'] ?? '')), 0, 60),
            'provider'      => mb_substr(trim((string) ($raw['provider'] ?? '')), 0, 60),
            'proxy'         => ! empty($raw['proxy']),
            'mode'          => (string) ($raw['mode'] ?? '') === 'caption' ? 'caption' : 'native',
            'mode_note'     => mb_substr(trim((string) ($raw['mode_note'] ?? '')), 0, 400),
            'caption_model' => mb_substr(trim((string) ($raw['caption_model'] ?? '')), 0, 160),
            'text_model'    => mb_substr(trim((string) ($raw['text_model'] ?? '')), 0, 160),
            'model_note'    => mb_substr(trim((string) ($raw['model_note'] ?? '')), 0, 400),
            'models'        => array_slice($models, 0, 20),
            'models_error'  => mb_substr(trim((string) ($raw['models_error'] ?? '')), 0, 300),
            'model_known'   => ! empty($raw['model_known']),
            'text_ms'       => (int) ($raw['text_ms'] ?? 0),
            'attempts'      => $attempts,
        ];
    }

    protected static function _checks($raw)
    {
        $out = [];

        foreach ((array) $raw as $check)
        {
            if ( ! is_array($check))
            {
                continue;
            }

            $value = $check['value'] ?? NULL;

            $ok = array_key_exists('ok', $check) && $check['ok'] !== NULL
                ? (bool) $check['ok'] : NULL;

            // Уровень подробнее, чем «да/нет»: у повторяемости есть середина —
            // вектор воспроизводится почти точно (0,998), и это не провал, а
            // свойство квантованной модели. Старые прогоны поля не знают,
            // поэтому выводим его из ok.
            $level = (string) ($check['level'] ?? '');

            if ( ! in_array($level, ['ok', 'warn', 'fail', 'skip'], TRUE))
            {
                $level = ($ok === NULL) ? 'skip' : ($ok ? 'ok' : 'fail');
            }

            $out[] = [
                'key'   => mb_substr((string) ($check['key'] ?? ''), 0, 40),
                'title' => mb_substr(trim((string) ($check['title'] ?? '')), 0, 200),
                'value' => ($value === NULL) ? NULL : round((float) $value, 4),
                // NULL — проверку пропустили; это не то же самое, что провал.
                'ok'    => $ok,
                'level' => $level,
                'note'  => mb_substr(trim((string) ($check['note'] ?? '')), 0, 300),
            ];
        }

        return $out;
    }

    protected static function _kinds($raw)
    {
        $out = [];

        foreach ((array) $raw as $kind)
        {
            $out[] = isset(self::KINDS[(string) $kind]) ? (string) $kind : 'text';
        }

        return $out;
    }
}
